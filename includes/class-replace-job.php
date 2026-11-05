<?php

namespace WOWM;

defined('ABSPATH') || exit;

/**
 * Search & replace across the whole database, serialization-aware, row by row.
 * Tables without a single-column primary key cannot be updated safely and are skipped.
 */
final class Replace_Job extends Job {
  const BATCH = 200;

  public static function sanitize(array $in): array {
    return [
      'from' => trim((string) ($in['from'] ?? '')),
      'to' => trim((string) ($in['to'] ?? '')),
    ];
  }

  /**
   * from/to in every encoding they are stored in. URLs also match the other scheme
   * and the protocol-relative form.
   */
  public static function variants(string $from, string $to): array {
    $pairs = [];
    $escape = function ($value) {
      return str_replace('/', '\/', $value);
    };

    if (preg_match('#^https?://#i', $from)) {
      $bare = preg_replace('#^https?:#i', '', $from);
      $to_bare = preg_replace('#^https?:#i', '', $to);

      foreach (['http:', 'https:'] as $scheme) {
        $pairs[] = [$scheme . $bare, $to];
        $pairs[] = [$escape($scheme . $bare), $escape($to)];
      }

      $pairs[] = [$bare, $to_bare];
      $pairs[] = [$escape($bare), $escape($to_bare)];
    } else {
      $pairs[] = [$from, $to];
      $pairs[] = [$escape($from), $escape($to)];
    }

    return $pairs;
  }

  protected function steps(): array {
    return [
      'prepare' => [__('Finding tables', 'winonweb-migrate'), 3],
      'replace' => [__('Replacing', 'winonweb-migrate'), 92],
      'finalize' => [__('Clearing caches', 'winonweb-migrate'), 5],
    ];
  }

  protected function step_prepare(float $deadline): bool {
    if ($this->opt('from') === '' || $this->opt('from') === $this->opt('to')) {
      throw new \RuntimeException(esc_html(__('Enter what to find and something different to replace it with.', 'winonweb-migrate')));
    }

    $tables = [];

    foreach (Db_Exporter::tables() as $table) {
      $info = Links::columns($table);

      if (!$info['columns']) {
        continue;
      }

      if (!$info['pk']) {
        $this->log(sprintf(/* translators: %s: database table name */ __('Skipped %s: no primary key', 'winonweb-migrate'), $table));
        continue;
      }

      $tables[] = [$table, $info['pk'], $info['columns']];
    }

    $this->state['data'] = ['tables' => $tables, 'ti' => 0, 'last' => null, 'rows' => 0, 'changed' => 0];

    return true;
  }

  protected function step_replace(float $deadline): bool {
    $d = &$this->state['data'];
    $pairs = self::variants($this->opt('from'), $this->opt('to'));
    $replacer = new Replacer($pairs);
    $needles = array_values(array_unique(array_column($pairs, 0)));

    Db::session();

    while ($d['ti'] < count($d['tables']) && microtime(true) < $deadline) {
      [$table, $pk, $columns] = $d['tables'][$d['ti']];

      $where = '(' .
        implode(
          ' OR ',
          array_map(function ($column) use ($needles) {
            return Links::like($column, $needles);
          }, $columns),
        ) .
        ')';

      if ($d['last'] !== null) {
        $where .= " AND `$pk` > '" . Db::esc((string) $d['last']) . "'";
      }

      $select = '`' . $pk . '`, `' . implode('`, `', $columns) . '`';
      $count = 0;

      foreach (Db::rows("SELECT $select FROM `$table` WHERE $where ORDER BY `$pk` ASC LIMIT " . self::BATCH) as $row) {
        $count++;
        $d['rows']++;
        $d['last'] = $row[$pk];
        $set = [];

        foreach ($columns as $column) {
          if ($row[$column] === null) {
            continue;
          }

          $value = $replacer->value($row[$column]);

          if ($value !== $row[$column]) {
            $set[] = "`$column` = '" . Db::esc($value) . "'";
          }
        }

        if ($set) {
          Db::query("UPDATE `$table` SET " . implode(', ', $set) . " WHERE `$pk` = '" . Db::esc((string) $row[$pk]) . "'");
          $d['changed']++;
        }
      }

      if ($count < self::BATCH) {
        $d['ti']++;
        $d['last'] = null;
      }
    }

    $total = count($d['tables']);
    $this->stat('changed', number_format_i18n($d['changed']));
    $this->progress('replace', $total ? $d['ti'] / $total : 1, isset($d['tables'][$d['ti']]) ? sprintf(/* translators: %s: database table name */ __('Table %s', 'winonweb-migrate'), $d['tables'][$d['ti']][0]) : '');

    return $d['ti'] >= $total;
  }

  protected function step_finalize(float $deadline): bool {
    Cache::flush_all();

    $this->state['result'] = ['changed' => $this->state['data']['changed']];
    $this->log(sprintf(/* translators: %s: number of rows */ __('%s rows updated.', 'winonweb-migrate'), number_format_i18n($this->state['data']['changed'])));

    return true;
  }

  protected function cleanup(bool $success): void {
  }
}
