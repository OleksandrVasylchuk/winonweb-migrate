<?php

namespace WOWM;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- archives are streamed in chunks (seek/append), which WP_Filesystem cannot do.

final class Export_Job extends Job {
  const DEFAULTS = [
    'components' => ['database' => true, 'themes' => true, 'plugins' => true, 'muplugins' => true, 'uploads' => true, 'other' => true],
    'exclude_transients' => true,
    'exclude_spam' => true,
    'exclude_revisions' => false,
    'exclude_dropins' => true,
    'excludes' => [],
    'replace' => [],
    'origin' => 'manual',
    'label' => '',
    'base' => '',
  ];

  public static function sanitize(array $in): array {
    $out = self::DEFAULTS;

    foreach (array_keys(self::DEFAULTS['components']) as $component) {
      $out['components'][$component] = !empty($in['components'][$component]);
    }

    foreach (['exclude_transients', 'exclude_spam', 'exclude_revisions', 'exclude_dropins'] as $key) {
      $out[$key] = !empty($in[$key]);
    }

    $excludes = is_array($in['excludes'] ?? null) ? $in['excludes'] : preg_split('/\R/', (string) ($in['excludes'] ?? ''));
    $out['excludes'] = array_slice(array_values(array_filter(array_map('trim', $excludes))), 0, 200);
    $out['replace'] = self::sanitize_pairs($in['replace'] ?? []);
    $out['origin'] = in_array($in['origin'] ?? '', ['manual', 'scheduled', 'pre-import', 'cli'], true) ? $in['origin'] : 'manual';
    $out['label'] = sanitize_text_field($in['label'] ?? '');
    $out['base'] = basename((string) ($in['base'] ?? ''));

    return $out;
  }

  public static function sanitize_pairs($pairs): array {
    $out = [];

    foreach (is_array($pairs) ? $pairs : [] as $pair) {
      $from = trim((string) ($pair['from'] ?? ''));

      if ($from !== '') {
        $out[] = ['from' => $from, 'to' => trim((string) ($pair['to'] ?? ''))];
      }
    }

    return array_slice($out, 0, 50);
  }

  protected function steps(): array {
    return [
      'prepare' => [__('Preparing', 'winonweb-migrate'), 1],
      'database' => [__('Exporting database', 'winonweb-migrate'), 25],
      'scan' => [/* translators: %s: progress in percent */ __('Scanning files', 'winonweb-migrate'), 6],
      'files' => [__('Packing files', 'winonweb-migrate'), 64],
      'finalize' => [__('Finalizing archive', 'winonweb-migrate'), 4],
    ];
  }

  private function component(string $key): bool {
    return !empty($this->state['options']['components'][$key]);
  }

  private function part_path(): string {
    return Storage::dir() . '/' . $this->state['data']['archive'] . '.part';
  }

  protected function step_prepare(float $deadline): bool {
    $this->state['data'] = [
      'archive' => Storage::new_archive_name($this->opt('origin') === 'pre-import' ? 'pre-import-' : ''),
      'archive_pos' => 0,
      'db_pos' => 0,
      'db' => [],
      'list_pos' => 0,
      'scan' => [],
      'read_pos' => 0,
      'entry' => null,
      'db_queued' => false,
      'files_done' => 0,
      'bytes_done' => 0,
      'entries' => 0,
      'idx_pos' => 0,
      'unchanged' => 0,
      'base' => '',
    ];

    // Differential: only files that changed since a full backup that has a file index.
    $base = $this->opt('base', '');

    if ($base !== '') {
      $base_path = Storage::path($base);
      $base_meta = $base_path ? Storage::read_meta($base_path) : [];

      if ($base_path && ($base_meta['kind'] ?? 'full') === 'full' && is_file(Storage::index_path($base_path))) {
        $this->state['data']['base'] = $base;
        $this->log(sprintf(/* translators: %s: file name of the full backup */ __('Differential backup: only files changed since %s', 'winonweb-migrate'), $base));
      } else {
        $this->log(__('The base backup is missing or has no file index: making a full backup instead.', 'winonweb-migrate'));
      }
    }

    file_put_contents($this->part_path(), '');
    $this->log(sprintf(/* translators: %s: archive file name */ __('Creating %s', 'winonweb-migrate'), $this->state['data']['archive']));

    return true;
  }

  protected function step_database(float $deadline): bool {
    if (!$this->component('database')) {
      return $this->skip('database');
    }

    $d = &$this->state['data'];
    $fh = self::open_at($this->dir() . '/database.jsonl', $d['db_pos']);
    $done = Db_Exporter::run($d['db'], $fh, $deadline, $this->state['options']);
    $d['db_pos'] = ftell($fh);
    fclose($fh);

    $total = count($d['db']['tables']);
    $current = $d['db']['tables'][$d['db']['ti']] ?? '';
    $this->stat('tables', min($d['db']['ti'], $total) . ' / ' . $total);
    $this->stat('rows', $d['db']['rows']);
    $this->progress('database', $total ? $d['db']['ti'] / $total : 1, $current ? sprintf(/* translators: %s: database table name */ __('Table %s', 'winonweb-migrate'), $current) : '');

    if ($done) {
      $this->log(sprintf(/* translators: 1: number of tables, 2: number of rows */ __('Database: %1$d tables, %2$s rows', 'winonweb-migrate'), $total, number_format_i18n($d['db']['rows'])));
    }

    return $done;
  }

  protected function step_scan(float $deadline): bool {
    $components = array_diff_key($this->state['options']['components'], ['database' => 1]);

    if (!array_filter($components)) {
      return $this->skip('scan');
    }

    $d = &$this->state['data'];
    $patterns = Scanner::patterns($this->opt('exclude_dropins', true), $this->opt('excludes', []));
    $fh = self::open_at($this->dir() . '/files.txt', $d['list_pos']);
    $done = Scanner::run($d['scan'], $fh, $deadline, $components, $patterns);
    $d['list_pos'] = ftell($fh);
    fclose($fh);

    $this->stat('files', number_format_i18n($d['scan']['files']));
    $this->progress('scan', $done ? 1 : 0.5, sprintf(/* translators: 1: number of files, 2: total size */ __('Found %1$s files (%2$s)', 'winonweb-migrate'), number_format_i18n($d['scan']['files']), size_format($d['scan']['bytes'], 1)));

    if ($done) {
      $this->log(sprintf(/* translators: 1: number of files, 2: total size */ __('Files: %1$s files, %2$s', 'winonweb-migrate'), number_format_i18n($d['scan']['files']), size_format($d['scan']['bytes'], 1)));
    }

    return $done;
  }

  protected function step_files(float $deadline): bool {
    $d = &$this->state['data'];
    $out = self::open_at($this->part_path(), $d['archive_pos']);
    $list_file = $this->dir() . '/files.txt';
    $list = is_file($list_file) ? fopen($list_file, 'rb') : null;
    $total = ($d['scan']['bytes'] ?? 0) + ($this->component('database') ? $d['db_pos'] : 0);
    $checkpoint = microtime(true) + 3;
    $done = false;

    while (true) {
      if (!$d['entry']) {
        $next = $this->next_entry($list);

        if (!$next) {
          $done = true;
          break;
        }

        fwrite($out, Archive::header($next['name'], $next['size'], $next['mtime']));
        $d['entries']++;

        if ($next['name'] !== Archive::DATABASE && $d['base'] === '') {
          $this->index_line(substr($next['name'], 11) . "\t" . $next['size'] . "\t" . $next['mtime']);
        }
        $d['entry'] = $next + ['offset' => 0];
        $d['archive_pos'] = ftell($out);
      }

      $entry = $d['entry'];
      $in = @fopen($entry['abs'], 'rb');

      if ($in) {
        fseek($in, $entry['offset']);
      }

      while ($entry['offset'] < $entry['size']) {
        $length = min(self::CHUNK, $entry['size'] - $entry['offset']);
        $buffer = $in ? fread($in, $length) : '';

        // The file shrank or vanished since the header was written: pad to the declared size.
        if ($buffer === false || $buffer === '') {
          $buffer = str_repeat("\0", $length);
        }

        fwrite($out, $buffer);
        $entry['offset'] += strlen($buffer);
        $d['archive_pos'] = ftell($out);

        if (microtime(true) > $deadline) {
          break;
        }
      }

      $in && fclose($in);

      if ($entry['offset'] >= $entry['size']) {
        $d['entry'] = null;
        $d['bytes_done'] += $entry['size'];

        if ($entry['name'] !== Archive::DATABASE) {
          $d['files_done']++;
        }
      } else {
        $d['entry'] = $entry;
      }

      if (microtime(true) > $checkpoint) {
        $this->save();
        $checkpoint = microtime(true) + 3;
      }

      if (microtime(true) > $deadline) {
        break;
      }
    }

    fclose($out);
    $list && fclose($list);

    $this->stat('packed', size_format($d['bytes_done'], 1));
    $this->progress('files', $total ? $d['bytes_done'] / $total : 1, $d['entry'] ? sprintf(/* translators: %s: file path */ __('Packing %s', 'winonweb-migrate'), $d['entry']['name']) : sprintf(__('%s files packed', 'winonweb-migrate'), number_format_i18n($d['files_done'])));

    return $done;
  }

  private function next_entry($list): ?array {
    $d = &$this->state['data'];

    if (!$d['db_queued']) {
      $d['db_queued'] = true;
      $dump = $this->dir() . '/database.jsonl';

      if ($this->component('database') && is_file($dump)) {
        return ['abs' => $dump, 'name' => Archive::DATABASE, 'size' => (int) filesize($dump), 'mtime' => time()];
      }
    }

    while ($list) {
      fseek($list, $d['read_pos']);
      $line = fgets($list);

      if ($line === false) {
        return null;
      }

      $d['read_pos'] = ftell($list);
      $rel = rtrim($line, "\r\n");
      $abs = Scanner::abs($rel);
      $size = $rel !== '' ? @filesize($abs) : false;

      if ($size === false || !is_readable($abs)) {
        $this->log(sprintf(/* translators: %s: file path */ __('Skipped unreadable file %s', 'winonweb-migrate'), $rel));
        continue;
      }

      $mtime = (int) @filemtime($abs);

      if ($d['base'] !== '' && ($this->base_index()[$rel] ?? null) === $size . "\t" . $mtime) {
        $d['unchanged']++;
        $d['bytes_done'] += (int) $size;
        continue;
      }

      return ['abs' => $abs, 'name' => 'wp-content/' . $rel, 'size' => (int) $size, 'mtime' => $mtime];
    }

    return null;
  }

  protected function step_finalize(float $deadline): bool {
    $d = &$this->state['data'];
    $site = Site::info();
    $components = $this->state['options']['components'];

    $manifest = [
      'format' => Archive::FORMAT,
      'generator' => 'WinOnWeb Migrate ' . WOWM_VERSION,
      'created' => time(),
      'kind' => $d['base'] === '' ? 'full' : 'differential',
      'base' => $d['base'],
      'base_created' => $d['base'] !== '' ? (int) (Storage::read_meta(Storage::dir() . '/' . $d['base'])['created'] ?? 0) : null,
      'entries' => $d['entries'],
      'site' => $site,
      'components' => $components,
      'options' => array_intersect_key($this->state['options'], array_flip(['exclude_transients', 'exclude_spam', 'exclude_revisions', 'exclude_dropins', 'excludes'])),
      'replace' => $this->opt('replace', []),
      'stats' => [
        'files' => $d['files_done'],
        'bytes' => $d['scan']['bytes'] ?? 0,
        'tables' => count($d['db']['tables'] ?? []),
        'rows' => $d['db']['rows'] ?? 0,
        'db_bytes' => $d['db_pos'],
        'unchanged' => $d['unchanged'],
      ],
    ];

    $json = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $out = self::open_at($this->part_path(), $d['archive_pos']);
    fwrite($out, Archive::header(Archive::MANIFEST, strlen($json), time()) . $json);
    fclose($out);

    $final = Storage::dir() . '/' . $d['archive'];

    if (!@rename($this->part_path(), $final)) {
      throw new \RuntimeException(esc_html(__('Could not finalize the archive.', 'winonweb-migrate')));
    }

    $size = (int) filesize($final);
    $index = $this->dir() . '/index.txt';

    if ($manifest['kind'] === 'full' && is_file($index)) {
      @rename($index, Storage::index_path($final));
    }

    Storage::write_meta($final, [
      'created' => $manifest['created'],
      'origin' => $this->opt('origin', 'manual'),
      'label' => $this->opt('label', ''),
      'source' => $site['home'],
      'components' => array_keys(array_filter($components)),
      'stats' => $manifest['stats'],
      'kind' => $manifest['kind'],
      'base' => $d['base'],
    ]);

    $this->state['result'] = ['file' => $d['archive'], 'size' => $size];
    $this->stat('archive', size_format($size, 1));
    $this->log(sprintf(/* translators: 1: archive file name, 2: size */ __('Archive ready: %1$s (%2$s)', 'winonweb-migrate'), $d['archive'], size_format($size, 1)));

    if ($this->opt('origin') === 'scheduled') {
      Scheduler::enforce_retention();
    }

    return true;
  }

  /**
   * One line of the file index ("path TAB size TAB mtime"), kept in step with the archive position.
   */
  private function index_line(string $line): void {
    $d = &$this->state['data'];
    $fh = self::open_at($this->dir() . '/index.txt', $d['idx_pos']);
    fwrite($fh, $line . "\n");
    $d['idx_pos'] = ftell($fh);
    fclose($fh);
  }

  private $base_index = null;

  private function base_index(): array {
    if ($this->base_index === null) {
      $this->base_index = Storage::read_index(Storage::dir() . '/' . $this->state['data']['base']);
    }

    return $this->base_index;
  }

  protected function cleanup(bool $success): void {
    @unlink($this->dir() . '/database.jsonl');
    @unlink($this->dir() . '/files.txt');
    @unlink($this->dir() . '/index.txt');

    if (!$success && !empty($this->state['data']['archive'])) {
      @unlink($this->part_path());
    }
  }
}
