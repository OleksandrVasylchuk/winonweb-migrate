<?php

namespace WOWM;

defined('ABSPATH') || exit;

final class Plugin {
  private static $instance;

  public static function instance(): self {
    if (!self::$instance) {
      self::$instance = new self();
    }

    return self::$instance;
  }

  private function __construct() {
    Admin::init();
    Ajax::init();
    Scheduler::init();

    register_activation_hook(WOWM_FILE, [self::class, 'activate']);
    register_deactivation_hook(WOWM_FILE, [Scheduler::class, 'unschedule']);

    if (defined('WP_CLI') && WP_CLI) {
      \WP_CLI::add_command('wowm', Cli::class);
    }
  }

  public static function activate(): void {
    if (PHP_INT_SIZE < 8) {
      deactivate_plugins(plugin_basename(WOWM_FILE));
      wp_die(esc_html__('WinOnWeb Migrate requires a 64-bit PHP build.', 'winonweb-migrate'));
    }

    Storage::dir();
    Scheduler::reschedule();
  }

  public static function basename(): string {
    return plugin_basename(WOWM_FILE);
  }
}
