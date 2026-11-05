<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

// Backups in wp-content/wowm-backups are left in place on purpose: they are the user's data.
// The copy of the previous database kept for "Undo import" goes: without the plugin nothing could restore it.
$wowm_rollback = get_option('wowm_rollback');

if (is_array($wowm_rollback) && !empty($wowm_rollback['tables'])) {
  global $wpdb;

  foreach (array_column((array) $wowm_rollback['tables'], 1) as $wowm_table) {
    if (is_string($wowm_table) && preg_match('/^wowmo_[A-Za-z0-9_]+$/', $wowm_table)) {
      $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($wowm_table) . '`'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- table name created by this plugin, validated above and escaped.
    }
  }
}

foreach (['wowm_settings', 'wowm_rollback', 'wowm_last_import', 'wowm_active_job'] as $wowm_option) {
  delete_option($wowm_option);
}

foreach (['wowm_scheduled_backup', 'wowm_continue_job', 'wowm_auto_undo'] as $wowm_hook) {
  wp_clear_scheduled_hook($wowm_hook);
}
