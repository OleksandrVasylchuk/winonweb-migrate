<?php

namespace WOWM;

defined('ABSPATH') || exit;

/**
 * All SQL goes through here, on top of $wpdb.
 *
 * The statements a caller passes in are assembled from values escaped with esc()
 * or like() and from table names this plugin created itself (wowmt_*, wowmo_*,
 * and the site's own tables as listed by SHOW TABLES), quoted in backticks.
 * wpdb::prepare() is not used because every statement names such a table and
 * the %i identifier placeholder only exists since WordPress 6.2.
 * check_current_query is turned off so wpdb does not strip binary data out of INSERTs.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
 */
final class Db {
  private static function run(callable $call) {
    global $wpdb;

    $wpdb->check_current_query = false;
    $suppress = $wpdb->suppress_errors(true);
    $result = $call($wpdb);
    $wpdb->suppress_errors($suppress);

    // A big import would otherwise keep every statement in memory.
    if (defined('SAVEQUERIES') && SAVEQUERIES) {
      $wpdb->queries = [];
    }

    if ($wpdb->last_error) {
      $error = $wpdb->last_error;
      $wpdb->last_error = '';

      throw new \RuntimeException(esc_html(sprintf(/* translators: %s: error message from the database server */ __('Database error: %s', 'winonweb-migrate'), $error)));
    }

    return $result;
  }

  public static function query(string $sql): int {
    return (int) self::run(function ($wpdb) use ($sql) {
      return $wpdb->query($sql); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- see the class comment: callers pass statements built from Db::esc() values and this plugin's own table names.
    });
  }

  public static function rows(string $sql, bool $assoc = true): array {
    $rows = self::run(function ($wpdb) use ($sql, $assoc) {
      return $wpdb->get_results($sql, $assoc ? ARRAY_A : ARRAY_N); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- see the class comment: callers pass statements built from Db::esc() values and this plugin's own table names.
    });

    $GLOBALS['wpdb']->flush();

    return is_array($rows) ? $rows : [];
  }

  public static function row(string $sql, bool $assoc = true): ?array {
    $row = self::run(function ($wpdb) use ($sql, $assoc) {
      return $wpdb->get_row($sql, $assoc ? ARRAY_A : ARRAY_N); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- see the class comment: callers pass statements built from Db::esc() values and this plugin's own table names.
    });

    return is_array($row) ? $row : null;
  }

  public static function value(string $sql) {
    return self::run(function ($wpdb) use ($sql) {
      return $wpdb->get_var($sql); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- see the class comment: callers pass statements built from Db::esc() values and this plugin's own table names.
    });
  }

  public static function esc(string $value): string {
    return esc_sql($value);
  }

  public static function like(string $value): string {
    global $wpdb;

    return $wpdb->esc_like($value);
  }

  public static function table_exists(string $table): bool {
    return self::value("SHOW TABLES LIKE '" . self::esc(self::like($table)) . "'") !== null;
  }

  public static function session(): void {
    self::query("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
    self::query('SET FOREIGN_KEY_CHECKS = 0');
    self::query('SET UNIQUE_CHECKS = 0');
  }
}
