=== WinOnWeb Migrate ===
Contributors: alexlionheart
Tags: migration, backup, migrate, clone, restore
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move or back up a WordPress site with no size limits. Imports All-in-One and Duplicator archives, can undo an import.

== Description ==

WinOnWeb Migrate packs a site into a single .wowm file and unpacks it on any other WordPress install: local to staging, staging to live, or from one host to another. There is no upload limit and nothing to buy.

= Features =

* No size limits. Archives are built, uploaded and unpacked in small steps that resume after timeouts and dropped connections.
* Imports All-in-One WP Migration (.wpress) and Duplicator (.zip) archives.
* Safe import. The database is loaded into temporary tables and switched over in one step, so a failed import leaves the site as it was.
* Undo import. The previous database is kept for 7 days and can be restored with one click from the plugin, with `wp wowm undo` from the command line, or through WordPress recovery mode if a plugin or theme breaks the site.
* Site check before and after import: the plugin requests the home page, login and REST API and shows green/red. If wp-admin shows a critical error right after the import, the previous database is put back at once; other errors trigger an automatic undo after 10 minutes unless you keep the import.
* Server check before import: write access, disk space, database server, max_allowed_packet, utf8mb4, PHP memory, plus an integrity check of the archive.
* Site URLs and paths are rewritten automatically, including serialized data (widgets, page builders, ACF) and JSON-escaped values.
* Leftover link finder: shows where an old address is still stored and replaces it everywhere.
* The host's object-cache.php, advanced-cache.php and db.php are not moved or overwritten by default.
* Caches, logs, other plugins' backups and leftover copies such as plugins-old are left out of archives.
* Choose what to export: database, media, themes, plugins, must-use plugins, other files, and your own exclusions.
* Scheduled backups with changes-only runs between full backups.
* Caches are cleared after an import (WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache, Autoptimize, SiteGround Optimizer, object cache).
* WP-CLI: `wp wowm export`, `wp wowm import <file>`, `wp wowm list`, `wp wowm undo`, `wp wowm keep`.

Need a hand? The [WinOnWeb](https://www.winonweb.dev/en/migrate#service) team can do the migration for you.

== Installation ==

1. Install the plugin from Plugins > Add New, or upload the zip under Plugins > Add New > Upload Plugin.
2. Activate it and open Migrate in the admin menu.
3. On the old site, use Export to create an archive and download it.
4. On the new site, open Import, drop the archive, review the server check and confirm.

== Frequently Asked Questions ==

= Is it really free? =

Yes. There is no size limit, no paid extension and no trial.

= Can I import an archive made with All-in-One WP Migration or Duplicator? =

Yes. Drop the .wpress file or the Duplicator .zip on the Import tab. Password-protected .wpress archives are not supported yet.

= What happens if an import fails? =

Until the last step the site keeps running on its own database. After the switch the previous database is kept for 7 days and "Undo import" brings it back. If wp-admin cannot open right after the import, the plugin undoes it by itself; `wp wowm undo` does the same from the command line. Files are covered by the backup the plugin offers to make before the import.

= Where are backups stored? =

In wp-content/wowm-backups: next to the content it backs up and outside the uploads folder, so a backup never ends up inside the next one. The folder is protected for Apache and IIS, every archive gets a random file name and nothing secret is written there, so it cannot be misused on nginx either.

= Does it support multisite? =

Not yet.

= Does the plugin send data anywhere? =

No. Archives stay on your server unless you download them. The plugin does not contact any external service.

== Screenshots ==

1. Export: pick what goes into the archive, with live size estimates.
2. Import check: source and destination, server check and options before anything changes.
3. Progress of an import, resumable step by step.
4. Backups list with download, restore and delete.
5. Tools: find and replace leftover links after a migration.

== Changelog ==

= 1.0.0 =
* First stable release.

= 0.4.3 =
* New logo and colors in the plugin screen.

= 0.4.2 =
* Files unpacked from an archive are staged as numbered files without an extension or folder structure and only get their real name when moved into place, so a PHP file from an archive is never reachable under its own name while an import runs.

= 0.4.1 =
* The standalone recover.php is gone. When the login page shows a critical error right after an import, the previous database is put back in the same step; `wp wowm undo` and `wp wowm keep` were added for the command line, and WordPress recovery mode covers a broken plugin or theme.
* Nothing secret is written to disk any more: the undo state lives in the options table and job files hold only a hash of the job secret.
* Plugins, must-use plugins, themes and uploads are located through WP_PLUGIN_DIR, WPMU_PLUGIN_DIR, get_theme_root() and wp_upload_dir(), so sites with a custom folder layout export and import correctly.
* The site is checked before the import too: a page that already failed is not counted as something the import broke.

= 0.4.0 =
* Site check after every import: home page, login page and REST API are requested and must answer without a critical error. Result shown green/red.
* Automatic undo: if the site shows a critical error after an import, the previous database is put back after 10 minutes unless you choose to keep the import (can be switched off per import).
* Emergency undo without WordPress: recover.php restores the previous database when wp-admin cannot load. The link is shown after the import and saved to wp-content/wowm-backups/RECOVERY.txt.
* Plugins whose files did not arrive, and plugins tied to the previous host, are deactivated after the import and listed.
* PHP extensions used by the source site are compared with this server before the import.
* Jobs keep running on the server when the tab is closed (WP-Cron fallback) and the page reconnects to them.
* An interrupted upload can be continued after a page reload by picking the same file.

= 0.3.4 =
* Upload progress moves continuously while a piece is being sent, instead of jumping once per piece.
* Large files show an explanation up front (slow on shared hosting, still working, SFTP + Restore is faster for multi-GB archives).

= 0.3.3 =
* When the host limits the upload size, a sticky note under the bar explains what is happening and that it is working, with a pointer to the SFTP + Restore route for very large files.

= 0.3.2 =
* On an HTTP 413 (request too large) the upload now shrinks the piece size immediately instead of retrying, and remembers a working size for next time.

= 0.3.1 =
* Uploads no longer hang on hosts that hold or reject large requests: each piece times out, retries are shown, and the piece size shrinks automatically.

= 0.3.0 =
* All database access goes through $wpdb.
* Stricter input sanitizing and output escaping.
* Translator comments for every string with placeholders.

= 0.2.1 =
* Help tab with a migration guide, FAQ and WP-CLI reference.

= 0.2.0 =
* Import All-in-One (.wpress) and Duplicator (.zip) archives.
* Undo import.
* Server check and archive integrity check before import.
* Leftover link finder with replace.
* Changes-only backups, manual and scheduled.

= 0.1.1 =
* The safety backup before an import only covers what the import replaces.
* Size estimate measured across requests; backups and leftover copies are skipped.

= 0.1.0 =
* First release.
