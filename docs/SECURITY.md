# FindIT security and limitations

FindIT has local application safeguards and isolated verification evidence. The original installation keeps student claims disabled. Treat the application as a local development and demonstration system until the campus requirements below are met.

## Application safeguards

- **Authentication and sessions.** Passwords are stored as PHP password hashes and checked with `password_verify()`. Login regenerates the session ID. Protected sessions use strict mode, HttpOnly and SameSite=Lax cookies, Secure cookies when PHP detects HTTPS, and a 30-minute inactivity timeout. Logout accepts a CSRF-protected POST and destroys the session. [Authentication helper](../includes/auth.php) · [Login](../pages/login.php)
- **Authorization and revocation.** Protected requests read the account's current database role and status. Missing, inactive, or suspended accounts lose access. An administrator demoted to student loses staff permissions on the next protected request. Report management and student claim details enforce ownership. Administrator claim decisions verify the active staff role inside the transaction. [Account checks](../includes/auth.php) · [Claim review](../includes/admin-claims.php)
- **CSRF, SQL, and output.** State-changing forms validate a session CSRF token. PDO uses native prepared statements to bind user values. Input checks enforce eligible IDs, statuses, categories, and form data. Text rendered in HTML is escaped. [CSRF checks](../includes/auth.php) · [Notification actions](../pages/notifications.php)
- **Private photo storage.** Uploads are checked for allowed MIME type, matching image format, dimensions, and size. The app generates stored filenames and restricts file reads to the upload directory. A signed-in user can view photos on non-cancelled reports. A cancelled report's photo is available to its student owner. Apache denies direct upload access, and the PHP image route checks access and sends private, no-store and MIME-safety headers. [Photo validation](../includes/item-photos.php) · [Photo route](../pages/item-photo.php)
- **Notification privacy.** Inbox queries and read updates use the signed-in recipient. Event messages omit private proof, account contact details, and rejection feedback. Opening an alert rechecks target access and can hide stale destinations. Claim transitions and notifications are written in the same transaction. Event keys suppress duplicates. [Notification implementation](../includes/notifications.php)
- **Private paths.** Apache's supplied rules disable directory listing and block direct access to configuration, helpers, SQL, Markdown, and uploads. The app-level rule disables browser display of PHP errors under `mod_php`. Another server or a different PHP handler must enforce equivalent protections. [Apache rules](../.htaccess) · [Upload rules](../uploads/.htaccess)

## Current limits

[`config/claims.php`](../config/claims.php) sets `FINDIT_STUDENT_CLAIMS_ENABLED` to `false`. This blocks student submission and withdrawal in the original installation. Existing claim views and authorized staff review remain accessible. Keep that setting until operational approval.

Public registration checks form inputs and uniqueness. Campus identity verification and authentication throttling remain to be implemented or provided by the hosting environment. Any signed-in active account can browse non-cancelled reports. Keep real names, contact details, and ownership clues out of public report descriptions and demonstration photos.

FindIT treats matches as possible links between reports. The candidate limit keeps the check bounded and can exclude older items. Match discovery runs on demand. Notifications are in-app, and their 90-day visibility window does not delete stored notices. Campus retention rules should account for notices, claims, audit entries, and physical items.

## Backups and recovery

For an authorized installation, back up the database and protected upload files together so photo references still resolve. Store backups with restricted access outside the web root. Keep database credentials and any backup-encryption keys under separate access controls. Rehearse a restore on a disposable instance and verify accounts, reports, photos, claim history, and access restrictions before relying on the backup.

For submission, include the [schema](../database/schema.sql), [migration](../database/migrations/001_create_notifications.sql), [category seed](../database/seeds/001_categories.sql), and safe configuration example. Use synthetic data and images. Exclude the live connection file, personal records, private uploads, database exports containing real data, sessions, logs, temporary account helpers, and demo environments.

## Requirements before campus use

| Requirement | Work still needed |
| --- | --- |
| Campus identity | Approve an enrollment process that verifies student identity and authorized staff access. |
| Abuse protection | Add login and registration throttling and a process for reviewing suspicious activity. |
| HTTPS and cookies | Deploy HTTPS and verify that PHP detects secure requests and issues Secure cookies. |
| Runtime and hosting | Use supported and patched PHP, MariaDB, and web-server versions. Recheck the app on the target stack. [PHP supported versions](https://www.php.net/supported-versions.php) · [MariaDB maintenance policy](https://mariadb.org/about/) |
| Errors and security headers | Disable public error output at the production server, protect and review logs, and configure and test an appropriate header policy. |
| Credentials and files | Limit database privileges, manage secrets outside the public tree where practical, and protect uploads, backups, and session storage. |
| Retention and restore | Approve retention and deletion rules, assign backup responsibility, and test restoration. |
| Staff and physical custody | Assign authorized staff, define how evidence is handled, and establish pickup identity checks and actual handoff procedures. [Administrator guide](ADMIN_GUIDE.md) |

See the [testing summary](TESTING.md#checks-for-a-new-host) for acceptance checks on another host. A campus operator must approve activation separately from academic acceptance.
