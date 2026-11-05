<?php

namespace WOWM;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- archives are streamed in chunks (seek/append), which WP_Filesystem cannot do.

/**
 * Loads a JSON-lines dump into temporary tables (wowmt_*). The live site keeps
 * running on its own tables until Import_Job swaps them in one RENAME.
 */
final class Db_Importer {
  const TMP = 'wowmt_';
  const OLD = 'wowmo_';
  const MAX_ROWS = 300;
  const MAX_BYTES = 2000000;

  public static function tmp_name(string $table): string {
    return substr(self::TMP . $table, 0, 64);
  }

  public static function old_name(string $table): string {
    return substr(self::OLD . $table, 0, 64);
  }

  /**
   * State: pos (byte offset in dump), tables ([tmp, target] pairs), table (current), rows.
   */
  public static function run(array &$st, string $file, float $deadline, Replacer $replacer, string $src_prefix, string $dst_prefix): bool {
    $st += ['pos' => 0, 'tables' => [], 'table' => null, 'rows' => 0];

    Db::session();

    // Keep each INSERT well under the server's packet limit.
    $max_bytes = (int) min(self::MAX_BYTES, max(65536, (int) Db::value('SELECT @@max_allowed_packet') / 2));

    $fh = fopen($file, 'rb');
    fseek($fh, $st['pos']);

    $buffer = [];
    $bytes = 0;
    $done = false;

    $flush = function () use (&$buffer, &$bytes, &$st, $fh) {
      if ($buffer) {
        Db::query('INSERT IGNORE INTO `' . $st['table'] . '` VALUES ' . implode(',', $buffer));
        $st['rows'] += count($buffer);
      }

      $buffer = [];
      $bytes = 0;
      $st['pos'] = ftell($fh);
    };

    try {
      while (true) {
        if (microtime(true) > $deadline) {
          break;
        }

        $line = fgets($fh);

        if ($line === false) {
          $done = true;
          break;
        }

        if (trim($line) === '') {
          continue;
        }

        $data = json_decode($line, true);

        if (!is_array($data)) {
          throw new \RuntimeException(esc_html(__('The database dump is corrupted.', 'winonweb-migrate')));
        }

        if (isset($data['t'])) {
          $buffer && $flush();
          $st['table'] = self::create($data['t'], $data['create'], $src_prefix, $dst_prefix, $st['tables']);
          $st['pos'] = ftell($fh);
          continue;
        }

        // Rows for a table created earlier in the dump (SQL dumps from other tools).
        if (isset($data['s'])) {
          $buffer && $flush();
          $st['table'] = self::find_tmp($data['s'], $src_prefix, $dst_prefix, $st['tables']);
          $st['pos'] = ftell($fh);
          continue;
        }

        if (!$st['table']) {
          continue;
        }

        $values = [];

        foreach ($data['r'] as $value) {
          if ($value === null) {
            $values[] = 'NULL';
          } elseif (is_array($value)) {
            $values[] = "'" . Db::esc((string) base64_decode($value['b'])) . "'";
          } else {
            $values[] = "'" . Db::esc($replacer->value((string) $value)) . "'";
          }
        }

        $tuple = '(' . implode(',', $values) . ')';
        $buffer[] = $tuple;
        $bytes += strlen($tuple);

        if (count($buffer) >= self::MAX_ROWS || $bytes >= $max_bytes) {
          $flush();
        }
      }

      $flush();
    } finally {
      fclose($fh);
    }

    return $done;
  }

  private static function target(string $source, string $src_prefix, string $dst_prefix): string {
    return $src_prefix !== '' && strpos($source, $src_prefix) === 0 ? $dst_prefix . substr($source, strlen($src_prefix)) : $source;
  }

  private static function find_tmp(string $source, string $src_prefix, string $dst_prefix, array $tables): ?string {
    $target = self::target($source, $src_prefix, $dst_prefix);

    foreach ($tables as [$tmp, $known]) {
      if ($known === $target) {
        return $tmp;
      }
    }

    // Rows for a table whose CREATE is not in the dump: skip them.
    return null;
  }

  private static function create(string $source, string $create, string $src_prefix, string $dst_prefix, array &$tables): string {
    $target = self::target($source, $src_prefix, $dst_prefix);
    $tmp = self::tmp_name($target);

    Db::query("DROP TABLE IF EXISTS `$tmp`");

    $sql = preg_replace('/^CREATE TABLE `[^`]+`/', 'CREATE TABLE `' . $tmp . '`', $create, 1);

    try {
      Db::query($sql);
    } catch (\RuntimeException $e) {
      // MySQL 8 collations are unknown to older MySQL/MariaDB servers.
      $sql = preg_replace('/utf8mb4_0900_\w+/', 'utf8mb4_unicode_520_ci', $sql);
      Db::query($sql);
    }

    $tables[] = [$tmp, $target];

    return $tmp;
  }

  public static function drop(array $tables): void {
    foreach ($tables as $table) {
      Db::query('DROP TABLE IF EXISTS `' . (is_array($table) ? $table[0] : $table) . '`');
    }
  }
}
