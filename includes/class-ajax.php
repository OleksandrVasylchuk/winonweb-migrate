<?php

namespace WOWM;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- archives are streamed in chunks (seek/append), which WP_Filesystem cannot do.

final class Ajax {
  const NONCE = 'wowm';

  public static function init(): void {
    foreach (['export_start', 'import_start', 'convert_start', 'replace_start', 'upload_start', 'upload_chunk', 'inspect', 'cancel', 'backups', 'delete', 'sizes', 'save_schedule', 'scan_links', 'rollback', 'health', 'active_job'] as $action) {
      add_action('wp_ajax_wowm_' . $action, [self::class, $action]);
    }

    // nopriv on purpose: after the DB swap the admin's cookie no longer validates.
    // Ticks are authenticated with the job secret.
    add_action('wp_ajax_wowm_tick', [self::class, 'tick']);
    add_action('wp_ajax_nopriv_wowm_tick', [self::class, 'tick']);
    add_action('wp_ajax_wowm_download', [self::class, 'download']);
  }

  private static function guard(): void {
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('You are not allowed to do this.', 'winonweb-migrate')], 403);
    }

    if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
      wp_send_json_error(['message' => __('Your session expired. Reload the page and try again.', 'winonweb-migrate')], 403);
    }
  }

  // Request input. Handlers call guard() first; tick() uses the job secret instead.

  private static function text(string $key): string {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard() / job secret in tick().
    return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
  }

  private static function number(string $key, int $default = 0): int {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
    return isset($_POST[$key]) ? (int) sanitize_text_field(wp_unslash($_POST[$key])) : $default;
  }

  /**
   * JSON options from the admin screen. Each field is sanitized by the job's own sanitize().
   */
  private static function input(string $key): array {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); decoded JSON, every field sanitized by the *_Job::sanitize() it is passed to.
    $value = isset($_POST[$key]) ? json_decode(wp_unslash($_POST[$key]), true) : null;

    return is_array($value) ? $value : [];
  }

  private static function budget(): float {
    $max = (int) ini_get('max_execution_time');
    $budget = $max > 0 ? max(5, min(20, $max - 10)) : 20;

    return (float) apply_filters('wowm_tick_budget', $budget);
  }

  private static function job(): Job {
    $job = Job::load(sanitize_key(self::text('job')));

    if (!$job || !$job->verify(preg_replace('/[^a-f0-9]/', '', self::text('secret')))) {
      wp_send_json_error(['message' => __('Unknown or expired job.', 'winonweb-migrate')], 404);
    }

    return $job;
  }

  private static function started(Job $job): void {
    Scheduler::watch($job, self::text('title'));
    wp_send_json_success(['job' => $job->id, 'secret' => $job->secret()] + $job->status());
  }

  public static function export_start(): void {
    self::guard();
    self::started(Job::create('export', Export_Job::sanitize(self::input('options'))));
  }

  public static function import_start(): void {
    self::guard();
    $options = Import_Job::sanitize(self::input('options'));

    if (!Storage::path($options['file'])) {
      wp_send_json_error(['message' => __('The archive was not found.', 'winonweb-migrate')]);
    }

    self::started(Job::create('import', $options));
  }

  public static function tick(): void {
    $job = self::job();
    $status = $job->tick(self::budget());
    Scheduler::rewatch($job);
    wp_send_json_success($status);
  }

  /**
   * A job still running from an earlier page view (tab closed, page reloaded).
   */
  public static function active_job(): void {
    self::guard();
    wp_send_json_success(['active' => Scheduler::active()]);
  }

  public static function health(): void {
    self::guard();
    $checks = Health::check();
    Rollback::update(['health' => $checks]);
    wp_send_json_success(['health' => $checks, 'broken' => Health::failed($checks)]);
  }

  public static function cancel(): void {
    self::guard();
    wp_send_json_success(self::job()->cancel());
  }

  public static function upload_start(): void {
    self::guard();

    $size = self::number('size');
    $free = Storage::free_space();

    if ($size <= 0) {
      wp_send_json_error(['message' => __('The file is empty.', 'winonweb-migrate')]);
    }

    if ($free !== null && $free < $size) {
      wp_send_json_error(['message' => sprintf(/* translators: 1: free space, 2: space needed */ __('Not enough disk space: %1$s free, %2$s needed.', 'winonweb-migrate'), size_format($free, 1), size_format($size, 1))]);
    }

    // Same file as an interrupted upload: carry on from where the server got to.
    $resume = sanitize_key(self::text('resume'));
    $part = $resume ? Storage::dir() . "/upload-$resume.part" : '';

    if ($resume && is_file($part)) {
      clearstatcache(true, $part);
      wp_send_json_success(['upload' => $resume, 'offset' => (int) filesize($part)]);
    }

    $id = strtolower(wp_generate_password(16, false));
    file_put_contents(Storage::dir() . "/upload-$id.part", '');

    wp_send_json_success(['upload' => $id, 'offset' => 0]);
  }

  public static function upload_chunk(): void {
    self::guard();

    $id = sanitize_key(self::text('upload'));
    $part = Storage::dir() . "/upload-$id.part";
    $offset = self::number('offset', -1);
    $total = self::number('total');
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); a PHP upload temp path, checked with is_uploaded_file() below.
    $chunk = isset($_FILES['chunk']['tmp_name']) ? (string) $_FILES['chunk']['tmp_name'] : '';

    if (!$id || !is_file($part) || !$chunk || !is_uploaded_file($chunk)) {
      wp_send_json_error(['message' => __('Upload failed. Try again.', 'winonweb-migrate')]);
    }

    clearstatcache(true, $part);
    $current = (int) filesize($part);

    // Resumable: the client re-sends from wherever the server actually is.
    if ($offset !== $current) {
      wp_send_json_success(['offset' => $current]);
    }

    $out = fopen($part, 'ab');
    $in = fopen($chunk, 'rb');
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);

    clearstatcache(true, $part);
    $current = (int) filesize($part);

    if ($current < $total) {
      wp_send_json_success(['offset' => $current]);
    }

    if (!Archive::is_archive($part)) {
      // All-in-One (.wpress) or Duplicator (.zip): keep it for conversion.
      $ext = strtolower(pathinfo(sanitize_file_name(self::text('name')), PATHINFO_EXTENSION));
      $file = in_array($ext, Storage::FOREIGN, true) ? Storage::new_archive_name('uploaded-', $ext) : '';
      $format = $file && rename($part, Storage::dir() . '/' . $file) ? Convert_Job::format_of(Storage::dir() . '/' . $file) : null;

      if (!$format) {
        @unlink($part);
        $file && @unlink(Storage::dir() . '/' . $file);
        wp_send_json_error(['message' => __('Unsupported archive. Use a .wowm file, an All-in-One .wpress file or a Duplicator .zip archive.', 'winonweb-migrate')]);
      }

      wp_send_json_success(['offset' => $current, 'convert' => $file, 'format' => Convert_Job::FORMATS[$format]]);
    }

    $file = Storage::new_archive_name('uploaded-');
    rename($part, Storage::dir() . '/' . $file);

    try {
      $info = Import_Job::inspect(Storage::dir() . '/' . $file);
    } catch (\Throwable $e) {
      Storage::delete($file);
      wp_send_json_error(['message' => wp_specialchars_decode($e->getMessage(), ENT_QUOTES)]);
    }

    Storage::write_meta(Storage::dir() . '/' . $file, [
      'created' => $info['created'],
      'origin' => 'uploaded',
      'label' => self::text('name'),
      'source' => $info['source']['home'],
      'components' => array_keys(array_filter($info['components'])),
    ]);

    wp_send_json_success(['offset' => $current, 'file' => $file, 'info' => $info]);
  }

  public static function inspect(): void {
    self::guard();
    $path = Storage::path(sanitize_file_name(self::text('file')));

    if (!$path) {
      wp_send_json_error(['message' => __('The archive was not found.', 'winonweb-migrate')]);
    }

    try {
      wp_send_json_success(Import_Job::inspect($path));
    } catch (\Throwable $e) {
      wp_send_json_error(['message' => wp_specialchars_decode($e->getMessage(), ENT_QUOTES)]);
    }
  }

  public static function backups(): void {
    self::guard();
    wp_send_json_success(self::backups_payload());
  }

  public static function backups_payload(): array {
    return [
      'items' => Storage::backups(),
      'free' => Storage::free_space(),
      'dir' => str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path(Storage::dir())),
    ];
  }

  public static function delete(): void {
    self::guard();
    $file = sanitize_file_name(self::text('file'));

    if (!Storage::delete($file)) {
      wp_send_json_error(['message' => __('Could not delete the backup.', 'winonweb-migrate')]);
    }

    // Changes-only backups cannot be restored without their full backup.
    foreach (Storage::backups() as $backup) {
      if ($backup['base'] === $file) {
        Storage::delete($backup['file']);
      }
    }

    wp_send_json_success(self::backups_payload());
  }

  public static function convert_start(): void {
    self::guard();
    $options = Convert_Job::sanitize(self::input('options'));

    if (!Storage::foreign_path($options['file'])) {
      wp_send_json_error(['message' => __('The archive was not found.', 'winonweb-migrate')]);
    }

    self::started(Job::create('convert', $options));
  }

  public static function replace_start(): void {
    self::guard();
    self::started(Job::create('replace', Replace_Job::sanitize(self::input('options'))));
  }

  public static function scan_links(): void {
    self::guard();

    $needle = trim(self::text('needle'));

    if (strlen($needle) < 4) {
      wp_send_json_error(['message' => __('Enter at least 4 characters to search for.', 'winonweb-migrate')]);
    }

    $key = 'wowm_scan_' . get_current_user_id();
    $state = self::number('restart') ? false : get_transient($key);
    $state = Links::scan(is_array($state) ? $state : [], $needle, 4);
    $done = $state['ti'] >= count($state['tables']);

    $done ? delete_transient($key) : set_transient($key, $state, 10 * MINUTE_IN_SECONDS);

    wp_send_json_success([
      'done' => $done,
      'progress' => count($state['tables']) ? round(($state['ti'] / count($state['tables'])) * 100) : 100,
      'hits' => $state['hits'],
    ]);
  }

  public static function rollback(): void {
    self::guard();

    try {
      if (self::text('mode') === 'undo') {
        Rollback::undo();
      } elseif (self::text('mode') === 'keep_pending') {
        // "Keep anyway" while the countdown runs: stop the automatic undo, keep the old DB a while longer.
        Rollback::cancel_auto_undo();
      } else {
        Rollback::clear();
      }
    } catch (\Throwable $e) {
      wp_send_json_error(['message' => wp_specialchars_decode($e->getMessage(), ENT_QUOTES)]);
    }

    wp_send_json_success(['login' => wp_login_url(admin_url('admin.php?page=' . Admin::PAGE))]);
  }

  public static function sizes(): void {
    self::guard();

    // Measured over several calls; the walk state lives in a short-lived transient.
    $key = 'wowm_sizes_' . get_current_user_id();
    $state = self::number('restart') ? false : get_transient($key);
    $state = Scanner::measure(is_array($state) ? $state : [], 4);
    $result = Scanner::summary($state);

    $result['done'] ? delete_transient($key) : set_transient($key, $state, 10 * MINUTE_IN_SECONDS);
    $result['sizes']['database'] = Db_Exporter::size();

    wp_send_json_success($result);
  }

  public static function save_schedule(): void {
    self::guard();
    $settings = Scheduler::save(self::input('settings'));
    wp_send_json_success(['settings' => $settings, 'next' => Scheduler::next_run()]);
  }

  public static function download(): void {
    $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';

    if (!current_user_can('manage_options') || !wp_verify_nonce($nonce, self::NONCE)) {
      wp_die(esc_html__('You are not allowed to do this.', 'winonweb-migrate'), 403);
    }

    $path = Storage::path(sanitize_file_name(isset($_GET['file']) ? sanitize_text_field(wp_unslash($_GET['file'])) : ''));

    if (!$path) {
      wp_die(esc_html__('The archive was not found.', 'winonweb-migrate'), 404);
    }

    while (ob_get_level()) {
      ob_end_clean();
    }

    // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- streaming a multi-GB archive must not be cut off by max_execution_time.
    @set_time_limit(0);
    nocache_headers();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));

    $fh = fopen($path, 'rb');

    while (!feof($fh)) {
      echo fread($fh, Job::CHUNK); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw archive bytes of a file download.
      flush();
    }

    fclose($fh);
    exit();
  }
}
