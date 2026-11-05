<?php

namespace WOWM;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- archives are streamed in chunks (seek/append), which WP_Filesystem cannot do.

/**
 * Converts a mysqldump-style SQL file (All-in-One, Duplicator, phpMyAdmin) into the
 * JSON-lines format Db_Importer reads. Resumable at statement boundaries; only
 * CREATE TABLE and INSERT/REPLACE are used, the rest is skipped.
 */
final class Sql_Converter {
  const READ = 1048576;

  const OPTIONS = ['siteurl', 'home', 'blogname', 'template', 'stylesheet', 'active_plugins', 'upload_url_path'];

  /**
   * State: pos (byte offset of the next statement), out_pos, table, info (captured options), prefix, tables, rows.
   */
  public static function run(array &$st, string $sql_file, string $out_file, float $deadline): bool {
    $st += ['pos' => 0, 'out_pos' => 0, 'table' => null, 'options_table' => null, 'info' => [], 'prefix' => null, 'tables' => 0, 'rows' => 0];

    $in = fopen($sql_file, 'rb');
    fseek($in, $st['pos']);
    $out = Job::open_at($out_file, $st['out_pos']);

    $buffer = '';
    $i = 0;
    $quote = null;
    $eof = false;
    $done = false;
    $handled = 0;

    try {
      while (true) {
        if ($i >= strlen($buffer)) {
          if ($eof) {
            if (trim($buffer) !== '') {
              self::statement($buffer, $st, $out);
            }

            $st['pos'] += strlen($buffer);
            $done = true;
            break;
          }

          // At least one statement per call, even with no time left.
          if ($handled > 0 && microtime(true) > $deadline && $quote === null && $i === 0) {
            break;
          }

          $chunk = fread($in, self::READ);

          if ($chunk === '' || $chunk === false) {
            $eof = true;
            continue;
          }

          $buffer .= $chunk;
        }

        $length = strlen($buffer);

        if ($quote !== null) {
          $j = $i + strcspn($buffer, $quote . '\\', $i);

          if ($j >= $length) {
            $i = $length;
          } elseif ($buffer[$j] === '\\') {
            $i = $j + 2;
          } else {
            $quote = null;
            $i = $j + 1;
          }

          continue;
        }

        $j = $i + strcspn($buffer, "'\"`;-#/", $i);

        if ($j >= $length) {
          $i = $length;
          continue;
        }

        $char = $buffer[$j];

        if ($char === "'" || $char === '"' || $char === '`') {
          $quote = $char;
          $i = $j + 1;
          continue;
        }

        if ($char === ';') {
          $statement = substr($buffer, 0, $j);
          $buffer = (string) substr($buffer, $j + 1);
          $st['pos'] += $j + 1;
          $i = 0;
          self::statement($statement, $st, $out);
          $st['out_pos'] = ftell($out);
          $handled++;

          if (microtime(true) > $deadline) {
            break;
          }

          continue;
        }

        // Comments: "-- ", "#", "/* */". Need a little lookahead; read more if the buffer ends here.
        if ($j + 2 >= $length && !$eof) {
          // Re-scan from $j once more data arrives.
          $chunk = fread($in, self::READ);

          if ($chunk === '' || $chunk === false) {
            $eof = true;
          } else {
            $buffer .= $chunk;
          }

          $i = $j;
          continue;
        }

        if ($char === '-' && ($buffer[$j + 1] ?? '') === '-' && strpos(" \t\r\n", $buffer[$j + 2] ?? "\n") !== false) {
          $i = self::skip_to($buffer, "\n", $j, $in, $eof);
          continue;
        }

        if ($char === '#') {
          $i = self::skip_to($buffer, "\n", $j, $in, $eof);
          continue;
        }

        if ($char === '/' && ($buffer[$j + 1] ?? '') === '*') {
          $i = self::skip_to($buffer, '*/', $j + 2, $in, $eof);
          continue;
        }

        $i = $j + 1;
      }
    } finally {
      fclose($in);
      $st['out_pos'] = ftell($out);
      fclose($out);
    }

    return $done;
  }

  /**
   * Position right after the next $needle, reading more of the file if needed.
   */
  private static function skip_to(string &$buffer, string $needle, int $from, $in, bool &$eof): int {
    while (true) {
      $pos = strpos($buffer, $needle, $from);

      if ($pos !== false) {
        return $pos + strlen($needle);
      }

      if ($eof) {
        return strlen($buffer);
      }

      $chunk = fread($in, self::READ);

      if ($chunk === '' || $chunk === false) {
        $eof = true;
      } else {
        $buffer .= $chunk;
      }
    }
  }

  private static function statement(string $sql, array &$st, $out): void {
    // Drop leading comments (including /*!40101 ... */ version comments).
    $sql = ltrim(preg_replace('~^(?:\s*(?:--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$)|/\*.*?\*/))*~s', '', $sql));

    if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([^`\s(]+)`?/i', $sql, $m)) {
      $create = 'CREATE TABLE `' . $m[1] . '`' . substr($sql, strlen($m[0]));
      fwrite($out, Db_Exporter::json(['t' => $m[1], 'create' => $create]) . "\n");
      $st['table'] = $m[1];
      $st['tables']++;

      return;
    }

    if (!preg_match('/^(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?([^`\s(]+)`?\s*(?:\([^)]*\)\s*)?VALUES\s*/i', $sql, $m)) {
      return;
    }

    $table = $m[1];

    if ($table !== $st['table']) {
      fwrite($out, Db_Exporter::json(['s' => $table]) . "\n");
      $st['table'] = $table;
    }

    $is_options = substr($table, -7) === 'options';

    self::tuples($sql, strlen($m[0]), function (array $row) use (&$st, $out, $table, $is_options) {
      fwrite($out, Db_Exporter::encode_row($row) . "\n");
      $st['rows']++;

      if ($is_options && isset($row[1], $row[2]) && in_array($row[1], self::OPTIONS, true)) {
        $st['info'][$row[1]] = $row[2];

        if ($row[1] === 'siteurl') {
          $st['prefix'] = substr($table, 0, -7);
        }
      }
    });
  }

  public static function tuples(string $s, int $p, callable $emit): void {
    $length = strlen($s);

    while (true) {
      $p += strspn($s, " \t\r\n,", $p);

      if ($p >= $length) {
        return;
      }

      if ($s[$p] !== '(') {
        throw new \RuntimeException(esc_html(__('The SQL dump could not be read.', 'winonweb-migrate')));
      }

      $p++;
      $row = [];

      while (true) {
        $p += strspn($s, " \t\r\n", $p);
        $char = $s[$p] ?? '';

        if ($char === "'") {
          $start = ++$p;

          while (true) {
            $j = $p + strcspn($s, "\\'", $p);

            if ($j >= $length) {
              throw new \RuntimeException(esc_html(__('The SQL dump could not be read.', 'winonweb-migrate')));
            }

            if ($s[$j] === '\\') {
              $p = $j + 2;
            } elseif (($s[$j + 1] ?? '') === "'") {
              $p = $j + 2;
            } else {
              break;
            }
          }

          $row[] = self::unescape(substr($s, $start, $j - $start));
          $p = $j + 1;
        } elseif (strncasecmp(substr($s, $p, 7), '_binary', 7) === 0) {
          $p += 7;
          continue;
        } elseif (($char === 'X' || $char === 'x') && ($s[$p + 1] ?? '') === "'") {
          $end = strpos($s, "'", $p + 2);
          $row[] = (string) hex2bin(substr($s, $p + 2, $end - $p - 2));
          $p = $end + 1;
        } elseif ($char === '0' && strtolower($s[$p + 1] ?? '') === 'x') {
          $j = $p + 2 + strspn($s, '0123456789abcdefABCDEF', $p + 2);
          $row[] = (string) hex2bin(substr($s, $p + 2, $j - $p - 2));
          $p = $j;
        } else {
          $j = $p + strcspn($s, ",) \t\r\n", $p);
          $token = substr($s, $p, $j - $p);
          $row[] = strcasecmp($token, 'NULL') === 0 ? null : $token;
          $p = $j;
        }

        $p += strspn($s, " \t\r\n", $p);
        $char = $s[$p] ?? '';

        if ($char === ',') {
          $p++;
          continue;
        }

        if ($char === ')') {
          $p++;
          break;
        }

        throw new \RuntimeException(esc_html(__('The SQL dump could not be read.', 'winonweb-migrate')));
      }

      $emit($row);
    }
  }

  private static function unescape(string $raw): string {
    if (strpos($raw, '\\') === false && strpos($raw, "''") === false) {
      return $raw;
    }

    return strtr($raw, [
      '\\0' => "\0",
      "\\'" => "'",
      '\\"' => '"',
      '\\b' => "\x08",
      '\\n' => "\n",
      '\\r' => "\r",
      '\\t' => "\t",
      '\\Z' => "\x1a",
      '\\\\' => '\\',
      "''" => "'",
    ]);
  }
}
