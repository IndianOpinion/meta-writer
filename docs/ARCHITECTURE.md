# meta-writer: architecture

This document describes how meta-writer 0.1.0 is built and records every
decision, including the owner's rulings on the PRD's open questions. The
requirements are in [PRD.md](PRD.md); case numbers below refer to it.

## Modules

| Module | Role |
|---|---|
| `meta-writer.php`, `bootstrap.php` | Plugin header, PHP version guard, autoloading with or without Composer. |
| `Plugin` | Wires hooks: activation (table, cron), version upgrade with column check, deletion hook, queue handler, privacy text, admin, REST, WP-CLI. |
| `Container` | Builds every service once per request. Tests pass in-memory stores and a clock. |
| `Settings` | Defaults, `META_WRITER_*` constant overrides, validation that rejects (never clamps). |
| `KillSwitch` | Constant, network option and site option, read fresh from the database. |
| `DailyCap` | Calls per site day through `CounterStore`. |
| `Store\WpdbSuggestionStore` | The `{prefix}meta_writer_suggestions` table: insert, compare-and-set transitions, paged queries that hide trashed posts, purge, column check. |
| `Store\WpdbCounterStore` | One option row per day, atomic increment, fresh option reads. |
| `Store\WpdbPostSource` | Keyset paging over `wp_posts` (no offset, no unbounded query). |
| `Input` | Title, manual excerpt, cleaned and budgeted body, language, input hash. |
| `Text\Cleaner`, `Text\Fitter`, `Text\Length`, `Text\ScriptCheck`, `Text\DetailCheck` | Pure text functions: cleaning, normalising, measuring, shortening, script share, unsupported details. |
| `Validator` | Limits (with the suffix), fit, script, same-as-title, the validate filter. Shared by model output and inline edits. |
| `Prompt` | Generic built-in prompt, strict schema, prompt-library integration with fallback. |
| `Caller\*` | `ModelCaller` interface; `LlmJsonCaller` over `indianopinion/llm-json-php`; `CallableCaller` for a site's own caller; `CallerFactory`. |
| `Integration\SpendMeter`, `Integration\GenQueue` | Optional companions, active only when they answer their hooks. |
| `Generator` | One post: selection checks, gates, call, one validation retry, flags, store. Never writes a target. |
| `Runner` | Many posts: cursor, batches, stop reasons, dry-run totals, run report. |
| `Reviewer` | Accept, reject, revert, migrate. Re-reads targets before writing; compare-and-set on status. |
| `Provenance` | `_meta_writer_written` records; the "ours or manual" test. |
| `Adapter\*` | `TargetAdapter`, `MetaKeyAdapter`, `AdapterRegistry` (the five shipped adapters and the auto rule). |
| `Cron` | On-publish jobs, admin bulk runs in ticks, daily cleanup. |
| `Admin\*` | Settings and status screen, review screen, post edit box, notices. |
| `Rest\RestController` | `meta-writer/v1` routes. |
| `Cli\Command` | `wp meta-writer`. |
| `Frontend\FallbackOutput` | Description tag and document title for the fallback target. |
| `Api`, `src/functions.php` | Public functions; the only place exceptions become `WP_Error`. |
| `Uninstaller`, `uninstall.php` | Removes own data on every site. |

## Data flow

```
selection (Runner + PostSource, ID cursor)
  -> candidate checks, opt-out, target state per field (Generator + Provenance + adapter)
  -> Input (clean, budget, words, language, hash)
  -> dry run? report and stop here
  -> gates: kill switch -> cap headroom -> spend meter -> atomic cap take
  -> queue mode? enqueue (handler comes back to the gates)
  -> ModelCaller (llm-json-php or the site's caller)
  -> Validator per wanted field; one retry with a note on failure
  -> flags (trimmed, retried, duplicate, unsupported_detail)
  -> suggestion row, status pending
review (Reviewer)
  -> capability, status pending or stale, stale check against a fresh input hash
  -> inline edits validated
  -> compare-and-set pending -> accepted
  -> per field: re-read target; empty or ours -> write through the adapter + provenance; manual -> superseded
  -> clean_post_cache
```

## Exceptions and errors

Internal code throws subclasses of `MetaWriterException`, each with a
WP_Error code from PRD 5.2 and a reason code. `Api` and the REST
controller convert them. Cron handlers catch every `Throwable` and log,
so nothing escapes a cron event (case 75).

## Decisions

### Rulings on the PRD's open questions

1. **All in One SEO (13.1): experimental adapter.** Ruling: ship it as
   experimental; it writes post meta only (`_aioseo_title`,
   `_aioseo_description`), is labelled "may not appear on the page", and
   never touches that plugin's own table. The label is in the adapter name,
   the settings screen notice and the README. Reason: that plugin keeps its
   working copy in its own table, and writing a third-party table is
   forbidden (lesson 13).
2. **Day boundary (13.2): the site's time zone.** Ruling: the cap resets by
   the site's time zone. The counter key is `wp_date('Y-m-d')` at the time
   of the call. Reason: operators think in their own day, and the cap
   notice says "resets at midnight, site time". The cost (case 77): one day
   may start fresh early or late when the zone changes. Documented, not
   fixed.
3. **Length unit (13.3): characters.** Ruling: length is measured in
   characters. A pixel-width estimate needs per-font tables and is left
   for later. Reason: predictable, testable, the same in every script.
4. **Template-only values (13.4): opt-in.** Ruling: yes, with the setting
   `template_as_empty` (default off). Each adapter supplies a pattern for
   one template variable of its plugin (for example `%%name%%`); a value
   made only of such variables and spaces counts as empty when the setting
   is on. Default stays "manual" (case 34). The previous value is kept in
   provenance, so revert restores it.
5. **Suggestions storage (13.5): custom table.** Ruling: a custom table.
   Reason: the review screen needs indexed queries by status, date, post
   and description hash at 100,000 posts; post meta cannot index those.
6. **Term and author archives (13.6): roadmap.** Ruling: not in 0.1.0; a
   roadmap item. The adapter interface is per post, so archives will need a
   second interface.
7. **Block editor sidebar (13.7): no.** Ruling: no sidebar panel in 0.1.0;
   a separate sidebar host plugin will come later. The edit-screen box is a
   classic meta box, which the block editor shows under the content.
8. **Script check (13.8): Unicode ranges and a filterable table.** Ruling:
   the check covers Latin, Devanagari, Bengali, Tamil, Telugu, Kannada,
   Malayalam, Gujarati, Gurmukhi, Odia, Arabic, Cyrillic and CJK (Han,
   kana and Hangul) through explicit Unicode ranges. The expected script
   comes from the BCP 47 tag: an explicit script subtag (`hi-Latn`,
   `pa-Arab`) wins; otherwise the primary language is looked up in a small
   table, filterable with `meta_writer_language_scripts`. Unknown languages
   are not checked. Because the ruling lists Latin, Latin-script languages
   are checked too (the PRD only proposed non-Latin ones): a Devanagari
   answer for an English post fails. The rule is "at least half of the
   letters", counting only letters.

### Builder decisions

9. **Graphemes.** `intl` when present (`grapheme_strlen` and the ICU
   character break iterator for cutting); else PCRE `\X`; `mb_strlen` only
   when the text is not valid UTF-8. Reason: PCRE alone is always there and
   counts combining marks correctly, but older PCRE2 builds join runs of
   emoji ZWJ sequences into one cluster, which made a cut overshoot.
10. **Gate order.** Kill switch, cap headroom (a read), spend meter
    pre-spend, then the atomic cap take. When the take fails after the
    spend meter allowed the call, the reservation is released. Reason: the
    PRD orders cap before spend meter, but taking the cap slot first would
    count a call the spend meter then refuses.
11. **Spend meter protocol (spend-meter contract 1).** Detection is
    `defined('SPEND_METER_API')`. A lower version means no meter; a higher
    one is a contract this release does not know, so every call is denied
    (fail closed). Before each call meta-writer applies
    `apply_filters('spend_meter_pre_spend', null, $intent)` with `purpose`
    `meta-writer.generate`, `caller` `meta-writer`, `provider` and `model`
    from the route label (`provider/model`; a site caller is `custom`),
    `est_input_tokens` (prompt bytes divided by three, a generous
    estimate), `max_output_tokens` (600, the call's token cap) and `meta`
    with the post ID. `null` means no meter answered: the call goes ahead
    under meta-writer's own cap and kill switch. Only `allowed === true`
    with reason `ok` (filterable with `meta_writer_spend_allow_reasons`) is
    an allow. A deny, an unknown reason, a decision whose `api` is not 1, a
    malformed answer and a thrown error all stop the run with
    `spend_denied` and the reason in the log; an allow that is refused this
    way releases its `reservation_id`. After an allow, exactly one of:
    `do_action('spend_meter_record', $call)` for every attempt that reached
    the caller (status `ok` with tokens and the provider's cost in nanos,
    `error_billed` for a failed call, `error_unbilled` with HTTP 402 when
    every provider is out of credit), or `do_action('spend_meter_release',
    $reservation_id)` when meta-writer took the reservation and then did
    not call (the atomic cap take failed). A failed call is recorded, never
    released, because the provider may have billed it.
12. **Queue protocol (gen-queue contract).** Enqueue is
    `apply_filters('gen_queue_enqueue_job', null, 'meta_writer_generate',
    $payload, ['idempotency_key' => 'meta-writer:<post>:<input hash>',
    'source' => 'meta-writer'])`. Only a positive integer job id means the
    queue took the job; `null` (no queue) or a `WP_Error` means the post
    runs directly. The type is registered on `gen_queue_register_types`
    with `gen_queue_register_type()`, three attempts, and `meter.purpose`
    `meta-writer.generate` so gen-queue's own pre-spend check asks about
    the same purpose. The handler receives gen-queue's `Job` and
    `JobContext`, runs the same generator, which re-checks the kill switch
    and the cap (case 52), and reports any provider cost with
    `$ctx->addCost()` (`metered` true when spend-meter already recorded
    it). A stop that clears with time (cap, kill switch, spend deny,
    target) throws gen-queue's `RetryableException` (after the cap: retry
    after the next site midnight), credit exhaustion throws its
    `AccountException`; a generated, skipped or failed post completes the
    job, so the queue never repeats a call that may have been billed.
    Enqueueing checks the kill switch and cap headroom but takes no cap
    slot; the handler takes it.
13. **Prompt library protocol (prompt-library contract 1.0).** On `init`,
    when prompt-library is active, meta-writer calls
    `promptlib_register_consumer('meta-writer-title-description',
    'meta-writer/title-description', <the eight variables>)` and
    `promptlib_register_default()` with the built-in prompt as a
    `prompt-library/1` definition (typed variables, JSON output schema,
    token cap 600), so the template is installed as version 1 and the
    editor can change it. Rendering is `apply_filters('promptlib_render',
    null, slug, vars, 'live', ['consumer' => ..., 'fallback' =>
    <definition>])`. An array with `system` and `user` becomes the
    messages, with prompt version `library-<n>` (a render from the
    fallback counts as the built-in version). `null` means the library is
    absent; a `WP_Error`, an exception or an array without a usable `user`
    part logs a warning and falls back to the built-in prompt (case 58).
14. **Model calls without the library.** Ruling of 14 November: group C
    repos may depend on `indianopinion/llm-json-php` and must also offer a
    filter for a site's own caller. `meta_writer_caller` may return a
    `ModelCaller` or a callable `(messages, schema): array`. When neither
    the filter nor the library is there, generation is off (case 79). A
    client that cannot be built (no key, bad URL, WordPress HTTP missing)
    also means no generation: fail closed.
15. **"First time" on publish.** A post moving from a status outside the
    enabled set into one inside it schedules one job, unless one is already
    scheduled for that post. No extra meta is stored. Republishing after a
    move to draft schedules again, but the selection rules (pending exists,
    both filled, rejected before) stop any repeat spend.
16. **`--limit`.** Counts posts that reach generation (generated, failed,
    queued, or planned in a dry run), not posts skipped. Reason: the limit
    is about spend.
17. **Regenerate.** The new suggestion is stored first; then the old pending
    one moves to `superseded` with "replaced by #id". Reason: a failed
    regeneration must not lose the pending text.
18. **Extra WP_Error codes.** `meta_writer_generate()` must return a
    `Suggestion` or a `WP_Error`. Outcomes that are not errors in the usual
    sense are returned as codes beyond PRD 5.2: `meta_writer_dry_run`,
    `meta_writer_skipped` and `meta_writer_queued` (with the reason in the
    data), plus `meta_writer_credit_exhausted`, `meta_writer_call_failed`
    and `meta_writer_storage`.
19. **"Repeats the title".** A description repeats the title when, after
    lower-casing and dropping punctuation, it equals the post title or the
    generated title, or starts with one of them and adds fewer than 20
    characters.
20. **Admin bulk runs.** The admin form always runs a bounded dry run first
    (20 seconds). The real run needs a ticked confirmation and then proceeds
    in WP-Cron ticks of `cron_time_budget` seconds, storing its cursor
    between ticks, until it stops for any reason but time. It can be
    cancelled. Reason: a web request must never run for minutes.
21. **Must-use loader stub.** The shared brief asks for a must-use loader
    stub; the PRD says the plugin is not supported as a must-use plugin (it
    has a table and an uninstall routine). The stub in
    `examples/mu-plugin-loader.php` therefore does not load the plugin: it
    pins settings and the kill switch in code. Both statements hold.
22. **Constants for every setting.** `META_WRITER_<KEY>` fixes any setting,
    not only the two the PRD names. The screen disables those inputs and
    says which constant fixes them. When `META_WRITER_API_KEY` is defined
    the stored key is blanked on the next save.
23. **Credit exhausted.** The post that hit it gets a `failed` row with
    error class `CreditExhaustedException`, the call counts against the cap,
    and the run stops with cursor before that post, so a resume retries it.
24. **Partial supersede.** When one field is written and the other was
    filled meanwhile, the suggestion stays `accepted` with a note; when
    nothing is written it becomes `superseded`.
25. **Fresh reads.** The kill switch and the counter are read with direct
    queries, never through the options cache, so an object cache flush or a
    long WP-CLI run cannot miss a change. The network kill switch is the
    network option `meta_writer_network_kill` (or the constant, which is
    global anyway).
26. **Stale detection.** The input hash covers title, excerpt, cleaned body
    and language. The first accept attempt that sees a different hash marks
    the row `stale`; bulk accept always refuses stale rows; a single accept
    needs `confirm_stale`.
27. **Title cuts.** Trailing commas, colons, semicolons and dashes are
    removed at a title cut too, not only before an ellipsis. A cut at a
    full sentence never gets an ellipsis.
28. **Fallback document title.** Set through `pre_get_document_title`,
    escaped with `esc_html`, because WordPress prints that value as is.
29. **Library transients.** Uninstall does not remove `llm-json-php`'s
    cooldown transients: they may be shared with other plugins using the
    same library and they expire on their own.
30. **Unsupported details.** URLs are compared without scheme and `www.`,
    phone numbers by digits (eight or more), emails case-insensitively.
    Years and ranges are not phone numbers.
31. **Selection order for explicit IDs.** `wp meta-writer generate 5 3 9`
    processes in the order given, and the cursor is the last ID done.

## Tests

PHPUnit 10 with a stub layer in `tests/stubs/` (no WordPress install) and
in-memory stores with the same contracts as the table stores. Every
acceptance test T1 to T63 maps to a test method; the docblock of each test
names it. The `db` suite runs the three `Wpdb*` stores against a real MySQL
or MariaDB (set `META_WRITER_TEST_DB_SOCKET` or `META_WRITER_TEST_DB_HOST`),
including the cap race with two connections; it is skipped when no database
is configured, and CI runs it against a MySQL service.
