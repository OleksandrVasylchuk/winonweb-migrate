<?php

namespace WOWM;

defined('ABSPATH') || exit;

/**
 * After an import: fix what commonly fatals (plugins whose files did not arrive,
 * plugins tied to the old host) and then check the site actually answers.
 */
final class Health {
  /**
   * Plugins that only make sense on the host they came from. Deactivated after an
   * import and listed in the result, never deleted.
   */
  const HOST_PLUGINS = [
    'hostinger',
    'hostinger-affiliate-plugin',
    'hostinger-ai-assistant',
    'wpcloud',
    'wp-cloud',
    'kinsta-mu-plugins',
    'wpengine-security-auditor',
    'wpe-sign-on-plugin',
    'sg-cachepress',
    'siteground-optimizer',
    'siteground-security',
    'breeze',
    'cloudways-object-cache',
    'object-cache-pro',
    'flywheel',
    'wp-rocket-hosting',
    'nitropack',
    'bluehost-wordpress-plugin',
    'godaddy-launch',
    'dreamhost-panel-login',
    'litespeed-cache',
  ];

  /**
   * @return array{deactivated_missing: string[], deactivated_host: string[]}
   */
  public static function repair(): array {
    $active = (array) get_option('active_plugins', []);
    $missing = [];
    $host = [];
    $keep = [];

    foreach ($active as $plugin) {
      $plugin = (string) $plugin;
      $slug = strtolower(dirname($plugin) === '.' ? basename($plugin, '.php') : dirname($plugin));

      if (!is_file(WP_PLUGIN_DIR . '/' . $plugin)) {
        $missing[] = $plugin;
      } elseif (in_array($slug, self::HOST_PLUGINS, true)) {
        $host[] = $plugin;
      } else {
        $keep[] = $plugin;
      }
    }

    if ($missing || $host) {
      update_option('active_plugins', array_values($keep));
    }

    // A theme that did not arrive leaves a white page; fall back to any installed one.
    if (!wp_get_theme()->exists()) {
      $themes = wp_get_themes();

      if ($themes) {
        $fallback = reset($themes);
        switch_theme($fallback->get_stylesheet());
      }
    }

    return ['deactivated_missing' => $missing, 'deactivated_host' => $host];
  }

  /**
   * Loopback requests to the site. A fatal is a fail; not being able to reach
   * the site at all (loopback blocked) is only a warning, so a strict firewall
   * cannot trigger an automatic undo.
   *
   * @return array<int, array{key:string,label:string,status:string,detail:string}>
   */
  public static function check(int $timeout = 25): array {
    $targets = [
      'home' => [__('Home page', 'winonweb-migrate'), home_url('/')],
      'login' => [__('Login page', 'winonweb-migrate'), wp_login_url()],
      'rest' => [__('REST API', 'winonweb-migrate'), rest_url()],
    ];

    $checks = [];

    foreach ($targets as $key => [$label, $url]) {
      $response = wp_remote_get(
        add_query_arg('wowm_health', (string) time(), $url),
        ['timeout' => $timeout, 'redirection' => 3, 'sslverify' => false, 'headers' => ['Cache-Control' => 'no-cache']],
      );

      if (is_wp_error($response)) {
        $checks[] = ['key' => $key, 'label' => $label, 'status' => 'warn', 'detail' => sprintf(/* translators: %s: network error */ __('Could not be checked: %s', 'winonweb-migrate'), $response->get_error_message())];
        continue;
      }

      $code = (int) wp_remote_retrieve_response_code($response);
      $body = (string) wp_remote_retrieve_body($response);
      $fatal = stripos($body, 'critical error on this website') !== false || preg_match('/<b>(Fatal error|Parse error)<\/b>/i', $body) || stripos($body, 'Error establishing a database connection') !== false;

      if ($fatal) {
        $checks[] = ['key' => $key, 'label' => $label, 'status' => 'fail', 'detail' => __('Shows a critical error', 'winonweb-migrate')];
      } elseif ($code >= 500) {
        $checks[] = ['key' => $key, 'label' => $label, 'status' => 'fail', 'detail' => sprintf('HTTP %d', $code)];
      } elseif ($code >= 400) {
        $checks[] = ['key' => $key, 'label' => $label, 'status' => 'warn', 'detail' => sprintf('HTTP %d', $code)];
      } else {
        $checks[] = ['key' => $key, 'label' => $label, 'status' => 'ok', 'detail' => sprintf('HTTP %d', $code)];
      }
    }

    return $checks;
  }

  /**
   * Keys that fail now and did not fail before the import. A page that was
   * already broken before the import is not something the import broke.
   *
   * @return string[]
   */
  public static function failing(array $checks, array $baseline = []): array {
    $before = [];

    foreach ($baseline as $check) {
      if (($check['status'] ?? '') === 'fail') {
        $before[] = $check['key'];
      }
    }

    $keys = [];

    foreach ($checks as $check) {
      if ($check['status'] === 'fail' && !in_array($check['key'], $before, true)) {
        $keys[] = $check['key'];
      }
    }

    return $keys;
  }

  public static function failed(array $checks, array $baseline = []): bool {
    return (bool) self::failing($checks, $baseline);
  }
}
