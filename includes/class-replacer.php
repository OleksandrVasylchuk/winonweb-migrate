<?php

namespace WOWM;

defined('ABSPATH') || exit;

/**
 * Search & replace that keeps serialized data valid. Serialized values are parsed
 * token by token (no unserialize()) and string lengths are recomputed.
 */
final class Replacer {
  private $map = [];
  private $from = [];
  public $replaced = 0;

  /**
   * @param array $pairs [[from, to], ...]
   */
  public function __construct(array $pairs) {
    $map = [];

    foreach ($pairs as $pair) {
      [$from, $to] = array_values($pair) + ['', ''];

      if ($from !== '' && $from !== $to) {
        $map[(string) $from] = (string) $to;
      }
    }

    $this->map = $map;
    $this->from = array_map('strval', array_keys($map));
  }

  public function value(string $value): string {
    if (!$this->from || !$this->contains($value)) {
      return $value;
    }

    if (is_serialized($value)) {
      try {
        $i = 0;
        $out = $this->walk($value, $i);

        if ($i === strlen($value)) {
          return $out;
        }
      } catch (\Throwable $e) {
        // Broken serialized data: leave as is.
      }

      return $value;
    }

    $this->replaced++;

    // strtr: longest match first and no re-replacing of replaced text.
    return strtr($value, $this->map);
  }

  private function contains(string $value): bool {
    foreach ($this->from as $from) {
      if (strpos($value, $from) !== false) {
        return true;
      }
    }

    return false;
  }

  private function walk(string $s, int &$i): string {
    $type = $s[$i] ?? '';

    switch ($type) {
      case 'N':
        $this->expect($s, $i + 1, ';');
        $i += 2;

        return 'N;';

      case 'b':
      case 'i':
      case 'd':
      case 'r':
      case 'R':
        $end = strpos($s, ';', $i);

        if ($end === false) {
          throw new \UnexpectedValueException();
        }

        $token = substr($s, $i, $end - $i + 1);
        $i = $end + 1;

        return $token;

      case 's':
        $this->expect($s, $i + 1, ':');
        $colon = strpos($s, ':', $i + 2);
        $length = (int) substr($s, $i + 2, $colon - $i - 2);
        $this->expect($s, $colon + 1, '"');
        $start = $colon + 2;
        $this->expect($s, $start + $length, '"');
        $this->expect($s, $start + $length + 1, ';');
        $inner = substr($s, $start, $length);
        $i = $start + $length + 2;
        $inner = $this->value($inner);

        return 's:' . strlen($inner) . ':"' . $inner . '";';

      case 'a':
        $this->expect($s, $i + 1, ':');
        $colon = strpos($s, ':', $i + 2);
        $count = (int) substr($s, $i + 2, $colon - $i - 2);
        $this->expect($s, $colon + 1, '{');
        $i = $colon + 2;
        $out = 'a:' . $count . ':{';

        for ($n = 0; $n < $count * 2; $n++) {
          $out .= $this->walk($s, $i);
        }

        $this->expect($s, $i, '}');
        $i++;

        return $out . '}';

      case 'O':
        // O:<len>:"<class>":<count>:{...}
        $colon = strpos($s, ':', $i + 2);
        $length = (int) substr($s, $i + 2, $colon - $i - 2);
        $class = substr($s, $colon + 2, $length);
        $after = $colon + 2 + $length;
        $this->expect($s, $after, '"');
        $this->expect($s, $after + 1, ':');
        $colon2 = strpos($s, ':', $after + 2);
        $count = (int) substr($s, $after + 2, $colon2 - $after - 2);
        $this->expect($s, $colon2 + 1, '{');
        $i = $colon2 + 2;
        $out = 'O:' . $length . ':"' . $class . '":' . $count . ':{';

        for ($n = 0; $n < $count * 2; $n++) {
          $out .= $this->walk($s, $i);
        }

        $this->expect($s, $i, '}');
        $i++;

        return $out . '}';

      case 'C':
      case 'E':
        // Custom-serialized objects and enums: copy verbatim.
        $start = $i;
        $colon = strpos($s, ':', $i + 2);
        $length = (int) substr($s, $i + 2, $colon - $i - 2);
        $after = $colon + 2 + $length;

        if ($type === 'E') {
          $this->expect($s, $after + 1, ';');
          $i = $after + 2;

          return substr($s, $start, $i - $start);
        }

        $colon2 = strpos($s, ':', $after + 2);
        $size = (int) substr($s, $after + 2, $colon2 - $after - 2);
        $i = $colon2 + 2 + $size;
        $this->expect($s, $i, '}');
        $i++;

        return substr($s, $start, $i - $start);
    }

    throw new \UnexpectedValueException();
  }

  private function expect(string $s, int $pos, string $char): void {
    if (($s[$pos] ?? null) !== $char) {
      throw new \UnexpectedValueException();
    }
  }
}
