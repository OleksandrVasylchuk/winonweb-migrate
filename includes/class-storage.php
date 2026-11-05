<?php

namespace WOWM;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- archives are streamed in chunks (seek/append), which WP_Filesystem cannot do.

/**
 * Backups folder: wp-content/wowm-backups. Archives get an unguessable name
 * because .htaccess protection does nothing on nginx.
 */
final class Storage {
  const DIR = 'wowm-backups';
  const EXT = 'wowm';

  public static function dir(): string {
    $dir = WP_CONTENT_DIR . '/' . self::DIR;

    if (!is_dir($dir)) {
      wp_mkdir_p($dir);
    }

    self::protect($dir);

    return $dir;
  }

  public static function jobs_dir(): string {
    $dir = self::dir() . '/jobs';

    if (!is_dir($dir)) {
      wp_mkdir_p($dir);
    }

    return $dir;
  }

  private static function protect(string $dir): void {
    $files = [
      '.htaccess' => "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n",
      'index.php' => "<?php\n// Silence is golden.\n",
      'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
    ];

    foreach ($files as $name => $contents) {
      if (!file_exists("$dir/$name")) {
        @file_put_contents("$dir/$name", $contents);
      }
    }
  }

  const FOREIGN = ['wpress', 'zip'];

  public static function new_archive_name(string $prefix = '', string $ext = self::EXT): string {
    $url = wp_parse_url(get_option('home'));
    $host = sanitize_title(($url['host'] ?? 'site') . ($url['path'] ?? ''));

    return sprintf('%s%s-%s-%s.%s', $prefix, $host ?: 'site', gmdate('Ymd-His'), strtolower(wp_generate_password(12, false)), $ext);
  }

  /**
   * Uploaded All-in-One (.wpress) or Duplicator (.zip) archive waiting for conversion.
   */
  public static function foreign_path(string $file): ?string {
    $file = basename($file);

    if (!preg_match('/^uploaded-[A-Za-z0-9._-]+\.(' . implode('|', self::FOREIGN) . ')$/', $file)) {
      return null;
    }

    $path = self::dir() . '/' . $file;

    return is_file($path) ? $path : null;
  }

  /**
   * File index of a full backup (path, size, mtime separated by tabs, one per line), used as the base of differential backups.
   */
  public static function index_path(string $archive): string {
    return substr($archive, 0, -strlen(self::EXT)) . 'idx';
  }

  public static function read_index(string $archive): array {
    $index = [];
    $fh = @fopen(self::index_path($archive), 'rb');

    if (!$fh) {
      return $index;
    }

    while (($line = fgets($fh)) !== false) {
      $tab = strpos($line, "\t");

      if ($tab !== false) {
        $index[substr($line, 0, $tab)] = rtrim(substr($line, $tab + 1), "\r\n");
      }
    }

    fclose($fh);

    return $index;
  }

  /**
   * Resolve a backup file name to a path inside the storage folder.
   */
  public static function path(string $file): ?string {
    $file = basename($file);

    if (!preg_match('/^[A-Za-z0-9._-]+\.' . self::EXT . '$/', $file)) {
      return null;
    }

    $path = self::dir() . '/' . $file;

    return is_file($path) ? $path : null;
  }

  public static function meta_path(string $archive): string {
    return substr($archive, 0, -strlen(self::EXT)) . 'json';
  }

  public static function read_meta(string $archive): array {
    $path = self::meta_path($archive);

    if (!is_file($path)) {
      return [];
    }

    $meta = json_decode((string) file_get_contents($path), true);

    return is_array($meta) ? $meta : [];
  }

  public static function write_meta(string $archive, array $meta): void {
    file_put_contents(self::meta_path($archive), wp_json_encode(array_merge(self::read_meta($archive), $meta)));
  }

  public static function backups(): array {
    $items = [];

    foreach (glob(self::dir() . '/*.' . self::EXT) ?: [] as $path) {
      $meta = self::read_meta($path);
      $items[] = [
        'file' => basename($path),
        'size' => filesize($path),
        'created' => (int) ($meta['created'] ?? filemtime($path)),
        'origin' => $meta['origin'] ?? 'manual',
        'label' => $meta['label'] ?? '',
        'source' => $meta['source'] ?? '',
        'components' => $meta['components'] ?? [],
        'kind' => $meta['kind'] ?? 'full',
        'base' => $meta['base'] ?? '',
        'has_index' => is_file(self::index_path($path)),
      ];
    }

    usort($items, function ($a, $b) {
      return $b['created'] <=> $a['created'];
    });

    return $items;
  }

  public static function delete(string $file): bool {
    $path = self::path($file);

    if (!$path) {
      return false;
    }

    @unlink(self::meta_path($path));
    @unlink(self::index_path($path));

    return @unlink($path);
  }

  public static function free_space(): ?int {
    $free = function_exists('disk_free_space') ? @disk_free_space(self::dir()) : false;

    return $free === false ? null : (int) $free;
  }

  public static function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }

    $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($items as $item) {
      $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($dir);
  }

  /**
   * Remove finished job folders and abandoned uploads older than a day.
   */
  public static function cleanup(): void {
    $limit = time() - DAY_IN_SECONDS;

    foreach (glob(self::jobs_dir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
      if (@filemtime($dir . '/state.json') < $limit) {
        self::rrmdir($dir);
      }
    }

    foreach (array_merge(glob(self::dir() . '/*.part') ?: [], glob(self::dir() . '/uploaded-*.{wpress,zip}', GLOB_BRACE) ?: []) as $leftover) {
      if (@filemtime($leftover) < $limit) {
        @unlink($leftover);
      }
    }
  }
}
