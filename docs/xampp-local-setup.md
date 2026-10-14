# Pediacare: easy XAMPP setup on your laptop

Follow these steps in order. This first setup is for **checking the UI and
features with fake records on your laptop**.

For the final installation with real health records, use the
[final deployment instructions](xampp-local-deployment-details.md). They cover
HTTPS, restricted database permissions, private files and backups.

## Before you start

You will use three things:

| Tool | What you do there |
| --- | --- |
| Browser | Download Composer, then open Pediacare |
| XAMPP Control Panel | Start MySQL and Apache |
| VS Code PowerShell terminal | Run the commands shown below |

**You do not need XAMPP's Shell button to install Composer.** Download its
Windows installer in your browser, then run the installer.

Run commands one at a time. If a command fails, stop at that step and keep the
error message. Do not include passwords when sharing an error.

## Step 1: Check that XAMPP is installed

1. Open File Explorer.
2. Go to `C:\xampp\php`.
3. Confirm that `php.exe` is there.

If it is there, continue. Apache and MySQL do not need to be running to install
Composer.

## Step 2: Install Composer on your PC

If Composer is already installed, go straight to the version check below.

1. In your browser, open [Composer's Windows installation page](https://getcomposer.org/doc/00-intro.md#installation-windows).
2. Click the **Composer-Setup.exe** link and download it.
3. Open the downloaded installer.
4. When it asks for the PHP executable, select:
   `C:\xampp\php\php.exe`.
5. Continue through the installer. Leave proxy settings empty unless your
   network specifically requires a proxy.
6. Finish the installation.
7. Close **all VS Code windows**, then open VS Code and the Pediacare folder
   again. This lets the terminal pick up Composer's installation.

In VS Code, choose **Terminal > New Terminal**. Use **PowerShell** for this guide.
Type this command and press Enter:

```powershell
composer --version
```

**Success:** you see a line starting with `Composer version`.

If you see “composer is not recognized”, restart VS Code again. If it still
fails, the installation or its PATH setting needs fixing before continuing.
XAMPP's Shell button is not required.

## Step 3: Install Pediacare's dependencies

In the same PowerShell terminal, run:

```powershell
Set-Location 'C:\Users\brian\OneDrive\Documents\Pediacare'
```

Then run:

```powershell
$env:Path = 'C:\xampp\php;' + $env:Path
```

That line tells this terminal where XAMPP's PHP is. It applies only to this
terminal session; repeat it when opening a new terminal if PHP is not found.

Now run these commands **one at a time**:

```powershell
php --version
composer install
composer check-platform-reqs
```

**Success:** PHP reports version 8.1 or newer, installation finishes, and the
platform checks show `success`.

If Composer reports a missing PHP extension, open
`C:\xampp\php\php.ini`, find the named extension line, remove its leading
semicolon, save, and try again. For example, `;extension=zip` becomes
`extension=zip`. Do not add duplicate extension lines.

Composer downloads the project's dependencies. These commands **do not** start
the website or create your administrator account.

## Step 4: Start MySQL and create the laptop database

1. Open **XAMPP Control Panel**.
2. Click **Start** beside **MySQL**.
3. Wait until MySQL turns green. Leave Apache stopped for now.

Back in the VS Code PowerShell terminal, run:

```powershell
& C:\xampp\mysql\bin\mysql.exe -u root -p
```

Enter your MySQL root password when asked. If your fresh XAMPP installation has
no root password, press Enter. Use an existing password if one was already set.

You should now see a prompt such as `MariaDB [(none)]>`.
The commands below are **SQL**: paste them into that prompt, not PowerShell.

First create the database:

```sql
CREATE DATABASE IF NOT EXISTS pediacare
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

In VS Code, open `config/config.local.php`. Find the generated password inside
the **database** section. Replace the placeholder below with that value before
running the SQL:

```sql
CREATE USER 'pediacare_app'@'127.0.0.1'
    IDENTIFIED BY 'PASTE_DATABASE_PASSWORD_FROM_LOCAL_CONFIG';
GRANT ALL PRIVILEGES ON pediacare.*
    TO 'pediacare_app'@'127.0.0.1';
EXIT;
```

**Success:** SQL commands say `Query OK`, then `EXIT;` returns to PowerShell.
If the account already exists, stop and check its credentials; do not drop it.

These broader permissions are for this **fake-data laptop setup**, so the
migration command can create tables. The final deployment guide restricts the
runtime account. Do not use the database root account in Pediacare's config.

## Step 5: Check the config and create your administrator

Open `config/config.local.php`. It has already been prepared in this workspace.

Check these settings in its **database** section:

| Setting | Expected value |
| --- | --- |
| host | `127.0.0.1` |
| port | `3306`, unless you changed MySQL's port |
| name | `pediacare` |
| username | `pediacare_app` |
| password | The same database password you used in step 4 |

Your separate **first_admin** section contains:

- Email: `admin@pediacare.test`
- Password: the generated value under `first_admin.password`

Save the administrator password privately. The database password and login
password are different. Do not replace either with the example placeholders.

In PowerShell, run each command separately:

```powershell
php bin/migrate.php
php bin/seed-who.php
php bin/seed-vaccines.php
php bin/seed.php
```

**Success:** the last command says `First Admin created.`

If it says `Admin already exists; no accounts changed.`, use that account's
existing password. Seeding does not reset existing administrator credentials.

After success, change only `first_admin.password` to an empty string (`''`)
in the config, and save. Keep your saved login password. Leave the database
password in place.

## Step 6: Tell Apache where Pediacare is

This laptop demo uses HTTP with fake records. Complete HTTPS setup in the final
deployment guide before entering real health data.

Open `C:\xampp\apache\conf\httpd.conf` in your editor. Find these existing
lines and remove their leading `#` if present:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
Include conf/extra/httpd-vhosts.conf
```

For a XAMPP installation used only on this laptop, find `Listen 80` and change
it to `Listen 127.0.0.1:80`. If XAMPP serves other projects/devices, review that
shared setting first. Do not add a second overlapping `Listen` line.

Now open `C:\xampp\apache\conf\extra\httpd-vhosts.conf` and add this block.
If a `pediacare.test` host already exists, replace that block instead of adding
a duplicate:

```apache
<VirtualHost 127.0.0.1:80>
    ServerName pediacare.test
    DocumentRoot "C:/Users/brian/OneDrive/Documents/Pediacare/public"

    <Directory "C:/Users/brian/OneDrive/Documents/Pediacare/public">
        Options -Indexes
        AllowOverride All
        Require local
    </Directory>
</VirtualHost>
```

Save both files. The folder must end in **/public**.

Next:

1. Open the Windows Start menu.
2. Search for **Notepad**, right-click it, and choose **Run as administrator**.
3. Choose **File > Open**.
4. Open `C:\Windows\System32\drivers\etc\hosts`.
   Select **All files** if it is not visible.
5. Add this line once and save:

```text
127.0.0.1 pediacare.test
```

Return to PowerShell and check Apache:

```powershell
& C:\xampp\apache\bin\httpd.exe -t
```

**Success:** it says `Syntax OK`.

In XAMPP Control Panel, start Apache. If it was already running, stop it and then
start it again. MySQL should still be green.

## Step 7: Open the app and log in

Open your browser and enter:

```text
http://pediacare.test/login
```

Use:

- **Email:** `admin@pediacare.test`
- **Password:** the administrator password you saved in step 5.

Do not open `public/index.php` directly in File Explorer or use VS Code Live
Server. Open the browser address above.

## Step 8: Try the UI and features

Use fake information only:

1. Create a purok.
2. Register a fake child under five years old.
3. Add a growth measurement and check the chart.
4. Record a vaccination and a health visit.
5. Open a report, export CSV, and try printing.
6. Archive and restore the fake child; inspect the audit log.
7. Create test staff accounts and check what each role can access.

For a full checklist, follow [qa-checklist.md](qa-checklist.md).

If you prefer a populated demo, run the following **before registering any
children**, on this disposable laptop database only:

```powershell
php bin/seed-demo.php --demo
```

It adds around 200 fake children and refuses a database that already contains
children. It does not create or reset login accounts.

## After you finish testing

For everyday laptop testing, start Apache and MySQL in XAMPP, then open
`http://pediacare.test/login`. You do not reinstall Composer or rerun seeds each
time. Records remain in the database after you close the browser.

For the **final computer with real health records**, follow
[xampp-local-deployment-details.md](xampp-local-deployment-details.md). Use
`C:\Pediacare` outside OneDrive, configure trusted HTTPS and restricted database
accounts, and finish the documented backup and release checks. The laptop HTTP
demo above is not the final deployment configuration.

Automated tests need a separate test database; see the [README](../README.md#tests).
Do not run them against real records.
