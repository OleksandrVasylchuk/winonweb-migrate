<?php

namespace WOWM;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- archives are streamed in chunks (seek/append), which WP_Filesystem cannot do.

/**
 * Walks the site's content incrementally and writes the selected files into a
 * list, one path per line.
 *
 * Paths in the list (and in archives) are canonical: "plugins/x", "mu-plugins/x",
 * "themes/x", "uploads/x" and, for everything else, the path inside wp-content.
 * Where those folders really are is resolved on both ends through WP_PLUGIN_DIR,
 * WPMU_PLUGIN_DIR, get_theme_root() and wp_upload_dir(), so a site with a custom
 * layout exports and imports correctly. WP_CONTENT_DIR itself is only the container
 * of the remaining ("other") files and of the backups folder.
 *
 * Exclusion patterns: "/x" is anchored to the canonical root, "x" matches a file
 * or folder name at any depth, anything with a slash is matched against the path.
 */
final class Scanner {
  const DROPINS = ['object-cache.php', 'advanced-cache.php', 'db.php'];

  // Caches, logs, other plugins' backups and leftover copies (plugins-old, uploads.bak...).
  const DEFAULT_EXCLUDES = [
    '/cache',
    '/upgrade',
    '/' . Storage::DIR,
    '/ai1wm-backups',
    '/updraft',
    '/backups-dup-*',
    '/backup-db',
    '/backup',
    '/backups',
    '/backup-*',
    '/backups-*',
    '/backupwordpress-*',
    '/wpvividbackups',
    '/*-old',
    '/*.old',
    '/*-bak',
    '/*.bak',
    '/*-backup',
    '/*-backups',
    '/*_backup',
    '/*-copy',
    '/wflogs',
    '/et-cache',
    '/litespeed',
    '/debug.log',
    '/plugins/winonweb-migrate',
    'node_modules',
    '.git',
    '.svn',
    '.DS_Store',
    'Thumbs.db',
    '*.wowm',
    '*.wpress',
  ];

  /** Canonical top-level folder => component. */
  const COMPONENTS = ['themes' => 'themes', 'plugins' => 'plugins', 'mu-plugins' => 'muplugins', 'uploads' => 'uploads'];

  public static function patterns(bool $exclude_dropins, array $custom): array {
    $patterns = self::DEFAULT_EXCLUDES;

    if ($exclude_dropins) {
      foreach (self::DROPINS as $file) {
        $patterns[] = '/' . $file;
      }
    }

    return array_merge($patterns, $custom);
  }

  public static function component_of(string $root_entry): string {
    return self::COMPONENTS[$root_entry] ?? 'other';
  }

  /**
   * Where each component lives on this server: canonical folder => absolute path.
   */
  public static function roots(): array {
    $roots = [
      'plugins' => WP_PLUGIN_DIR,
      'mu-plugins' => WPMU_PLUGIN_DIR,
      'themes' => get_theme_root(),
      'uploads' => wp_get_upload_dir()['basedir'],
    ];

    foreach ($roots as $name => $path) {
      $roots[$name] = untrailingslashit(wp_normalize_path((string) $path));
    }

    return $roots;
  }

  /**
   * Absolute path of a canonical list / archive path on this server.
   */
  public static function abs(string $rel): string {
    $slash = strpos($rel, '/');
    $first = $slash === false ? $rel : substr($rel, 0, $slash);
    $roots = self::roots();

    if (isset($roots[$first])) {
      return $roots[$first] . ($slash === false ? '' : substr($rel, $slash));
    }

    return untrailingslashit(wp_normalize_path(WP_CONTENT_DIR)) . '/' . $rel;
  }

  /**
   * excluded() for a full path: also true when a parent folder is excluded.
   */
  public static function excluded_path(string $rel, array $patterns): bool {
    $path = '';

    foreach (explode('/', $rel) as $part) {
      $path = $path === '' ? $part : $path . '/' . $part;

      if (self::excluded($path, $patterns)) {
        return true;
      }
    }

    return false;
  }

  public static function excluded(string $rel, array $patterns): bool {
    $name = basename($rel);

    foreach ($patterns as $pattern) {
      if ($pattern === '') {
        continue;
      }

      if ($pattern[0] === '/') {
        $anchored = ltrim($pattern, '/');

        // FNM_PATHNAME: "/*-old" matches "plugins-old" but not "plugins/foo-old".
        if ($rel === $anchored || fnmatch($anchored, $rel, FNM_NOESCAPE | FNM_PATHNAME)) {
          return true;
        }
      } elseif (strpos($pattern, '/') !== false) {
        if (fnmatch($pattern, $rel, FNM_NOESCAPE)) {
          return true;
        }
      } elseif (fnmatch($pattern, $name, FNM_NOESCAPE)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Top-level entries to walk: [absolute path, canonical path, component, is dir].
   * Component roots come first; then whatever else sits in wp-content, except the
   * component folders themselves (wherever they are) and names reserved for them.
   */
  private static function top_level(array $patterns): array {
    $entries = [];
    $taken = [];

    foreach (self::roots() as $name => $abs) {
      $taken[] = realpath($abs) ?: $abs;

      if (is_dir($abs) && !is_link($abs) && !self::excluded($name, $patterns)) {
        $entries[] = [$abs, $name, self::component_of($name), true];
      }
    }

    $content = untrailingslashit(wp_normalize_path(WP_CONTENT_DIR));

    foreach (scandir($content) ?: [] as $name) {
      if ($name === '.' || $name === '..' || isset(self::COMPONENTS[$name]) || self::excluded($name, $patterns)) {
        continue;
      }

      $abs = $content . '/' . $name;

      if (is_link($abs) || in_array(realpath($abs) ?: $abs, $taken, true)) {
        continue;
      }

      $entries[] = [$abs, $name, 'other', is_dir($abs)];
    }

    return $entries;
  }

  /**
   * @param array    $st         Persistent state (stack, files, bytes).
   * @param resource $out        List file, positioned at its end.
   * @param array    $components Component => bool.
   */
  public static function run(array &$st, $out, float $deadline, array $components, array $patterns): bool {
    if (!isset($st['stack']) || (isset($st['stack'][0]) && !is_array($st['stack'][0]))) {
      $st = ['stack' => [], 'files' => 0, 'bytes' => 0];

      foreach (self::top_level($patterns) as [$abs, $rel, $component, $dir]) {
        if (empty($components[$component])) {
          continue;
        }

        if ($dir) {
          $st['stack'][] = [$abs, $rel];
        } elseif (is_file($abs) && is_readable($abs)) {
          fwrite($out, $rel . "\n");
          $st['files']++;
          $st['bytes'] += (int) filesize($abs);
        }
      }
    }

    while ($st['stack']) {
      if (microtime(true) > $deadline) {
        return false;
      }

      [$abs_dir, $rel_dir] = array_pop($st['stack']);
      $handle = @opendir($abs_dir);

      if (!$handle) {
        continue;
      }

      while (false !== ($name = readdir($handle))) {
        if ($name === '.' || $name === '..') {
          continue;
        }

        $rel = $rel_dir . '/' . $name;

        if (self::excluded($rel, $patterns)) {
          continue;
        }

        $path = $abs_dir . '/' . $name;

        if (is_dir($path)) {
          if (!is_link($path)) {
            $st['stack'][] = [$path, $rel];
          }
        } elseif (is_file($path) && is_readable($path)) {
          fwrite($out, $rel . "\n");
          $st['files']++;
          $st['bytes'] += (int) filesize($path);
        }
      }

      closedir($handle);
    }

    return true;
  }

  /**
   * Sizes for the export screen, measured over several requests. Time is split
   * evenly between top-level folders so a huge one does not block the rest.
   */
  public static function measure(array $st, float $budget): array {
    $patterns = self::patterns(true, []);

    if (!isset($st['entries'])) {
      $st = ['entries' => []];

      foreach (self::top_level($patterns) as [$abs, $rel, $component, $dir]) {
        $st['entries'][] = [
          'name' => $rel,
          'component' => $component,
          'dir' => $dir,
          'size' => $dir ? 0 : (int) @filesize($abs),
          'done' => !$dir,
          'stack' => $dir ? [[$abs, $rel]] : [],
        ];
      }
    }

    $deadline = microtime(true) + $budget;

    while (microtime(true) < $deadline) {
      $open = array_keys(
        array_filter($st['entries'], function ($entry) {
          return !$entry['done'];
        }),
      );

      if (!$open) {
        break;
      }

      $slice = max(0.2, ($deadline - microtime(true)) / count($open));

      foreach ($open as $key) {
        self::measure_entry($st['entries'][$key], min($deadline, microtime(true) + $slice), $patterns);

        if (microtime(true) >= $deadline) {
          break;
        }
      }
    }

    return $st;
  }

  private static function measure_entry(array &$entry, float $until, array $patterns): void {
    while ($entry['stack']) {
      if (microtime(true) > $until) {
        return;
      }

      [$abs_dir, $rel_dir] = array_pop($entry['stack']);
      $handle = @opendir($abs_dir);

      if (!$handle) {
        continue;
      }

      while (false !== ($name = readdir($handle))) {
        if ($name === '.' || $name === '..') {
          continue;
        }

        $rel = $rel_dir . '/' . $name;
        $path = $abs_dir . '/' . $name;

        if (is_link($path) || self::excluded($rel, $patterns)) {
          continue;
        }

        if (is_dir($path)) {
          $entry['stack'][] = [$path, $rel];
        } else {
          $entry['size'] += (int) @filesize($path);
        }
      }

      closedir($handle);
    }

    $entry['done'] = true;
  }

  public static function summary(array $st): array {
    $sizes = ['themes' => 0, 'plugins' => 0, 'muplugins' => 0, 'uploads' => 0, 'other' => 0];
    $partial = [];
    $folders = [];

    foreach ($st['entries'] as $entry) {
      $sizes[$entry['component']] += $entry['size'];

      if (!$entry['done']) {
        $partial[$entry['component']] = true;
      }

      if ($entry['component'] === 'other') {
        $folders[] = ['name' => $entry['name'], 'size' => $entry['size'], 'dir' => $entry['dir'], 'done' => $entry['done']];
      }
    }

    usort($folders, function ($a, $b) {
      return $b['size'] <=> $a['size'];
    });

    return ['sizes' => $sizes, 'partial' => $partial, 'folders' => $folders, 'done' => !$partial];
  }
}
