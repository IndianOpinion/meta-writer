# meta-writer: product requirements

Status: draft for review. Version target: 0.1.0.

Written from scratch; not derived from any third-party plugin. This
document is the only specification the builder works from.

meta-writer is a WordPress plugin. It reads a post, asks a language model
for an SEO title and a meta description, checks both against length and
quality rules, and holds the pair as a suggestion. An editor reviews the
suggestion. Only an accepted suggestion is written, and only into an empty
field of the SEO plugin the site already uses (Yoast SEO, Rank Math,
SEOPress or All in One SEO), or into the plugin's own fields when no SEO
plugin is present. It never overwrites a value a person wrote.

## 1. Problem

Search results show a title and a short description for every page. When
a post has no description of its own, the SEO plugin falls back to the
first words of the body, which are often a dateline, an image caption, a
shortcode remnant or a sentence cut in half. When the title field is empty,
the plugin uses the post title, which is often too long and truncated by
the search engine, or too short to say what the page is about.

Writing both fields by hand is fine for ten posts and impossible for an
archive of thousands. Sites that try to fill the gap with one template
("Latest news on X from Site") produce the same sentence on many pages,
which search engines treat as duplicate, thin content.

Measured on our own site: most of the archive had no description of its
own, and pages whose body was empty (template-driven pages) had nothing to
generate from at all, so the fallback text was the only text shown.

Without this plugin an operator either leaves the fields empty, writes a
one-off script that overwrites manual values, or pastes model output
straight into the database with no length check and no review.

## 2. Goals and non-goals

### Goals

1. Generate one title and one description per post from that post's own
   content, in the post's language.
2. Enforce configurable length limits on both, measured in characters as a
   reader sees them, not bytes.
3. Never overwrite a non-empty value in the target field, whoever wrote
   it. A value the plugin wrote earlier and a person then edited counts as
   manual.
4. Review before write: every generated pair is a suggestion until a user
   with the right capability accepts it.
5. Bulk operation over the archive, with a dry run that spends nothing and
   writes nothing and says exactly what a real run would do.
6. Write to the meta fields that the active SEO plugin reads, so the
   result shows up in that plugin's own output with no theme change.
7. A daily cap on model calls and a kill switch, both in the plugin's own
   settings, honoured even when a shared queue or spend meter is present.
8. An audit trail: who accepted what, when, what the field held before,
   and a one-step revert while the field still holds what was written.

### Non-goals

1. No other SEO work: no schema markup, no sitemaps, no robots rules, no
   canonical URLs, no Open Graph images, no keyword research, no scoring
   of readability or keyword density.
2. No editing of the post title, excerpt or body. Only the SEO title and
   meta description fields.
3. No publishing on its own. No "apply automatically" mode in 0.1.0.
4. No term (category, tag) or author archive descriptions in 0.1.0. Posts
   of chosen post types only. (Open question 13.6.)
5. No translation. A post is described in its own language; multilingual
   plugin integration is limited to a language filter.
6. No bundled model provider SDK. Model calls go through the
   `indianopinion/llm-json-php` library only.
7. No house style, topic lists or editorial rules in the shipped prompt.
   The prompt is generic and the operator may replace it.

## 3. Users and setting

- **Site operator** (administrator). Installs the plugin, sets the model
  provider key, picks the target SEO plugin, sets limits, cap and post
  types, runs bulk jobs from WP-CLI or the admin screen.
- **Editor** (default: anyone with `edit_others_posts`). Reviews
  suggestions in the review screen, edits them inline if needed, accepts
  or rejects, single or in bulk.
- **Author** (anyone who can edit the post). May request and accept a
  suggestion for a post they can edit, from the post edit screen, when the
  operator allows it (setting `allow_authors`, default off).

Where it runs: a normal WordPress site or a site in a multisite network,
PHP 8.1 or later, WordPress 6.4 or later. Generation happens in WP-Cron
or WP-CLI, never inside a front-end page view. It works behind a full-page
cache because the only front-end output (fallback mode) is ordinary head
markup.

## 4. Behaviour

Terms used below:

- **Field**: one of two slots, `title` or `description`.
- **Target**: the place a field is written to, set by the active adapter
  (5.6).
- **Manual value**: any non-empty target value that is not byte-equal to
  the value meta-writer itself last wrote there (see case 30).
- **Suggestion**: a generated, validated pair held in meta-writer's own
  storage with status `pending`, `accepted`, `rejected`, `stale`,
  `superseded` or `failed`.
- **Run**: one invocation of generation over one or more posts, from the
  admin screen, WP-CLI or cron.
- **Day**: the calendar day in the site's time zone, from 00:00:00 to
  23:59:59 (decision recorded in section 13.2).

### 4.1 Selecting posts

1. A post is a candidate when its post type is in the enabled list
   (default `post` and `page`), its status is in the enabled list
   (default `publish` and `future`), and it is not password protected.
2. Private posts, drafts, pending posts, trashed posts, autosaves and
   revisions are never candidates unless the operator adds the status to
   the list. Revisions and autosaves cannot be added.
3. A post is skipped with reason `excluded` when it carries the per-post
   opt-out flag (5.3) or when the filter `meta_writer_should_process`
   returns false.
4. A field is wanted only when its target is empty. A post where both
   targets are non-empty is skipped with reason `both_filled`, and no model
   call is made for it.
5. When only one field is empty, the model is still asked for both (one
   call), and only the wanted field becomes part of the suggestion. The
   other is discarded and not stored. The field selection option (`fields`,
   default both) can restrict a run to `title` or `description` only.
6. A post that already has a `pending` suggestion is skipped with reason
   `pending_exists`, unless the run is started with `--regenerate`.
7. A post that has a `rejected` suggestion is skipped with reason
   `rejected_before` unless `--regenerate` is given. Rejection is
   remembered so a bulk run does not keep paying for text an editor
   refused.
8. Candidates are processed in ascending post ID order by default, or
   descending date with `--order=newest`. The order is stable across
   batches so a resumed run does not skip or repeat posts.
9. Selection pages through the database in batches (default 100 IDs) by
   ID cursor, never with an unbounded query or an offset.

### 4.2 Building the input

10. The input is the decoded post title, the manual excerpt if any, and
    the cleaned body. The cleaned body is the post content with block
    comments, shortcodes, script, style, figure captions and HTML tags
    removed, HTML entities decoded to UTF-8 (so `&amp;` becomes `&` and
    `&#8217;` becomes the right quote), and runs of whitespace collapsed to
    one space.
11. Shortcodes are stripped, not rendered, so no shortcode's side effects
    run during generation. A filter lets the operator supply their own
    cleaner.
12. The cleaned body is cut to a configurable budget (default 6,000
    characters) at a sentence or word boundary, never inside a multibyte
    character.
13. If the cleaned body has fewer words than the minimum (default 40,
    counted by whitespace for space-separated scripts and by characters
    divided by two for scripts without spaces), the post is skipped with
    reason `too_little_content`. No call is made. This covers pages whose
    content is a template and image-only posts. Template text across many
    thin pages is its own duplicate-content problem, so the plugin does
    not invent filler.
14. The language is the site locale by default. A filter
    (`meta_writer_post_language`) lets a multilingual setup give the
    post's own language as a BCP 47 tag. The prompt asks for output in
    that language.
15. No personal data beyond what is in the post is sent. Author names,
    emails, comment text, custom fields and private meta are never part
    of the input.

### 4.3 Calling the model

16. The prompt is generic. It asks for a title and a description that
    describe this page accurately, in the given language, within the given
    character ranges, with no quotation marks around them, no markdown, no
    emoji, no site name (the adapter adds it when the operator wants it),
    no clickbait, and no claim that is not in the input. It asks for JSON
    with exactly two string properties, `title` and `description`.
17. The output schema is strict: an object with `title` (string) and
    `description` (string), no other properties.
18. All calls go through `indianopinion/llm-json-php` with the operator's
    chosen provider route. The library handles retries, cooldowns,
    provider failover and JSON repair. meta-writer does not retry a call
    the library has given up on, within the same run.
19. When `prompt-library` is active, meta-writer registers its default
    prompt as a consumer (`meta-writer/title-description`) and renders
    through it, so an operator can edit the prompt. When it is absent, the
    built-in prompt is used. Either way the variables are: `title`,
    `excerpt`, `body`, `language`, `title_min`, `title_max`,
    `description_min`, `description_max`.
20. Before each call, the plugin checks its own kill switch and its own
    daily cap (cases 46 to 52). Then, when `spend-meter` is active, it asks
    `spend_meter_pre_spend` with purpose `meta-writer.generate` and obeys
    a deny. Then, when `gen-queue` is active and the operator has chosen
    queue mode, it enqueues instead of calling (case 56).
21. A call that fails with any exception from the library marks that
    post's suggestion `failed` with the error class and a short redacted
    message, counts against the daily cap (the provider may have billed
    it), and the run moves to the next post.
22. When the library reports that every provider is out of credit, the
    run stops at once with stop reason `credit_exhausted`, and no further
    posts are attempted in that run.

### 4.4 Validating the output

23. Both strings are normalised: HTML tags removed, entities decoded,
    control characters and line breaks replaced with a space, whitespace
    collapsed, leading and trailing space trimmed, one pair of wrapping
    quotes (straight or curly, or the language's own) removed, and a
    leading label such as "Title:" or "Meta description:" removed.
24. Length is measured in grapheme clusters (what a reader counts as one
    character), via the `intl` extension when present, and in Unicode
    code points via `mb_strlen` otherwise. Bytes are never used. The
    measured length is stored with the suggestion.
25. Title limits default to 30 to 60 characters. Description limits
    default to 70 to 155 characters. Both are settings. The title budget
    shrinks by the length of the site-name suffix when the adapter is set
    to append one (case 37).
26. A value longer than its maximum is shortened deterministically: cut at
    the last sentence end within the limit if that leaves at least the
    minimum, else at the last word boundary within the limit. Titles get
    no ellipsis. Descriptions that were cut mid-sentence end with a single
    ellipsis character, counted in the length. Trailing punctuation that
    would sit before the ellipsis (comma, colon, semicolon, dash) is
    removed. A value is never cut inside a word or a grapheme cluster. For
    scripts without spaces, the cut falls at the last punctuation mark
    within the limit, else at the limit on a grapheme boundary.
27. A value shorter than its minimum after normalisation, an empty value,
    or a value that is identical (case-insensitive, after normalisation)
    to the post title when used as the description, is a validation
    failure. So is a description that repeats the title as its opening
    words with nothing else of length.
28. On a validation failure the plugin makes one more call for that post
    with a note in the prompt naming what was wrong (too short, empty,
    same as title). That second call counts against the cap. If the
    second answer also fails, the post's suggestion is `failed` with
    reason `invalid_output`, and the raw answer is kept for the operator
    (truncated to 2,000 characters) when `keep_raw` is on (default off).
29. A description identical to the description of another post already
    written or pending on the site (compared by a hash of the normalised,
    lower-cased text) is flagged `duplicate` on the suggestion. It is
    still shown for review; the flag is visible in the review screen and
    WP-CLI output. It is not auto-rejected.

### 4.5 Manual values and provenance

30. When meta-writer writes a value to a target, it records in its own
    post meta: the field, the exact bytes written, a SHA-256 of them, the
    previous target value (usually empty), the user who accepted, the
    time, the adapter, and the suggestion id.
31. A target is "ours" only when its current value is byte-equal to the
    last value meta-writer wrote there. Any other non-empty value is
    manual, including a value meta-writer wrote and a person later
    changed by one character.
32. A manual value is never overwritten, never cleared and never edited,
    by any code path: accept, bulk accept, regenerate, revert, uninstall,
    WP-CLI with any flag. There is no force flag for this.
33. A target that is ours is treated as filled. It is regenerated only
    when the operator runs with `--refresh-own`, and then only through the
    same review step (a new pending suggestion that replaces ours on
    accept). Default off.
34. Values that consist only of the SEO plugin's template variables (for
    example a title field holding just the plugin's title variable) are
    non-empty and therefore manual. They are not touched. (Open question
    13.4.)

### 4.6 Review and accept

35. A suggestion records the post ID, the fields, the generated text, the
    measured lengths, the flags (`trimmed`, `duplicate`, `retried`), a
    SHA-256 of the cleaned input it was made from, the model route and
    prompt version used, the time, and the status.
36. The review screen lists pending suggestions with the post title and
    link, the current target value (empty), the suggested text, length
    counters coloured against the limits, and the flags. An editor can
    edit a suggestion inline before accepting. Edited text is validated by
    the same length and normalisation rules (cases 23 to 27, without the
    model retry); text that fails is not accepted and the editor sees why.
37. Accepting writes through the adapter. Immediately before writing each
    field, the plugin re-reads the target. If it is no longer empty (and
    not ours), the field is not written and the suggestion becomes
    `superseded` for that field. The read and the write happen in the same
    request with no model call between them.
38. If the post's cleaned input hash has changed since the suggestion was
    made, the suggestion is `stale`. A stale suggestion cannot be accepted
    in bulk; it can be accepted singly after the editor ticks a
    confirmation that they have read it, or regenerated.
39. Bulk accept applies to the suggestions the editor selected on the
    current screen, never to "all pending" by a hidden query. In WP-CLI,
    bulk accept takes explicit IDs or `--all-pending` together with
    `--yes`; without `--yes` it prints what it would write and stops.
40. Rejecting stores status `rejected` and the user. The text is kept for
    audit until the retention purge (6.3).
41. Revert restores the previous value for a field only while the target
    still holds exactly what meta-writer wrote. If a person has changed it
    since, revert refuses with reason `changed_since_write`.
42. Accepting or rejecting requires the `edit_post` capability for that
    post, plus the review capability (default `edit_others_posts`, or
    `edit_posts` when `allow_authors` is on). Generating requires the
    generate capability (default `edit_others_posts`). Settings and bulk
    runs from the admin screen require `manage_options`. Each is filterable.

### 4.7 Bulk runs and dry run

43. A dry run walks the same selection as a real run and prints, per
    post: ID, title, which fields are wanted, the skip reason if skipped,
    and the cleaned input length. It ends with totals per reason, the
    number of model calls a real run would make, and how many of those fit
    within today's remaining cap. It makes no model call, writes no
    suggestion, writes no target, and does not move the cap counter.
44. Dry run is the default for WP-CLI `generate` and for the admin bulk
    form: a real run needs `--run` (WP-CLI) or a second confirmation step
    (admin). This makes the expensive action the explicit one.
45. A real run stops at the first of: the cap is reached, the kill switch
    is on, the time budget is spent (default 50 seconds per cron tick,
    unlimited in WP-CLI unless `--time` is given), the post limit is
    reached (`--limit`), credit is exhausted, or there are no more
    candidates. It reports the stop reason, counts per outcome and the
    cursor to resume from.

### 4.8 Daily cap and kill switch

46. The daily cap is a count of model calls per day (default 200). Every
    attempt that reaches the library counts, including the validation
    retry and failed calls. A dry run counts nothing.
47. The cap counter is incremented atomically before the call, with an
    add-or-increment that cannot be lost to two concurrent runs (5.7).
    When the increment would pass the cap, the call is not made and the
    run stops with reason `capped`.
48. The cap resets at the start of the next day. No counter is carried
    over.
49. A cap of 0 means no calls at all. There is no "unlimited" value; the
    largest allowed value is 100,000.
50. The kill switch is an option toggled from the settings screen or
    WP-CLI, and also the constant `META_WRITER_DISABLED`. When the
    constant is true, the option cannot turn generation back on, and the
    screen says so. When the switch is on: no model calls, scheduled runs
    stop at their next check, running runs stop before their next call,
    and generation buttons are disabled with a notice.
51. The kill switch does not block review, accept, reject or revert.
    Those spend nothing and an editor should be able to clear the queue.
52. The cap and kill switch are checked again inside the queued job
    handler (case 56), not only when enqueueing.

### 4.9 On-publish trigger

53. With `on_publish` on (default off), a post moving into an enabled
    status for the first time schedules a single-post generation in WP-Cron
    a short delay later (default 60 seconds), never inline in the save
    request. The result is a pending suggestion, never a write.
54. Repeated saves within the delay do not schedule more than one job per
    post.
55. Scheduled jobs obey the same selection, cap and kill switch.

### 4.10 Integration with optional plugins

56. Queue mode: when `gen-queue` is active and `use_queue` is on, each
    post becomes a job of type `meta_writer_generate` enqueued through the
    `gen_queue_enqueue_job` filter with an idempotency key of the post ID
    plus input hash. The handler does the same work as a direct run. When
    gen-queue is absent, the filter returns null and the plugin runs
    directly.
57. Spend metering: when `spend-meter` is active, every call follows its
    reservation protocol: one pre-spend check, then exactly one record or
    release for the reservation. A deny (`allowed` not exactly true) stops
    the run with reason `spend_denied` and the meter's reason code in the
    log. An unknown reason code is treated as a deny.
58. Prompt editing: when `prompt-library` is active, see case 19. A render
    failure falls back to the built-in prompt and logs a warning; it does
    not stop the run.

### 4.11 SEO plugin targets

59. Exactly one adapter is active at a time. With setting `target` on
    `auto` (default), the plugin picks the one SEO plugin it detects as
    active. If more than one is active, auto refuses to pick, generation
    is blocked with a notice, and the operator must choose. If none is
    active, the built-in fallback target is used (case 63).
60. If the chosen SEO plugin is later deactivated, generation and accept
    are blocked with reason `target_unavailable` until the operator picks
    another target. Pending suggestions stay pending.
61. Each adapter reads and writes post meta only, with the WordPress meta
    API, so object caches stay consistent and the SEO plugin's own hooks
    on meta updates fire. Adapters never write to a third-party plugin's
    own database tables (see 5.6 for All in One SEO).
62. An adapter's empty test treats a value of only whitespace as empty.
63. Fallback target: when no SEO plugin is in use, accepted values are
    stored in meta-writer's own meta keys. The plugin then prints a
    `<meta name="description">` tag in the head and filters the document
    title for singular views of that post. It does nothing on other views
    and prints nothing when the value is empty. If an SEO plugin is
    activated later, fallback output switches off automatically to avoid
    two description tags, and WP-CLI `migrate-target` copies fallback
    values into the new plugin's empty fields (never over manual values).

### 4.12 Edge cases and failure modes

64. Post deleted between generation and accept: the suggestion is
    dropped when the post is deleted (hooked to post deletion); a stale
    request for it fails with `post_missing`.
65. Post moved to trash: pending suggestions stay but are hidden from the
    review list until the post is restored.
66. Two editors accept the same suggestion at once: the status change from
    `pending` to `accepted` is a compare-and-set; the second request sees
    it already accepted and writes nothing.
67. A run and an editor's manual edit race: case 37's re-read decides.
    The manual value always wins.
68. Model returns text in the wrong language or script: the plugin checks
    that at least half of the letters in the output are in the expected
    script when the expected language uses a non-Latin script; failing
    that is a validation failure (case 28).
69. Model returns the same text for title and description: validation
    failure for the description.
70. Model output contains a URL, an email address or a phone number that
    is not in the input: the value is flagged `unsupported_detail` for
    review, not auto-rejected.
71. Very long post (hundreds of thousands of characters): the body budget
    (case 12) bounds the input; memory use is bounded by processing one
    post at a time.
72. Post with only embedded media and a caption: `too_little_content`.
73. Right-to-left languages: lengths and cuts work on graphemes; no
    direction marks are added.
74. Provider returns a refusal or content-filter error: `failed` with that
    class, counted against the cap, run continues.
75. Database write of a suggestion fails: the run logs it, counts the
    call (money was spent), marks nothing, and continues. It never throws
    out of a cron handler.
76. Multisite: settings, cap counter, kill switch and suggestions are per
    site. A network option for a network-wide kill switch is honoured
    when set (constant or network setting).
77. Site time zone changes mid-day: the counter key is the date string in
    the zone at the time of the call, so the worst case is one day with
    a counter that started fresh early or late. Documented, not fixed.
78. Object cache flushed mid-day: the counter lives in the database
    (5.7), not only in the object cache, so a flush does not reset it.
79. Library missing (installed without Composer and without the bundled
    vendor copy): the plugin loads, shows an admin notice, and disables
    generation. Review, accept and revert still work.

Fail open or closed:

- Generation fails closed: any doubt about cap, kill switch, spend meter
  or target means no call.
- Writing fails closed: any doubt about whether a target is empty means
  no write.
- Front-end output (fallback target) fails open: an error reading meta
  prints nothing extra and never breaks the page.

## 5. Public interface

Namespace `IndianOpinion\MetaWriter`. Global functions and hooks use the
prefix `meta_writer_`. Post meta keys use the prefix `_meta_writer_`
(leading underscore, hidden from the custom fields box).

### 5.1 PHP functions

| Function | Returns | Notes |
|---|---|---|
| `meta_writer_generate(int $post_id, array $args = [])` | `Suggestion` or `WP_Error` | One post. Args: `fields`, `regenerate`, `refresh_own`, `dry_run`. Never writes a target. |
| `meta_writer_get_suggestion(int $post_id)` | `?Suggestion` | Latest suggestion for the post. |
| `meta_writer_accept(int $suggestion_id, array $edits = [], array $args = [])` | `AcceptResult` or `WP_Error` | Edits: `title`, `description`. Args: `confirm_stale`. Checks capabilities for the current user. |
| `meta_writer_reject(int $suggestion_id)` | `true` or `WP_Error` | |
| `meta_writer_revert(int $post_id, string $field)` | `true` or `WP_Error` | Case 41. |
| `meta_writer_is_manual(int $post_id, string $field)` | `bool` | Case 31. |
| `meta_writer_measure(string $text)` | `int` | Grapheme length, case 24. |
| `meta_writer_fit(string $text, int $min, int $max, string $field)` | `FitResult` | Normalise and shorten without a model (cases 23 to 27). Pure; usable in tests. |
| `meta_writer_is_enabled()` | `bool` | Fresh read of kill switch and constant. |
| `meta_writer_cap_remaining()` | `int` | Calls left today. |

### 5.2 Value objects

- `Suggestion` (read-only): `id`, `postId`, `fields` (list), `title`,
  `description`, `titleLength`, `descriptionLength`, `flags` (list),
  `status`, `inputHash`, `route`, `promptVersion`, `createdAt`,
  `decidedBy`, `decidedAt`, `error`.
- `AcceptResult`: `written` (list of fields), `superseded` (list of
  fields), `skipped` (field to reason).
- `FitResult`: `text`, `length`, `ok` (bool), `trimmed` (bool),
  `reason` (null or one of `empty`, `too_short`, `same_as_title`,
  `wrong_script`).
- `RunReport`: `dryRun`, `considered`, `skipped` (reason to count),
  `generated`, `failed`, `calls`, `stopReason`, `nextCursor`, `seconds`.

Errors returned as `WP_Error` use codes: `meta_writer_disabled`,
`meta_writer_capped`, `meta_writer_spend_denied`,
`meta_writer_target_unavailable`, `meta_writer_forbidden`,
`meta_writer_post_missing`, `meta_writer_stale`, `meta_writer_invalid`,
`meta_writer_changed_since_write`, `meta_writer_not_pending`. Internal
code throws typed exceptions extending `MetaWriterException`; public
functions convert them to `WP_Error` at the boundary.

### 5.3 Hooks

Filters:

| Filter | Arguments | Default |
|---|---|---|
| `meta_writer_should_process` | `bool $yes, WP_Post $post` | true |
| `meta_writer_post_types` | `array $types` | `['post','page']` |
| `meta_writer_post_statuses` | `array $statuses` | `['publish','future']` |
| `meta_writer_clean_input` | `string $text, WP_Post $post` | built-in cleaner |
| `meta_writer_post_language` | `string $bcp47, WP_Post $post` | site locale |
| `meta_writer_limits` | `array $limits, WP_Post $post` | keys `title_min`, `title_max`, `description_min`, `description_max` |
| `meta_writer_prompt` | `array $messages, array $vars, WP_Post $post` | built-in prompt |
| `meta_writer_llm_client` | `?Client $client` | built from settings |
| `meta_writer_validate` | `FitResult $result, string $field, WP_Post $post` | built-in result; may only turn ok into not ok |
| `meta_writer_adapters` | `array $adapters` | the five shipped adapters |
| `meta_writer_title_suffix` | `string $suffix` | empty |
| `meta_writer_capability` | `string $cap, string $action` | actions `generate`, `review`, `settings` |
| `meta_writer_default_settings` | `array $defaults` | section 7 |

Actions:

| Action | Arguments |
|---|---|
| `meta_writer_suggestion_created` | `Suggestion $s` |
| `meta_writer_accepted` | `Suggestion $s, AcceptResult $r, int $user_id` |
| `meta_writer_rejected` | `Suggestion $s, int $user_id` |
| `meta_writer_reverted` | `int $post_id, string $field, int $user_id` |
| `meta_writer_run_finished` | `RunReport $report` |
| `meta_writer_cap_reached` | `int $cap, string $day` |
| `meta_writer_kill_switch_changed` | `bool $old, bool $new, string $actor` |
| `meta_writer_log` | `string $level, string $message, array $context` |

Per-post opt-out: post meta `_meta_writer_skip` set to `1`, editable as a
checkbox in the post edit screen for users who can edit the post.

### 5.4 WP-CLI

Command `wp meta-writer`:

| Subcommand | Behaviour |
|---|---|
| `generate [<id>...] [--post-type=] [--status=] [--fields=title,description] [--limit=] [--from-id=] [--order=oldest\|newest] [--time=] [--regenerate] [--refresh-own] [--run] [--format=table\|json\|csv]` | Dry run unless `--run`. |
| `list [--status=pending\|accepted\|rejected\|stale\|superseded\|failed] [--flag=] [--format=]` | Suggestions. |
| `show <id>` | One suggestion with lengths, flags and the current target values. |
| `accept <id>... \| --all-pending [--confirm-stale] [--yes]` | Prints what it would write without `--yes`. Runs as the user given by the global `--user` flag, which is required. |
| `reject <id>... [--yes]` | |
| `revert <post-id> --field=title\|description [--yes]` | |
| `status` | Target, detected SEO plugins, kill switch, cap used and left, pending count, last run report. |
| `kill on\|off` | Toggle the option. Reports when the constant overrides it. |
| `migrate-target --to=<adapter> [--yes]` | Case 63. |
| `purge [--older-than=<days>] [--yes]` | Case in 6.3. |

Exit codes: 0 success, 1 error, 2 stopped by cap, kill switch or spend
deny (so scripts can tell "nothing more allowed today" from a fault).

### 5.5 REST API

Namespace `meta-writer/v1`, all routes require authentication and a
nonce for cookie auth:

- `GET /suggestions?status=&page=&per_page=` (review capability)
- `GET /suggestions/<id>`
- `POST /suggestions/<id>/accept` body `title`, `description`,
  `confirm_stale`
- `POST /suggestions/<id>/reject`
- `POST /posts/<id>/generate` (generate capability; one post only; no bulk
  over REST)
- `POST /posts/<id>/revert` body `field`

Bulk generation is not exposed over REST.

### 5.6 Adapters

Interface `TargetAdapter`: `id()`, `label()`, `isAvailable()`,
`read(int $post_id, string $field): string`,
`write(int $post_id, string $field, string $value): void`,
`clear(int $post_id, string $field): void` (used only by revert),
`suffixHint(): string` (the plugin's own separator and site-name syntax,
for documentation only).

Shipped adapters and the post meta keys they use (public, documented by
each SEO plugin and visible in its exports):

| Adapter id | Title key | Description key | Detection |
|---|---|---|---|
| `yoast` | `_yoast_wpseo_title` | `_yoast_wpseo_metadesc` | Yoast SEO active |
| `rankmath` | `rank_math_title` | `rank_math_description` | Rank Math active |
| `seopress` | `_seopress_titles_title` | `_seopress_titles_desc` | SEOPress active |
| `aioseo` | `_aioseo_title` | `_aioseo_description` | All in One SEO active |
| `fallback` | `_meta_writer_title` | `_meta_writer_description` | always available |

Detection uses the plugin's public version constant or main class, never
file paths. All in One SEO keeps its working copy of these values in its
own table and treats the post meta keys as a copy; whether a post meta
write is picked up without a re-save is open question 13.1. Until the
builder has confirmed it from that plugin's public documentation, the
`aioseo` adapter ships marked experimental, and the settings screen says
so.

### 5.7 Storage helpers

The daily counter uses an option per day (`meta_writer_calls_<Y-m-d>`,
autoload off) incremented with a single SQL `UPDATE ... SET value = value
+ 1 WHERE value < cap` and an insert-if-absent, so concurrent runs cannot
both take the last call. Counters older than 7 days are removed by the
daily cleanup event.

## 6. Data

### 6.1 Suggestions

A custom table `{prefix}meta_writer_suggestions`, one row per suggestion:
`id`, `post_id`, `fields`, `title`, `description`, `title_len`,
`description_len`, `flags`, `status`, `input_hash`, `route`,
`prompt_version`, `error_class`, `error_message`, `raw` (nullable, case
28), `description_hash`, `created_at`, `created_by`, `decided_at`,
`decided_by`. Indexes on `(status, created_at)`, `post_id` and
`description_hash`. Created with `dbDelta` on activation and on version
change; after creation, the plugin checks the columns exist (with `SHOW
COLUMNS`) and shows an admin notice when one is missing (section 14).

### 6.2 Post meta

- `_meta_writer_written`: JSON per field: value, SHA-256, previous value,
  user, time, adapter, suggestion id. Up to the last 5 writes per field.
- `_meta_writer_skip`: per-post opt-out.
- `_meta_writer_title`, `_meta_writer_description`: fallback target only.

### 6.3 Options

`meta_writer_settings` (one array, autoload on, small),
`meta_writer_kill` (bool, autoload on), daily counters (5.7), last run
report (autoload off), log ring buffer (last 500 entries, autoload off).

### 6.4 Retention

Rejected, failed, superseded and stale suggestions older than 90 days
(setting) are removed by the daily cleanup event. Accepted rows are kept
as the audit trail until uninstall. Raw model output is cleared after 14
days regardless.

### 6.5 Uninstall

`uninstall.php` removes the suggestions table, the options, the counters,
the scheduled events, `_meta_writer_written`, `_meta_writer_skip`, and
the fallback keys only when the operator ticked "remove fallback values on
uninstall" (default off, because they are live content). It never touches
any SEO plugin's keys: values written there are the site's content now.
On multisite it does this for every site.

## 7. Configuration

| Setting | Default | Notes |
|---|---|---|
| `target` | `auto` | `auto` or an adapter id |
| `fields` | `title,description` | |
| `post_types` | `post,page` | public types only |
| `post_statuses` | `publish,future` | |
| `title_min` / `title_max` | 30 / 60 | 10 to 120 allowed |
| `description_min` / `description_max` | 70 / 155 | 50 to 320 allowed |
| `title_suffix` | empty | text the SEO plugin appends; its length is subtracted from the title budget |
| `body_budget` | 6000 characters | 500 to 20000 |
| `min_words` | 40 | |
| `daily_cap` | 200 calls | 0 to 100000 |
| `kill` | off | also constant `META_WRITER_DISABLED` |
| `on_publish` | off | |
| `on_publish_delay` | 60 s | |
| `use_queue` | on when gen-queue present | |
| `allow_authors` | off | |
| `keep_raw` | off | |
| `retention_days` | 90 | |
| `cron_time_budget` | 50 s | |
| `batch_size` | 100 | |
| Model route | none | provider, model, key; key from constant `META_WRITER_API_KEY` preferred over the option |

Constants override options and the screen shows which values are fixed by
a constant. Invalid values are rejected on save with a message; they are
never silently clamped.

## 8. Security and privacy

- Capabilities and nonces on every admin action, form and REST route
  (case 42). REST routes declare a permission callback; none is public.
- All admin output escaped. Suggested text is shown as text, never HTML.
- No `eval`, no shell, no remote code, no `@` suppression.
- The API key is never printed back in full on the settings screen (last
  4 characters only), never logged, never sent anywhere but the chosen
  provider, and never stored when the constant is used.
- Post content is sent to the third-party model provider. The settings
  screen says this plainly, and the plugin adds a paragraph to the
  WordPress privacy policy guide. Private, draft and password-protected
  posts are excluded by default for this reason.
- Log lines and stored errors are redacted: no key, no full prompt, no
  full body. At most the first 200 characters of a provider error.
- Values written to targets are plain text: tags stripped and entities
  decoded on the way in, so a model cannot inject markup or scripts into
  the head. The SEO plugin escapes on output; the fallback target escapes
  with the attribute and HTML escaping functions.

## 9. Performance budget

- Front end: zero queries added when an SEO plugin is the target. With
  the fallback target, one post meta read on singular views (from the
  primed meta cache), nothing elsewhere.
- Admin: the review screen pages at 20 rows by default, one query for the
  rows plus primed post and meta caches. No query per row.
- Selection: indexed queries in batches of `batch_size`; no
  `posts_per_page = -1`, no offset paging.
- One model call per post per run in the normal case, two at most (case
  28).
- Memory per post bounded by the body budget; a 50,000-post dry run must
  complete in WP-CLI within 256 MB.
- Cron ticks bounded by `cron_time_budget`.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. `mbstring` required; `intl` optional (graphemes).
- WordPress 6.4 or later; block and classic editors.
- SEO plugins: current major versions of Yoast SEO, Rank Math, SEOPress
  and All in One SEO at release time; versions tested go in the README.
- Works as a normal plugin. Not supported as a must-use plugin, because it
  has an activation step (table creation) and an uninstall routine.
- Full-page caches: an accepted value appears once the page cache for that
  post expires or is purged. The plugin calls `clean_post_cache` after a
  write so cache plugins that listen to it purge; it does not call any
  cache plugin directly.
- Object caches: all meta access through the meta API.
- Multisite: per site (case 76).
- Composer: requires `indianopinion/llm-json-php` (listed as a `vcs`
  repository). The release zip bundles it under `vendor/` so the plugin
  installs without Composer.

## 11. Observability

- Status panel on the settings screen and `wp meta-writer status`:
  target, detected SEO plugins, kill switch and its source, cap used and
  left today, pending, stale and failed counts, last run report.
- Log: the `meta_writer_log` action plus a 500-line ring buffer viewable
  on the screen, with level, time, post ID, event and reason code. One
  line per run summary, one per failure, one per accept, reject and
  revert. No line per successful skip in cron (counts only), so the log
  stays readable.
- Admin notices for: kill switch on, cap reached today, target
  unavailable, more than one SEO plugin with `auto`, library missing,
  table column missing.
- Every skip and stop has a reason code from a fixed list:
  `excluded`, `both_filled`, `pending_exists`, `rejected_before`,
  `too_little_content`, `capped`, `disabled`, `spend_denied`,
  `credit_exhausted`, `target_unavailable`, `time`, `limit`, `empty`.

## 12. Acceptance tests

Each test names the case it covers. Tests run under PHPUnit with a stub
layer for WordPress and a mock transport for the model library.

| Test | Cases | Asserts |
|---|---|---|
| T1 | 1 | Post of enabled type and status is a candidate; disabled type is not. |
| T2 | 2 | Draft, private, revision and autosave are not candidates; a revision cannot be enabled. |
| T3 | 3 | Opt-out meta and the `should_process` filter both give `excluded` and no call. |
| T4 | 4 | Both targets filled gives `both_filled` and zero calls on the mock transport. |
| T5 | 5 | One empty field: one call, only that field stored in the suggestion. |
| T6 | 6 | Existing pending skips; `--regenerate` replaces it. |
| T7 | 7 | Rejected skips unless regenerate. |
| T8 | 8, 9 | Two batches of 100 cover IDs in order with no gap or repeat; resume from cursor continues exactly. |
| T9 | 10 | Entities decoded, tags, shortcodes, block comments and captions removed, whitespace collapsed. |
| T10 | 11 | A shortcode with a side effect is not executed. |
| T11 | 12 | Body over budget cut at a boundary; output is valid UTF-8. |
| T12 | 13, 72 | Under 40 words and an image-only post give `too_little_content` and no call. |
| T13 | 14 | Language filter value reaches the prompt variables. |
| T14 | 15 | Prompt payload contains no author email, comments or custom fields. |
| T15 | 16, 17 | Request carries the strict two-property schema and the length variables. |
| T16 | 18, 21 | Library exception marks `failed`, counts against cap, run continues. |
| T17 | 19, 58 | With prompt-library present, its render is used; a render failure falls back and logs. |
| T18 | 20, 50 | Kill switch on: no transport call. Constant overrides the option. |
| T19 | 22 | Credit exhausted stops the run with `credit_exhausted`. |
| T20 | 23 | Wrapping quotes, labels, line breaks and tags are removed. |
| T21 | 24, 73 | Lengths count graphemes for combining marks, emoji sequences, Devanagari and Arabic samples; bytes never. |
| T22 | 25 | Title budget shrinks by the suffix length. |
| T23 | 26 | Long title cut at a word, no ellipsis; long description cut at a sentence when possible, else a word plus one ellipsis within the max; no trailing comma before it; no-space script cut at punctuation. |
| T24 | 27, 28 | Too short, empty and same-as-title trigger exactly one retry; second failure gives `invalid_output`. |
| T25 | 29 | Duplicate description is flagged, not rejected. |
| T26 | 30, 31 | Write records provenance; a one-character edit after makes the field manual. |
| T27 | 32 | No code path (accept, bulk accept, regenerate, refresh-own, revert, migrate, uninstall) changes a manual value. |
| T28 | 33 | Own value is not regenerated without `--refresh-own`; with it, a pending suggestion is made, not a write. |
| T29 | 34 | A field holding only a template variable is manual. |
| T30 | 35, 36 | Suggestion stores all listed properties; inline edit is validated and a failing edit is refused with a reason. |
| T31 | 37, 67 | Target filled between generation and accept: not written, `superseded`. |
| T32 | 38 | Changed post makes the suggestion `stale`; bulk accept refuses; single accept with confirmation writes. |
| T33 | 39 | CLI `accept --all-pending` without `--yes` writes nothing and prints the plan. |
| T34 | 40 | Reject stores user and status. |
| T35 | 41 | Revert restores while unchanged; refuses with `changed_since_write` after an edit. |
| T36 | 42 | Each action denied without its capability; author allowed only with `allow_authors`. |
| T37 | 43, 44 | Dry run: zero transport calls, zero writes, counter unchanged, totals and call estimate correct; default is dry. |
| T38 | 45 | Each stop reason is reported with the right cursor. |
| T39 | 46, 47 | Cap of 3 with two concurrent runs (simulated) allows exactly 3 calls; retries and failures count. |
| T40 | 48, 77 | Counter key follows the site day; next day starts at zero. |
| T41 | 49 | Cap 0 makes no call; values over 100000 are rejected on save. |
| T42 | 51 | With kill switch on, accept, reject and revert still work. |
| T43 | 52, 56 | Queue mode enqueues with the idempotency key; handler rechecks cap and kill switch; without gen-queue runs directly. |
| T44 | 53, 54, 55 | First publish schedules one job after the delay; repeated saves do not add jobs; result is pending, not written. |
| T45 | 57 | Spend-meter deny stops the run; allow is followed by exactly one record or release; unknown reason is a deny. |
| T46 | 59 | Auto with two SEO plugins blocks; with one, picks it; with none, fallback. |
| T47 | 60 | Deactivated target blocks accept with `target_unavailable`; pending stays. |
| T48 | 61 | Each adapter writes through the meta API to its documented keys. |
| T49 | 62 | Whitespace-only target is empty. |
| T50 | 63 | Fallback prints one escaped description tag and title on singular views only; switches off when an SEO plugin appears; migrate copies only into empty fields. |
| T51 | 64, 65 | Deleted post drops suggestions; trashed post hides them until restore. |
| T52 | 66 | Two concurrent accepts write once. |
| T53 | 68 | Latin output for a Hindi post fails validation and retries. |
| T54 | 69 | Description equal to title fails. |
| T55 | 70 | URL or phone not in input sets `unsupported_detail`. |
| T56 | 71 | A 500,000-character post stays within the body budget and memory bound. |
| T57 | 74 | Refusal gives `failed` with the refusal class. |
| T58 | 75 | Suggestion insert failure is logged, call counted, no exception escapes cron. |
| T59 | 76 | Multisite: cap and kill switch are per site; network kill switch blocks all. |
| T60 | 78 | Flushing the object cache does not reset the counter. |
| T61 | 79 | Missing library: notice shown, generation disabled, accept works. |
| T62 | 6.5 | Uninstall removes own data and leaves SEO plugin keys untouched. |
| T63 | 8 (section) | API key not present in logs, errors or settings output. |

## 13. Open questions

Answered by the builder in `docs/ARCHITECTURE.md`.

1. All in One SEO keeps these values in its own table. Does its public
   documentation give a supported way to set a post's SEO title and
   description from another plugin (a filter, a function or a REST
   field), and does a post meta write alone show on the front end? If
   there is no supported way, ship the adapter as experimental with a
   clear notice, or drop it from 0.1.0. Never write its table directly.
2. Day boundary: site time zone (proposed here) or UTC? Record the choice
   and why.
3. Length in characters or in pixel width? Search engines truncate by
   width. Characters are proposed for 0.1.0; is an optional width
   estimate worth adding later?
4. Should there be an opt-in to treat a target that holds exactly the SEO
   plugin's default template variable as empty? Default stays "manual".
5. Is a custom table the right home for suggestions at 100,000 posts, or
   is post meta enough? The table is proposed for indexed review queries.
6. Term and author archives: in scope for 0.2.0 or not at all?
7. Should the block editor get a sidebar panel in 0.1.0, or only the
   classic meta box plus the review screen?
8. Script check (case 68): which scripts get the check, and how is the
   expected script derived from a BCP 47 tag without a large table?

## 14. Lessons from production

Written as rules. Each one cost real time on a live site.

1. Never treat a non-empty field as yours unless it is byte-equal to what
   you wrote. People edit generated text by one word, and that edit is
   theirs.
2. Decode HTML entities before measuring or sending. WordPress stores
   titles and term names escaped; `&amp;` counts as five characters and
   shows as one, and it leaks into JSON and prompts if you forget.
3. Measure length in characters a reader sees, never in bytes. Byte
   counts make every non-Latin description look three times too long.
4. Cut at sentence or word boundaries. A description cut mid-word reads
   as broken in the results page and looks worse than none.
5. Do not generate from nothing. A page whose body is empty, or a
   template, gets no generated description; write that one by hand.
6. Do not fill thin pages with a template sentence. The same sentence on
   thousands of pages is a duplicate-content signal. Flag duplicates.
7. Keep a fallback path that costs nothing. When the model is unavailable,
   leave the field empty so the SEO plugin's own fallback stands; never
   write a placeholder.
8. When a translated or language-specific copy has no description of its
   own, describe it in its own language; never fall back to the English
   text on a non-English page.
9. Schema changes need checking. `dbDelta` has silently skipped adding a
   column; verify columns after creating or upgrading the table.
10. Counters for spend live in the database, not only in the object cache.
    A cache flush must never reset a cap.
11. A bulk job that spends money is a dry run until someone says otherwise.
12. One switch must stop spending immediately and be impossible to
    override from the screen when an operator has set it in code.
13. Never write to a third-party plugin's private tables. Use the meta
    keys it reads and documents, through the meta API, so its own hooks
    and caches stay consistent.
