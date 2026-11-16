# Contributing to meta-writer

Thank you for helping. Bug reports, fixes and documentation are all welcome.

## Setup

You need PHP 8.1 or later with `mbstring` (and ideally `intl`) and Composer 2.

```sh
git clone https://github.com/IndianOpinion/meta-writer
cd meta-writer
composer install
```

No WordPress install is needed for the tests: `tests/stubs/` provides the
small part of WordPress the plugin uses.

## Tests

```sh
composer test        # PHPUnit
composer analyse     # PHPStan, level 8
composer lint        # phpcs, PSR-12
composer check       # all three
```

The `db` test suite checks the SQL against a real MySQL or MariaDB. It is
skipped unless you point it at a disposable database:

```sh
META_WRITER_TEST_DB_HOST=127.0.0.1 META_WRITER_TEST_DB_USER=root META_WRITER_TEST_DB_PASS=secret vendor/bin/phpunit --testsuite db
```

It creates and drops tables in `meta_writer_test` (or
`META_WRITER_TEST_DB_NAME`). Never point it at a real site's database.

## Style

- PSR-12, `declare(strict_types=1);` in every PHP file, PHP 8.1 syntax only
  (no readonly classes, no DNF types).
- Escape every output, check a capability and a nonce on every admin action.
- No `eval`, no shell, no `@` suppression. Typed exceptions inside; WP_Error
  only at the public boundary.
- Plain British English in docs and messages. No em dashes.

## Pull requests

- One topic per pull request, with tests for the behaviour you change.
- `composer check` must pass.
- Describe the user-visible change and add a line to `CHANGELOG.md` under
  Unreleased.
- A DCO-style sign-off is not required.
