<?php

namespace WOWM;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- archives are streamed in chunks (seek/append), which WP_Filesystem cannot do.

/**
 * Backups and migrations from the command line.
 */
final class Cli {
  /**
   * Export the site to a .wowm archive.
   *
   * ## OPTIONS
   *
   * [--skip=<components>]
   * : Comma separated: database, themes, plugins, muplugins, uploads, other.
   *
   * [--exclude=<patterns>]
   * : Comma separated exclusion patterns ("/x" anchored to wp-content).
   *
   * [--keep-transients]
   * : Export transients too.
   *
   * ## EXAMPLES
   *
   *     wp wowm export --skip=uploads
   */
  public function export($args, $assoc) {
    $components = Export_Job::DEFAULTS['components'];

    foreach (array_filter(explode(',', $assoc['skip'] ?? '')) as $skip) {
      $components[trim($skip)] = false;
    }

    $job = Job::create(
      'export',
      Export_Job::sanitize([
        'components' => $components,
        'excludes' => explode(',', $assoc['exclude'] ?? ''),
        'exclude_transients' => empty($assoc['keep-transients']),
        'exclude_spam' => true,
        'exclude_dropins' => true,
        'origin' => 'cli',
      ]),
    );

    $status = $this->drive($job, 'Exporting');
    \WP_CLI::success(sprintf('Archive: %s (%s)', Storage::dir() . '/' . $status['result']['file'], size_format($status['result']['size'], 1)));
  }

  /**
   * Import a .wowm archive into this site.
   *
   * ## OPTIONS
   *
   * <file>
   * : Path to the archive, or the name of a backup in the backups folder.
   *
   * [--skip-db]
   * : Import files only.
   *
   * [--skip-files]
   * : Import the database only.
   *
   * [--replace-dropins]
   * : Overwrite this host's object-cache.php / advanced-cache.php / db.php.
   *
   * [--yes]
   * : Do not ask for confirmation.
   */
  public function import($args, $assoc) {
    $file = $args[0];

    if (!Storage::path($file)) {
      if (!is_file($file)) {
        \WP_CLI::error('File not found: ' . $file);
      }

      $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

      if (Archive::is_archive($file)) {
        $name = Storage::new_archive_name('uploaded-');
        copy($file, Storage::dir() . '/' . $name);
        $file = $name;
      } elseif (in_array($ext, Storage::FOREIGN, true)) {
        // All-in-One / Duplicator: convert to .wowm first.
        $name = Storage::new_archive_name('uploaded-', $ext);
        copy($file, Storage::dir() . '/' . $name);

        if (!Convert_Job::format_of(Storage::dir() . '/' . $name)) {
          @unlink(Storage::dir() . '/' . $name);
          \WP_CLI::error('Unsupported archive: ' . $file);
        }

        $status = $this->drive(Job::create('convert', ['file' => $name]), 'Converting');
        $file = $status['result']['file'];
      } else {
        \WP_CLI::error('Not a .wowm, .wpress or Duplicator .zip archive: ' . $file);
      }
    }

    $info = Import_Job::inspect(Storage::path($file));

    foreach ($info['notes'] as $note) {
      \WP_CLI::log(strtoupper($note['level']) . ': ' . $note['text']);
    }

    if ($info['blocked']) {
      \WP_CLI::error('The archive cannot be imported.');
    }

    \WP_CLI::confirm(sprintf('Overwrite %s with %s?', $info['target']['home'], $info['source']['home']), $assoc);

    $job = Job::create(
      'import',
      Import_Job::sanitize([
        'file' => basename($file),
        'import_db' => empty($assoc['skip-db']),
        'import_files' => empty($assoc['skip-files']),
        'keep_dropins' => empty($assoc['replace-dropins']),
      ]),
    );

    $this->drive($job, 'Importing');
    \WP_CLI::success('Import complete.');
  }

  /**
   * List backups.
   *
   * @subcommand list
   */
  public function list_($args, $assoc) {
    $rows = array_map(function ($backup) {
      return [
        'file' => $backup['file'],
        'size' => size_format($backup['size'], 1),
        'created' => wp_date('Y-m-d H:i', $backup['created']),
        'origin' => $backup['origin'],
      ];
    }, Storage::backups());

    \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['file', 'size', 'created', 'origin']);
  }

  /**
   * Put the database from before the last import back.
   *
   * When a plugin or theme from the import breaks the site, skip it while running this:
   *
   *     wp wowm undo --skip-themes --skip-plugins=broken-plugin
   */
  public function undo($args, $assoc) {
    try {
      Rollback::undo();
    } catch (\Throwable $e) {
      \WP_CLI::error(wp_specialchars_decode($e->getMessage(), ENT_QUOTES));
    }

    \WP_CLI::success('The previous database is back.');
  }

  /**
   * Keep the last import: remove the previous database that was kept for undo.
   */
  public function keep($args, $assoc) {
    Rollback::clear();
    \WP_CLI::success('The previous database was removed.');
  }

  private function drive(Job $job, string $label): array {
    $bar = \WP_CLI\Utils\make_progress_bar($label, 100);
    $shown = 0;

    do {
      $status = $job->tick(60);
      $now = (int) floor($status['progress']);
      $now > $shown && $bar->tick($now - $shown);
      $shown = max($shown, $now);
    } while ($status['status'] === 'running');

    $bar->finish();

    if ($status['status'] !== 'done') {
      \WP_CLI::error($status['error'] ?: $status['status']);
    }

    return $status;
  }
}
