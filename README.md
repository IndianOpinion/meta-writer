# meta-writer

A WordPress plugin that drafts an SEO title and meta description for each post from the post's own text, and holds them as suggestions until an editor accepts them. It respects length limits, never overwrites a value a person wrote, runs over the whole archive with a dry run first, and writes only into the fields your SEO plugin already reads.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

Measured on our own site: most of the archive had no description of its own, and template-driven pages had no body to generate from, so the SEO plugin's fallback text was all a search result could show.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [WP-CLI](#wp-cli)
- [Hooks](#hooks)
- [Settings in code (must-use loader stub)](#settings-in-code-must-use-loader-stub)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

Needs PHP 8.1 or later with `mbstring`, and WordPress 6.4 or later. `intl` is optional and makes character counts exact for emoji sequences.

**Release zip (no Composer).** Download the release zip, upload it under Plugins > Add New > Upload, and activate. The zip bundles the model library under `vendor/`.

**With Composer.** The model library is on GitHub, not Packagist yet, so add it as a `vcs` repository:

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/IndianOpinion/llm-json-php"},
        {"type": "vcs", "url": "https://github.com/IndianOpinion/meta-writer"}
    ],
    "require": {"indianopinion/meta-writer": "^0.1"}
}
```

**From a clone.** Put the folder in `wp-content/plugins/meta-writer`, run `composer install --no-dev` inside it, and activate.

**Without the model library.** The plugin still loads. Generation stays off and an admin notice says why, but review, accept and revert keep working. You can also plug in your own caller (see the FAQ) and skip the library entirely.

meta-writer is a normal plugin, not a must-use plugin: it creates a table on activation and removes it on uninstall.

## Quick start

1. Activate the plugin. Go to Settings > meta-writer.
2. Choose a provider (OpenRouter, OpenAI, or any chat completions endpoint), a model and a key. Prefer the key in `wp-config.php`:
   ```php
   define('META_WRITER_API_KEY', 'sk-...');
   ```
3. Leave the target on `auto`. It picks the one active SEO plugin (Yoast SEO, Rank Math, SEOPress, or All in One SEO, which is experimental), or meta-writer's own fallback fields when there is none.
4. See what a run would do. Nothing is called or written:
   ```sh
   wp meta-writer generate --limit=20
   ```
5. Spend calls, within the daily cap:
   ```sh
   wp meta-writer generate --limit=20 --run
   ```
6. Review under Tools > SEO suggestions. Edit inline if needed, then accept or reject. Only an accepted suggestion is written, and only into an empty field.

## Configuration

Every setting can also be fixed by a constant, `META_WRITER_` plus the key in capitals (`META_WRITER_DAILY_CAP`). The screen shows which values a constant fixes. Invalid values are rejected on save with a message; they are never silently clamped.

| Setting | Default | Notes |
|---|---|---|
| `target` | `auto` | `auto`, `yoast`, `rankmath`, `seopress`, `aioseo` or `fallback` |
| `fields` | `title,description` | Restrict runs to one field |
| `post_types` | `post,page` | Public types only; never revisions |
| `post_statuses` | `publish,future` | `inherit`, `auto-draft` and `trash` are refused |
| `title_min` / `title_max` | 30 / 60 | 10 to 120, in characters |
| `description_min` / `description_max` | 70 / 155 | 50 to 320, in characters |
| `title_suffix` | empty | Text your SEO plugin appends to titles; its length comes off the title budget |
| `body_budget` | 6000 | Characters of body sent, 500 to 20000 |
| `min_words` | 40 | Fewer words: skipped as `too_little_content` |
| `daily_cap` | 200 | Model calls per site day, 0 to 100000; 0 means none |
| `on_publish` | off | Suggest after a post is first published |
| `on_publish_delay` | 60 | Seconds before that job runs |
| `use_queue` | on | Use the generation queue plugin when it is active |
| `allow_authors` | off | Authors may generate and accept for posts they can edit |
| `keep_raw` | off | Keep raw output of failed calls, for 14 days |
| `template_as_empty` | off | Treat a field holding only the SEO plugin's template variables as empty |
| `remove_fallback_on_uninstall` | off | Fallback values are live content, so they stay by default |
| `retention_days` | 90 | Rejected, failed, superseded and stale rows are purged after this |
| `cron_time_budget` | 50 | Seconds per cron tick of an admin bulk run |
| `batch_size` | 100 | IDs per selection query |
| `provider` | `openrouter` | `openrouter`, `openai` or `generic` |
| `model` / `fallback_model` | empty | Model ids; the fallback is tried by the library when the first fails |
| `base_url` | empty | For `generic` only |
| API key | empty | `META_WRITER_API_KEY` is preferred; the screen shows the last four characters only |
| Kill switch | off | Settings screen, `wp meta-writer kill on`, or `META_WRITER_DISABLED` |

## How it works

1. **Select.** Posts of the enabled types and statuses, not password protected, in ascending ID order (or newest first), paged by an ID cursor in batches. Posts with the opt-out box ticked, posts whose fields are both filled, posts with a pending suggestion and posts whose last suggestion was rejected are skipped with a reason code.
2. **Read.** Title, manual excerpt and body. Block comments, shortcodes (stripped by pattern, never run), scripts, styles and captions are removed; entities are decoded; the body is cut to the budget at a sentence or word. Nothing else is sent: no author, comments or custom fields.
3. **Gate.** Kill switch, then today's cap, then the spend meter if it is active, then an atomic database increment of the cap counter. Any doubt means no call.
4. **Call.** One call through `indianopinion/llm-json-php` with a strict two-property JSON schema. The library owns retries and failover; meta-writer does not retry a call it gave up on.
5. **Validate.** Both strings are normalised (tags, entities, quotes, labels, line breaks), measured in characters a reader sees, and shortened at a sentence or word when too long. Too short, empty, the same as the title, or in the wrong script for the language: one more call with a note saying what was wrong, then `failed`.
6. **Flag.** `trimmed`, `retried`, `duplicate` (same description as another pending or accepted one) and `unsupported_detail` (a URL, email or phone number the post does not contain). Flags never reject; they tell the reviewer where to look.
7. **Review.** The editor sees the suggestion beside the current value, with length counters, and accepts or rejects. Accept re-reads each target just before writing it; a field someone filled meanwhile is left alone and marked `superseded`.
8. **Record.** Each write keeps the value, a SHA-256, the previous value, the user, the time and the suggestion. A field is "ours" only while it is byte-equal to that record; one character changed by a person makes it theirs for good. Revert works only while the field is still ours.

Where values go:

| Target | Title key | Description key |
|---|---|---|
| Yoast SEO | `_yoast_wpseo_title` | `_yoast_wpseo_metadesc` |
| Rank Math | `rank_math_title` | `rank_math_description` |
| SEOPress | `_seopress_titles_title` | `_seopress_titles_desc` |
| All in One SEO (experimental) | `_aioseo_title` | `_aioseo_description` |
| Fallback | `_meta_writer_title` | `_meta_writer_description` |

All writes use the WordPress meta API. meta-writer never writes another plugin's own tables. With the fallback target, the plugin prints one `<meta name="description">` and sets the document title on singular views, and stops doing so as soon as an SEO plugin is active (`wp meta-writer migrate-target --to=yoast` then copies the values into empty fields).

## WP-CLI

| Command | What it does |
|---|---|
| `wp meta-writer generate [<id>...] [--post-type=] [--status=] [--fields=] [--limit=] [--from-id=] [--order=oldest\|newest] [--time=] [--regenerate] [--refresh-own] [--run] [--format=table\|json\|csv]` | Dry run unless `--run`. Prints one line per post, totals per reason, calls, the stop reason and the cursor to resume from. |
| `wp meta-writer list [--status=] [--flag=] [--format=]` | Suggestions. |
| `wp meta-writer show <id>` | One suggestion and the current target values. |
| `wp meta-writer accept <id>... \| --all-pending [--confirm-stale] [--yes] --user=<login>` | Without `--yes` prints what it would write. |
| `wp meta-writer reject <id>... [--yes] --user=<login>` | |
| `wp meta-writer revert <post-id> --field=title\|description [--yes] --user=<login>` | |
| `wp meta-writer status` | Target, detected SEO plugins, kill switch, cap used and left, counts, last run. |
| `wp meta-writer kill on\|off` | Reports when the constant overrides it. |
| `wp meta-writer migrate-target --to=<adapter> [--yes]` | Copy fallback values into empty fields of an SEO plugin. |
| `wp meta-writer purge [--older-than=<days>] [--yes]` | Remove old rejected, failed, superseded and stale rows. |

Exit codes: 0 success, 1 error, 2 stopped by the cap, the kill switch or a spend deny, so a cron script can tell "nothing more allowed today" from a fault.

## Hooks

Filters: `meta_writer_should_process`, `meta_writer_post_types`, `meta_writer_post_statuses`, `meta_writer_clean_input`, `meta_writer_post_language`, `meta_writer_limits`, `meta_writer_prompt`, `meta_writer_llm_client`, `meta_writer_caller`, `meta_writer_validate` (may only turn ok into not ok), `meta_writer_adapters`, `meta_writer_title_suffix`, `meta_writer_capability`, `meta_writer_default_settings`, `meta_writer_language_scripts`, `meta_writer_spend_allow_reasons`.

Actions: `meta_writer_suggestion_created`, `meta_writer_accepted`, `meta_writer_rejected`, `meta_writer_reverted`, `meta_writer_run_finished`, `meta_writer_cap_reached`, `meta_writer_kill_switch_changed`, `meta_writer_log`.

PHP functions: `meta_writer_generate()`, `meta_writer_get_suggestion()`, `meta_writer_accept()`, `meta_writer_reject()`, `meta_writer_revert()`, `meta_writer_is_manual()`, `meta_writer_measure()`, `meta_writer_fit()`, `meta_writer_is_enabled()`, `meta_writer_cap_remaining()`.

REST, under `meta-writer/v1`, authenticated only: `GET /suggestions`, `GET /suggestions/<id>`, `POST /suggestions/<id>/accept`, `POST /suggestions/<id>/reject`, `POST /posts/<id>/generate` (one post), `POST /posts/<id>/revert`. There is no bulk generation over REST.

Optional companions, used only when active: a generation queue (`gen_queue_enqueue_job`), a spend meter (`spend_meter_pre_spend`, `spend_meter_record`, `spend_meter_release`) and a prompt library (`prompt_library_register`, `prompt_library_render`). meta-writer's own cap and kill switch apply even when they are present. The exact protocol is in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Settings in code (must-use loader stub)

meta-writer is not supported as a must-use plugin. To pin its settings in code, so nobody can change them from the screen, copy [`examples/mu-plugin-loader.php`](examples/mu-plugin-loader.php) into `wp-content/mu-plugins/`. It defines `META_WRITER_*` constants (key, cap, provider, model, and the kill switch if you want it) and a language filter. Other samples: [`examples/fit-and-measure.php`](examples/fit-and-measure.php) runs offline with `php examples/fit-and-measure.php`; [`examples/custom-caller.php`](examples/custom-caller.php) replaces the model library with your own HTTP call.

## Limitations

- Length is in characters, not pixel width. Search engines truncate by width, so a wide-letter title may still be cut.
- Posts of chosen types only. Category, tag and author archive descriptions are on the roadmap, not in 0.1.0.
- No block editor sidebar panel in 0.1.0; the box on the edit screen is a classic meta box, which the block editor also shows.
- All in One SEO is experimental: meta-writer writes its post meta keys only and never touches its own table, so the value may not appear on the page until that plugin picks it up.
- Not tested against live installs of each SEO plugin in this release: the adapters write the post meta keys those plugins document and export. Please report a mismatch.
- The wrong-script check knows Latin, Devanagari, Bengali, Tamil, Telugu, Kannada, Malayalam, Gujarati, Gurmukhi, Odia, Arabic, Cyrillic and CJK. Other languages are not checked.
- If the site time zone changes mid-day, that one day's counter may start fresh early or late.
- Post text goes to the model provider you configure. Private, draft and password-protected posts are excluded by default.

## FAQ

**Will it ever overwrite what my editors wrote?** No. A non-empty field is only treated as meta-writer's own while it is byte-equal to what meta-writer last wrote there. There is no force flag.

**Why does `generate` do nothing?** It is a dry run until you add `--run` (CLI) or tick the confirmation after the dry run (admin). The expensive action is the explicit one.

**How do I stop all spending now?** `wp meta-writer kill on`, the button on the settings screen, or `define('META_WRITER_DISABLED', true);` which the screen cannot undo. Review still works.

**Can I use my own model client?** Yes. Return a callable from the `meta_writer_caller` filter that takes the message list and the JSON schema and returns `['title' => ..., 'description' => ...]`. See `examples/custom-caller.php`. Then the model library is not needed at all.

**Can I change the prompt?** Use the `meta_writer_prompt` filter, or install the prompt library plugin, which lets you edit it from a screen. The shipped prompt is generic on purpose.

**Why was a post skipped as `too_little_content`?** It had fewer than `min_words` words after cleaning. A template page or an image post has nothing to describe; write that one by hand rather than ship filler.

**What does `duplicate` mean?** Another post already has the same description, pending or accepted. It is shown for review, not rejected.

**Where is the log?** Settings > meta-writer shows the last 100 of 500 lines. Hook `meta_writer_log` to send them elsewhere.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Design and decisions are in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md); the requirements are in [docs/PRD.md](docs/PRD.md).

## Licence

MIT. See [LICENSE](LICENSE). Copyright (c) 2026 Indian Opinion.
