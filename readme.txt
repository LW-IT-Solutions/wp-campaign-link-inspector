=== Campaign Link Inspector ===
Contributors: lwitsolutions
Tags: analytics, campaign, utm, links, marketing
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Inspect campaign links in stored WordPress posts and pages. No tracking, account, external requests or automatic link changes.

== Description ==

Campaign Link Inspector finds questionable campaign parameters in saved anchor links.
Open Tools > Campaign Links and inspect content in batches, with an edit link for each finding.

Checks include repeated or empty UTM parameters, parameter capitalization,
source/medium capitalization, whitespace, malformed percent encoding, missing
recommended campaign fields, same-host campaign links, fragment parameters and
possible sensitive query parameters. Findings are review prompts, not proof of
incorrect attribution, delivery to Google Analytics or legal compliance.

The plugin does not render shortcodes, dynamic blocks, menus, reusable block references
or page-builder metadata. It reads stored HTML anchor tags in posts and pages.
Drafts, scheduled and private content are included only for an administrator
who can edit the content item. User-triggered scans process ten items per batch.
Each item is limited to 2 MB, 5,000 anchors and 100 finding rows for resource safety.
An incomplete item is explicitly reported. A CSV contains only the current batch.
The list is read live; concurrent content changes can affect pagination.
Other plugins can filter the queried content list.

= Privacy =

No external requests, telemetry, cookies, visitor tracking or remote libraries
are introduced by this plugin. No report, setting, content copy or scheduled task
is stored. Reports are returned to the requesting administrator's browser.
CSV export is created locally in that browser. Report URLs omit query values,
credentials and fragments, but content titles and URL paths may still be sensitive.
Existing WordPress access/server logs and other plugins are outside this plugin's control.

= Interpretation =

Same-host UTM links need review, not automatic removal. GA4 does not create a new
session just because a campaign changes. Source, medium and campaign are a
recommended consistent set, not a claim that every possible campaign needs all three.
Potential sensitive-data checks are heuristic and not exhaustive.

Reference: https://support.google.com/analytics/answer/11080067
Reference: https://support.google.com/analytics/answer/11242841

== Installation ==

1. Upload the campaign-link-inspector folder to wp-content/plugins, or install the ZIP.
2. Activate Campaign Link Inspector.
3. Open Tools > Campaign Links as an administrator.
4. Start the scan, review each batch, export if needed, and edit content manually.

== Frequently Asked Questions ==

= Does it change or visit my links? =

No. It reads saved content only. It never requests the link targets.

= Does it work without Google Analytics? =

Yes. It requires no Google account or Google API.

= Can a clean report guarantee correct tracking? =

No. Dynamic links, runtime tracking and the analytics platform are outside the scan.

= What does uninstalling remove? =

The plugin creates no database records or files at runtime. Removing the plugin
files is sufficient; manually downloaded CSV files remain wherever they were saved.

== Screenshots ==

1. Scan report under Tools > Campaign Links: each finding names the content item, the link position and a review hint. Query values are omitted from the report.

== Changelog ==

= 0.1.0 =
* First installable prototype: bounded read-only content checks and local CSV export.
