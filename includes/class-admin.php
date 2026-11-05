<?php

namespace WOWM;

defined('ABSPATH') || exit;

final class Admin {
  const PAGE = 'winonweb-migrate';
  const SITE = 'https://www.winonweb.dev/en/migrate';

  /**
   * Link to the plugin's page on winonweb.dev, tagged with where in the plugin it was clicked.
   */
  public static function site_url(string $medium, string $anchor = ''): string {
    return add_query_arg(['utm_source' => 'wowm-plugin', 'utm_medium' => $medium, 'utm_campaign' => 'winonweb-migrate'], self::SITE) . ($anchor ? '#' . $anchor : '');
  }

  const ICONS = [
    'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
    'uploads' => '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>',
    'themes' => '<circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2a10 10 0 0 0 0 20c.9 0 1.6-.7 1.6-1.7 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1a1.6 1.6 0 0 1 1.7-1.7h2a5.6 5.6 0 0 0 5.5-5.5C22 6 17.5 2 12 2Z"/>',
    'plugins' => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
    'muplugins' => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
    'other' => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.7-.9l-.8-1.2A2 2 0 0 0 7.9 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
    'export' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
    'import' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
    'backups' => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
    'schedule' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
    'restore' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
    'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    'check' => '<path d="M20 6 9 17l-5-5"/>',
    'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    'arrow' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
    'warning' => '<path d="m21.7 18-8-14a2 2 0 0 0-3.5 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    'error' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
    'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
    'shield' => '<path d="M20 13c0 5-3.5 7.5-7.7 9a1 1 0 0 1-.6 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.2-2.7a1.2 1.2 0 0 1 1.6 0C14.5 3.8 17 5 19 5a1 1 0 0 1 1 1Z"/><path d="m9 12 2 2 4-4"/>',
    'file' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
    'chevron' => '<path d="m6 9 6 6 6-6"/>',
    'login' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
    'help' => '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
    'tools' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.8-3.8a6 6 0 0 1-7.9 7.9l-6.9 6.9a2.1 2.1 0 0 1-3-3l6.9-6.9a6 6 0 0 1 7.9-7.9l-3.8 3.8Z"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'undo' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
    'layers' => '<path d="m12.8 2.2 8.6 3.9a1 1 0 0 1 0 1.8l-8.6 3.9a2 2 0 0 1-1.6 0L2.6 7.9a1 1 0 0 1 0-1.8l8.6-3.9a2 2 0 0 1 1.6 0Z"/><path d="m22 17.6-9.2 4.2a2 2 0 0 1-1.6 0L2 17.6"/><path d="m22 12.6-9.2 4.2a2 2 0 0 1-1.6 0L2 12.6"/>',
  ];

  public static function init(): void {
    add_action('admin_menu', [self::class, 'menu']);
    add_action('admin_enqueue_scripts', [self::class, 'assets']);
    add_filter('plugin_action_links_' . Plugin::basename(), function ($links) {
      array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '">' . esc_html__('Open', 'winonweb-migrate') . '</a>');

      return $links;
    });
    add_filter(
      'plugin_row_meta',
      function ($meta, $file) {
        if ($file === Plugin::basename()) {
          $meta[] = '<a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE . '#help')) . '">' . esc_html__('Guide', 'winonweb-migrate') . '</a>';
          $meta[] = '<a href="' . esc_url(self::site_url('plugins-list')) . '" target="_blank" rel="noopener">' . esc_html__('Migration help from WinOnWeb', 'winonweb-migrate') . '</a>';
        }

        return $meta;
      },
      10,
      2,
    );
  }

  public static function icon(string $name, int $size = 20): string {
    return sprintf('<svg class="wowm-icon" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%2$s</svg>', $size, self::ICONS[$name] ?? '');
  }

  public static function the_icon(string $name, int $size = 20): void {
    $shape = ['d' => true, 'cx' => true, 'cy' => true, 'r' => true, 'rx' => true, 'ry' => true, 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'points' => true, 'x1' => true, 'x2' => true, 'y1' => true, 'y2' => true];

    echo wp_kses(self::icon($name, $size), [
      'svg' => ['class' => true, 'width' => true, 'height' => true, 'viewbox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'aria-hidden' => true],
      'path' => $shape,
      'circle' => $shape,
      'ellipse' => $shape,
      'rect' => $shape,
      'line' => $shape,
      'polyline' => $shape,
    ]);
  }

  public static function menu(): void {
    $icon = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a7aaad" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>');

    add_menu_page(__('WinOnWeb Migrate', 'winonweb-migrate'), __('Migrate', 'winonweb-migrate'), 'manage_options', self::PAGE, [self::class, 'render'], $icon, 80);
  }

  public static function assets(string $hook): void {
    if ($hook !== 'toplevel_page_' . self::PAGE) {
      return;
    }

    // Before the page data is built, so an expired undo is not offered.
    Rollback::expire();

    $css = WOWM_DIR . 'assets/admin.css';
    $js = WOWM_DIR . 'assets/admin.js';

    wp_enqueue_style('wowm-admin', WOWM_URL . 'assets/admin.css', [], WOWM_VERSION . '.' . filemtime($css));
    wp_enqueue_script('wowm-admin', WOWM_URL . 'assets/admin.js', [], WOWM_VERSION . '.' . filemtime($js), true);

    $max_upload = wp_max_upload_size();

    wp_localize_script('wowm-admin', 'WOWM', [
      'ajax' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce(Ajax::NONCE),
      'chunk' => (int) max(256 * KB_IN_BYTES, min(8 * MB_IN_BYTES, $max_upload * 0.8)),
      'home' => untrailingslashit((string) get_option('home')),
      'login' => wp_login_url(admin_url('admin.php?page=' . self::PAGE)),
      'backups' => Ajax::backups_payload(),
      'schedule' => ['settings' => Scheduler::settings(), 'next' => Scheduler::next_run()],
      'rollback' => self::rollback_payload(),
      'lastImport' => Rollback::last_import(),
      'activeJob' => Scheduler::active(),
      'now' => time(),
      'icons' => array_map(function ($svg) {
        return '<svg class="wowm-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $svg . '</svg>';
      }, self::ICONS),
      'i18n' => [
        'calculating' => __('Calculating…', 'winonweb-migrate'),
        'convertTitle' => /* translators: %s: plugin the archive was made with, e.g. Duplicator */ __('Converting the %s archive', 'winonweb-migrate'),
        'checksTitle' => __('Server check', 'winonweb-migrate'),
        'rollbackTitle' => /* translators: %s: relative time, e.g. "2 hours ago" */ __('Import finished %s', 'winonweb-migrate'),
        'rollbackText' => __('The previous database is kept for 7 days, so this import can be undone. Is the site working as expected?', 'winonweb-migrate'),
        'rollbackKeep' => __('Everything works, remove the old database', 'winonweb-migrate'),
        'rollbackUndo' => __('Undo import', 'winonweb-migrate'),
        'confirmUndo' => __('Put the previous database back? The imported database is discarded. Files stay as they are (use the safety backup to restore them).', 'winonweb-migrate'),
        'undone' => __('The previous database is back. Log in again.', 'winonweb-migrate'),
        'kept' => __('Old database removed', 'winonweb-migrate'),
        'undoNote' => __('Not happy with the result? "Undo import" at the top of this page brings the previous database back.', 'winonweb-migrate'),
        'healthTitle' => __('Site check after import', 'winonweb-migrate'),
        'healthOk' => __('The site answers normally.', 'winonweb-migrate'),
        'healthBroken' => __('The site shows an error after this import.', 'winonweb-migrate'),
        /* translators: %s: countdown like 9:41 */
        'autoUndoIn' => __('The previous database comes back automatically in %s unless you keep the import.', 'winonweb-migrate'),
        'autoUndoOff' => __('Automatic undo is off: use "Undo import" or "Keep" below.', 'winonweb-migrate'),
        'keepAnyway' => __('Keep the import anyway', 'winonweb-migrate'),
        'undoNow' => __('Undo import now', 'winonweb-migrate'),
        'recheck' => __('Check again', 'winonweb-migrate'),
        'recoveryTitle' => __('If wp-admin stops opening', 'winonweb-migrate'),
        'recoveryText' => __('When a plugin or theme causes a critical error, WordPress emails the site administrator a recovery-mode link. Log in through it and click "Undo import". From the command line:', 'winonweb-migrate'),
        'recoveryCli' => 'wp wowm undo',
        'undoneTitle' => __('Import undone', 'winonweb-migrate'),
        'undoneText' => __('The login page showed a critical error right after the import, so the previous database was put back automatically. Fix the cause shown below (usually the PHP version or a plugin) and import again. Files from the archive stayed in place; the backup made before the import restores them.', 'winonweb-migrate'),
        'deactivatedTitle' => __('Deactivated after import', 'winonweb-migrate'),
        'deactivatedText' => __('Their files are not on this server or they belong to the previous host. Reinstall or re-enable them from Plugins if you need them.', 'winonweb-migrate'),
        'autoUndo' => __('If the site shows a critical error after the import, undo it automatically: at once when wp-admin cannot open, otherwise after 10 minutes (recommended)', 'winonweb-migrate'),
        'resumeTitle' => __('Continue the interrupted upload?', 'winonweb-migrate'),
        /* translators: 1: file name, 2: size already uploaded */
        'resumeText' => __('%1$s: %2$s already on the server. Pick the same file to continue from there.', 'winonweb-migrate'),
        'resumeDiscard' => __('Start over', 'winonweb-migrate'),
        /* translators: %s: size already uploaded */
        'resuming' => __('Resuming from %s…', 'winonweb-migrate'),
        'reattachTitle' => __('Still running in the background', 'winonweb-migrate'),
        'reattachText' => __('This job kept going while the page was closed. Reconnected to its progress.', 'winonweb-migrate'),
        'backgroundNote' => __('You can close this tab: the job continues on the server and this page reconnects when you come back.', 'winonweb-migrate'),
        'differential' => __('Changes only', 'winonweb-migrate'),
        'basedOn' => /* translators: %s: date of the full backup */ __('on top of the backup from %s', 'winonweb-migrate'),
        'confirmDeleteChain' => /* translators: %d: number of changes-only backups */ __('Delete this full backup? Its %d changes-only backups are deleted too, they cannot be restored without it.', 'winonweb-migrate'),
        'baseNone' => __('Everything (full backup)', 'winonweb-migrate'),
        'baseChanges' => /* translators: %s: date of the full backup */ __('Only files changed since %s', 'winonweb-migrate'),
        'scanNeedle' => __('Old address, e.g. old-domain.com', 'winonweb-migrate'),
        'scanning' => /* translators: %s: progress in percent */ __('Scanning… %s%%', 'winonweb-migrate'),
        'noHits' => __('Nothing found. The database is clean.', 'winonweb-migrate'),
        'hitsTitle' => /* translators: %s: number of rows */ __('Found in %s places', 'winonweb-migrate'),
        'table' => __('Table', 'winonweb-migrate'),
        'column' => __('Column', 'winonweb-migrate'),
        'matches' => __('Rows', 'winonweb-migrate'),
        'replaceTitle' => __('Replacing in the database', 'winonweb-migrate'),
        'replaceDone' => __('Replacement finished', 'winonweb-migrate'),
        'replaceResult' => /* translators: %s: number of rows */ __('%s rows updated.', 'winonweb-migrate'),
        'confirmReplace' => /* translators: 1: text to find, 2: replacement */ __('Replace "%1$s" with "%2$s" in the whole database? A database backup is made first.', 'winonweb-migrate'),
        'replaceYes' => __('Replace', 'winonweb-migrate'),
        'dbBackupTitle' => __('Backing up the database first', 'winonweb-migrate'),
        'changed' => __('Changed', 'winonweb-migrate'),
        'counting' => __('counting…', 'winonweb-migrate'),
        'excludeFolder' => __('Click to leave this out of the archive', 'winonweb-migrate'),
        'includeFolder' => __('Excluded. Click to include it again', 'winonweb-migrate'),
        'exportTitle' => __('Exporting your site', 'winonweb-migrate'),
        'importTitle' => __('Importing archive', 'winonweb-migrate'),
        'preBackupTitle' => __('Backing up this site first', 'winonweb-migrate'),
        'restoreTitle' => __('Restoring backup', 'winonweb-migrate'),
        'exportDone' => __('Your archive is ready', 'winonweb-migrate'),
        'importDone' => __('Import complete', 'winonweb-migrate'),
        'failed' => __('Something went wrong', 'winonweb-migrate'),
        'cancelled' => __('Cancelled', 'winonweb-migrate'),
        'cancel' => __('Cancel', 'winonweb-migrate'),
        'cancelling' => __('Cancelling…', 'winonweb-migrate'),
        'close' => __('Close', 'winonweb-migrate'),
        'download' => __('Download', 'winonweb-migrate'),
        'restore' => __('Restore', 'winonweb-migrate'),
        'delete' => __('Delete', 'winonweb-migrate'),
        'login' => __('Log in again', 'winonweb-migrate'),
        'viewSite' => __('View site', 'winonweb-migrate'),
        'loginNote' => __('Users and passwords now come from the imported site. Log in with an account from that site.', 'winonweb-migrate'),
        'elapsed' => __('Elapsed', 'winonweb-migrate'),
        'tables' => __('Tables', 'winonweb-migrate'),
        'rows' => __('Rows', 'winonweb-migrate'),
        'files' => __('Files', 'winonweb-migrate'),
        'packed' => __('Packed', 'winonweb-migrate'),
        'unpacked' => __('Unpacked', 'winonweb-migrate'),
        'archive' => __('Archive', 'winonweb-migrate'),
        'uploading' => /* translators: %s: file name */ __('Uploading %s', 'winonweb-migrate'),
        'checking' => __('Checking archive…', 'winonweb-migrate'),
        'badFile' => __('Choose a .wowm, All-in-One .wpress or Duplicator .zip file.', 'winonweb-migrate'),
        'badResponse' => __('The server returned an unexpected response.', 'winonweb-migrate'),
        'retrying' => __('Connection hiccup, retrying…', 'winonweb-migrate'),
        'timedOut' => __('The server did not answer in time.', 'winonweb-migrate'),
        /* translators: 1: error message, 2: attempt number, 3: number of attempts */
        'uploadRetry' => __('%1$s Retrying (%2$s of %3$s)…', 'winonweb-migrate'),
        'bigFileNote' => __('Large file: this can take a long time on shared hosting, and the bar may pause for a minute between pieces. It is still working, keep this tab open. For files of a few GB it is faster to SFTP the archive into wp-content/wowm-backups and use Restore from Backups.', 'winonweb-migrate'),
        /* translators: %s: piece size, e.g. 2 MB */
        'chunkNote' => __('This host limits the upload size, so the file is sent in %s pieces. It is slower but reliable: keep this tab open and wait. For very large files, SFTP into wp-content/wowm-backups and use Restore from Backups instead.', 'winonweb-migrate'),
        'confirmDelete' => __('Delete this backup permanently?', 'winonweb-migrate'),
        'deleted' => __('Backup deleted', 'winonweb-migrate'),
        'saved' => __('Schedule saved', 'winonweb-migrate'),
        'noBackups' => __('No backups yet. Create your first export, it takes a minute.', 'winonweb-migrate'),
        'nextRun' => /* translators: %s: date and time */ __('Next backup: %s', 'winonweb-migrate'),
        'scheduleOff' => __('Automatic backups are off.', 'winonweb-migrate'),
        'free' => /* translators: %s: size, e.g. 12 GB */ __('%s free on disk', 'winonweb-migrate'),
        'origin' => [
          'manual' => __('Manual', 'winonweb-migrate'),
          'scheduled' => __('Scheduled', 'winonweb-migrate'),
          'pre-import' => __('Before import', 'winonweb-migrate'),
          'uploaded' => __('Uploaded', 'winonweb-migrate'),
          'cli' => __('WP-CLI', 'winonweb-migrate'),
        ],
        'components' => [
          'database' => __('Database', 'winonweb-migrate'),
          'uploads' => __('Media', 'winonweb-migrate'),
          'themes' => __('Themes', 'winonweb-migrate'),
          'plugins' => __('Plugins', 'winonweb-migrate'),
          'muplugins' => __('MU plugins', 'winonweb-migrate'),
          'other' => __('Other', 'winonweb-migrate'),
        ],
        'from' => __('From', 'winonweb-migrate'),
        'to' => __('To', 'winonweb-migrate'),
        'created' => __('Created', 'winonweb-migrate'),
        'size' => __('Size', 'winonweb-migrate'),
        'wordpress' => __('WordPress', 'winonweb-migrate'),
        'php' => __('PHP', 'winonweb-migrate'),
        'theme' => __('Theme', 'winonweb-migrate'),
        'plugins' => __('Active plugins', 'winonweb-migrate'),
        'nothingSelected' => __('Select at least one thing to export.', 'winonweb-migrate'),
        'findPlaceholder' => __('Find, e.g. old-domain.com', 'winonweb-migrate'),
        'replacePlaceholder' => __('Replace with', 'winonweb-migrate'),
        'remove' => __('Remove', 'winonweb-migrate'),
        'readyToImport' => __('Ready to import', 'winonweb-migrate'),
        'understand' => /* translators: %s: site address */ __('I understand this replaces the content of %s', 'winonweb-migrate'),
        'importNow' => __('Import now', 'winonweb-migrate'),
        'importDb' => __('Import the database', 'winonweb-migrate'),
        'importFiles' => __('Import files', 'winonweb-migrate'),
        'keepDropins' => __('Keep this host\'s drop-ins (recommended)', 'winonweb-migrate'),
        'preBackup' => /* translators: %s: list of parts, e.g. Database, Themes */ __('First back up what gets replaced: %s (recommended)', 'winonweb-migrate'),
        'preBackupLabel' => /* translators: %s: address of the site the archive comes from */ __('Before importing %s', 'winonweb-migrate'),
        'extraReplace' => __('Extra find & replace', 'winonweb-migrate'),
        'addRule' => __('Add rule', 'winonweb-migrate'),
        'filesOnlyNote' => __('Files were imported. The database was not changed.', 'winonweb-migrate'),
      ],
    ]);
  }

  /**
   * Rollback state for the screen.
   */
  public static function rollback_payload(): ?array {
    $data = Rollback::get();

    if (!$data) {
      return null;
    }

    return $data;
  }

  public static function render(): void {
    Storage::cleanup();
    require WOWM_DIR . 'views/admin.php';
  }
}
