<?php

namespace WOWM;

defined('ABSPATH') || exit;

/**
 * Keeps the pre-import database (wowmo_* tables) until the admin keeps or undoes the import.
 *
 * The state is an option in the imported database: it is written after the switch, so
 * it lives in the tables that are live at that point and disappears with them on undo.
 * Nothing secret is written to disk.
 */
final class Rollback {
  const OPTION = 'wowm_rollback';
  const LAST = 'wowm_last_import';
  const TTL = 7 * DAY_IN_SECONDS;
  const AUTO_HOOK = 'wowm_auto_undo';
  const AUTO_DELAY = 10 * MINUTE_IN_SECONDS;

  public static function get(): ?array {
    $data = get_option(self::OPTION);

    return is_array($data) && !empty($data['tables']) ? $data : null;
  }

  /**
   * @param array $tables [[live table, kept previous table], ...]
   * @param array $new    Tables the import created that did not exist before.
   */
  public static function save(array $tables, array $new, string $source): void {
    update_option(self::OPTION, ['created' => time(), 'source' => $source, 'tables' => $tables, 'new' => $new, 'health' => null, 'auto_undo_at' => null], false);
    update_option(self::LAST, ['created' => time(), 'source' => $source], false);
  }

  public static function update(array $fields): void {
    $data = self::get();

    if ($data) {
      update_option(self::OPTION, array_merge($data, $fields), false);
    }
  }

  public static function last_import(): ?array {
    $data = get_option(self::LAST);

    return is_array($data) ? $data : null;
  }

  /*
   * Automatic undo when the site check fails
   */

  public static function schedule_auto_undo(): int {
    $at = time() + self::AUTO_DELAY;
    wp_clear_scheduled_hook(self::AUTO_HOOK);
    wp_schedule_single_event($at, self::AUTO_HOOK);
    self::update(['auto_undo_at' => $at]);

    return $at;
  }

  public static function cancel_auto_undo(): void {
    wp_clear_scheduled_hook(self::AUTO_HOOK);
    self::update(['auto_undo_at' => null]);
  }

  public static function run_auto_undo(): void {
    $data = self::get();

    if ($data && !empty($data['auto_undo_at']) && $data['auto_undo_at'] <= time()) {
      self::undo();
    }
  }

  /**
   * Keep the imported database: drop the previous one.
   */
  public static function clear(): void {
    $data = self::get();

    if ($data) {
      Db_Importer::drop(array_column($data['tables'], 1));
    }

    wp_clear_scheduled_hook(self::AUTO_HOOK);
    delete_option(self::OPTION);
  }

  /**
   * Put the previous database back.
   */
  public static function undo(): void {
    $data = self::get();

    if (!$data) {
      throw new \RuntimeException(esc_html(__('There is nothing to undo.', 'winonweb-migrate')));
    }

    // Both live in the tables that are about to be set aside; tidy them first anyway.
    wp_clear_scheduled_hook(self::AUTO_HOOK);
    delete_option(self::OPTION);

    $renames = [];
    $discard = [];

    foreach ($data['tables'] as [$live, $previous]) {
      if (!Db::table_exists($previous)) {
        continue;
      }

      $trash = substr('wowmx_' . $live, 0, 64);
      Db::query("DROP TABLE IF EXISTS `$trash`");

      if (Db::table_exists($live)) {
        $renames[] = "`$live` TO `$trash`";
        $discard[] = $trash;
      }

      $renames[] = "`$previous` TO `$live`";
    }

    if ($renames) {
      Db::query('RENAME TABLE ' . implode(', ', $renames));
    }

    Db_Importer::drop(array_merge($discard, $data['new']));

    wp_cache_flush();
    Cache::flush_all();
  }

  public static function expire(): void {
    $data = self::get();

    if ($data && $data['created'] < time() - self::TTL) {
      self::clear();
    }
  }
}
