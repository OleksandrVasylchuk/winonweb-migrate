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
  const BATCH = 500;

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
        $cursor = ['pk' => self::int_primary_key($table), 'last' => null, 'offset' => 0];
      }

      $where = array_filter([self::filter($table, $opts), $cursor['pk'] && $cursor['last'] !== null ? sprintf('`%s` > %d', $cursor['pk'], $cursor['last']) : '']);
      $sql = "SELECT * FROM `$table`" . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
      $sql .= $cursor['pk'] ? sprintf(' ORDER BY `%s` ASC LIMIT %d', $cursor['pk'], self::BATCH) : sprintf(' LIMIT %d, %d', $cursor['offset'], self::BATCH);

      $count = 0;

      foreach (Db::rows($sql) as $row) {
        fwrite($out, self::encode_row(array_values($row)) . "\n");
        $count++;

        if ($cursor['pk']) {
          $cursor['last'] = (int) $row[$cursor['pk']];
        }
      }

      $cursor['offset'] += $count;
      $st['rows'] += $count;

      if ($count < self::BATCH) {
        $st['ti']++;
        $st['cursor'] = null;
      } else {
        $st['cursor'] = $cursor;
      }
    }

    return true;
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
