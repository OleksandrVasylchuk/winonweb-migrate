<?php

namespace WOWM;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- archives are streamed in chunks (seek/append), which WP_Filesystem cannot do.

/**
 * Resumable multi-step job. Each tick runs until its time budget is used and saves
 * state; files are written at recorded offsets so a killed tick can be replayed.
 */
abstract class Job {
  const CHUNK = 1048576;

  public $id;
  public $state;

  /** The secret itself is only known to the request that created the job; the state file holds its hash. */
  public $issued_secret = '';

  /**
   * @return array step key => [label, weight]
   */
  abstract protected function steps(): array;

  abstract protected function cleanup(bool $success): void;

  protected function cancellable(): bool {
    return true;
  }

  const TYPES = [
    'export' => Export_Job::class,
    'import' => Import_Job::class,
    'convert' => Convert_Job::class,
    'replace' => Replace_Job::class,
  ];

  public static function create(string $type, array $options): self {
    $id = gmdate('Ymd-His') . '-' . strtolower(wp_generate_password(8, false));
    $class = self::TYPES[$type];

    $secret = bin2hex(random_bytes(16));
    $job = new $class($id, [
      'type' => $type,
      'secret_hash' => hash('sha256', $secret),
      'status' => 'running',
      'step' => 0,
      'options' => $options,
      'progress' => [],
      'skipped' => [],
      'stats' => [],
      'message' => '',
      'error' => null,
      'result' => null,
      'log' => [],
      'created' => time(),
      'finished' => null,
      'data' => [],
    ]);

    wp_mkdir_p($job->dir());
    $job->save();
    $job->issued_secret = $secret;

    return $job;
  }

  public static function load(string $id): ?self {
    if (!preg_match('/^\d{8}-\d{6}-[a-z0-9]{8}$/', $id)) {
      return null;
    }

    $file = Storage::jobs_dir() . "/$id/state.json";
    $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    if (!is_array($state)) {
      return null;
    }

    $class = self::TYPES[$state['type']] ?? null;

    return $class ? new $class($id, $state) : null;
  }

  protected function __construct(string $id, array $state) {
    $this->id = $id;
    $this->state = $state;
  }

  public function dir(): string {
    return Storage::jobs_dir() . '/' . $this->id;
  }

  public function secret(): string {
    return $this->issued_secret;
  }

  public function verify(string $secret): bool {
    if ($secret === '') {
      return false;
    }

    if (isset($this->state['secret_hash'])) {
      return hash_equals($this->state['secret_hash'], hash('sha256', $secret));
    }

    // Jobs started before 0.4.1 kept the secret itself.
    return isset($this->state['secret']) && hash_equals((string) $this->state['secret'], $secret);
  }

  public function save(): void {
    $file = $this->dir() . '/state.json';
    file_put_contents($file . '.tmp', wp_json_encode($this->state));
    @rename($file . '.tmp', $file);
  }

  public function tick(float $budget): array {
    $lock = fopen($this->dir() . '/lock', 'c');

    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
      $lock && fclose($lock);

      return $this->status() + ['busy' => true];
    }

    try {
      if ($this->state['status'] === 'running') {
        @set_time_limit((int) ceil($budget) + 60); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        ignore_user_abort(true);

        $deadline = microtime(true) + $budget;
        $keys = array_keys($this->steps());

        try {
          while ($this->state['status'] === 'running') {
            if (file_exists($this->dir() . '/cancel') && $this->cancellable()) {
              $this->finish('cancelled');
              break;
            }

            $key = $keys[$this->state['step']] ?? null;

            if ($key === null) {
              $this->finish('done');
              break;
            }

            if ($this->{'step_' . $key}($deadline)) {
              $this->state['progress'][$key] = 1;
              $this->state['step']++;
              $this->save();
            }

            if (microtime(true) >= $deadline) {
              break;
            }
          }
        } catch (\Throwable $e) {
          $message = wp_specialchars_decode($e->getMessage(), ENT_QUOTES);
          $this->log($message);
          $this->state['error'] = $message;
          $this->finish('failed');
        }

        $this->save();
      }
    } finally {
      flock($lock, LOCK_UN);
      fclose($lock);
    }

    return $this->status();
  }

  public function cancel(): array {
    if ($this->state['status'] === 'running' && $this->cancellable()) {
      touch($this->dir() . '/cancel');

      return $this->tick(0.01);
    }

    return $this->status();
  }

  private function finish(string $status): void {
    $this->state['status'] = $status;
    $this->state['finished'] = time();

    try {
      $this->cleanup($status === 'done');
    } catch (\Throwable $e) {
      $this->log(wp_specialchars_decode($e->getMessage(), ENT_QUOTES));
    }

    if ($status === 'done') {
      $this->state['message'] = '';
    }
  }

  public function status(): array {
    $steps = [];
    $total = 0;
    $done = 0;
    $index = 0;

    foreach ($this->steps() as $key => [$label, $weight]) {
      $current = $index === $this->state['step'] && $this->state['status'] === 'running';

      if ($index < $this->state['step']) {
        $status = empty($this->state['skipped'][$key]) ? 'done' : 'skipped';
      } elseif ($current) {
        $status = 'active';
      } else {
        $status = $this->state['status'] === 'failed' && $index === $this->state['step'] ? 'failed' : 'pending';
      }

      $total += $weight;
      $done += $weight * ($index < $this->state['step'] ? 1 : (float) ($this->state['progress'][$key] ?? 0));
      $steps[] = ['key' => $key, 'label' => $label, 'status' => $status];
      $index++;
    }

    return [
      'id' => $this->id,
      'type' => $this->state['type'],
      'status' => $this->state['status'],
      'progress' => $this->state['status'] === 'done' ? 100 : ($total ? round($done / $total * 100, 1) : 0),
      'steps' => $steps,
      'message' => $this->state['message'],
      'stats' => $this->state['stats'],
      'error' => $this->state['error'],
      'result' => $this->state['result'],
      'elapsed' => ($this->state['finished'] ?: time()) - $this->state['created'],
      'log' => array_slice($this->state['log'], -30),
      'cancellable' => $this->state['status'] === 'running' && $this->cancellable(),
    ];
  }

  protected function progress(string $key, float $value, string $message = ''): void {
    $this->state['progress'][$key] = max(0, min(1, $value));

    if ($message !== '') {
      $this->state['message'] = $message;
    }
  }

  protected function skip(string $key): bool {
    $this->state['skipped'][$key] = true;

    return true;
  }

  protected function log(string $message): void {
    $this->state['log'][] = [time(), $message];
    $this->state['log'] = array_slice($this->state['log'], -100);
    @file_put_contents($this->dir() . '/log.txt', gmdate('c') . ' ' . $message . "\n", FILE_APPEND);
  }

  protected function opt(string $key, $default = null) {
    return $this->state['options'][$key] ?? $default;
  }

  protected function stat(string $key, $value): void {
    $this->state['stats'][$key] = $value;
  }

  /**
   * Open a file for appending at a known-good position, discarding anything a
   * killed tick wrote after the last saved state.
   *
   * @return resource
   */
  public static function open_at(string $path, int $pos) {
    $fh = fopen($path, 'cb');

    if (!$fh) {
      throw new \RuntimeException(esc_html(sprintf(/* translators: %s: file path */ __('Could not write %s', 'winonweb-migrate'), $path)));
    }

    ftruncate($fh, $pos);
    fseek($fh, 0, SEEK_END);

    return $fh;
  }
}
