# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `wp r2 orphans`: lists objects under the key prefix that no attachment record
  refers to (left behind by deletes while the plugin was inactive, failed deletes
  or old image edits), as table, JSON, CSV, YAML or a count. Dry run by default;
  `--delete` removes them after a confirmation (`--yes` to skip it), with
  `--min-age` (default 24 hours) protecting recent uploads and `--limit`.
- `wp_r2_offload_orphan_keep` filter to protect unreferenced objects.
- `S3Client::list_object_metas()`: ListObjectsV2 with size, ETag and LastModified.

### Fixed

- XML responses in the default S3 namespace returned every repeated element twice
  (`Contents` in listings, `Error` in DeleteObjects results).
- ListObjectsV2 paging stops with an error when the same continuation token comes
  back twice instead of looping.

### Security

- `wp r2 orphans --delete` refuses to run when the key prefix may be shared: an
  empty prefix, or a multisite network whose prefix has no `{site_id}`. References
  are read again right before deleting, and records naming another bucket still
  count as references.

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
