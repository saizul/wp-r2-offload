# Contributing

Thanks for helping improve R2 Offload. Bug reports, compatibility fixes for other
media plugins, documentation and translations are all welcome.

## Reporting a bug

Open an issue with:

- WordPress and PHP versions, single site or multisite
- the output of `wp r2 status` (secrets are masked)
- the error shown in the attachment details or by `wp r2 offload`
- for S3 errors: the error code and message (for example `SignatureDoesNotMatch`)

Never post access keys, secret keys or private URLs.

## Development setup

```bash
git clone https://github.com/saizul/wp-r2-offload.git
cd wp-r2-offload
composer install
composer test     # PHPUnit
composer phpcs    # WordPress Coding Standards
composer lint     # php -l
```

PHP 7.4 or newer is required. The dependencies are resolved for PHP 7.4
(`config.platform`) so the same lock file works across the CI matrix.

## Guidelines

- Keep `src/` free of WordPress functions and cover it with tests. WordPress
  integration lives in `includes/` and is tested against `tests/Stubs/` and the fake
  S3 server in `tests/WpTestCase.php`.
- Changes to the signer must keep the AWS test suite (`tests/Fixtures/aws-sigv4`) and
  the S3 documentation examples passing.
- Only use S3 features that Cloudflare lists as implemented for R2, and link the
  documentation in the pull request.
- Code must run on PHP 7.4: no union types, `match`, named arguments, constructor
  promotion, or argument unpacking with string keys.
- Follow the WordPress Coding Standards (`composer phpcbf` fixes most issues), escape
  all output and check capabilities and nonces in every request handler.
- Wrap user-facing strings with the `wp-r2-offload` text domain and run
  `php bin/make-pot.php` (CI checks the `.pot` file).
- New hooks start with `wp_r2_offload_` and are documented in the README.
- Add an entry under "Unreleased" in `CHANGELOG.md`.

## Releasing

1. Update the version in `wp-r2-offload.php` (header and constant),
   `includes/Plugin.php`, `readme.txt` (Stable tag and changelog) and `CHANGELOG.md`.
2. Tag `vX.Y.Z` and publish a GitHub release. The `Release zip` workflow checks the
   versions and attaches `wp-r2-offload.zip`.

By contributing you agree that your contribution is licensed under the
GPL-2.0-or-later license of this project.
