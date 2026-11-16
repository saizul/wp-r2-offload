=== R2 Offload ===
Contributors: saizul
Tags: cloudflare, r2, s3, media library, offload
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload the media library to Cloudflare R2 and serve images from your own domain. All sizes, srcset, edits, deletes and resumable WP-CLI bulk offload.

== Description ==

R2 Offload copies your uploads to a Cloudflare R2 bucket and serves them from your custom domain or r2.dev URL.

**What it does**

* Uploads the original file, the unscaled original of large images, every image size, WebP/AVIF versions and image editor backups.
* Rewrites attachment URLs, image src, srcset and images in post content to the bucket URL.
* Mirrors image edits and deletes objects when you delete an attachment.
* Uploads large files in parts (multipart), and resumes interrupted uploads.
* Can remove local files after upload to save disk space, and download them again when needed.
* Moves your existing library with a resumable WP-CLI command.

**Secure by default**

Put your R2 credentials in wp-config.php and nothing secret is stored in the database. Credentials entered on the settings screen are encrypted before they are saved.

**No AWS SDK**

The plugin includes a small S3 client with its own AWS Signature Version 4 implementation, tested against the official AWS test suite.

**WP-CLI**

* `wp r2 offload --batch=100` - offload existing media, resumable
* `wp r2 status` - configuration and progress
* `wp r2 verify --fix` - check every object and re-upload problems
* `wp r2 orphans --delete` - find and remove bucket objects no attachment uses
* `wp r2 restore --all` - download removed local files

Development happens on GitHub: https://github.com/saizul/wp-r2-offload

== Installation ==

1. Upload the plugin zip in Plugins > Add New > Upload Plugin and activate it.
2. In Cloudflare, create an R2 bucket, connect a custom domain (or enable the r2.dev URL) and create an R2 API token with Object Read & Write permission.
3. Add the account ID, access key ID, secret access key, bucket and public URL to wp-config.php (see the FAQ) or enter them in Media > R2 Offload.
4. Click "Test connection", then run `wp r2 offload` to move existing media.

== Frequently Asked Questions ==

= Which constants can I use? =

`WP_R2_OFFLOAD_ACCOUNT_ID`, `WP_R2_OFFLOAD_ACCESS_KEY_ID`, `WP_R2_OFFLOAD_SECRET_ACCESS_KEY`, `WP_R2_OFFLOAD_BUCKET` and `WP_R2_OFFLOAD_PUBLIC_URL`, plus one constant for every other setting (see the README on GitHub).

= Do I need a custom domain? =

The bucket must be publicly reachable, through a custom domain (recommended for caching) or its r2.dev URL.

= What happens to existing posts? =

Images inserted with the editor are rewritten when the post is shown. Files that are not in the bucket keep their local URL.

= Can I deactivate the plugin later? =

Yes. If you enabled "Remove local files", first run `wp r2 restore --all` so the files exist on the server again.

= Does it work with jurisdictional (EU) buckets? =

Yes, choose the jurisdiction in the settings or set `WP_R2_OFFLOAD_JURISDICTION` to `eu` or `fedramp`.

== Screenshots ==

1. Settings screen with credentials from wp-config.php.
2. Bulk offload with WP-CLI.

== Changelog ==

= Unreleased =
* New: `wp r2 orphans` lists objects in the bucket that no attachment uses and can delete them (dry run by default, recent uploads protected, refused under a shared key prefix).
* Fix: repeated elements in namespaced S3 XML responses were read twice.

= 0.1.0 =
* First release: AWS SigV4 S3 client for Cloudflare R2, offload of all attachment files, URL and srcset rewriting, multipart uploads, local file removal and restore, WP-CLI offload/status/verify/restore, encrypted credentials or wp-config.php constants.

== Upgrade Notice ==

= 0.1.0 =
First release.
