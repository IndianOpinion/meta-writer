# Changelog

All notable changes to this project are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-18

### Added

- Suggestions for the SEO title and meta description of posts, made from
  the post's own title, excerpt and cleaned body through
  `indianopinion/llm-json-php`, or a caller of your own via
  `meta_writer_caller`.
- Review before write: a review screen (Tools > SEO suggestions) with
  inline edits, length counters and flags; accept, reject and revert from
  the screen, the post edit box, REST and WP-CLI.
- Manual values are never overwritten: a field is meta-writer's own only
  while it is byte-equal to what meta-writer wrote, with provenance kept
  per field.
- Targets for Yoast SEO, Rank Math, SEOPress and All in One SEO
  (experimental), plus a fallback that prints a description tag and title
  when no SEO plugin is active, and `migrate-target` to move off it.
- Length rules in characters a reader sees, deterministic shortening at
  sentence or word boundaries, one validation retry, a wrong-script check
  for thirteen scripts, duplicate and unsupported-detail flags.
- Dry run by default for bulk runs, a daily cap counted atomically in the
  database, and a kill switch that a constant can pin on.
- Optional integration with gen-queue (queued jobs with an idempotency
  key), spend-meter contract 1 (one pre-spend check and one record or
  release per call) and prompt-library (an editable default template with
  the built-in prompt as its fallback).
- `wp meta-writer` commands: generate, list, show, accept, reject, revert,
  status, kill, migrate-target and purge.

[Unreleased]: https://github.com/IndianOpinion/meta-writer/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/meta-writer/releases/tag/v0.1.0
