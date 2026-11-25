# FindIT

FindIT is a web-based campus lost-and-found management system. Students can report lost or found items, compare possible matches, and follow claims. Administrators can review claims and record when an item is returned.

**Project status**  
FindIT has been verified for local development and academic demonstration. Student claim submission and withdrawal are disabled in the original installation. Real campus use requires separate operational approval.

## Project objectives

- Give students one place to report, search for, and track lost and found items.
- Help students discover relevant reports while protecting account information and claim evidence.
- Give authorized staff a way to review claims, coordinate pickup, and record completed handoffs.

## Features and roles

| Role | Features |
| --- | --- |
| Student | Register and sign in, report lost or found items with an optional photo, browse and filter reports, and manage eligible reports in **My Reports**. |
| Student | View **Potential Matches** for an open Lost report. Use **Notifications** to check for matches and manage unread updates. View and track personal claims. |
| Administrator | Use **Manage Claims** to review evidence, approve or reject claims, cancel failed pickups, and confirm handoffs. Use **Administrator Matches** to compare Lost and Found reports. |

Public registration creates student accounts. Administrator accounts are provisioned by an authorized local operator. See the [administrator guide](docs/ADMIN_GUIDE.md).

## Technology and database

FindIT uses PHP with PDO and MariaDB, served by Apache through XAMPP on Windows. The interface uses HTML, CSS, and vanilla JavaScript. Installation requires no package manager or frontend build step. Development testing used PHP 8.2.12 and MariaDB 10.4.32.

| Table | Purpose |
| --- | --- |
| `users` | Account identity, role, status, and password hash |
| `categories` | Categories available for classifying reports |
| `items` | Lost and Found reports, their status, dates, and optional photo references |
| `claims` | Claimant, private evidence, review outcome, and handoff state |
| `activity_logs` | Audit entries for administrator claim decisions and related closures |
| `notifications` | Recipient-specific alerts and read state, with duplicate event protection |

## Basic workflow

1. A student reports a Lost or Found item with a title, category, description, and location.
2. Signed-in users search **Browse Items**. The owner of an open Lost report can see possible Found matches and check for alerts from **Notifications**.
3. In an authorized environment with claims enabled, a student submits identifying details for another person's open Found report. Staff review the evidence. Approval reserves the item for pickup. Staff record the handoff after the item is returned.

Matching suggestions are leads for verification. Staff verify ownership through the claim workflow.

## Local installation

1. Copy the project to `C:\xampp\htdocs\findit` and start **Apache** and **MySQL** in XAMPP Control Panel.
2. Import the [base schema](database/schema.sql), [notification migration](database/migrations/001_create_notifications.sql), and [category seed](database/seeds/001_categories.sql), in that order.
3. Copy the [database configuration example](config/database.example.php) to `config/database.php`, then set the local database connection values. Give Apache write access to `uploads/` and retain the supplied `.htaccess` files.
4. Open [FindIT on localhost](http://localhost/findit/) and register a disposable student account.

Follow the [Windows installation guide](docs/INSTALLATION.md) for prerequisites, database privileges, access checks, and troubleshooting. Repeat the first-use checks on the evaluation computer after setup.

## Configuration and limits

- [`config/claims.php`](config/claims.php) defines `FINDIT_STUDENT_CLAIMS_ENABLED`. Keep it `false` in the original installation until operational approval. The flag controls student submission and withdrawal. Existing claims can still be viewed by their owners, and authorized staff can use the review pages.
- [`config/notifications.php`](config/notifications.php) defines the 90-day inbox visibility window. Older alerts remain in storage for deduplication.
- Match checks run on demand. FindIT ranks the 120 newest eligible Found reports in the same category and shows up to five suggestions per Lost report. Older reports can fall outside the candidate window.
- Notifications appear in FindIT. The current system has no email, SMS, or push alerts.

FindIT uses hashed passwords, role and ownership checks, CSRF tokens, prepared statements, and an authenticated route for photos. Keep local database credentials, real photos, and personal records out of any submission or public repository. The [security guide](docs/SECURITY.md) explains the safeguards and the work required before campus use.

## Testing and troubleshooting

The [testing summary](docs/TESTING.md) separates automated results from manual checks. Phase 6B passed 15 isolated test groups across 170 HTTP requests, plus PHP syntax and browser layout checks. The owner also verified the root entry, Account, Report Item, and the denied schema URL on localhost.

If FindIT fails to load, check Apache and MariaDB first, then verify the database configuration and import order. If the report form has no categories, apply the category seed to the local development database. A `403` for direct access to private files is expected. See [installation troubleshooting](docs/INSTALLATION.md#troubleshooting).

## Documentation

| Guide | Use it for |
| --- | --- |
| [Installation](docs/INSTALLATION.md) | Set up a local Windows installation and check that it works. |
| [User guide](docs/USER_GUIDE.md) | Walk through student and administrator features. |
| [Administrator guide](docs/ADMIN_GUIDE.md) | Provision trusted staff accounts and operate the claim workflow. |
| [Testing summary](docs/TESTING.md) | Review verification coverage and plan checks for a new host. |
| [Security and limitations](docs/SECURITY.md) | Prepare a safe handoff and assess campus readiness. |
| [Optional demonstration data](docs/DEMO_DATA.md) | Create fictional accounts and sample reports using the application. |
