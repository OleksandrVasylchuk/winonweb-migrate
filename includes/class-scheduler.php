<?php

namespace WOWM;

defined('ABSPATH') || exit;

/**
 * Scheduled backups run through WP-Cron. A backup that does not fit into one
 * cron request continues in a follow-up single event.
 */
final class Scheduler {
  const OPTION = 'wowm_settings';
  const HOOK = 'wowm_scheduled_backup';
  const CONTINUE_HOOK = 'wowm_continue_job';
  const FREQUENCIES = ['off', 'daily', 'weekly', 'monthly'];

  const ACTIVE = 'wowm_active_job';
  const WATCH_DELAY = 90;

  public static function init(): void {
    add_action(self::HOOK, [self::class, 'run']);
    add_action(self::CONTINUE_HOOK, [self::class, 'continue_job']);
    add_action(Rollback::AUTO_HOOK, [Rollback::class, 'run_auto_undo']);
    add_filter('cron_schedules', function ($schedules) {
      $schedules['monthly'] = $schedules['monthly'] ?? ['interval' => MONTH_IN_SECONDS, 'display' => __('Once Monthly', 'winonweb-migrate')];

      return $schedules;
    });
  }

  public static function settings(): array {
    $saved = get_option(self::OPTION, []);
    $saved = is_array($saved) ? $saved : [];

    return array_replace_recursive(
      [
        'frequency' => 'off',
        'hour' => 3,
        'retention' => 5,
        'full_every' => 7,
        'components' => Export_Job::DEFAULTS['components'],
      ],
      $saved,
    );
  }

  public static function save(array $in): array {
    $settings = [
      'frequency' => in_array($in['frequency'] ?? '', self::FREQUENCIES, true) ? $in['frequency'] : 'off',
      'hour' => max(0, min(23, (int) ($in['hour'] ?? 3))),
      'retention' => max(1, min(100, (int) ($in['retention'] ?? 5))),
      'full_every' => max(1, min(60, (int) ($in['full_every'] ?? 7))),
      'components' => Export_Job::sanitize(['components' => $in['components'] ?? []])['components'],
    ];

    update_option(self::OPTION, $settings, false);
    self::reschedule();

    return $settings;
  }

  public static function reschedule(): void {
    wp_clear_scheduled_hook(self::HOOK);
    $settings = self::settings();

    if ($settings['frequency'] === 'off') {
      return;
    }

    $next = new \DateTime('today', wp_timezone());
    $next->setTime($settings['hour'], 0);

    if ($next->getTimestamp() <= time()) {
      $next->modify('+1 day');
    }

    wp_schedule_event($next->getTimestamp(), $settings['frequency'], self::HOOK);
  }

  public static function unschedule(): void {
    wp_clear_scheduled_hook(self::HOOK);
    wp_clear_scheduled_hook(self::CONTINUE_HOOK);
  }

  public static function next_run(): ?int {
    $next = wp_next_scheduled(self::HOOK);

    return $next ? (int) $next : null;
  }

  public static function run(): void {
    $settings = self::settings();

    $job = Job::create(
      'export',
      Export_Job::sanitize([
        'components' => $settings['components'],
        'origin' => 'scheduled',
        'exclude_transients' => true,
        'exclude_spam' => true,
        'exclude_dropins' => true,
        'base' => self::next_base($settings['full_every']),
      ]),
    );

    self::drive($job);
  }

  /**
   * A full backup every $full_every runs; in between, only files changed since
   * the latest scheduled full backup. Empty string means "make a full one".
   */
  public static function next_base(int $full_every): string {
    if ($full_every <= 1) {
      return '';
    }

    $scheduled = array_values(
      array_filter(Storage::backups(), function ($backup) {
        return $backup['origin'] === 'scheduled';
      }),
    );

    foreach ($scheduled as $backup) {
      if ($backup['kind'] === 'full') {
        $diffs = count(
          array_filter($scheduled, function ($other) use ($backup) {
            return $other['base'] === $backup['file'];
          }),
        );

        return $backup['has_index'] && $diffs + 1 < $full_every ? $backup['file'] : '';
      }
    }

    return '';
  }

  public static function continue_job($id): void {
    $job = Job::load((string) $id);

    if ($job) {
      self::drive($job);
    }
  }

  /*
   * Jobs started from the admin screen are driven by the browser. If the tab is
   * closed, this fallback picks the job up through WP-Cron, so an import never
   * stays half done. Every tick from the browser pushes the fallback further out.
   */

  public static function watch(Job $job, string $title = ''): void {
    update_option(self::ACTIVE, ['id' => $job->id, 'secret' => $job->secret(), 'type' => $job->state['type'], 'title' => $title, 'started' => time()], false);
    self::rewatch($job);
  }

  public static function rewatch(Job $job): void {
    wp_clear_scheduled_hook(self::CONTINUE_HOOK, [$job->id]);

    if ($job->state['status'] === 'running') {
      wp_schedule_single_event(time() + self::WATCH_DELAY, self::CONTINUE_HOOK, [$job->id]);
    } else {
      self::unwatch($job);
    }
  }

  public static function unwatch(Job $job): void {
    $active = get_option(self::ACTIVE);

    if (is_array($active) && ($active['id'] ?? '') === $job->id) {
      delete_option(self::ACTIVE);
    }
  }

  /**
   * The job the admin screen should reattach to, if one is still running.
   */
  public static function active(): ?array {
    $active = get_option(self::ACTIVE);

    if (!is_array($active) || empty($active['id'])) {
      return null;
    }

    $job = Job::load($active['id']);

    if (!$job || $job->state['status'] !== 'running') {
      delete_option(self::ACTIVE);

      return null;
    }

    return $active + ['status' => $job->status()];
  }

  private static function drive(Job $job): void {
    $deadline = microtime(true) + 40;

    while (microtime(true) < $deadline) {
      $status = $job->tick(min(20, $deadline - microtime(true)));

      if ($status['status'] !== 'running' || !empty($status['busy'])) {
        break;
      }
    }

    if ($job->state['status'] === 'running') {
      wp_schedule_single_event(time() + 10, self::CONTINUE_HOOK, [$job->id]);
    } else {
      self::unwatch($job);
    }
  }

  /**
   * Keep the newest N full backups together with their changes-only backups;
   * a changes-only backup is useless without its full backup, so they go together.
   */
  public static function enforce_retention(): void {
    $keep = self::settings()['retention'];
    $scheduled = array_filter(Storage::backups(), function ($backup) {
      return $backup['origin'] === 'scheduled';
    });

    $fulls = array_column(
      array_filter($scheduled, function ($backup) {
        return $backup['kind'] === 'full';
      }),
      'file',
    );
    $kept = array_slice($fulls, 0, $keep);

    foreach ($scheduled as $backup) {
      $chain = $backup['kind'] === 'full' ? $backup['file'] : $backup['base'];

      if (!in_array($chain, $kept, true)) {
        Storage::delete($backup['file']);
      }
    }
  }
}
