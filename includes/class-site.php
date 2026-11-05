<?php

namespace WOWM;

defined('ABSPATH') || exit;

final class Site {
  public static function info(): array {
    global $wpdb, $wp_version;

    $uploads = wp_get_upload_dir();

    return [
      'name' => get_bloginfo('name'),
      'home' => untrailingslashit((string) get_option('home')),
      'siteurl' => untrailingslashit((string) get_option('siteurl')),
      'abspath' => untrailingslashit(wp_normalize_path(ABSPATH)),
      'content_dir' => untrailingslashit(wp_normalize_path(WP_CONTENT_DIR)),
      'content_url' => untrailingslashit(content_url()),
      'uploads_dir' => untrailingslashit(wp_normalize_path($uploads['basedir'])),
      'uploads_url' => untrailingslashit($uploads['baseurl']),
      'wp' => $wp_version,
      'php' => PHP_VERSION,
      'mysql' => $wpdb->db_server_info(),
      'prefix' => $wpdb->prefix,
      'charset' => $wpdb->charset,
      'multisite' => is_multisite(),
      'theme' => get_stylesheet(),
      'plugins' => array_values((array) get_option('active_plugins', [])),
      'extensions' => self::extensions(),
    ];
  }

  /**
   * PHP extensions a site tends to depend on. Compared on import so a missing
   * one (no imagick, no intl...) is a warning before the import, not a fatal after.
   */
  public static function extensions(): array {
    $watch = ['gd', 'imagick', 'zip', 'intl', 'mbstring', 'curl', 'exif', 'xml', 'simplexml', 'dom', 'soap', 'bcmath', 'openssl', 'sodium', 'fileinfo', 'iconv'];
    $loaded = array_map('strtolower', get_loaded_extensions());

    return array_values(array_intersect($watch, $loaded));
  }

  /**
   * Host drop-ins present on this site. These belong to the server and are not moved by default.
   */
  public static function host_dropins(): array {
    return array_values(
      array_filter(Scanner::DROPINS, function ($file) {
        return file_exists(WP_CONTENT_DIR . '/' . $file);
      }),
    );
  }
}
