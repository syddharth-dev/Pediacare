# Pediacare: final local deployment details

Start with the [easy laptop setup guide](xampp-local-setup.md) if you are
installing Composer or checking the app with fake records. Follow this guide
for the final same-computer installation before storing real health records.

This guide sets up Pediacare on **one Windows computer**, accessible only from
that computer's browser. The final address is **https://pediacare.test/login**.
It needs Apache and MySQL running locally, not a public website or internet
connection. Installing XAMPP/Composer and downloading dependencies initially
needs internet access, or an offline copy prepared on another computer.

Use fake records for the initial checks. Before real records, complete the
remaining release items in [acceptance-review.md](acceptance-review.md) and
the hardening and backup steps in [deploy.md](deploy.md).

## 1. Choose the installation folder

For laptop testing, this guide uses your current folder:

```text
C:\Users\brian\OneDrive\Documents\Pediacare
```

For the final health-center installation, copy the reviewed application to
`C:\Pediacare`, outside OneDrive and XAMPP's `htdocs`. Replace the project paths
in this guide accordingly. Keep `public/.htaccess`, local assets, migrations,
and `composer.lock`. Do not copy demo databases into the final installation.

Open PowerShell in the project root:

```powershell
Set-Location 'C:\Users\brian\OneDrive\Documents\Pediacare'
```

## 2. Check XAMPP and install dependencies

Assume XAMPP is installed at `C:\xampp`. Use PHP 8.1+ compatible with the locked
dependencies. Install Composer using this XAMPP PHP executable, and reopen
PowerShell afterward. For this PowerShell session:

```powershell
$env:Path = 'C:\xampp\php;' + $env:Path
php --version
composer --version
composer install
composer check-platform-reqs
```

In `C:\xampp\php\php.ini`, enable `pdo_mysql`, `mbstring`, `openssl`, and `zip`
if disabled. PHPUnit also needs DOM/XMLWriter and the extensions reported by
Composer. Remove the leading semicolon on an existing extension line; do not
add duplicate lines. Restart Apache after PHP configuration changes.

Start **MySQL** in the XAMPP Control Panel. Leave Apache stopped during setup.
FileZilla, Mercury and Tomcat are not needed.

## 3. Find your prepared administrator credentials

For this workspace, `config/config.local.php` has been created from the example
with these first-admin values:

| Setting | Value |
| --- | --- |
| Name | Pediacare Administrator |
| Login email | `admin@pediacare.test` |
| Login password | Generated locally; read `first_admin.password` in `config/config.local.php` |
| Database user | `pediacare_app` |
| Database password | A separate generated value in `database.password` in the same file |

Open the file privately in your editor. Save the administrator password in a
password manager before removing it from the config after seeding. The `.test`
email is a login identifier; no mailbox or email service is required.

**These credentials do not yet create a database user or an application account.**
Steps 4 and 6 create them. No passwords are stored in this guide.

On another computer without a local config, create one only if absent:

```powershell
if (-not (Test-Path -LiteralPath 'config/config.local.php')) {
    Copy-Item -LiteralPath 'config/config.example.php' -Destination 'config/config.local.php'
}
```

Generate a different password for each account:

```powershell
& C:\xampp\php\php.exe -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'
```

Insert the values locally. Do not overwrite an existing installation's config
or commit/share this file. Keep credentials outside Markdown documentation.

## 4. Create the database and database accounts

Open the MySQL administrator CLI:

```powershell
& C:\xampp\mysql\bin\mysql.exe -u root -p
```

Enter the root password at the prompt. On a fresh XAMPP installation it may be
blank; press Enter in that case. Set a unique root password before final use:

```sql
ALTER USER 'root'@'localhost' IDENTIFIED BY 'REPLACE_WITH_UNIQUE_ROOT_PASSWORD';
```

Replace placeholders **before** executing SQL. Do not reset an existing root
account or replace an existing application database. These provisioning commands
create databases/accounts; application tables are created only by migrations.

```sql
CREATE DATABASE IF NOT EXISTS pediacare
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'pediacare_app'@'127.0.0.1'
    IDENTIFIED BY 'PASTE_DATABASE_PASSWORD_FROM_LOCAL_CONFIG';
GRANT SELECT, INSERT, UPDATE, DELETE ON pediacare.*
    TO 'pediacare_app'@'127.0.0.1';

CREATE USER 'pediacare_setup'@'127.0.0.1'
    IDENTIFIED BY 'REPLACE_WITH_DIFFERENT_TEMPORARY_SETUP_PASSWORD';
GRANT ALL PRIVILEGES ON pediacare.* TO 'pediacare_setup'@'127.0.0.1';
EXIT;
```

If a user already exists, inspect its credentials/grants rather than dropping
it. The setup user has database-scoped schema privileges for migrations; the
application user has only normal read/write privileges.

In `C:\xampp\mysql\bin\my.ini`, set `bind-address=127.0.0.1` under `[mysqld]`,
editing any existing setting instead of duplicating it. Stop and start MySQL.
Keep the firewall enabled and do not forward database or Apache ports.

## 5. Configure the application

Keep the generated `first_admin` and runtime database password values. The
structure of `config/config.local.php` is:

```php
<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'Pediacare',
        'timezone' => 'Asia/Manila',
    ],
    'first_admin' => [
        'name' => 'Pediacare Administrator',
        'email' => 'admin@pediacare.test',
        'password' => 'KEEP_YOUR_GENERATED_ADMIN_PASSWORD',
    ],
    'backup' => [
        'directory' => '',
        'key' => '',
        'mysqldump' => 'C:/xampp/mysql/bin/mysqldump.exe',
        'mysql' => 'C:/xampp/mysql/bin/mysql.exe',
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'pediacare',
        'test_name' => 'pediacare_test',
        'username' => 'pediacare_app',
        'password' => 'KEEP_YOUR_GENERATED_DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],
];
```

This is a structure example, not replacement credentials. Match the configured
port to your XAMPP MySQL port. Backup fields stay blank only during initial
setup; configure them before final use using [backup.md](backup.md).

Temporarily change **only** `database.username` to `pediacare_setup` and
`database.password` to that account's temporary password. Keep the runtime
password safely so you can restore it in step 7. Leave Apache stopped.

## 6. Create tables, reference data, and the administrator

Run each command from the project root and stop if any command fails:

```powershell
& C:\xampp\php\php.exe bin/migrate.php
& C:\xampp\php\php.exe bin/seed-who.php
& C:\xampp\php\php.exe bin/seed-vaccines.php
& C:\xampp\php\php.exe bin/seed.php
```

The last command should print `First Admin created.` It stores a password hash
in the database. If it prints `Admin already exists; no accounts changed.`,
use the existing administrator credentials: this command does not reset them.

The vaccine seed is a draft; confirm its schedule/FIC configuration with the
center before real use. The nurse/nutritionist must also confirm growth bands.

For an **empty, disposable laptop demo database only**, optionally run:

```powershell
& C:\xampp\php\php.exe bin/seed-demo.php --demo
```

This adds around 200 fake children. It refuses a database already containing
children. Do not run it on the final health-center database or manually delete
health records to make it run.

## 7. Remove setup credentials and restore runtime access

After successful admin creation:

1. Keep the login password in your password manager.
2. Set `first_admin.password` to `''` in `config/config.local.php`.
3. Restore `database.username` to `pediacare_app` and its original generated
   database password. Do not clear the runtime database password.
4. Open the root CLI again and remove the temporary setup account:

```sql
DROP USER 'pediacare_setup'@'127.0.0.1';
SHOW GRANTS FOR 'pediacare_app'@'127.0.0.1';
EXIT;
```

Dropping this temporary account does not delete the application database. For
later migrations, use temporary maintenance access again with Apache stopped.

## 8. Add the local hostname

Run Notepad as administrator. Open:

```text
C:\Windows\System32\drivers\etc\hosts
```

Select **All files** in the Open dialog if needed. Add the following line once,
then save without adding a `.txt` extension:

```text
127.0.0.1 pediacare.test
```

This hostname resolves on this computer only; no purchased domain is needed.

## 9. Create and trust the local HTTPS certificate

The project requires HTTPS for real health data, including local installations.
For this same-computer setup, create a self-signed certificate with the correct
hostname. In PowerShell:

```powershell
New-Item -ItemType Directory -Force -Path 'C:\PediacarePrivate\TLS'
& C:\xampp\apache\bin\openssl.exe req -x509 -newkey rsa:3072 -nodes -sha256 -days 365 -config C:/xampp/apache/conf/openssl.cnf -keyout C:/PediacarePrivate/TLS/pediacare.key -out C:/PediacarePrivate/TLS/pediacare.crt -subj '/CN=pediacare.test' -addext 'subjectAltName=DNS:pediacare.test' -addext 'basicConstraints=critical,CA:TRUE'
```

Create these files only if they do not already exist; avoid overwriting an
existing certificate/key. Protect the private key with Windows NTFS permissions
so only the required Apache identity and administrators can read it.

Trust **your own generated certificate** on this computer: run `certmgr.msc`,
open **Trusted Root Certification Authorities > Certificates**, and use
**All Tasks > Import** to import `pediacare.crt` for the current Windows user.
Do not import the private `.key` file or unrelated certificates. Restart the
browser afterward. Record the expiry date and renew/retrust before expiry.

OpenSSL documents the certificate options in its
[req command reference](https://docs.openssl.org/3.0/man1/openssl-req/).

## 10. Configure Apache for this computer only

These settings assume XAMPP is dedicated to this local installation. Back up
the existing Apache configuration before editing it. If XAMPP hosts other
applications, review the shared configuration before replacing its listeners.

In `C:\xampp\apache\conf\httpd.conf`:

1. Replace `Listen 80` with `Listen 127.0.0.1:80`.
2. Ensure these existing module/include lines are enabled once:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
LoadModule ssl_module modules/mod_ssl.so
Include conf/extra/httpd-vhosts.conf
Include conf/extra/httpd-ssl.conf
```

Keep the other required XAMPP modules enabled. Review other included files for
extra `Listen` directives; there must be no wildcard listeners. Specifying an
address limits where Apache accepts connections.
[Apache listener documentation](https://httpd.apache.org/docs/2.4/bind.html)

In `C:\xampp\apache\conf\extra\httpd-vhosts.conf`, remove unused sample hosts
and define the HTTP redirect:

```apache
<VirtualHost 127.0.0.1:80>
    ServerName pediacare.test
    Redirect permanent / https://pediacare.test/
</VirtualHost>
```

In `C:\xampp\apache\conf\extra\httpd-ssl.conf`, replace `Listen 443` with
`Listen 127.0.0.1:443`. Replace the sample SSL virtual host with the following,
keeping the required global SSL configuration outside that host:

```apache
<VirtualHost 127.0.0.1:443>
    ServerName pediacare.test
    DocumentRoot "C:/Users/brian/OneDrive/Documents/Pediacare/public"
    SSLEngine on
    SSLCertificateFile "C:/PediacarePrivate/TLS/pediacare.crt"
    SSLCertificateKeyFile "C:/PediacarePrivate/TLS/pediacare.key"

    <Directory "C:/Users/brian/OneDrive/Documents/Pediacare/public">
        Options -Indexes
        AllowOverride All
        Require local
    </Directory>

    LogFormat "%t %m %U %H %>s %B" pediacare_private
    CustomLog "logs/pediacare-access.log" pediacare_private
    ErrorLog "logs/pediacare-error.log"
</VirtualHost>
```

Serve **only `public/`**, never the whole repository. `Require local` adds a
directory access restriction for requests originating on the same computer.
[Apache access documentation](https://httpd.apache.org/docs/2.4/mod/mod_authz_host.html)

Review default hosts and aliases too; remove unused XAMPP dashboard/sample
endpoints and keep phpMyAdmin restricted with `Require local`. Disable any
additional logging that records application query strings. The format above
omits search queries, cookies and referrers.

Validate the configuration:

```powershell
& C:\xampp\apache\bin\httpd.exe -t
& C:\xampp\apache\bin\httpd.exe -S
& C:\xampp\apache\bin\httpd.exe -M
```

Resolve errors before proceeding. Fully stop and start Apache after changing
listeners. Do not add overlapping `Listen` directives.

## 11. Open Pediacare and check it

With Apache and MySQL running, open:

```text
https://pediacare.test/login
```

Sign in using `admin@pediacare.test` and the password saved in step 3. The
browser should show a trusted HTTPS connection, without bypassing warnings.
`http://pediacare.test/login` should redirect to HTTPS.

On a fake-data testing copy, follow [qa-checklist.md](qa-checklist.md):

- Create a purok and test staff/BHW/Viewer accounts.
- Register a fake child aged 0-59 months; try a duplicate registration.
- Save a measurement and inspect the history/chart.
- Record vaccinations, visits and supplements.
- Open reports, download CSVs, and check printing.
- Archive/restore a fake child and check the audit log.
- Verify Viewer cannot open individual records and BHW cannot archive children.
- Log out and confirm a protected page redirects to login.

Use F12 and Ctrl+Shift+M in Chrome/Edge to inspect smaller screen sizes. This
emulates a phone layout without allowing another device to access the app.
Opening PHP files directly or using VS Code Live Server does not run this app.

## 12. Run automated checks on a separate testing copy

Do not run PHPUnit against the final health-center installation. On your laptop
testing copy, provision a separate `pediacare_test` database and scoped testing
account according to the [README](../README.md#tests). Integration tests reset
test data, and backup tests also need the isolated restore permissions described
in [backup.md](backup.md). Keep these privileges away from the runtime account.

```powershell
composer test
```

Check for failed or skipped integration tests; passing unit tests alone do not
verify the database workflows. Before packaging the final release:

```powershell
composer install --no-dev --optimize-autoloader
composer check-platform-reqs --no-dev
```

## 13. Finish the final installation

Before real records, complete [deploy.md](deploy.md) and [backup.md](backup.md):

- Set PHP error display off, disable raw automatic error logging as documented,
  and create a private session directory with 1800-second garbage collection.
- Protect config, logs, sessions, database files and TLS keys with NTFS access
  permissions. Apache needs only appropriate read access and log/session writes.
- Configure daily encrypted backups, protected off-computer copies, and a
  separate recovery key copy. Verify a restore into a different empty database.
- Confirm clinical settings, provide the approved collection privacy notice,
  and review the remaining scope gaps in the acceptance review.
- Confirm Apache/MySQL startup after reboot and run the browser checks again.

For ordinary use, start Apache and MySQL, then open the HTTPS bookmark. Closing
the browser does not erase records. Shut down the computer normally; daily
backups are still needed for computer failure or theft.

## Troubleshooting

| Problem | What to check |
| --- | --- |
| Apache will not start | Run `httpd.exe -t`; inspect Apache's error log for duplicate listeners, occupied ports or missing certificate paths. |
| MySQL will not start | Inspect the MySQL error log and configured port; do not delete database files to force startup. |
| Hostname cannot be found | Check the hosts entry, its filename, and spelling of `pediacare.test`. |
| Certificate warning | Check hostname/SAN, expiry, Windows clock and current-user trust import. |
| Generic 500 page | Verify local config, database credentials, migrations and extensions; inspect `storage/logs/app.log` without posting secrets or health data. |
| Login rejects the prepared credentials | Confirm the seed printed `First Admin created.` Existing admins are not reset by re-running the seed. Check the saved password and keyboard layout. |
| Login temporarily blocked | Stop retrying and allow the 15-minute failed-login window to expire. |
| Clean URLs fail or assets disappear | Check `public/` DocumentRoot, enabled rewrite/headers modules and `AllowOverride All`. |
| Charts have no references | Confirm WHO seeding succeeded; use fake measurements within the supported age coverage. |
| Another device cannot connect | Expected: this installation deliberately listens only on `127.0.0.1`. |

Writing this guide and preparing local credentials do not configure Windows
services, create accounts in MySQL, seed the application, or certify deployment.
