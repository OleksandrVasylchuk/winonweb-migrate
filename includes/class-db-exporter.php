<?php

namespace WOWM;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- archives are streamed in chunks (seek/append), which WP_Filesystem cannot do.

/**
 * Dumps tables as JSON lines:
 *   {"t":"wp_posts","create":"CREATE TABLE ..."}
 *   {"r":["1","admin",null,{"b":"<base64 for non-UTF-8 values>"}]}
 * Rows stay raw; URL/path replacement happens on import, where the target is known.
 */
final class Db_Exporter {
  // Rows per query follow the table's average row size: ~4 MB per batch keeps
  // memory flat on tables with big rows (mail logs) and cuts round trips on
  // narrow ones (postmeta).
  const BATCH_MIN = 500;
  const BATCH_MAX = 5000;
  const BATCH_BYTES = 4194304;

  // Logs and queues a copy of the site never needs; offered as a one-click preset.
  const LOG_TABLES = ['*_mails', '*wsal_*', '*actionscheduler_logs', '*taxjar_record_queue', '*wpmailsmtp_*', '*_email_log', '*redirection_logs', '*redirection_404'];

  public static function tables(): array {
    global $wpdb;

    $tables = [];

    foreach (Db::rows('SHOW FULL TABLES', false) as $row) {
      if (($row[1] ?? 'BASE TABLE') !== 'BASE TABLE' || strpos($row[0], $wpdb->base_prefix) !== 0) {
        continue;
      }

      if (strpos($row[0], Db_Importer::TMP) === 0 || strpos($row[0], Db_Importer::OLD) === 0) {
        continue;
      }

      $tables[] = $row[0];
    }

    return $tables;
  }

  public static function size(): int {
    global $wpdb;

    return (int) Db::value("SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = '" . Db::esc(DB_NAME) . "' AND table_name LIKE '" . Db::esc(Db::like($wpdb->base_prefix)) . "%'");
  }

  /**
   * @param resource $out Dump file, positioned at its end.
   */
  public static function run(array &$st, $out, float $deadline, array $opts): bool {
    if (!isset($st['tables'])) {
      $st = ['tables' => self::tables(), 'ti' => 0, 'cursor' => null, 'rows' => 0];
    }

    while ($st['ti'] < count($st['tables'])) {
      if (microtime(true) > $deadline) {
        return false;
      }

      $table = $st['tables'][$st['ti']];
      $cursor = $st['cursor'];

      if ($cursor === null) {
        $create = Db::row("SHOW CREATE TABLE `$table`", false)[1];
        fwrite($out, self::json(['t' => $table, 'create' => $create]) . "\n");

        // Excluded tables keep their structure so plugins on the copy still find them.
        if (self::excluded($table, $opts['exclude_tables'] ?? [])) {
          $st['ti']++;
          continue;
        }

        $pk = self::int_primary_key($table);
        $cursor = ['pk' => $pk, 'pk_index' => $pk ? self::column_index($table, $pk) : null, 'last' => null, 'offset' => 0, 'batch' => self::batch($table)];
      }

      $batch = $cursor['batch'] ?? self::BATCH_MIN;
      $where = array_filter([self::filter($table, $opts), $cursor['pk'] && $cursor['last'] !== null ? sprintf('`%s` > %d', $cursor['pk'], $cursor['last']) : '']);
      $sql = "SELECT * FROM `$table`" . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
      $sql .= $cursor['pk'] ? sprintf(' ORDER BY `%s` ASC LIMIT %d', $cursor['pk'], $batch) : sprintf(' LIMIT %d, %d', $cursor['offset'], $batch);

      $rows = Db::rows($sql, false);
      $count = count($rows);
      $chunk = '';

      foreach ($rows as $row) {
        $chunk .= self::encode_row($row) . "\n";
      }

      if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) {
        throw new \RuntimeException(esc_html(__('Could not write the database dump. Is the disk full?', 'winonweb-migrate') . Job::reason()));
      }

      if ($cursor['pk'] && $count) {
        // States saved by 1.0.0 have no pk_index: look it up once.
        $cursor['pk_index'] = $cursor['pk_index'] ?? self::column_index($table, $cursor['pk']);
        $cursor['last'] = (int) $rows[$count - 1][$cursor['pk_index']];
      }

      unset($rows, $chunk);
      $cursor['offset'] += $count;
      $st['rows'] += $count;

      if ($count < $batch) {
        $st['ti']++;
        $st['cursor'] = null;
      } else {
        $st['cursor'] = $cursor;
      }
    }

    return true;
  }

  /**
   * @param string[] $patterns Shell patterns, matched against the name with and without the table prefix.
   */
  public static function excluded(string $table, array $patterns): bool {
    global $wpdb;

    // FNM_CASEFOLD is missing on some platforms (Windows): compare lowercased instead.
    $table = strtolower($table);
    $bare = strpos($table, strtolower($wpdb->base_prefix)) === 0 ? substr($table, strlen($wpdb->base_prefix)) : $table;

    foreach ($patterns as $pattern) {
      $pattern = strtolower($pattern);

      if ($pattern !== '' && (fnmatch($pattern, $table) || fnmatch($pattern, $bare))) {
        return true;
      }
    }

    return false;
  }

  private static function batch(string $table): int {
    $avg = (int) Db::value("SELECT AVG_ROW_LENGTH FROM information_schema.TABLES WHERE table_schema = '" . Db::esc(DB_NAME) . "' AND table_name = '" . Db::esc($table) . "'");
    $batch = $avg > 0 ? intdiv(self::BATCH_BYTES, $avg) : self::BATCH_MIN;

    return (int) apply_filters('wowm_export_batch', max(self::BATCH_MIN, min(self::BATCH_MAX, $batch)), $table, $avg);
  }

  private static function column_index(string $table, string $column): ?int {
    $index = array_search($column, array_column(Db::rows("SHOW COLUMNS FROM `$table`"), 'Field'), true);

    return $index === false ? null : (int) $index;
  }

  private static function filter(string $table, array $opts): string {
    global $wpdb;

    if ($table === $wpdb->options && !empty($opts['exclude_transients'])) {
      return "`option_name` NOT LIKE '\\_transient\\_%' AND `option_name` NOT LIKE '\\_site\\_transient\\_%'";
    }

    if ($table === $wpdb->posts && !empty($opts['exclude_revisions'])) {
      return "`post_type` <> 'revision'";
    }

    if ($table === $wpdb->comments && !empty($opts['exclude_spam'])) {
      return "`comment_approved` <> 'spam'";
    }

    return '';
  }

  private static function int_primary_key(string $table): ?string {
    $keys = Db::rows("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");

    if (count($keys) !== 1) {
      return null;
    }

    $column = $keys[0]['Column_name'];
    $type = Db::row("SHOW COLUMNS FROM `$table` LIKE '" . Db::esc(Db::like($column)) . "'")['Type'] ?? '';

    return preg_match('/int/i', $type) ? $column : null;
  }

  public static function encode_row(array $row): string {
    $row = array_values($row);

    foreach ($row as $i => $value) {
      if ($value !== null && !preg_match('//u', $value)) {
        $row[$i] = ['b' => base64_encode($value)];
      }
    }

    return self::json(['r' => $row]);
  }

  public static function json(array $data): string {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($json === false) {
      throw new \RuntimeException(esc_html(json_last_error_msg()));
    }

    return $json;
  }
}
