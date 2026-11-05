<?php

namespace WOWM;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- archives are streamed in chunks (seek/append), which WP_Filesystem cannot do.

/**
 * Files are staged and the database is loaded into wowmt_* tables first, then one
 * RENAME swaps the tables and the staged files are moved in place.
 *
 * Staged files are stored flat, as plain numbers without an extension or folder
 * structure (stage/1, stage/2, ...); the real path is only given when a file is
 * moved into place. A PHP file from an archive therefore never sits under its own
 * name in a web-reachable folder, whatever the web server makes of .htaccess.
 */
final class Import_Job extends Job {
  const DEFAULTS = [
    'file' => '',
    'import_db' => true,
    'import_files' => true,
    'keep_dropins' => true,
    'auto_undo' => true,
    'replace' => [],
  ];

  public static function sanitize(array $in): array {
    return [
      'file' => basename((string) ($in['file'] ?? '')),
      'import_db' => !empty($in['import_db']),
      'import_files' => !empty($in['import_files']),
      'keep_dropins' => !empty($in['keep_dropins']),
      'auto_undo' => !empty($in['auto_undo']),
      'replace' => Export_Job::sanitize_pairs($in['replace'] ?? []),
    ];
  }

  protected function steps(): array {
    return [
      'prepare' => [__('Checking archive', 'winonweb-migrate'), 2],
      'extract' => [__('Unpacking files', 'winonweb-migrate'), 35],
      'database' => [__('Importing database', 'winonweb-migrate'), 40],
      'swap' => [__('Switching database', 'winonweb-migrate'), 3],
      'files' => [__('Moving files into place', 'winonweb-migrate'), 14],
      'finalize' => [__('Cleaning up', 'winonweb-migrate'), 6],
    ];
  }

  protected function cancellable(): bool {
    return empty($this->state['data']['swapped']);
  }

  private function archive_path(): string {
    $path = Storage::path($this->opt('file', ''));

    if (!$path) {
      throw new \RuntimeException(esc_html(__('The archive was not found.', 'winonweb-migrate')));
    }

    return $path;
  }

  private function stage(): string {
    return $this->dir() . '/stage';
  }

  /*
   * Inspection (also used by the import screen before anything is changed)
   */

  public static function read_manifest(string $path): array {
    $reader = new Archive_Reader($path);
    $entry = $reader->find(Archive::MANIFEST);
    $manifest = $entry ? json_decode($reader->contents($entry), true) : null;

    if (!is_array($manifest) || empty($manifest['site'])) {
      throw new \RuntimeException(esc_html(__('This is not a complete WinOnWeb Migrate archive (manifest missing).', 'winonweb-migrate')));
    }

    return $manifest;
  }

  public static function inspect(string $path): array {
    $manifest = self::read_manifest($path);
    $source = $manifest['site'];
    $target = Site::info();
    $size = (int) filesize($path);
    $notes = [];

    $note = function (string $level, string $text) use (&$notes) {
      $notes[] = ['level' => $level, 'text' => $text];
    };

    if (($manifest['format'] ?? 0) > Archive::FORMAT) {
      $note('error', __('This archive was made by a newer version of WinOnWeb Migrate. Update the plugin first.', 'winonweb-migrate'));
    }

    if (!empty($source['multisite']) || is_multisite()) {
      $note('error', __('Multisite networks are not supported yet.', 'winonweb-migrate'));
    }

    // Integrity: every entry header must be readable and the count must match the manifest.
    $integrity = self::verify_archive($path, $manifest);

    if ($integrity !== true) {
      $note('error', $integrity);
    }

    // Differential backups need their full backup.
    $base_size = 0;

    if (($manifest['kind'] ?? 'full') === 'differential') {
      $base = Storage::path($manifest['base'] ?? '');

      if (!$base) {
        $note('error', sprintf(/* translators: %s: file name of the full backup */ __('This backup only holds changes since the full backup %s, which is no longer on this server.', 'winonweb-migrate'), $manifest['base'] ?? '?'));
      } else {
        $base_size = (int) filesize($base);
        $note('info', sprintf(/* translators: %s: date of the full backup */ __('Changes-only backup: the full backup from %s is restored first, then these changes on top.', 'winonweb-migrate'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) ($manifest['base_created'] ?? filemtime($base)))));
      }
    }

    if (version_compare($source['wp'], $target['wp'], '>')) {
      $note('warning', sprintf(/* translators: 1: WordPress version of the archive, 2: WordPress version of this site */ __('The archive comes from WordPress %1$s, this site runs %2$s. Update WordPress here first to avoid database incompatibilities.', 'winonweb-migrate'), $source['wp'], $target['wp']));
    }

    if (version_compare(self::minor($source['php']), self::minor($target['php']), '>')) {
      $note('warning', sprintf(/* translators: 1: PHP version of the source site, 2: PHP version of this server */ __('The source ran PHP %1$s, this server runs PHP %2$s. Some plugins or the theme may need a newer PHP.', 'winonweb-migrate'), $source['php'], $target['php']));
    }

    if ($source['home'] === $target['home']) {
      $note('info', __('This archive was made on this site: importing it restores that snapshot.', 'winonweb-migrate'));
    } elseif ($source['home'] !== '') {
      $note('info', sprintf(/* translators: 1: old site address, 2: new site address */ __('Links and paths will be rewritten from %1$s to %2$s, including serialized data.', 'winonweb-migrate'), $source['home'], $target['home']));
    }

    if ($source['prefix'] !== $target['prefix'] && strpos($source['prefix'], 'SERVMASK') === false) {
      $note('info', sprintf(/* translators: 1: old table prefix, 2: new table prefix */ __('Table prefix %1$s will be converted to %2$s.', 'winonweb-migrate'), $source['prefix'], $target['prefix']));
    }

    $dropins = Site::host_dropins();

    if ($dropins) {
      $note('info', sprintf(/* translators: %s: list of file names */ __('This host\'s drop-ins (%s) will be kept, so its caching keeps working.', 'winonweb-migrate'), implode(', ', $dropins)));
    }

    if (empty($manifest['components']['database'])) {
      $note('info', __('The archive has no database: only files will be imported.', 'winonweb-migrate'));
    }

    $checks = self::server_checks($manifest, $size + $base_size);

    foreach ($checks as $check) {
      if ($check['status'] === 'fail') {
        $note('error', $check['label'] . ': ' . $check['detail']);
      }
    }

    return [
      'file' => basename($path),
      'size' => $size,
      'created' => $manifest['created'] ?? filemtime($path),
      'generator' => $manifest['generator'] ?? '',
      'kind' => $manifest['kind'] ?? 'full',
      'source' => $source,
      'target' => $target,
      'components' => $manifest['components'] ?? [],
      'stats' => $manifest['stats'] ?? [],
      'notes' => $notes,
      'checks' => $checks,
      'blocked' => (bool) array_filter($notes, function ($n) {
        return $n['level'] === 'error';
      }),
    ];
  }

  /**
   * @return true|string True, or what is wrong.
   */
  public static function verify_archive(string $path, array $manifest) {
    try {
      $reader = new Archive_Reader($path);
      $count = 0;

      while ($entry = $reader->next()) {
        if ($entry['name'] !== Archive::MANIFEST) {
          $count++;
        }

        $reader->seek($entry['pos'] + $entry['size']);
      }
    } catch (\Throwable $e) {
      return sprintf(/* translators: %s: technical reason */ __('The archive is damaged: %s', 'winonweb-migrate'), wp_specialchars_decode($e->getMessage(), ENT_QUOTES));
    }

    if (isset($manifest['entries']) && (int) $manifest['entries'] !== $count) {
      return sprintf(/* translators: 1: expected number of files, 2: number found */ __('The archive is incomplete: expected %1$s files, found %2$s. Upload it again.', 'winonweb-migrate'), number_format_i18n($manifest['entries']), number_format_i18n($count));
    }

    return true;
  }

  /**
   * What this server needs for the import, each as ok / warn / fail.
   */
  public static function server_checks(array $manifest, int $archive_bytes): array {
    global $wpdb;

    $checks = [];
    $add = function (string $key, string $label, string $status, string $detail) use (&$checks) {
      $checks[] = compact('key', 'label', 'status', 'detail');
    };

    // Write access
    // The content folder plus wherever plugins, must-use plugins, themes and uploads really live.
    $dirs = array_merge([WP_CONTENT_DIR], array_values(Scanner::roots()));
    $locked = array_filter($dirs, function ($dir) {
      return is_dir($dir) && !wp_is_writable($dir);
    });
    $add('write', __('Write access', 'winonweb-migrate'), $locked ? 'fail' : 'ok', $locked ? sprintf(/* translators: %s: folder names */ __('Cannot write to %s', 'winonweb-migrate'), implode(', ', array_map('wp_basename', $locked))) : __('wp-content is writable', 'winonweb-migrate'));

    // Disk: unpacked files + temporary tables (roughly the dump size) + some headroom.
    $need = (int) ($archive_bytes * 1.1 + ($manifest['stats']['db_bytes'] ?? 0) * 1.5);
    $free = Storage::free_space();
    $disk = $free === null ? 'warn' : ($free < $need ? 'fail' : ($free < $need * 1.5 ? 'warn' : 'ok'));
    $add('disk', __('Disk space', 'winonweb-migrate'), $disk, $free === null ? __('Could not be measured', 'winonweb-migrate') : sprintf(/* translators: 1: free space, 2: space needed */ __('%1$s free, about %2$s needed', 'winonweb-migrate'), size_format($free, 1), size_format($need, 1)));

    // Database server
    $server = (string) $wpdb->db_server_info();
    $mariadb = stripos($server, 'mariadb') !== false;
    $version = (string) strtok(preg_replace('/^5\.5\.5-/', '', $server), '-');
    $source_db = (string) ($manifest['site']['mysql'] ?? '');
    $from_mysql8 = $source_db !== '' && stripos($source_db, 'mariadb') === false && version_compare($source_db, '8.0', '>=');
    $converts = $from_mysql8 && ($mariadb || version_compare($version, '8.0', '<'));
    $add('mysql', __('Database server', 'winonweb-migrate'), 'ok', ($mariadb ? 'MariaDB ' : 'MySQL ') . $version . ($converts ? ' · ' . __('MySQL 8 collations will be converted', 'winonweb-migrate') : ''));

    $packet = (int) Db::value('SELECT @@max_allowed_packet');
    $add('packet', 'max_allowed_packet', $packet < MB_IN_BYTES ? 'fail' : ($packet < 4 * MB_IN_BYTES ? 'warn' : 'ok'), size_format($packet) . ($packet < 4 * MB_IN_BYTES ? ' · ' . __('very large rows may fail to import', 'winonweb-migrate') : ''));

    $utf8mb4 = $wpdb->has_cap('utf8mb4');
    $add('utf8mb4', __('Emoji support (utf8mb4)', 'winonweb-migrate'), $utf8mb4 ? 'ok' : 'warn', $utf8mb4 ? __('Supported', 'winonweb-migrate') : __('Not supported: emoji may be lost', 'winonweb-migrate'));

    // PHP
    $memory = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
    $add('memory', __('PHP memory', 'winonweb-migrate'), $memory > 0 && $memory < 64 * MB_IN_BYTES ? 'warn' : 'ok', $memory > 0 ? size_format($memory) : __('Unlimited', 'winonweb-migrate'));

    if (!class_exists('\ZipArchive')) {
      $add('zip', 'ZipArchive', 'warn', __('Missing: Duplicator archives cannot be imported', 'winonweb-migrate'));
    }

    // Extensions the source site had that this server lacks: the usual cause of a fatal after the move.
    $source_ext = is_array($manifest['site']['extensions'] ?? null) ? $manifest['site']['extensions'] : [];
    $missing_ext = array_values(array_diff($source_ext, Site::extensions()));

    if ($source_ext) {
      $add(
        'extensions',
        __('PHP extensions', 'winonweb-migrate'),
        $missing_ext ? 'warn' : 'ok',
        $missing_ext ? sprintf(/* translators: %s: list of PHP extensions */ __('Missing here: %s. Plugins that need them may break.', 'winonweb-migrate'), implode(', ', $missing_ext)) : __('Everything the source site used is available', 'winonweb-migrate'),
      );
    }

    return $checks;
  }

  private static function minor(string $version): string {
    return implode('.', array_slice(explode('.', $version), 0, 2));
  }

  /**
   * URL and path pairs from the source site to this one, in every encoding
   * they usually appear in: plain, both schemes, protocol-relative, JSON-escaped, URL-encoded.
   */
  public static function pairs(array $source, array $extra = []): array {
    $target = Site::info();
    $pairs = [];

    $url = function (string $from, string $to) use (&$pairs) {
      $from = untrailingslashit($from);
      $to = untrailingslashit($to);

      if ($from === '' || $from === $to) {
        return;
      }

      $from_bare = preg_replace('#^https?:#', '', $from);
      $to_bare = preg_replace('#^https?:#', '', $to);

      foreach (['http:', 'https:'] as $scheme) {
        $pairs[] = [$scheme . $from_bare, $to];
        $pairs[] = [str_replace('/', '\/', $scheme . $from_bare), str_replace('/', '\/', $to)];
        $pairs[] = [rawurlencode($scheme . $from_bare), rawurlencode($to)];
      }

      $pairs[] = [$from_bare, $to_bare];
      $pairs[] = [str_replace('/', '\/', $from_bare), str_replace('/', '\/', $to_bare)];
    };

    $path = function (string $from, string $to) use (&$pairs) {
      if ($from === '' || $from === $to) {
        return;
      }

      $pairs[] = [$from, $to];
      $pairs[] = [str_replace('/', '\/', $from), str_replace('/', '\/', $to)];

      // Windows paths stored with backslashes.
      if (preg_match('#^[A-Za-z]:/#', $from)) {
        $pairs[] = [str_replace('/', '\\', $from), $to];
        $pairs[] = [str_replace('/', '\\\\', $from), str_replace('/', '\/', $to)];
      }
    };

    $url($source['home'], $target['home']);
    $url($source['siteurl'], $target['siteurl']);

    if (strpos($source['uploads_url'] ?? '', $source['home']) !== 0) {
      $url($source['uploads_url'] ?? '', $target['uploads_url']);
    }

    $path($source['content_dir'], $target['content_dir']);
    $path($source['abspath'], $target['abspath']);

    foreach ($extra as $pair) {
      $pairs[] = [$pair['from'], $pair['to']];
    }

    return $pairs;
  }

  /*
   * Steps
   */

  protected function step_prepare(float $deadline): bool {
    global $wpdb;

    $path = $this->archive_path();
    $info = self::inspect($path);

    if ($info['blocked']) {
      throw new \RuntimeException(esc_html(implode(' ', wp_list_pluck(wp_list_filter($info['notes'], ['level' => 'error']), 'text'))));
    }

    $manifest = self::read_manifest($path);

    // A differential backup is unpacked on top of its full backup.
    $archives = [basename($path)];

    if (($manifest['kind'] ?? 'full') === 'differential') {
      array_unshift($archives, basename((string) $manifest['base']));
    }

    $this->state['data'] = [
      'source' => $manifest['site'],
      'pairs' => self::pairs($manifest['site'], array_merge($manifest['replace'] ?? [], $this->opt('replace', []))),
      'src_prefix' => $manifest['site']['prefix'],
      'dst_prefix' => $wpdb->prefix,
      'archives' => $archives,
      'ai' => 0,
      'done_bytes' => 0,
      'archive_size' => array_sum(
        array_map(function ($file) {
          return (int) filesize(Storage::path($file));
        }, $archives),
      ),
      'extract_pos' => 0,
      'entry' => null,
      'db' => [],
      'move_pos' => 0,
      'staged_n' => 0,
      'moved' => 0,
      'failed' => 0,
      'swapped' => false,
      'old_tables' => [],
      // How the site answers before anything changes: a page that already fails
      // is not counted against the import afterwards.
      'baseline' => Health::check(8),
    ];

    Storage::rrmdir($this->stage());
    wp_mkdir_p($this->stage());
    @unlink($this->dir() . '/staged.txt');

    $this->log(sprintf(/* translators: 1: archive file name, 2: address of the source site */ __('Importing %1$s from %2$s', 'winonweb-migrate'), $info['file'], $manifest['site']['home']));

    return true;
  }

  protected function step_extract(float $deadline): bool {
    $d = &$this->state['data'];
    $archive = Storage::path($d['archives'][$d['ai']]);

    if (!$archive) {
      throw new \RuntimeException(esc_html(__('The archive was not found.', 'winonweb-migrate')));
    }

    $reader = new Archive_Reader($archive);
    $staged = fopen($this->dir() . '/staged.txt', 'ab');
    $done = false;

    while (microtime(true) < $deadline) {
      if (!$d['entry']) {
        $reader->seek($d['extract_pos']);
        $entry = $reader->next();

        if (!$entry) {
          // Next archive of the chain (full backup first, then the changes).
          if ($d['ai'] < count($d['archives']) - 1) {
            $d['done_bytes'] += $reader->size();
            $d['ai']++;
            $d['extract_pos'] = 0;
            $reader = new Archive_Reader(Storage::path($d['archives'][$d['ai']]));
            continue;
          }

          $done = true;
          break;
        }

        $target = $this->destination($entry['name'], $d['ai'] === count($d['archives']) - 1);

        if (!$target) {
          $d['extract_pos'] = $entry['pos'] + $entry['size'];
          continue;
        }

        if ($target['rel'] !== null) {
          $d['staged_n']++;
          $target['path'] = $this->stage() . '/' . $d['staged_n'];
        }

        $d['entry'] = $entry + $target + ['written' => 0];
      }

      $entry = $d['entry'];

      if (!is_dir($this->stage())) {
        wp_mkdir_p($this->stage());
      }

      $out = self::open_at($entry['path'], $entry['written']);
      $reader->seek($entry['pos'] + $entry['written']);

      while ($entry['written'] < $entry['size']) {
        $buffer = $reader->read(min(self::CHUNK, $entry['size'] - $entry['written']));

        if ($buffer === '') {
          throw new \RuntimeException(esc_html(__('The archive is truncated.', 'winonweb-migrate')));
        }

        fwrite($out, $buffer);
        $entry['written'] += strlen($buffer);

        if (microtime(true) > $deadline) {
          break;
        }
      }

      fclose($out);

      if ($entry['written'] >= $entry['size']) {
        @touch($entry['path'], $entry['mtime'] ?: time());

        if ($entry['rel'] !== null) {
          fwrite($staged, basename($entry['path']) . "\t" . $entry['rel'] . "\n");
        }

        $d['extract_pos'] = $entry['pos'] + $entry['size'];
        $d['entry'] = null;
      } else {
        $d['entry'] = $entry;
      }
    }

    fclose($staged);

    $unpacked = $d['done_bytes'] + $d['extract_pos'];
    $this->stat('unpacked', size_format($unpacked, 1) . ' / ' . size_format($d['archive_size'], 1));
    $this->progress('extract', $d['archive_size'] ? $unpacked / $d['archive_size'] : 1, $d['entry'] ? sprintf(/* translators: %s: file path */ __('Unpacking %s', 'winonweb-migrate'), $d['entry']['name']) : '');

    return $done;
  }

  private function destination(string $name, bool $last = true): ?array {
    if ($name === Archive::DATABASE) {
      return $this->opt('import_db') && $last ? ['path' => $this->dir() . '/database.jsonl', 'rel' => null] : null;
    }

    if (strpos($name, 'wp-content/') !== 0 || !$this->opt('import_files')) {
      return null;
    }

    $rel = self::safe_relative(substr($name, 11));

    if ($rel === null) {
      $this->log(sprintf(/* translators: %s: file path */ __('Skipped unsafe path %s', 'winonweb-migrate'), $name));

      return null;
    }

    if ($this->is_protected($rel)) {
      return null;
    }

    return ['path' => null, 'rel' => $rel];
  }

  public static function safe_relative(string $rel): ?string {
    $rel = str_replace('\\', '/', $rel);

    if ($rel === '' || strpos($rel, "\0") !== false || $rel[0] === '/' || strpos($rel, ':') !== false) {
      return null;
    }

    foreach (explode('/', $rel) as $part) {
      if ($part === '' || $part === '.' || $part === '..') {
        return null;
      }
    }

    return $rel;
  }

  private function is_protected(string $rel): bool {
    $own = 'plugins/' . dirname(Plugin::basename()) . '/';

    if (strpos($rel, $own) === 0 || strpos($rel, Storage::DIR . '/') === 0) {
      return true;
    }

    return $this->opt('keep_dropins') && in_array($rel, Scanner::DROPINS, true);
  }

  protected function step_database(float $deadline): bool {
    $dump = $this->dir() . '/database.jsonl';

    if (!$this->opt('import_db') || !is_file($dump)) {
      return $this->skip('database');
    }

    $d = &$this->state['data'];
    $replacer = new Replacer($d['pairs']);
    $done = Db_Importer::run($d['db'], $dump, $deadline, $replacer, $d['src_prefix'], $d['dst_prefix']);
    $size = (int) filesize($dump);

    $this->stat('tables', count($d['db']['tables']));
    $this->stat('rows', number_format_i18n($d['db']['rows']));
    $this->progress('database', $size ? $d['db']['pos'] / $size : 1, $d['db']['table'] ? sprintf(/* translators: %s: database table name */ __('Table %s', 'winonweb-migrate'), substr($d['db']['table'], strlen(Db_Importer::TMP))) : '');

    if ($done) {
      $this->log(sprintf(/* translators: 1: number of tables, 2: number of rows */ __('Database staged: %1$d tables, %2$s rows', 'winonweb-migrate'), count($d['db']['tables']), number_format_i18n($d['db']['rows'])));
    }

    return $done;
  }

  protected function step_swap(float $deadline): bool {
    global $wpdb;

    $d = &$this->state['data'];

    if (empty($d['db']['tables'])) {
      return $this->skip('swap');
    }

    $map = [];

    foreach ($d['db']['tables'] as [$tmp, $target]) {
      $map[$target] = $tmp;
    }

    $dst = $d['dst_prefix'];
    $src = $d['src_prefix'];
    $options = $map[$dst . 'options'] ?? null;

    if ($options) {
      // Keep this site's own schedule and keep the plugin active, so the remaining steps can run.
      $settings = Db::value("SELECT option_value FROM `{$wpdb->options}` WHERE option_name = '" . Scheduler::OPTION . "'");
      Db::query("DELETE FROM `$options` WHERE option_name = '" . Scheduler::OPTION . "'");

      if ($settings !== null) {
        Db::query("INSERT INTO `$options` (option_name, option_value, autoload) VALUES ('" . Scheduler::OPTION . "', '" . Db::esc($settings) . "', 'no')");
      }

      $active = maybe_unserialize((string) Db::value("SELECT option_value FROM `$options` WHERE option_name = 'active_plugins'"));
      $active = is_array($active) ? $active : [];

      if (!in_array(Plugin::basename(), $active, true)) {
        $active[] = Plugin::basename();
        Db::query("DELETE FROM `$options` WHERE option_name = 'active_plugins'");
        Db::query("INSERT INTO `$options` (option_name, option_value, autoload) VALUES ('active_plugins', '" . Db::esc(serialize(array_values($active))) . "', 'yes')");
      }

      if ($src !== $dst) {
        Db::query("UPDATE `$options` SET option_name = '" . Db::esc($dst . 'user_roles') . "' WHERE option_name = '" . Db::esc($src . 'user_roles') . "'");
      }
    }

    if ($src !== $dst && isset($map[$dst . 'usermeta'])) {
      Db::query(sprintf("UPDATE `%s` SET meta_key = CONCAT('%s', SUBSTRING(meta_key, %d)) WHERE meta_key LIKE '%s%%'", $map[$dst . 'usermeta'], Db::esc($dst), strlen($src) + 1, Db::esc(Db::like($src))));
    }

    // A pending undo from an earlier import gives way to this one.
    Rollback::clear();

    $renames = [];
    $old = [];
    $pairs = [];
    $new = [];

    foreach ($map as $target => $tmp) {
      if (Db::table_exists($target)) {
        $backup = Db_Importer::old_name($target);
        Db::query("DROP TABLE IF EXISTS `$backup`");
        $renames[] = "`$target` TO `$backup`";
        $old[] = $backup;
        $pairs[] = [$target, $backup];
      } else {
        $new[] = $target;
      }

      $renames[] = "`$tmp` TO `$target`";
    }

    Db::query('RENAME TABLE ' . implode(', ', $renames));

    $d['old_tables'] = $old;
    $d['rollback'] = ['tables' => $pairs, 'new' => $new];
    $d['swapped'] = true;
    $this->save();

    wp_cache_flush();
    $this->log(sprintf(/* translators: %d: number of tables */ __('Database switched (%d tables). The previous database is kept so the import can be undone.', 'winonweb-migrate'), count($map)));

    return true;
  }

  protected function step_files(float $deadline): bool {
    $list = $this->dir() . '/staged.txt';

    if (!$this->opt('import_files') || !is_file($list)) {
      return $this->skip('files');
    }

    $d = &$this->state['data'];
    $fh = fopen($list, 'rb');
    $size = (int) filesize($list);
    fseek($fh, $d['move_pos']);
    $done = false;

    while (microtime(true) < $deadline) {
      $line = fgets($fh);

      if ($line === false) {
        $done = true;
        break;
      }

      $d['move_pos'] = ftell($fh);
      $parts = explode("\t", rtrim($line, "\r\n"), 2);

      if (count($parts) !== 2 || $parts[1] === '' || !ctype_digit($parts[0])) {
        continue;
      }

      [$number, $rel] = $parts;
      $from = $this->stage() . '/' . $number;
      $to = Scanner::abs($rel);

      if (!is_file($from)) {
        continue;
      }

      if (is_dir($to)) {
        $this->log(sprintf(/* translators: %s: file path */ __('Skipped %s: a folder with that name exists', 'winonweb-migrate'), $rel));
        $d['failed']++;
        continue;
      }

      if (!is_dir(dirname($to))) {
        wp_mkdir_p(dirname($to));
      }

      if (file_exists($to)) {
        @unlink($to);
      }

      if (!@rename($from, $to)) {
        if (@copy($from, $to)) {
          @unlink($from);
        } else {
          $this->log(sprintf(/* translators: %s: file path */ __('Could not write %s', 'winonweb-migrate'), $rel));
          $d['failed']++;
          continue;
        }
      }

      $d['moved']++;
    }

    fclose($fh);

    $this->stat('files', number_format_i18n($d['moved']));
    $this->progress('files', $size ? $d['move_pos'] / $size : 1, sprintf(/* translators: %s: number of files */ __('%s files in place', 'winonweb-migrate'), number_format_i18n($d['moved'])));

    return $done;
  }

  protected function step_finalize(float $deadline): bool {
    $d = &$this->state['data'];

    // Keep the previous database until the admin confirms (or undoes) the import.
    if (!empty($d['rollback'])) {
      Rollback::save($d['rollback']['tables'], $d['rollback']['new'], $d['source']['home']);
    }

    // Plugins whose files did not arrive, or that belong to the old host, are the
    // usual reason for a white screen right after an import.
    $repair = Health::repair();

    foreach ($repair['deactivated_missing'] as $plugin) {
      $this->log(sprintf(/* translators: %s: plugin file */ __('Deactivated %s: its files are not on this server', 'winonweb-migrate'), $plugin));
    }

    foreach ($repair['deactivated_host'] as $plugin) {
      $this->log(sprintf(/* translators: %s: plugin file */ __('Deactivated %s: it belongs to the previous host', 'winonweb-migrate'), $plugin));
    }

    // Rebuilt with the imported plugins on the next request.
    delete_option('rewrite_rules');
    $flushed = Cache::flush_all();

    if ($flushed) {
      $this->log(sprintf(/* translators: %s: list of cache plugins */ __('Caches cleared: %s', 'winonweb-migrate'), implode(', ', $flushed)));
    }

    // Does the site actually answer? A fatal here means the import is not "done".
    $health = Health::check();
    $failing = Health::failing($health, $d['baseline'] ?? []);
    $broken = (bool) $failing;
    $auto_undo_at = null;
    $undone = false;

    if (!empty($d['rollback'])) {
      Rollback::update(['health' => $health]);

      if ($broken && $this->opt('auto_undo', true) && in_array('login', $failing, true)) {
        // Nobody can click "Undo" while wp-admin is down, and WP-Cron would not run
        // either, so the previous database goes back right here.
        Rollback::undo();
        $undone = true;
        $this->log(__('The login page shows a critical error after the import, so the previous database was put back automatically.', 'winonweb-migrate'));

        if (in_array('login', Health::failing(Health::check(8), $d['baseline'] ?? []), true)) {
          $this->log(__('The site still shows an error with the previous database, so the cause is in the imported files. Restore the backup made before the import.', 'winonweb-migrate'));
        }
      } elseif ($broken && $this->opt('auto_undo', true)) {
        $auto_undo_at = Rollback::schedule_auto_undo();
        $this->log(__('The site shows an error after the import. The previous database will be put back automatically in 10 minutes unless you choose to keep the import.', 'winonweb-migrate'));
      } elseif ($broken) {
        $this->log(__('The site shows an error after the import. Use "Undo import" to put the previous database back.', 'winonweb-migrate'));
      }
    }

    $this->state['result'] = [
      'home' => (string) get_option('home'),
      'login' => wp_login_url(),
      'db' => !empty($d['swapped']),
      'files' => $d['moved'],
      'failed' => $d['failed'],
      'undo' => !empty($d['rollback']) && !$undone,
      'health' => $health,
      'broken' => $broken,
      'undone' => $undone,
      'auto_undo_at' => $auto_undo_at,
      'deactivated' => array_merge($repair['deactivated_missing'], $repair['deactivated_host']),
    ];

    if ($undone) {
      $this->log(__('Import undone: the site runs on its previous database again.', 'winonweb-migrate'));
    } else {
      $this->log($broken ? __('Import finished, but the site needs attention.', 'winonweb-migrate') : __('Import complete, the site answers normally.', 'winonweb-migrate'));
    }

    return true;
  }

  protected function cleanup(bool $success): void {
    $d = $this->state['data'];

    if (!$success && empty($d['swapped']) && !empty($d['db']['tables'])) {
      Db_Importer::drop($d['db']['tables']);
    }

    if (!$success && !empty($d['swapped'])) {
      // Failed after the switch: offer the undo, the previous database is intact.
      if (!empty($d['rollback'])) {
        Rollback::save($d['rollback']['tables'], $d['rollback']['new'], $d['source']['home']);
      }

      $this->log(__('The database was already switched. Use "Undo import" on the plugin page to bring the previous database back.', 'winonweb-migrate'));
    }

    Storage::rrmdir($this->stage());
    @unlink($this->dir() . '/database.jsonl');
    @unlink($this->dir() . '/staged.txt');
  }
}
