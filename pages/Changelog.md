# Changelog

Published releases [1.0.0](https://github.com/sw-wordpress/sw-backupit/releases/tag/1.0.0) and [0.8.1](https://github.com/sw-wordpress/sw-backupit/releases/tag/0.8.1) have no release-note text. Their tags remain the source of historical versions; no feature-to-version assignments or release dates have been inferred here.

## 1.0.1 – 2026-11-25

### Bugs

- Require both storage-management and download permissions when transferring backups; moving backups additionally requires delete permission. Hide the transfer action when the required permissions are missing.
- Require storage-management permission when duplicating profiles that contain storage targets.
- Require backup-run permission to create, enable or edit active schedules. Users with profile-management permission alone can still create and edit disabled schedules and disable active ones; the admin interface reflects these restrictions.
- Bind saved SFTP credentials to the server, port, username and host-key settings. Changing these settings requires a password or private key to be entered again; changing the storage type discards the previous credentials.

### Other topics

- Add English user documentation for installation, profiles, schedules, storage, encryption, recovery and troubleshooting, with real demo screenshots, a searchable GitHub Pages site and a documentation build check.
- Explain the explicit **Lock and remove the restore application** finishing step and manual cleanup when automatic file removal fails.
- Update the admin interface's Vue, Quasar, Vue Router and Vue I18n dependencies, along with frontend development and test tooling.
- Update PHPStan to 2.3.7 and simplify the restore wizard's HTTPS check without changing its behavior.
- Keep ZipStream on the PHP 8.2-compatible 3.1 series and stop Dependabot from proposing incompatible releases.
