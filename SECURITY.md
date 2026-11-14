# Security policy

## Supported versions

| Version | Supported |
| ------- | --------- |
| 0.1.x   | yes       |

## Reporting a vulnerability

Please do **not** open a public issue. Use GitHub's private vulnerability reporting
("Security" tab, "Report a vulnerability") on
<https://github.com/saizul/wp-r2-offload>, or contact the maintainer through
<https://saizul.com>.

Include the affected version, a description and steps to reproduce. You can expect
an acknowledgement within 7 days. Please give us reasonable time to release a fix
before disclosing the issue.

## How credentials are handled

- Credentials defined as `WP_R2_OFFLOAD_*` constants in `wp-config.php` are never
  written to the database (recommended).
- Credentials entered on the settings screen are encrypted (libsodium secretbox or
  AES-256-GCM) with a key derived from the site salts or
  `WP_R2_OFFLOAD_ENCRYPTION_KEY`, are never printed back into forms and are masked
  in CLI output.
- Use an R2 API token limited to *Object Read & Write* on the one bucket.

## What the plugin does to stay safe

- The settings screen, saving and the connection test require `manage_options`
  (filterable with `wp_r2_offload_capability`) and a nonce.
- All requests to R2 use TLS with certificate verification and are signed with AWS
  Signature Version 4; redirects are not followed.
- Object keys are derived from the uploads folder; paths with `..` are rejected.
- S3 XML responses are parsed without network access or external entities.
- Output on the admin screens is escaped.
