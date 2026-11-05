<?php

namespace WOWM;

defined('ABSPATH') || exit;

/**
 * Finds where a string (usually an old domain) still appears in the database,
 * per table and column, with a short sample. Runs over several requests.
 */
final class Links {
  /**
   * Text-like columns of a table and its single-column primary key (if any).
   */
  public static function columns(string $table): array {
    $columns = [];

    foreach (Db::rows("SHOW COLUMNS FROM `$table`") as $row) {
      if (preg_match('/char|text|blob|json/i', $row['Type'])) {
        $columns[] = $row['Field'];
      }
    }

    $keys = Db::rows("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");
    $pk = count($keys) === 1 ? $keys[0]['Column_name'] : null;

    return ['columns' => $columns, 'pk' => $pk];
  }

  /**
   * The forms a string is stored in: as is and JSON-escaped (http:\/\/...).
   */
  public static function needles(string $needle): array {
    // "https://old.site" is searched as "//old.site": catches http, https and protocol-relative links.
    $needle = preg_replace('#^https?:#i', '', $needle);

    return array_values(array_unique([$needle, str_replace('/', '\/', $needle)]));
  }

  public static function like(string $column, array $needles): string {
    return '(' .
      implode(
        ' OR ',
        array_map(function ($needle) use ($column) {
          return "`$column` LIKE '%" . Db::esc(Db::like($needle)) . "%'";
        }, $needles),
      ) .
      ')';
  }

  public static function scan(array $st, string $needle, float $budget): array {
    if (($st['needle'] ?? null) !== $needle) {
      $st = ['needle' => $needle, 'tables' => Db_Exporter::tables(), 'ti' => 0, 'hits' => []];
    }

    $deadline = microtime(true) + $budget;
    $needles = self::needles($needle);

    while ($st['ti'] < count($st['tables']) && microtime(true) < $deadline) {
      $table = $st['tables'][$st['ti']];
      $columns = self::columns($table)['columns'];

      if ($columns) {
        $sums = array_map(function ($column) use ($needles) {
          return 'SUM(' . self::like($column, $needles) . ')';
        }, $columns);

        $counts = Db::row('SELECT ' . implode(', ', $sums) . " FROM `$table`", false);

        foreach ($columns as $i => $column) {
          if ((int) $counts[$i] > 0) {
            $sample = Db::value(sprintf("SELECT SUBSTRING(`%1\$s`, GREATEST(LOCATE('%2\$s', `%1\$s`) - 60, 1), 160) FROM `%3\$s` WHERE %4\$s LIMIT 1", $column, Db::esc($needles[0]), $table, self::like($column, $needles)));

            $st['hits'][] = [
              'table' => $table,
              'column' => $column,
              'count' => (int) $counts[$i],
              'sample' => wp_check_invalid_utf8((string) $sample, true),
            ];
          }
        }
      }

      $st['ti']++;
    }

    return $st;
  }
}
