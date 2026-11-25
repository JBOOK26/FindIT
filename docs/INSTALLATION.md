# FindIT installation on Windows

Use this guide to prepare a local demonstration with disposable data. Keep XAMPP on the local machine and keep the original FindIT database, real accounts, and private photos out of the installation files. Verify the installation on the evaluation computer with the first-use checks in this guide.

## 1. Check the prerequisites

- Install Windows XAMPP with Apache, PHP, MariaDB, and phpMyAdmin. The verified development baseline used PHP **8.2.12** and MariaDB **10.4.32**. Test a different bundle against the [new-host checklist](TESTING.md#checks-for-a-new-host). Compatibility with a separate MySQL installation has yet to be verified. [XAMPP downloads](https://www.apachefriends.org/download.html)
- Enable PHP sessions, PDO with `pdo_mysql`, and `fileinfo`. Enable `mbstring` for consistent Unicode matching. `C:\xampp\php\php.exe -m` lists the CLI modules. Apache must also load the necessary extensions.
- Use Apache with `mod_rewrite` and `.htaccess` overrides enabled for the FindIT directory. The supplied rules depend on Apache and include an error-display setting for `mod_php`.

## 2. Copy the project and start XAMPP

1. Copy the project into `C:\xampp\htdocs\findit`. Confirm that `index.php`, `pages/`, `includes/`, `assets/`, `config/`, and `database/` sit directly inside `findit`.
2. Keep the top-level [`.htaccess`](../.htaccess) and the rules in `config/`, `database/`, `includes/`, and `uploads/`. Ensure your copy method includes dotfiles.
3. Open **XAMPP Control Panel**. Start **Apache** and **MySQL**. XAMPP uses the latter label for its MariaDB service. The steps below assume Apache on port 80 and MariaDB on port 3306. [XAMPP Windows FAQ](https://www.apachefriends.org/faq_windows.html)
4. Open [local phpMyAdmin](http://localhost/phpmyadmin/). Use an authorized local database account that has permission to create the schema and tables.

## 3. Initialize the database

Use a fresh local MariaDB instance with no existing `findit` database. If that name already exists, stop and check which instance you are using. Preserve existing databases and records.

| Order | File | Instructions |
| --- | --- | --- |
| 1 | [`database/schema.sql`](../database/schema.sql) | From the server-level **Import** tab, choose this file and run the import. It creates and selects `findit`, then creates the base tables. |
| 2 | [`database/migrations/001_create_notifications.sql`](../database/migrations/001_create_notifications.sql) | Select `findit` in the sidebar and import this file into that database. It adds `notifications`. Apply this migration once to a fresh schema. |
| 3 | [`database/seeds/001_categories.sql`](../database/seeds/001_categories.sql) | Keep `findit` selected and import the category seed. It adds seven active categories. A repeat import preserves existing category values. |

Confirm that `findit` has six tables and that `categories` has seven active rows. A fresh installation starts with empty users, items, claims, and notification tables. The SQL files are imported from disk through phpMyAdmin. FindIT denies direct web access to them.

## 4. Configure the application database account

1. Use the local database administration tools to create a dedicated account for FindIT. Give it `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on the `findit` schema only. Keep the database administrator account for installation and maintenance.
2. If `config/database.php` is absent, copy [`config/database.example.php`](../config/database.example.php) to that filename. Preserve an existing configuration on any installation you intend to keep.
3. Open the copy in an editor and set these existing variables.

   | Variable | Local setting |
   | --- | --- |
   | `$host` | `localhost` for a database on the same computer |
   | `$database` | `findit` |
   | `$username` | Your dedicated local database account |
   | `$password` | The password for that account |
   | `$charset` | `utf8mb4` |

   Keep the connection file private. The example uses the default database port and defines no separate port variable. If your MariaDB service uses a different port, have the local operator verify the PDO connection string for that setup.

4. Confirm `FINDIT_STUDENT_CLAIMS_ENABLED` is `false` in [`config/claims.php`](../config/claims.php). This keeps student claim submission and withdrawal disabled pending operational approval. The inbox visibility setting is [`FINDIT_NOTIFICATION_INBOX_DAYS`](../config/notifications.php), which defaults to 90 days.

## 5. Prepare uploads and verify access protections

Create `C:\xampp\htdocs\findit\uploads` if needed. Retain [`uploads/.htaccess`](../uploads/.htaccess) and give the Apache process write permission to that directory. The report form accepts JPEG, PNG, and WebP files up to 5 MiB and 24 megapixels. Ensure PHP's `upload_max_filesize` supports 5 MiB and `post_max_size` allows that upload plus the form fields.

| Browser check | Expected result |
| --- | --- |
| [Project root](http://localhost/findit/) | Login when signed out, Account when signed in |
| [Public stylesheet](http://localhost/findit/assets/css/findit.css) | CSS loads |
| [Schema URL](http://localhost/findit/database/schema.sql) | `403 Forbidden` |
| [Database configuration URL](http://localhost/findit/config/database.php) | `403 Forbidden` |
| [Uploads directory](http://localhost/findit/uploads/) | `403 Forbidden` |
| [Pages directory](http://localhost/findit/pages/) | `403 Forbidden`, with directory listing disabled |

If a private URL shows file contents or lists a directory, check the `.htaccess` files and Apache overrides before adding personal data or uploading photos. Read the Markdown guides in an editor or repository viewer because Apache blocks direct access to them.

## 6. Run a first-use check

1. Open [FindIT](http://localhost/findit/), choose **Register**, and create a disposable student account using invented details.
2. Sign in and check that Account shows the student shortcuts. Open **Report an Item** and confirm that the category list is populated.
3. Submit a sample report with synthetic details and, if testing photos, a disposable image. Check that the entry appears in **My Reports** and **Browse Items**. Verify the photo loads through the report page and that direct upload access stays denied.
4. Open the notification bell and check that the inbox loads. Use [optional demonstration data](DEMO_DATA.md) and the [user guide](USER_GUIDE.md) for the matching demonstration and the [administrator guide](ADMIN_GUIDE.md) for authorized staff setup.

Keep this installation local. Before campus use, qualify a maintained runtime, deploy HTTPS and equivalent private-path protections, and complete the [operational security checks](SECURITY.md#requirements-before-campus-use).

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| Apache or MySQL cannot start | Open the XAMPP logs. Check whether another service is using port 80 or 3306. Confirm the URL and database connection match the local service. |
| The root page returns `404` | Confirm the folder is named `findit` under `htdocs`, with `index.php` directly inside it. |
| The root page returns `500` | Check the Apache error log and verify the required modules and `.htaccess` overrides. |
| FindIT reports a database or account-access error | Start MariaDB, check the selected schema and local connection file, and verify the dedicated account's privileges. Review logs locally before sharing diagnostics. |
| No categories appear | Confirm the seed was imported into `findit` and the expected rows are active. |
| The bell or inbox fails to load | Confirm the notification migration was applied in `findit`. If the table already exists, inspect its definition instead of importing the migration again. |
| A photo is rejected or missing | Check the image type, size, and dimensions. Check `fileinfo`, the PHP request limits, and Apache's write access to `uploads/`. |
| Sign-in or a form fails after a pause | Return to Login and sign in again. An inactive or suspended account requires help from authorized staff. |
