# Encrypted database backup and recovery

`bin/backup.php` implements T40 using the configured XAMPP `mysqldump` client.
It captures a consistent InnoDB snapshot, encrypts it in memory and publishes a
`.pcbackup` file only after the dump and encrypted write succeed. It includes all
application tables: accounts, audit history, archived records, record counters,
vaccine configuration, WHO references and migration tracking. It does not back up
application files, local configuration or encryption keys.

## Private configuration

Create a backup directory outside the project and every web server's document
root, for example `D:\PediacarePrivate\Backups`. Restrict its Windows NTFS ACL to
the backup operator/task account and administrators. Disable broad inherited
access before using it. PHP's `chmod(0600)` does not replace Windows ACLs. Protect
`config/config.local.php` and the scheduled task account in the same way.

Copy the `backup` section from `config/config.example.php` into the local config:

```php
'backup' => [
    'directory' => 'D:/PediacarePrivate/Backups',
    'key' => '', // Insert the generated key locally; never commit it.
    'mysqldump' => 'C:/xampp/mysql/bin/mysqldump.exe',
    'mysql' => 'C:/xampp/mysql/bin/mysql.exe',
],
```

Generate a key locally with PHP:

```powershell
C:\xampp\php\php.exe -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Store the result in the local config and keep a separately protected recovery
copy with the designated administrator. Never send keys in chat, commit them or
store them beside off-site backup files. Retain older keys when rotating keys:
existing files still require the key that encrypted them. A lost key makes the
corresponding backups unrecoverable.

The configured database account needs SELECT, SHOW VIEW and TRIGGER privileges
on the source database for this dump configuration. The current schema uses
InnoDB and has no routines or events; those are outside this MVP backup. Avoid
migrations or other schema changes during a backup. Keep the clients compatible
with the database server. Use a dedicated account rather than root in production;
grant recovery schema privileges separately only when performing recovery.

## Run and automate

```powershell
Set-Location C:\path\to\Pediacare
C:\xampp\php\php.exe -d memory_limit=256M bin/backup.php
```

Exit code 0 means a completed file was published; 1 means failure; 2 means invalid
arguments. Output contains only the generated filename. SQL, client diagnostics
and passwords are never printed or put in application error logs. Database client
credentials use a temporary option file in the private directory, removed on
normal success or failure, rather than command-line password arguments. After a
forced process termination, check for leftover `.client-*.cnf` files and remove
them securely before allowing other access to that directory.

For daily automation, create a Windows Task Scheduler task with:

- Program: `C:\xampp\php\php.exe`
- Arguments: `-d memory_limit=256M "C:\path\to\Pediacare\bin\backup.php"`
- Start in: `C:\path\to\Pediacare`
- Trigger: daily at a center-approved time when the server is on.
- Account: the restricted operator account with access to the config and backup directory.
- Settings: run after a missed start, prevent overlapping runs, and stop a hung task after a reasonable limit such as 30 minutes.

Run the task manually once, check Task Scheduler's last result is 0, confirm a new
`.pcbackup` file, and complete a recovery drill. Monitor failures and missing files;
a scheduled task that never runs is not a backup. Copy completed encrypted files
to protected off-server/off-site storage and verify the copied file's SHA-256
matches the original with `Get-FileHash`. Use the center's approved retention
policy; this command does not silently delete older backups. Scheduling and the
off-site destination must be configured on the deployment host by its operator.

`--test` backs up the separately configured `pediacare_test` database. It refuses
a missing, unexpected or application-matching test name. Automated integration
tests require a separate `pediacare_restore_test` database creation/drop permission
and access to the configured XAMPP clients. They refuse to use an already existing
recovery test database; any database they create is removed afterward.

## Recovery drill and incident recovery

1. Keep the existing application database intact. Use a trusted backup file and
   its original encryption key. Restrict access to the recovery workstation.
2. Have the database administrator create a **new empty** UTF-8 database such as
   `pediacare_recovered`, and give the recovery operator the necessary schema and
   data privileges on that database. The command does not create or clear it.
3. Configure the original key and matching server/client settings in the local
   config, then run:

```powershell
C:\xampp\php\php.exe -d memory_limit=256M bin/restore.php "D:\PediacarePrivate\Backups\pediacare-example.pcbackup" pediacare_recovered
```

The entire file is authenticated before any destination database connection or
SQL import. Wrong keys, altered files and truncation fail. Restore refuses the
configured application database, the configured test database and any destination
containing tables. Use a destination that no other process is writing to. If an
import fails, DDL may already be committed: inspect the separate recovery database
and recreate it empty before retrying. The command never drops existing tables
as a recovery preparation step.

4. Verify the restored schema and migration list, table counts, unique record
   numbers and counters, archive flags, saved growth results, vaccine history and
   audit entries. Compare aggregate reports with the saved pre-backup figures.
   Test login and a representative fake record in a drill. Avoid displaying real
   personal data in recovery notes or logs.
5. For an incident, stop application writes and have the administrator approve the
   switch only after verification. Point the application database configuration
   at the verified recovered database, use the normal restricted application
   account, and resume service. Keep the old database for investigation under
   restricted access. Repeat recovery drills and record the file, date, counts,
   success/failure and operator without patient names or details.

## Format and practical limits

Version 1 contains a 17-byte header (`PEDIACARE-BACKUP` plus byte `01`), a random
12-byte nonce, a 16-byte authentication tag and AES-256-GCM ciphertext. The header
is authenticated as associated data. Keys are exactly 32 random bytes, base64
encoded in configuration; they are not password-derived. This uses the standard
[PHP OpenSSL authenticated-encryption API](https://www.php.net/manual/en/function.openssl-encrypt.php).

The MVP keeps plaintext only in process memory and limits SQL dumps to 32 MiB.
Larger dumps fail without publishing a backup; monitor growth and implement
streaming authenticated encryption before that limit is reached. Allow at least
256 MiB CLI memory for the current bounded implementation. Encryption protects
stored files, not an already compromised server, its memory, key or privileged
operator. Copies and key recovery still need access controls.

The T40 automated drill runs the actual CLI tools against an isolated MariaDB
server with fake UTF-8, quotes, backslashes and multiline health data. It compares
every application's table after recovery, including audit and archived records,
checks corrupted files leave the empty destination unchanged, verifies protected
and nonempty targets are refused, and checks failed dumps leave no completed file
or client credential file. Windows deployment ACLs, Task Scheduler execution and
off-site storage are operator checks; they were not configured by this task.

Validation on 2026-10-11: `composer test` passed with 1,112 tests and 8,309 assertions,
with no skipped tests, on a disposable local MariaDB server. The new PHP files also
passed syntax checks. The test database server and temporary files were removed.
