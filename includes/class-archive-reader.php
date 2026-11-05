<?php

namespace WOWM;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- archives are streamed in chunks (seek/append), which WP_Filesystem cannot do.

final class Archive_Reader {
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

  public function size(): int {
    return $this->size;
  }

  public function seek(int $pos): void {
    fseek($this->fh, $pos);
  }

  /**
   * Read the entry header at the current position. Null at the end of the archive.
   */
  public function next(): ?array {
    $raw = fread($this->fh, Archive::HEADER);

    if ($raw === '' || $raw === false) {
      return null;
    }

    if (strlen($raw) < Archive::HEADER) {
      throw new \RuntimeException(esc_html(__('The archive is truncated.', 'winonweb-migrate')));
    }

    $head = unpack('a4magic/Nlen/Jsize/Nmtime', $raw);

    if ($head['magic'] !== Archive::MAGIC || $head['len'] < 1 || $head['len'] > 4096) {
      throw new \RuntimeException(esc_html(__('The archive is corrupted.', 'winonweb-migrate')));
    }

    $name = fread($this->fh, $head['len']);
    $pos = ftell($this->fh);

    if ($pos + $head['size'] > $this->size) {
      throw new \RuntimeException(esc_html(__('The archive is truncated.', 'winonweb-migrate')));
    }

    return [
      'name' => $name,
      'size' => $head['size'],
      'mtime' => $head['mtime'],
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
