<?php

namespace WOWM;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- archives are streamed in chunks (seek/append), which WP_Filesystem cannot do.

/**
 * .wowm format: a flat sequence of entries, each one
 *   "WOWE" | uint32 name length | uint64 size | uint32 mtime | name | content
 * Append-only, so export can resume mid-file and import can seek past what it skips.
 * The manifest is the last entry ("__wowm/manifest.json").
 */
final class Archive {
  const MAGIC = 'WOWE';
  const HEADER = 20;
  const FORMAT = 1;
  const MANIFEST = '__wowm/manifest.json';
  const DATABASE = '__wowm/database.jsonl';

  public static function header(string $name, int $size, int $mtime): string {
    return pack('a4NJN', self::MAGIC, strlen($name), $size, $mtime) . $name;
  }

  public static function is_archive(string $path): bool {
    $fh = @fopen($path, 'rb');

    if (!$fh) {
      return false;
    }

    $magic = fread($fh, 4);
    fclose($fh);

    return $magic === self::MAGIC;
  }
}
