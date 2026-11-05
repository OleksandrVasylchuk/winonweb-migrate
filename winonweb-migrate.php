<?php
/**
 * Plugin Name:       WinOnWeb Migrate
 * Plugin URI:        https://www.winonweb.dev/en/migrate
 * Description:       Move or back up a WordPress site with no size limits. Imports All-in-One and Duplicator archives, can undo an import.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            WinOnWeb
 * Author URI:        https://www.winonweb.dev/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       winonweb-migrate
 */

defined('ABSPATH') || exit;

define('WOWM_VERSION', '1.0.0');
define('WOWM_FILE', __FILE__);
define('WOWM_DIR', plugin_dir_path(__FILE__));
define('WOWM_URL', plugin_dir_url(__FILE__));

spl_autoload_register(function ($class) {
  if (strpos($class, 'WOWM\\') !== 0) {
    return;
  }

  $file = WOWM_DIR . 'includes/class-' . strtolower(str_replace('_', '-', substr($class, 5))) . '.php';

  if (is_readable($file)) {
    require $file;
  }
});

WOWM\Plugin::instance();
