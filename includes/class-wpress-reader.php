<?php

namespace WOWM;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- archives are streamed in chunks (seek/append), which WP_Filesystem cannot do.

/**
 * All-in-One WP Migration (.wpress) archive. Each entry has a 4377-byte header:
 *   name (255) | size (14) | mtime (12) | path prefix (4096), NUL-padded,
 * followed by the content. A header of zero bytes marks the end.
 * Paths are relative to wp-content; package.json and database.sql sit at the root.
 */
final class Wpress_Reader {
  const HEADER = 4377;

  private $fh;
  private $size;

  public function __construct(string $path) {
    $this->fh = @fopen($path, 'rb');

    if (!$this->fh) {
      throw new \RuntimeException(esc_html(__('Could not open the archive.', 'winonweb-migrate')));
    }

    $this->size = (int) filesize($path);
  }

  public function __destruct() {
    if ($this->fh) {
      fclose($this->fh);
    }
  }

  public static function looks_valid(string $path): bool {
    try {
      $reader = new self($path);
      $entry = $reader->next();

      return $entry !== null && $entry['name'] !== '';
    } catch (\Throwable $e) {
      return false;
    }
  }

  public function size(): int {
    return $this->size;
  }

  public function seek(int $pos): void {
    fseek($this->fh, $pos);
  }

  public function next(): ?array {
    $raw = fread($this->fh, self::HEADER);

    if ($raw === '' || $raw === false || trim($raw, "\0") === '') {
      return null;
    }

    if (strlen($raw) < self::HEADER) {
      throw new \RuntimeException(esc_html(__('The archive is truncated.', 'winonweb-migrate')));
    }

    $name = rtrim(substr($raw, 0, 255), "\0");
    $size = rtrim(substr($raw, 255, 14), "\0");
    $mtime = rtrim(substr($raw, 269, 12), "\0");
    $prefix = str_replace('\\', '/', rtrim(substr($raw, 281, 4096), "\0"));

    if (!ctype_digit($size) || $name === '') {
      throw new \RuntimeException(esc_html(__('The archive is corrupted.', 'winonweb-migrate')));
    }

    $pos = ftell($this->fh);

    if ($pos + (int) $size > $this->size) {
      throw new \RuntimeException(esc_html(__('The archive is truncated.', 'winonweb-migrate')));
    }

    $prefix = trim($prefix, '/');

    return [
      'name' => $prefix === '' || $prefix === '.' ? $name : $prefix . '/' . $name,
      'size' => (int) $size,
      'mtime' => (int) $mtime,
      'pos' => $pos,
    ];
  }

  public function read(int $length): string {
    return (string) fread($this->fh, $length);
  }

  public function find(string $name): ?array {
    $this->seek(0);

    while ($entry = $this->next()) {
      if ($entry['name'] === $name) {
        return $entry;
      }

      $this->seek($entry['pos'] + $entry['size']);
    }

    return null;
  }

  public function contents(array $entry): string {
    $this->seek($entry['pos']);

    return $entry['size'] ? $this->read($entry['size']) : '';
  }
}
