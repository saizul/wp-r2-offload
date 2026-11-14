# R2 Offload for WordPress

[![CI](https://github.com/saizul/wp-r2-offload/actions/workflows/ci.yml/badge.svg)](https://github.com/saizul/wp-r2-offload/actions/workflows/ci.yml)
[![Release zip](https://github.com/saizul/wp-r2-offload/actions/workflows/release-zip.yml/badge.svg)](https://github.com/saizul/wp-r2-offload/actions/workflows/release-zip.yml)
![PHP](https://img.shields.io/badge/PHP-7.4%20to%208.4-777bb4)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

Offload the WordPress media library to **Cloudflare R2** and serve it from your own
domain. Every upload is copied to the bucket with all its image sizes, URLs and
`srcset` are rewritten, edits and deletions are mirrored, and the existing library
is moved with a resumable WP-CLI command. Local copies can be removed to save disk
space.

There is no AWS SDK: the plugin ships its own small S3 client and an AWS Signature
Version 4 signer that is tested against the official AWS SigV4 test suite and the
signed examples of the Amazon S3 documentation.

Plugin slug: `wp-r2-offload` - display name: *R2 Offload*.

## Features

- **All files of an attachment**: the uploaded file, the unscaled original of
  "-scaled" images, every registered size, WebP/AVIF `sources`, and the backups the
  image editor keeps.
- **Served from your domain**: attachment URLs, `wp_get_attachment_image_src()`,
  `srcset` and images in post content (`wp-image-{id}`) point to your custom domain
  or `r2.dev` URL. Only files that are really in the bucket are rewritten.
- **Edits and deletes**: edited images upload their new files; deleting an attachment
  deletes its objects (one DeleteObjects request per 1000 keys). If the original is
  no longer on the server, it is downloaded again before editing.
- **Large files**: multipart upload above a threshold (default 64 MiB) with uniform
  parts, resumable after a timeout from the state stored on the attachment.
- **Optional local removal** after a successful upload, with protection against new
  uploads reusing the name of a removed file, and `wp r2 restore` to bring files back.
- **Bulk offload with WP-CLI** that saves progress after every attachment and
  continues where it stopped: `wp r2 offload --batch=100`.
- **Verification**: `wp r2 verify` sends HEAD for every object and compares sizes;
  `--fix` uploads missing or different objects again.
- **Credentials in wp-config.php** (recommended): nothing secret is stored in the
  database. Credentials entered on the settings screen are stored encrypted.
- Connection test on the settings screen, R2 status in the attachment details.
- Retries with exponential back-off for throttling, server and network errors.

## Cloudflare R2 compatibility

Checked against Cloudflare's documentation:

| Topic | What the plugin does | Source |
| ----- | -------------------- | ------ |
| Endpoint | `https://<ACCOUNT_ID>.r2.cloudflarestorage.com`; `<ACCOUNT_ID>.eu.r2...` and `<ACCOUNT_ID>.fedramp.r2...` for jurisdictional buckets | [R2 S3 API](https://developers.cloudflare.com/r2/api/s3/api/), [API tokens](https://developers.cloudflare.com/r2/api/s3/tokens/) |
| Region | `auto` in the signature scope | [R2 S3 API](https://developers.cloudflare.com/r2/api/s3/api/) |
| Addressing | Path style: `endpoint/<bucket>/<key>` | [Presigned URLs](https://developers.cloudflare.com/r2/api/s3/presigned-urls/) |
| Operations | PutObject, HeadObject, GetObject, DeleteObject, DeleteObjects, ListObjectsV2, Create/UploadPart/Complete/AbortMultipartUpload (all listed as implemented) | [R2 S3 API](https://developers.cloudflare.com/r2/api/s3/api/) |
| Headers | `Content-Type`, `Cache-Control`, `Content-MD5`; no `x-amz-acl` (not implemented by R2) | [R2 S3 API](https://developers.cloudflare.com/r2/api/s3/api/) |
| Multipart | Parts of 5 MiB to 5 GiB, max 10,000 parts, all parts except the last the same size; R2 aborts incomplete uploads after 7 days by default | [Multipart objects](https://developers.cloudflare.com/r2/objects/multipart-objects/) |
| Credentials | Access key ID = API token id, secret = SHA-256 of the token value (both shown when you create an R2 API token) | [API tokens](https://developers.cloudflare.com/r2/api/s3/tokens/) |

Public access is configured in Cloudflare (custom domain or `r2.dev` subdomain on
the bucket), not with ACLs.

The client was tested with recorded responses and an in-memory S3 fake; it has not
been run against a live R2 bucket for this release. Use the connection test before
offloading.

## Requirements

- WordPress 6.0+
- PHP 7.4 to 8.4 with OpenSSL or sodium (both are standard)
- An R2 bucket, an R2 API token with *Object Read & Write* for it, and a public URL
  for the bucket (custom domain recommended)

## Installation

1. Download `wp-r2-offload.zip` from the [releases](https://github.com/saizul/wp-r2-offload/releases)
   and upload it in *Plugins > Add New > Upload Plugin*. Activate it.
2. Add the credentials to `wp-config.php` (or enter them in *Media > R2 Offload*):

```php
define( 'WP_R2_OFFLOAD_ACCOUNT_ID', '0123456789abcdef0123456789abcdef' );
define( 'WP_R2_OFFLOAD_ACCESS_KEY_ID', 'your-access-key-id' );
define( 'WP_R2_OFFLOAD_SECRET_ACCESS_KEY', 'your-secret-access-key' );
define( 'WP_R2_OFFLOAD_BUCKET', 'my-site-media' );
define( 'WP_R2_OFFLOAD_PUBLIC_URL', 'https://media.example.com' );
```

3. Open *Media > R2 Offload* and click **Test connection**.
4. New uploads are offloaded from now on. Move the existing library with
   `wp r2 offload`.

### All settings

Every setting can be a constant `WP_R2_OFFLOAD_<NAME>`; a constant always wins and
is shown read-only on the settings screen.

| Setting | Constant | Default |
| ------- | -------- | ------- |
| Account ID | `WP_R2_OFFLOAD_ACCOUNT_ID` | - |
| Access key ID | `WP_R2_OFFLOAD_ACCESS_KEY_ID` | - |
| Secret access key | `WP_R2_OFFLOAD_SECRET_ACCESS_KEY` | - |
| Bucket | `WP_R2_OFFLOAD_BUCKET` | - |
| Jurisdiction (`eu`, `fedramp`) | `WP_R2_OFFLOAD_JURISDICTION` | default |
| Custom S3 endpoint | `WP_R2_OFFLOAD_ENDPOINT` | - |
| Public URL | `WP_R2_OFFLOAD_PUBLIC_URL` | - |
| Object key prefix (`{site_id}` allowed) | `WP_R2_OFFLOAD_KEY_PREFIX` | `wp-content/uploads/` |
| Offload new uploads | `WP_R2_OFFLOAD_ENABLED` | `true` |
| Serve media from the public URL | `WP_R2_OFFLOAD_REWRITE_URLS` | `true` |
| Remove local files after upload | `WP_R2_OFFLOAD_REMOVE_LOCAL` | `false` |
| Cache-Control | `WP_R2_OFFLOAD_CACHE_CONTROL` | `public, max-age=31536000, immutable` |
| Multipart from (MiB, 8 to 5120) | `WP_R2_OFFLOAD_MULTIPART_THRESHOLD` | `64` |
| Part size (MiB, 5 to 512) | `WP_R2_OFFLOAD_PART_SIZE` | `16` |

Keys mirror the uploads folder: `2026/11/photo-300x200.jpg` becomes
`wp-content/uploads/2026/11/photo-300x200.jpg`.

### How secrets are stored

- Defined as constants: never written to the database.
- Entered on the settings screen: encrypted before saving (libsodium secretbox, or
  AES-256-GCM with OpenSSL) with a key derived from `AUTH_KEY` and
  `SECURE_AUTH_SALT`, or from `WP_R2_OFFLOAD_ENCRYPTION_KEY` when defined. Changing
  the salts makes stored secrets unreadable: enter them again.
- Never printed back into the form, and masked in `wp r2 status`.

## WP-CLI

```bash
wp r2 status                       # configuration (masked), progress, problems
wp r2 offload --batch=100          # offload everything, 100 attachments per batch
wp r2 offload --limit=500          # stop after 500; run again to continue
wp r2 offload --dry-run            # show what would be uploaded
wp r2 offload --reset              # start again from the first attachment
wp r2 verify                       # HEAD every object and compare sizes
wp r2 verify 123 456 --fix         # re-upload missing or different objects
wp r2 restore --all                # download removed local copies again
```

Progress of `wp r2 offload` is stored in the `wp_r2_offload_bulk_state` option after
every attachment. Interrupted multipart uploads continue from their last part.

## Removing local files

With *Remove local files after upload*, files are deleted from the server once all
files of the attachment are in the bucket (after WordPress has saved the metadata).

- New uploads never reuse the name of an offloaded file whose local copy is gone,
  so they cannot overwrite an object in the bucket.
- The image editor downloads the original again before editing.
- Plugins that read image files directly (thumbnail regeneration, image
  optimisers) need local files: run `wp r2 restore` for the attachments first.
- **Before deactivating or deleting the plugin, run `wp r2 restore --all`**, or
  media URLs will point to files that are no longer on the server.

## Hooks

| Hook | Type | Purpose |
| ---- | ---- | ------- |
| `wp_r2_offload_should_offload` | filter | Return false to keep an attachment local (`$offload, $attachment_id`) |
| `wp_r2_offload_delete_remote` | filter | Return false to keep objects when an attachment is deleted |
| `wp_r2_offload_capability` | filter | Capability for the settings screen (default `manage_options`) |
| `wp_r2_offload_http_args` | filter | Arguments for `wp_remote_request()` (do not change signed headers) |
| `wp_r2_offload_attachment_offloaded` | action | After files were uploaded (`$attachment_id, $record`) |
| `wp_r2_offload_loaded` | action | Services are ready |

Attachment meta: `_wp_r2_offload` (bucket, relative path => key, sizes,
`local_removed`), `_wp_r2_offload_error` (last error), `_wp_r2_offload_multipart`
(resume state of large uploads).

## Limitations

- Files are uploaded synchronously during the upload request; very large uploads
  depend on PHP's time limit (interrupted multipart uploads resume on the next
  attempt, for example with `wp r2 offload`).
- Content rewriting covers images with a `wp-image-{id}` class (the block and
  classic editors add it). Other hard-coded upload URLs are left alone; they keep
  working as long as the local files exist.
- Private buckets and signed URLs are not used for media; the bucket must be public
  through a custom domain or `r2.dev`.
- Multisite works per site (`{site_id}` in the prefix); there is no network-wide
  settings screen.

## Uninstall

Deleting the plugin removes its settings and bulk progress. Objects in the bucket and
the `_wp_r2_offload` attachment meta are kept, so nothing in the bucket is lost.

## Development

```bash
composer install
composer test     # PHPUnit, including the AWS SigV4 test suite
composer phpcs    # WordPress Coding Standards + PHPCompatibilityWP
composer lint
php bin/make-pot.php
```

`src/` contains the WordPress-free parts: the SigV4 signer, the S3 client, the
multipart uploader, key and URL mapping, configuration and the bulk runner.
`includes/` connects them to WordPress. The SigV4 test vectors in
`tests/Fixtures/aws-sigv4` come from the AWS `aws-c-auth` project (Apache-2.0);
see the NOTICE file there.

## License

GPL-2.0-or-later. Copyright (c) 2026 Saizul Amin - <https://saizul.com>
