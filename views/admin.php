<?php

use WOWM\Admin;

defined('ABSPATH') || exit;

$wowm_tiles = [
  'database' => [__('Database', 'winonweb-migrate'), __('Posts, pages, settings, users', 'winonweb-migrate')],
  'uploads' => [__('Media', 'winonweb-migrate'), __('Everything in the uploads folder', 'winonweb-migrate')],
  'themes' => [__('Themes', 'winonweb-migrate'), __('Installed themes', 'winonweb-migrate')],
  'plugins' => [__('Plugins', 'winonweb-migrate'), __('Installed plugins', 'winonweb-migrate')],
  'muplugins' => [__('MU plugins', 'winonweb-migrate'), __('Must-use plugins', 'winonweb-migrate')],
  'other' => [__('Other files', 'winonweb-migrate'), __('Everything else in wp-content', 'winonweb-migrate')],
];
$wowm_home = untrailingslashit((string) get_option('home'));
?>
<div class="wrap wowm" data-wowm>
  <h1 class="screen-reader-text"><?php esc_html_e('WinOnWeb Migrate', 'winonweb-migrate'); ?></h1>

  <header class="wowm-hero">
    <div class="wowm-hero__brand">
      <span class="wowm-logo" aria-hidden="true">
        <svg viewBox="0 0 32 32" width="32" height="32"><path d="M6 9l4.2 14L16 12l5.8 11L26 9" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </span>
      <div>
        <p class="wowm-hero__title"><?php esc_html_e('WinOnWeb Migrate', 'winonweb-migrate'); ?></p>
        <p class="wowm-hero__tagline"><?php esc_html_e('Backups and migrations. No size limits, no upsells.', 'winonweb-migrate'); ?></p>
      </div>
    </div>
    <div class="wowm-hero__aside">
      <button type="button" class="wowm-hero__site wowm-hero__help" data-goto="help"><?php Admin::the_icon('help', 16); ?><span><?php esc_html_e('Guide', 'winonweb-migrate'); ?></span></button>
      <div class="wowm-hero__site">
        <?php Admin::the_icon('globe', 16); ?>
        <span><?php echo esc_html(preg_replace('#^https?://#', '', $wowm_home)); ?></span>
      </div>
    </div>
  </header>

  <nav class="wowm-tabs" role="tablist" aria-label="<?php esc_attr_e('Sections', 'winonweb-migrate'); ?>">
    <button type="button" class="wowm-tab" role="tab" data-tab="export" aria-selected="true"><?php Admin::the_icon('export', 18); ?><?php esc_html_e('Export', 'winonweb-migrate'); ?></button>
    <button type="button" class="wowm-tab" role="tab" data-tab="import" aria-selected="false"><?php Admin::the_icon('import', 18); ?><?php esc_html_e('Import', 'winonweb-migrate'); ?></button>
    <button type="button" class="wowm-tab" role="tab" data-tab="backups" aria-selected="false"><?php Admin::the_icon('backups', 18); ?><?php esc_html_e('Backups', 'winonweb-migrate'); ?><span class="wowm-count" data-backups-count></span></button>
    <button type="button" class="wowm-tab" role="tab" data-tab="schedule" aria-selected="false"><?php Admin::the_icon('schedule', 18); ?><?php esc_html_e('Schedule', 'winonweb-migrate'); ?></button>
    <button type="button" class="wowm-tab" role="tab" data-tab="tools" aria-selected="false"><?php Admin::the_icon('tools', 18); ?><?php esc_html_e('Tools', 'winonweb-migrate'); ?></button>
    <button type="button" class="wowm-tab" role="tab" data-tab="help" aria-selected="false"><?php Admin::the_icon('help', 18); ?><?php esc_html_e('Help', 'winonweb-migrate'); ?></button>
  </nav>

  <div class="wowm-banner" data-rollback hidden></div>

  <!-- Export -->
  <section class="wowm-panel" role="tabpanel" data-panel="export">
    <div class="wowm-card">
      <div class="wowm-card__head">
        <h2><?php esc_html_e('What goes into the archive', 'winonweb-migrate'); ?></h2>
        <p><?php esc_html_e('One .wowm file you can download, keep as a backup, or import on any other WordPress site.', 'winonweb-migrate'); ?></p>
      </div>

      <div class="wowm-tiles" data-components>
        <?php foreach ($wowm_tiles as $wowm_key => [$wowm_title, $wowm_desc]): ?>
          <label class="wowm-tile<?php echo $wowm_key === 'other' ? ' wowm-tile--other' : ''; ?>">
            <input type="checkbox" value="<?php echo esc_attr($wowm_key); ?>" checked>
            <span class="wowm-tile__icon"><?php Admin::the_icon($wowm_key, 22); ?></span>
            <span class="wowm-tile__body">
              <span class="wowm-tile__title"><?php echo esc_html($wowm_title); ?></span>
              <span class="wowm-tile__desc"><?php echo esc_html($wowm_desc); ?></span>
              <span class="wowm-tile__size" data-size="<?php echo esc_attr($wowm_key); ?>"><?php esc_html_e('Calculating…', 'winonweb-migrate'); ?></span>
              <?php if ($wowm_key === 'other'): ?>
                <span class="wowm-folders" data-folders></span>
              <?php endif; ?>
            </span>
            <span class="wowm-tile__check"><?php Admin::the_icon('check', 14); ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <details class="wowm-advanced">
        <summary><?php Admin::the_icon('chevron', 16); ?><?php esc_html_e('Advanced options', 'winonweb-migrate'); ?></summary>

        <div class="wowm-advanced__grid">
          <fieldset class="wowm-fieldset">
            <legend><?php esc_html_e('Database', 'winonweb-migrate'); ?></legend>
            <label class="wowm-switch"><input type="checkbox" data-opt="exclude_transients" checked><span class="wowm-switch__track"></span><span><?php esc_html_e('Skip transients (temporary cache in the database)', 'winonweb-migrate'); ?></span></label>
            <label class="wowm-switch"><input type="checkbox" data-opt="exclude_spam" checked><span class="wowm-switch__track"></span><span><?php esc_html_e('Skip spam comments', 'winonweb-migrate'); ?></span></label>
            <label class="wowm-switch"><input type="checkbox" data-opt="exclude_revisions"><span class="wowm-switch__track"></span><span><?php esc_html_e('Skip post revisions', 'winonweb-migrate'); ?></span></label>
          </fieldset>

          <fieldset class="wowm-fieldset">
            <legend><?php esc_html_e('Files', 'winonweb-migrate'); ?></legend>
            <label class="wowm-switch"><input type="checkbox" data-opt="exclude_dropins" checked><span class="wowm-switch__track"></span><span><?php esc_html_e('Skip host drop-ins (object-cache.php, advanced-cache.php, db.php)', 'winonweb-migrate'); ?></span></label>
            <label class="wowm-field">
              <span><?php esc_html_e('Files to include', 'winonweb-migrate'); ?></span>
              <select data-opt="base"></select>
              <small><?php esc_html_e('A changes-only backup is small and fast; restoring it brings back its full backup plus the changes.', 'winonweb-migrate'); ?></small>
            </label>
            <label class="wowm-field">
              <span><?php esc_html_e('Exclude paths', 'winonweb-migrate'); ?></span>
              <textarea rows="4" data-opt="excludes" spellcheck="false" placeholder="/uploads/2019&#10;/plugins-old&#10;*.zip"></textarea>
              <small><?php esc_html_e('One per line. Start with / to anchor to wp-content, otherwise it matches a name at any depth. Caches, backups of other plugins and leftover copies (*-old, *-backup, *.bak) are always skipped.', 'winonweb-migrate'); ?></small>
            </label>
          </fieldset>
        </div>

        <fieldset class="wowm-fieldset">
          <legend><?php esc_html_e('Extra find & replace', 'winonweb-migrate'); ?></legend>
          <p class="wowm-muted"><?php esc_html_e('Site URL and paths are rewritten automatically on import. Add anything else here, such as an old domain in content or an email address.', 'winonweb-migrate'); ?></p>
          <div class="wowm-pairs" data-pairs="export"></div>
          <button type="button" class="wowm-btn wowm-btn--ghost wowm-btn--sm" data-add-pair="export"><?php Admin::the_icon('plus', 16); ?><?php esc_html_e('Add rule', 'winonweb-migrate'); ?></button>
        </fieldset>
      </details>

      <footer class="wowm-card__foot">
        <div class="wowm-estimate">
          <span class="wowm-muted"><?php esc_html_e('Estimated size', 'winonweb-migrate'); ?></span>
          <strong data-estimate>…</strong>
        </div>
        <button type="button" class="wowm-btn wowm-btn--primary wowm-btn--lg" data-export><?php Admin::the_icon('export', 18); ?><?php esc_html_e('Export site', 'winonweb-migrate'); ?></button>
      </footer>
    </div>

    <ul class="wowm-perks">
      <li><?php Admin::the_icon('shield', 18); ?><span><strong><?php esc_html_e('Safe by design.', 'winonweb-migrate'); ?></strong> <?php esc_html_e('Imports stage the database in temporary tables and switch over in one step.', 'winonweb-migrate'); ?></span></li>
      <li><?php Admin::the_icon('database', 18); ?><span><strong><?php esc_html_e('Serialized data aware.', 'winonweb-migrate'); ?></strong> <?php esc_html_e('URLs inside widgets, page builders and ACF are rewritten without breaking them.', 'winonweb-migrate'); ?></span></li>
      <li><?php Admin::the_icon('restore', 18); ?><span><strong><?php esc_html_e('Resumable.', 'winonweb-migrate'); ?></strong> <?php esc_html_e('Works in small steps, so slow hosts and timeouts do not break big sites.', 'winonweb-migrate'); ?></span></li>
    </ul>
  </section>

  <!-- Import -->
  <section class="wowm-panel" role="tabpanel" data-panel="import" hidden>
    <div class="wowm-card" data-upload-card>
      <div class="wowm-drop" data-drop tabindex="0" role="button" aria-label="<?php esc_attr_e('Choose an archive to import', 'winonweb-migrate'); ?>">
        <input type="file" accept=".wowm,.wpress,.zip" data-file hidden>
        <div class="wowm-drop__idle">
          <span class="wowm-drop__icon"><?php Admin::the_icon('import', 28); ?></span>
          <p class="wowm-drop__title"><?php esc_html_e('Drop an archive here', 'winonweb-migrate'); ?></p>
          <p class="wowm-muted"><?php esc_html_e('or click to browse. .wowm, All-in-One .wpress or Duplicator .zip, any size: it is uploaded in small pieces.', 'winonweb-migrate'); ?></p>
        </div>
        <div class="wowm-drop__busy" hidden>
          <span class="wowm-drop__icon"><?php Admin::the_icon('file', 28); ?></span>
          <p class="wowm-drop__title" data-upload-name></p>
          <div class="wowm-bar"><span data-upload-bar></span></div>
          <p class="wowm-muted" data-upload-meta></p>
          <p class="wowm-note wowm-note--info wowm-upload-note" data-upload-note hidden></p>
        </div>
      </div>
      <p class="wowm-muted wowm-center"><?php esc_html_e('Already on this server?', 'winonweb-migrate'); ?> <button type="button" class="wowm-link" data-goto="backups"><?php esc_html_e('Restore from Backups', 'winonweb-migrate'); ?></button></p>
    </div>

    <div class="wowm-card" data-preflight hidden></div>
  </section>

  <!-- Backups -->
  <section class="wowm-panel" role="tabpanel" data-panel="backups" hidden>
    <div class="wowm-card">
      <div class="wowm-card__head wowm-card__head--row">
        <div>
          <h2><?php esc_html_e('Backups on this server', 'winonweb-migrate'); ?></h2>
          <p data-storage></p>
        </div>
      </div>
      <div class="wowm-list" data-backups></div>
    </div>
  </section>

  <!-- Schedule -->
  <section class="wowm-panel" role="tabpanel" data-panel="schedule" hidden>
    <form class="wowm-card" data-schedule>
      <div class="wowm-card__head">
        <h2><?php esc_html_e('Automatic backups', 'winonweb-migrate'); ?></h2>
        <p><?php esc_html_e('Runs in the background through WP-Cron and keeps only the newest copies.', 'winonweb-migrate'); ?></p>
      </div>

      <div class="wowm-form">
        <div class="wowm-field">
          <span><?php esc_html_e('How often', 'winonweb-migrate'); ?></span>
          <div class="wowm-segmented" role="radiogroup">
            <?php foreach (['off' => __('Off', 'winonweb-migrate'), 'daily' => __('Daily', 'winonweb-migrate'), 'weekly' => __('Weekly', 'winonweb-migrate'), 'monthly' => __('Monthly', 'winonweb-migrate')] as $wowm_value => $wowm_label): ?>
              <label><input type="radio" name="frequency" value="<?php echo esc_attr($wowm_value); ?>"><span><?php echo esc_html($wowm_label); ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="wowm-form__row">
          <label class="wowm-field">
            <span><?php esc_html_e('At', 'winonweb-migrate'); ?></span>
            <select name="hour">
              <?php for ($wowm_hour = 0; $wowm_hour < 24; $wowm_hour++): ?>
                <option value="<?php echo (int) $wowm_hour; ?>"><?php echo esc_html(sprintf('%02d:00', $wowm_hour)); ?></option>
              <?php endfor; ?>
            </select>
          </label>
          <label class="wowm-field">
            <span><?php esc_html_e('Keep the latest', 'winonweb-migrate'); ?></span>
            <input type="number" name="retention" min="1" max="100">
            <small><?php esc_html_e('full backups, with their changes', 'winonweb-migrate'); ?></small>
          </label>
          <label class="wowm-field">
            <span><?php esc_html_e('Full backup every', 'winonweb-migrate'); ?></span>
            <select name="full_every">
              <option value="1"><?php esc_html_e('run (always full)', 'winonweb-migrate'); ?></option>
              <?php foreach ([3, 7, 14, 30] as $wowm_runs): ?>
                <option value="<?php echo (int) $wowm_runs; ?>"><?php echo esc_html(sprintf(/* translators: %d: number of backup runs */ _n('%d run', '%d runs', $wowm_runs, 'winonweb-migrate'), $wowm_runs)); ?></option>
              <?php endforeach; ?>
            </select>
            <small><?php esc_html_e('In between, only changed files are packed: minutes instead of hours.', 'winonweb-migrate'); ?></small>
          </label>
        </div>

        <div class="wowm-field">
          <span><?php esc_html_e('Include', 'winonweb-migrate'); ?></span>
          <div class="wowm-chips">
            <?php foreach ($wowm_tiles as $wowm_key => [$wowm_title]): ?>
              <label class="wowm-chip"><input type="checkbox" name="components" value="<?php echo esc_attr($wowm_key); ?>"><span><?php Admin::the_icon($wowm_key, 16); ?><?php echo esc_html($wowm_title); ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <footer class="wowm-card__foot">
        <span class="wowm-muted" data-next-run></span>
        <button type="submit" class="wowm-btn wowm-btn--primary"><?php esc_html_e('Save schedule', 'winonweb-migrate'); ?></button>
      </footer>
    </form>
  </section>

  <!-- Tools -->
  <section class="wowm-panel" role="tabpanel" data-panel="tools" hidden>
    <form class="wowm-card" data-scan>
      <div class="wowm-card__head">
        <h2><?php esc_html_e('Find leftover links', 'winonweb-migrate'); ?></h2>
        <p><?php esc_html_e('After a migration, check where the old address still appears: hard-coded links in content, builder data, options. Then replace it everywhere in one go.', 'winonweb-migrate'); ?></p>
      </div>
      <div class="wowm-form">
        <div class="wowm-search">
          <input type="text" name="needle" spellcheck="false" autocomplete="off" required minlength="4">
          <button type="submit" class="wowm-btn wowm-btn--primary"><?php Admin::the_icon('search', 18); ?><?php esc_html_e('Scan database', 'winonweb-migrate'); ?></button>
        </div>
        <div class="wowm-bar" data-scan-bar hidden><span></span></div>
        <div data-scan-results></div>
      </div>
    </form>
  </section>

  <!-- Help -->
  <section class="wowm-panel" role="tabpanel" data-panel="help" hidden>
    <div class="wowm-card">
      <div class="wowm-card__head">
        <h2><?php esc_html_e('Move a site in three steps', 'winonweb-migrate'); ?></h2>
        <p><?php esc_html_e('Works the same for local to staging, staging to live, or one host to another.', 'winonweb-migrate'); ?></p>
      </div>
      <ol class="wowm-steps-guide">
        <li>
          <span class="wowm-steps-guide__num">1</span>
          <div>
            <h3><?php esc_html_e('Export the old site', 'winonweb-migrate'); ?></h3>
            <p><?php esc_html_e('Install the plugin there, open Export, pick what to include and press Export site. Download the .wowm file when it is ready.', 'winonweb-migrate'); ?></p>
          </div>
        </li>
        <li>
          <span class="wowm-steps-guide__num">2</span>
          <div>
            <h3><?php esc_html_e('Import on the new site', 'winonweb-migrate'); ?></h3>
            <p><?php esc_html_e('Install the plugin on the new site, open Import and drop the file. Review the server check, keep "back up first" on, confirm and import. Addresses and paths are rewritten automatically.', 'winonweb-migrate'); ?></p>
          </div>
        </li>
        <li>
          <span class="wowm-steps-guide__num">3</span>
          <div>
            <h3><?php esc_html_e('Log in and check', 'winonweb-migrate'); ?></h3>
            <p><?php esc_html_e('Log in with a user from the old site. Run Tools → Find leftover links once. When everything looks right, press "Everything works" in the banner; if not, "Undo import" brings the previous database back.', 'winonweb-migrate'); ?></p>
          </div>
        </li>
      </ol>
    </div>

    <div class="wowm-card">
      <div class="wowm-card__head">
        <h2><?php esc_html_e('Questions', 'winonweb-migrate'); ?></h2>
      </div>
      <div class="wowm-faq">
        <?php
        $wowm_faq = [
          __('Why is my archive smaller than with other plugins?', 'winonweb-migrate') => __('Caches, logs, backups of other plugins (UpdraftPlus, All-in-One, Duplicator) and leftover copies like plugins-old or uploads.bak are never packed. They are not part of your site and often make up most of the size.', 'winonweb-migrate'),
          __('Can I move from All-in-One WP Migration or Duplicator?', 'winonweb-migrate') => __('Yes. Drop a .wpress file or a Duplicator .zip on the Import tab. It is converted and then imported like any WinOnWeb archive. Password-protected .wpress files are not supported yet.', 'winonweb-migrate'),
          __('What if something goes wrong during an import?', 'winonweb-migrate') => __('Until the very last moment your site runs on its own database: the new one is prepared in temporary tables and switched in one step. After that, the previous database is kept for 7 days and "Undo import" restores it. Files are protected by the safety backup made before the import.', 'winonweb-migrate'),
          __('Will my host\'s caching break?', 'winonweb-migrate') => __('No. object-cache.php, advanced-cache.php and db.php belong to the server, so they are neither exported nor overwritten by default.', 'winonweb-migrate'),
          __('The import stopped or the page timed out.', 'winonweb-migrate') => __('Every job works in small steps and resumes where it stopped. Keep the page open; short connection drops are retried automatically.', 'winonweb-migrate'),
          __('What are "changes only" backups?', 'winonweb-migrate') => __('They pack only files changed since a full backup, so they take minutes instead of hours. Restoring one brings back its full backup first and then the changes. Deleting the full backup also deletes its changes-only backups.', 'winonweb-migrate'),
          __('Where are backups stored?', 'winonweb-migrate') => sprintf(/* translators: %s: folder path */ __('In %s on this server. The folder is closed to visitors and every archive has a random, unguessable name.', 'winonweb-migrate'), 'wp-content/' . WOWM\Storage::DIR),
        ];

        foreach ($wowm_faq as $wowm_question => $wowm_answer):
          ?>
          <details class="wowm-faq__item">
            <summary><?php Admin::the_icon('chevron', 16); ?><?php echo esc_html($wowm_question); ?></summary>
            <p><?php echo esc_html($wowm_answer); ?></p>
          </details>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="wowm-card">
      <div class="wowm-card__head">
        <h2><?php esc_html_e('WP-CLI', 'winonweb-migrate'); ?></h2>
        <p><?php esc_html_e('The same engine from the command line, without browser time limits.', 'winonweb-migrate'); ?></p>
      </div>
      <pre class="wowm-code"><code>wp wowm export                      <span># full backup</span>
wp wowm export --skip=uploads       <span># without media</span>
wp wowm import site.wowm            <span># also .wpress and Duplicator .zip</span>
wp wowm list                        <span># backups on this server</span></code></pre>
    </div>

    <div class="wowm-cta">
      <div class="wowm-cta__text">
        <h2><?php esc_html_e('Rather not do it yourself?', 'winonweb-migrate'); ?></h2>
        <p><?php esc_html_e('The WinOnWeb team builds and moves WordPress sites every day. We can migrate yours, fix what breaks along the way and hand it back working.', 'winonweb-migrate'); ?></p>
      </div>
      <a class="wowm-btn wowm-btn--lg wowm-cta__button" href="<?php echo esc_url(Admin::site_url('help-cta', 'service')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Talk to WinOnWeb', 'winonweb-migrate'); ?><?php Admin::the_icon('arrow', 18); ?></a>
    </div>
  </section>

  <footer class="wowm-footer">
    <span><?php echo esc_html(sprintf(/* translators: %s: plugin version */ __('WinOnWeb Migrate %s', 'winonweb-migrate'), WOWM_VERSION)); ?></span>
    <span aria-hidden="true">·</span>
    <span><?php echo wp_kses_post(sprintf(/* translators: %s: link to the WinOnWeb website */ __('Made by %s', 'winonweb-migrate'), '<a href="' . esc_url(Admin::site_url('footer')) . '" target="_blank" rel="noopener">WinOnWeb</a>')); ?></span>
    <span aria-hidden="true">·</span>
    <button type="button" class="wowm-link" data-goto="help"><?php esc_html_e('Guide', 'winonweb-migrate'); ?></button>
  </footer>

  <!-- Progress -->
  <div class="wowm-modal" data-modal hidden>
    <div class="wowm-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wowm-modal-title">
      <div class="wowm-modal__head">
        <div class="wowm-ring" data-ring>
          <svg viewBox="0 0 64 64" aria-hidden="true"><circle class="wowm-ring__bg" cx="32" cy="32" r="28"/><circle class="wowm-ring__fg" cx="32" cy="32" r="28" pathLength="100" stroke-dasharray="0 100"/></svg>
          <span class="wowm-ring__value" data-percent>0%</span>
          <span class="wowm-ring__icon" data-ring-icon></span>
        </div>
        <div class="wowm-modal__titles">
          <h2 id="wowm-modal-title" data-title></h2>
          <p class="wowm-muted" data-message aria-live="polite"></p>
        </div>
      </div>
      <ol class="wowm-steps" data-steps></ol>
      <p class="wowm-muted wowm-bgnote" data-bgnote hidden><?php Admin::the_icon('info', 14); ?><?php esc_html_e('You can close this tab: the job continues on the server and this page reconnects when you come back.', 'winonweb-migrate'); ?></p>
      <dl class="wowm-stats" data-stats></dl>
      <div data-result></div>
      <details class="wowm-log">
        <summary><?php Admin::the_icon('chevron', 14); ?><?php esc_html_e('Activity log', 'winonweb-migrate'); ?></summary>
        <ol data-log></ol>
      </details>
      <div class="wowm-modal__foot" data-actions></div>
    </div>
  </div>

  <!-- Confirm -->
  <div class="wowm-modal" data-confirm hidden>
    <div class="wowm-modal__dialog wowm-modal__dialog--sm" role="alertdialog" aria-modal="true">
      <p data-confirm-text></p>
      <div class="wowm-modal__foot">
        <button type="button" class="wowm-btn wowm-btn--ghost" data-confirm-no><?php esc_html_e('Cancel', 'winonweb-migrate'); ?></button>
        <button type="button" class="wowm-btn wowm-btn--danger" data-confirm-yes><?php esc_html_e('Delete', 'winonweb-migrate'); ?></button>
      </div>
    </div>
  </div>

  <div class="wowm-toasts" aria-live="polite" data-toasts></div>
</div>
