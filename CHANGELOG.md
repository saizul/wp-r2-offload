# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-14

### Added

- AWS Signature Version 4 signer (header and presigned URLs) without the AWS SDK,
  tested against all 38 cases of the AWS SigV4 test suite and the signed examples of
  the Amazon S3 documentation.
- S3 client for Cloudflare R2: PutObject, HeadObject, GetObject, DeleteObject,
  DeleteObjects, ListObjectsV2 and multipart upload, with retries and XML error parsing.
- Multipart uploader with R2-compliant uniform part sizes and resumable state.
- Offload of the original, scaled original, all sizes, WebP/AVIF sources and editor
  backups; mirroring of edits and deletes.
- URL rewriting for attachment URLs, image src, srcset and post content.
- Optional removal of local files, protection against filename reuse and
  restore before image editing.
- WP-CLI `wp r2 offload` (batches, limit, resume, dry run), `status`, `verify --fix`
  and `restore`.
- Configuration through `WP_R2_OFFLOAD_*` constants or a settings screen with
  encrypted credentials and a connection test.
- `.pot` file, `uninstall.php`, PHPUnit suite running without WordPress, WordPress
  Coding Standards config and GitHub workflows for CI and release zips.

[Unreleased]: https://github.com/saizul/wp-r2-offload/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/saizul/wp-r2-offload/releases/tag/v0.1.0
