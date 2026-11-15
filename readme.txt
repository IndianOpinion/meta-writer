=== meta-writer ===
Contributors: indianopinion
Tags: seo, meta description, seo title, ai, wp-cli
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Drafts an SEO title and meta description from the post body, holds them for review, and writes only accepted text into empty fields of your SEO plugin.

== Description ==

Written from scratch; not derived from any third-party plugin.

meta-writer reads a post, asks a language model for an SEO title and a meta description, checks both against length and quality rules, and holds the pair as a suggestion. An editor reviews it. Only an accepted suggestion is written, and only into an empty field of the SEO plugin the site already uses (Yoast SEO, Rank Math, SEOPress, or All in One SEO as an experimental target), or into the plugin's own fallback fields when there is none. It never overwrites a value a person wrote.

* Review before write, with inline edits and length counters.
* Never overwrites a manual value; revert works while the field still holds what was written.
* Bulk runs are a dry run until you confirm.
* A daily cap on model calls and a kill switch, which a constant in wp-config.php can pin on.
* Lengths in characters a reader sees, in any script; cuts at sentence or word boundaries.
* Flags duplicates and details (URLs, emails, phone numbers) the post does not contain.
* WP-CLI: `wp meta-writer generate`, `list`, `show`, `accept`, `reject`, `revert`, `status`, `kill`, `migrate-target`, `purge`.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

= Privacy =

The title, excerpt and text of a post are sent to the model provider the site operator configures, when a suggestion is requested for it. No author details, comments or custom fields are sent. Private, draft and password-protected posts are excluded by default. The plugin adds this paragraph to the privacy policy guide.

== Installation ==

1. Upload the release zip under Plugins > Add New > Upload and activate.
2. Under Settings > meta-writer, choose a provider and model, and set the key (preferably `define('META_WRITER_API_KEY', '...');` in wp-config.php).
3. Run a dry run from the settings screen or `wp meta-writer generate`, then a real run.
4. Review under Tools > SEO suggestions.

meta-writer is a normal plugin, not a must-use plugin.

== Frequently Asked Questions ==

= Will it overwrite what my editors wrote? =

No. A field counts as meta-writer's own only while it is byte-equal to what meta-writer last wrote there.

= How do I stop all spending? =

`wp meta-writer kill on`, the button on the settings screen, or `define('META_WRITER_DISABLED', true);`.

= Does it publish anything on its own? =

No. Every suggestion waits for an editor.

== Changelog ==

= 0.1.0 =
* First release.
