<?php

namespace WOWM;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- archives are streamed in chunks (seek/append), which WP_Filesystem cannot do.

/**
 * Turns an All-in-One (.wpress) or Duplicator (.zip) archive into a .wowm archive,
 * so it goes through the same preflight and safe import as our own backups.
 * The source file is removed once the conversion succeeds.
 */
final class Convert_Job extends Job {
  const FORMATS = [
    'wpress' => 'All-in-One WP Migration',
    'duplicator' => 'Duplicator',
  ];

  public static function sanitize(array $in): array {
    return ['file' => basename((string) ($in['file'] ?? ''))];
  }

  public static function format_of(string $path): ?string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if ($ext === 'wpress') {
      return Wpress_Reader::looks_valid($path) ? 'wpress' : null;
    }

    if ($ext === 'zip' && class_exists('\ZipArchive')) {
      $zip = new \ZipArchive();

      if ($zip->open($path) !== true) {
        return null;
      }

      $found = false;

      for ($i = 0; $i < $zip->numFiles && !$found; $i++) {
        $found = (bool) preg_match('#(^|/)dup-installer/dup-database__[^/]+\.sql$#', (string) $zip->getNameIndex($i));
      }

      $zip->close();

      return $found ? 'duplicator' : null;
    }

    return null;
  }

  protected function steps(): array {
    return [
      'prepare' => [__('Reading archive', 'winonweb-migrate'), 2],
      'files' => [__('Converting files', 'winonweb-migrate'), 60],
      'database' => [__('Converting database', 'winonweb-migrate'), 28],
      'finalize' => [__('Finalizing archive', 'winonweb-migrate'), 10],
    ];
  }

  private function source(): string {
    $path = Storage::foreign_path($this->opt('file', ''));

    if (!$path) {
      throw new \RuntimeException(esc_html(__('The archive was not found.', 'winonweb-migrate')));
    }

    return $path;
  }

  private function part_path(): string {
    return Storage::dir() . '/' . $this->state['data']['archive'] . '.part';
  }

  private function sql_path(): string {
    return $this->dir() . '/source.sql';
  }

  private function dump_path(): string {
    return $this->dir() . '/database.jsonl';
  }

  protected function step_prepare(float $deadline): bool {
    $path = $this->source();
    $format = self::format_of($path);

    if (!$format) {
      throw new \RuntimeException(esc_html(__('Unsupported archive. Use a .wowm file, an All-in-One .wpress file or a Duplicator .zip archive.', 'winonweb-migrate')));
    }

    $meta = [];
    $sql_name = 'database.sql';
    $content = '';

    if ($format === 'wpress') {
      $reader = new Wpress_Reader($path);
      $entry = $reader->find('package.json');
      $meta = $entry ? (array) json_decode($reader->contents($entry), true) : [];

      if (!empty($meta['Encrypted'])) {
        throw new \RuntimeException(esc_html(__('This .wpress archive is password protected. Export it again without a password.', 'winonweb-migrate')));
      }
    } else {
      $zip = new \ZipArchive();
      $zip->open($path);
      $content = 'wp-content';

      for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);

        if (preg_match('#(^|/)dup-installer/dup-database__[^/]+\.sql$#', $name)) {
          $sql_name = $name;
        } elseif (preg_match('#(^|/)dup-installer/dup-archive__[^/]+\.txt$#', $name)) {
          $meta = (array) json_decode((string) $zip->getFromIndex($i), true);
        }
      }

      $zip->close();
      $content = trim((string) (self::find_key($meta, ['relative_content_dir', 'wpContentDir']) ?: 'wp-content'), '/');
    }

    $this->state['data'] = [
      'format' => $format,
      'meta' => self::slim_meta($meta),
      'sql_name' => $sql_name,
      'content' => $content,
      'archive' => Storage::new_archive_name('converted-'),
      'archive_pos' => 0,
      'src_pos' => 0,
      'index' => 0,
      'entry' => null,
      'sql_pos' => 0,
      'sql' => [],
      'components' => [],
      'files' => 0,
      'bytes' => 0,
      'entries' => 0,
      'db_entry' => null,
    ];

    file_put_contents($this->part_path(), '');
    $this->log(sprintf(/* translators: %s: plugin the archive was made with */ __('Converting a %s archive', 'winonweb-migrate'), self::FORMATS[$format]));

    return true;
  }

  /**
   * Where a source entry goes: ['kind' => 'sql'] / ['kind' => 'file', 'rel' => ...] / null to skip.
   */
  private function route(string $name): ?array {
    $d = $this->state['data'];

    if ($name === $d['sql_name']) {
      return ['kind' => 'sql'];
    }

    if ($d['format'] === 'wpress') {
      if (strpos($name, '/') === false && preg_match('/\.json$/', $name)) {
        return null;
      }

      $rel = $name;
    } else {
      if (strpos($name, $d['content'] . '/') !== 0) {
        return null;
      }

      $rel = substr($name, strlen($d['content']) + 1);
    }

    $rel = Import_Job::safe_relative($rel);

    if ($rel === null || substr($name, -1) === '/' || Scanner::excluded_path($rel, Scanner::patterns(true, []))) {
      return null;
    }

    return ['kind' => 'file', 'rel' => $rel];
  }

  protected function step_files(float $deadline): bool {
    $d = &$this->state['data'];
    $path = $this->source();
    $out = self::open_at($this->part_path(), $d['archive_pos']);
    $wpress = $d['format'] === 'wpress' ? new Wpress_Reader($path) : null;
    $zip = null;

    if (!$wpress) {
      $zip = new \ZipArchive();
      $zip->open($path);
    }

    $done = false;

    while (microtime(true) < $deadline) {
      if (!$d['entry']) {
        if ($wpress) {
          $wpress->seek($d['src_pos']);
          $source = $wpress->next();
        } else {
          $stat = $d['index'] < $zip->numFiles ? $zip->statIndex($d['index']) : null;
          $source = $stat ? ['name' => $stat['name'], 'size' => (int) $stat['size'], 'mtime' => (int) $stat['mtime'], 'pos' => $d['index']] : null;
        }

        if (!$source) {
          $done = true;
          break;
        }

        $route = $this->route($source['name']);

        if (!$route) {
          $this->advance($source);
          continue;
        }

        if ($route['kind'] === 'file') {
          fwrite($out, Archive::header('wp-content/' . $route['rel'], $source['size'], $source['mtime']));
          $d['archive_pos'] = ftell($out);
          $d['entries']++;
          $d['components'][Scanner::component_of(explode('/', $route['rel'])[0])] = true;
        }

        $d['entry'] = $source + $route + ['offset' => 0];
      }

      $entry = $d['entry'];
      $target = $entry['kind'] === 'sql' ? self::open_at($this->sql_path(), $d['sql_pos']) : $out;

      if ($wpress) {
        $wpress->seek($entry['pos'] + $entry['offset']);
        $stream = null;
      } else {
        $stream = $zip->getStream($entry['name']);

        // Zip streams cannot seek: skip what an earlier tick already copied.
        for ($skip = $entry['offset']; $skip > 0; $skip -= self::CHUNK) {
          fread($stream, min(self::CHUNK, $skip));
        }
      }

      while ($entry['offset'] < $entry['size']) {
        $length = min(self::CHUNK, $entry['size'] - $entry['offset']);
        $buffer = $wpress ? $wpress->read($length) : (string) fread($stream, $length);

        if ($buffer === '') {
          throw new \RuntimeException(esc_html(__('The archive is truncated.', 'winonweb-migrate')));
        }

        fwrite($target, $buffer);
        $entry['offset'] += strlen($buffer);

        if (microtime(true) > $deadline) {
          break;
        }
      }

      $stream && fclose($stream);

      if ($entry['kind'] === 'sql') {
        $d['sql_pos'] = ftell($target);
        fclose($target);
      } else {
        $d['archive_pos'] = ftell($out);
      }

      if ($entry['offset'] >= $entry['size']) {
        if ($entry['kind'] === 'file') {
          $d['files']++;
          $d['bytes'] += $entry['size'];
        }

        $this->advance($entry);
        $d['entry'] = null;
      } else {
        $d['entry'] = $entry;
      }
    }

    fclose($out);
    $zip && $zip->close();

    $progress = $wpress ? $d['src_pos'] / max(1, $wpress->size()) : $d['index'] / max(1, $this->zip_count($path));

    $this->stat('files', number_format_i18n($d['files']));
    $this->stat('packed', size_format($d['bytes'], 1));
    $this->progress('files', $progress, $d['entry'] ? sprintf(/* translators: %s: file path */ __('Converting %s', 'winonweb-migrate'), $d['entry']['name']) : '');

    return $done;
  }

  private function advance(array $source): void {
    if ($this->state['data']['format'] === 'wpress') {
      $this->state['data']['src_pos'] = $source['pos'] + $source['size'];
    } else {
      $this->state['data']['index'] = $source['pos'] + 1;
    }
  }

  private function zip_count(string $path): int {
    if (!isset($this->state['data']['zip_count'])) {
      $zip = new \ZipArchive();
      $zip->open($path);
      $this->state['data']['zip_count'] = $zip->numFiles;
      $zip->close();
    }

    return $this->state['data']['zip_count'];
  }

  protected function step_database(float $deadline): bool {
    if (!is_file($this->sql_path()) || !filesize($this->sql_path())) {
      return $this->skip('database');
    }

    $d = &$this->state['data'];
    $done = Sql_Converter::run($d['sql'], $this->sql_path(), $this->dump_path(), $deadline);
    $size = (int) filesize($this->sql_path());

    $this->stat('tables', $d['sql']['tables']);
    $this->stat('rows', number_format_i18n($d['sql']['rows']));
    $this->progress('database', $size ? $d['sql']['pos'] / $size : 1);

    return $done;
  }

  protected function step_finalize(float $deadline): bool {
    $d = &$this->state['data'];
    $out = self::open_at($this->part_path(), $d['archive_pos']);
    $dump = $this->dump_path();

    // The database dump can be large: pack it across ticks like any other file.
    if (is_file($dump) && empty($d['db_packed'])) {
      if (!$d['db_entry']) {
        $d['db_entry'] = ['size' => (int) filesize($dump), 'offset' => 0];
        fwrite($out, Archive::header(Archive::DATABASE, $d['db_entry']['size'], time()));
        $d['entries']++;
      }

      $in = fopen($dump, 'rb');
      fseek($in, $d['db_entry']['offset']);

      while ($d['db_entry']['offset'] < $d['db_entry']['size']) {
        $buffer = (string) fread($in, min(self::CHUNK, $d['db_entry']['size'] - $d['db_entry']['offset']));
        fwrite($out, $buffer);
        $d['db_entry']['offset'] += strlen($buffer);

        if (microtime(true) > $deadline) {
          break;
        }
      }

      fclose($in);
      $d['archive_pos'] = ftell($out);

      if ($d['db_entry']['offset'] < $d['db_entry']['size']) {
        fclose($out);

        return false;
      }

      $d['db_packed'] = true;
    }

    $manifest = $this->manifest();
    $json = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    fwrite($out, Archive::header(Archive::MANIFEST, strlen($json), time()) . $json);
    fclose($out);

    $final = Storage::dir() . '/' . $d['archive'];

    if (!@rename($this->part_path(), $final)) {
      throw new \RuntimeException(esc_html(__('Could not finalize the archive.', 'winonweb-migrate')));
    }

    Storage::write_meta($final, [
      'created' => time(),
      'origin' => 'uploaded',
      'label' => sprintf(/* translators: %s: plugin the archive was made with */ __('Converted from %s', 'winonweb-migrate'), self::FORMATS[$d['format']]),
      'source' => $manifest['site']['home'],
      'components' => array_keys(array_filter($manifest['components'])),
      'kind' => 'full',
    ]);

    @unlink($this->source());

    $this->state['result'] = ['file' => $d['archive'], 'size' => (int) filesize($final)];
    $this->log(sprintf(/* translators: %s: archive file name */ __('Converted into %s', 'winonweb-migrate'), $d['archive']));

    return true;
  }

  private function manifest(): array {
    $d = $this->state['data'];
    $meta = $d['meta'];
    $info = $d['sql']['info'] ?? [];
    $home = untrailingslashit((string) ($info['home'] ?? ($meta['home'] ?? '')));
    $plugins = isset($info['active_plugins']) ? maybe_unserialize($info['active_plugins']) : [];

    $components = array_fill_keys(['database', 'themes', 'plugins', 'muplugins', 'uploads', 'other'], false);
    $components['database'] = is_file($this->dump_path());

    foreach ($d['components'] as $component => $on) {
      $components[$component] = true;
    }

    return [
      'format' => Archive::FORMAT,
      'generator' => 'WinOnWeb Migrate ' . WOWM_VERSION . ' (' . self::FORMATS[$d['format']] . ')',
      'created' => time(),
      'kind' => 'full',
      'site' => [
        'name' => (string) ($info['blogname'] ?? ''),
        'home' => $home,
        'siteurl' => untrailingslashit((string) ($info['siteurl'] ?? $home)),
        'abspath' => untrailingslashit(wp_normalize_path((string) ($meta['abspath'] ?? ''))),
        'content_dir' => untrailingslashit(wp_normalize_path((string) ($meta['content_dir'] ?? ''))),
        'content_url' => $home . '/wp-content',
        'uploads_dir' => '',
        'uploads_url' => untrailingslashit((string) ($info['upload_url_path'] ?? '')) ?: $home . '/wp-content/uploads',
        'wp' => (string) ($meta['wp'] ?? '0'),
        'php' => (string) ($meta['php'] ?? '0'),
        'mysql' => '',
        'prefix' => (string) ($d['sql']['prefix'] ?? ($meta['prefix'] ?? 'wp_')),
        'charset' => 'utf8mb4',
        'multisite' => false,
        'theme' => (string) ($info['stylesheet'] ?? ''),
        'plugins' => is_array($plugins) ? array_values($plugins) : [],
      ],
      'components' => $components,
      'replace' => [],
      'stats' => [
        'files' => $d['files'],
        'bytes' => $d['bytes'],
        'tables' => $d['sql']['tables'] ?? 0,
        'rows' => $d['sql']['rows'] ?? 0,
        'db_bytes' => is_file($this->dump_path()) ? (int) filesize($this->dump_path()) : 0,
      ],
      'entries' => $d['entries'],
    ];
  }

  /**
   * Pull the few facts we need out of All-in-One's package.json or Duplicator's archive info,
   * whose layouts differ between plugin versions.
   */
  private static function slim_meta(array $meta): array {
    return array_filter([
      'home' => self::find_key($meta, ['HomeURL', 'url_old', 'home_url', 'homeUrl']),
      'abspath' => self::find_key($meta, ['wproot', 'abspath', 'ABSPATH', 'path_old']),
      'content_dir' => self::find_key($meta, ['Content', 'wpContentDir', 'content_dir']),
      'wp' => self::find_key($meta, ['version_wp', 'wp_version', 'wpVersion']) ?: ($meta['WordPress']['Version'] ?? null),
      'php' => self::find_key($meta, ['version_php', 'php_version', 'phpVersion']) ?: ($meta['PHP']['Version'] ?? null),
      'prefix' => self::find_key($meta, ['wp_tableprefix', 'tablePrefix', 'Prefix']),
    ]);
  }

  private static function find_key(array $data, array $keys) {
    foreach ($data as $key => $value) {
      if (in_array($key, $keys, true) && is_scalar($value) && $value !== '') {
        return $value;
      }
    }

    foreach ($data as $value) {
      if (is_array($value)) {
        $found = self::find_key($value, $keys);

        if ($found !== null) {
          return $found;
        }
      }
    }

    return null;
  }

  protected function cleanup(bool $success): void {
    @unlink($this->sql_path());
    @unlink($this->dump_path());

    if (!$success && !empty($this->state['data']['archive'])) {
      @unlink($this->part_path());
    }
  }
}
