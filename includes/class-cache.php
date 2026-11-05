<?php

namespace WOWM;

defined('ABSPATH') || exit;

final class Cache {
  /**
   * Clear every page/object cache we know about. Returns the names of what was cleared.
   */
  public static function flush_all(): array {
    $flushed = [];

    if (function_exists('rocket_clean_domain')) {
      rocket_clean_domain();
      $flushed[] = 'WP Rocket';
    }

    if (function_exists('w3tc_flush_all')) {
      w3tc_flush_all();
      $flushed[] = 'W3 Total Cache';
    }

    if (function_exists('wp_cache_clear_cache')) {
      wp_cache_clear_cache();
      $flushed[] = 'WP Super Cache';
    }

    if (has_action('litespeed_purge_all')) {
      do_action('litespeed_purge_all'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook.
      $flushed[] = 'LiteSpeed Cache';
    }

    if (class_exists('\autoptimizeCache')) {
      \autoptimizeCache::clearall();
      $flushed[] = 'Autoptimize';
    }

    if (function_exists('sg_cachepress_purge_cache')) {
      sg_cachepress_purge_cache();
      $flushed[] = 'SiteGround Optimizer';
    }

    do_action('wowm_flush_caches');

    wp_cache_flush();
    $flushed[] = __('Object cache', 'winonweb-migrate');

    if (function_exists('opcache_reset')) {
      @opcache_reset();
    }

    return $flushed;
  }
}
