# Deployment and XAMPP hardening

This guide implements T41 and `plan.md` sections 7–8. It describes operator
steps; it does not certify that a deployment host has been configured. Complete
the checklist below before entering real health records.

## Choose the host and prepare a release

Record the center's hosting choice, administrator, privacy contact and recovery
operator. For a closed LAN, use a dedicated Windows PC with controlled physical
access, supported software, a fixed LAN address and a hostname staff devices can
resolve. Keep application files outside XAMPP's `htdocs` and personal cloud-sync
folders, for example `C:\Pediacare`. Never expose the XAMPP machine directly to
the internet or forward router ports to it. Online hosting needs a maintained
PHP/MySQL environment and a publicly trusted HTTPS certificate.

[Apache Friends documents XAMPP's development defaults](https://www.apachefriends.org/faq_windows.html#is-xampp-production-ready).
The LAN exception in the project plan requires the hardening checks here.

On a separate staging installation with fake data, install the locked
dependencies and run the required checks:

```powershell
composer install
composer check-platform-reqs
composer test
```

Use PHP 8.1 or newer compatible with the locked dependencies, PDO MySQL,
mbstring and OpenSSL. The PHPUnit development dependency currently requires
PHP 8.1+. Check platform requirements instead of ignoring them. Keep database
clients compatible with the server. Do not run tests against live data: use a
separate `pediacare_test` database and an isolated test server with the permissions
described in [backup.md](backup.md). Complete T42 browser QA and T43 acceptance
coverage review before release; neither is completed by this guide.

Copy the reviewed release to the host, preserving its `public/.htaccess`, local
assets and numbered migrations. Install production autoloading with:

```powershell
composer install --no-dev --optimize-autoloader
composer check-platform-reqs --no-dev
```

Never copy development credentials, test databases or fake demo records into the
live installation. Create `config/config.local.php` from the example locally;
keep the timezone `Asia/Manila`, and protect credentials with NTFS permissions.

## Database provisioning and initial data

Using the database administrator's authenticated CLI, set a strong root password,
remove unintended anonymous/remote administrative access, and create the empty
`pediacare` database with `utf8mb4_unicode_ci`. Do not use root for application
requests. Bind the database to `127.0.0.1` for a same-PC installation and block
database ports from staff devices. Do not expose port 3306 to the LAN or internet.

Create a dedicated runtime account, replacing the placeholder password locally:

```sql
CREATE USER 'pediacare_app'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_UNIQUE_SECRET';
GRANT SELECT, INSERT, UPDATE, DELETE ON pediacare.* TO 'pediacare_app'@'127.0.0.1';
```

Confirm the server matches this account to the configured TCP host. Inspect its
effective grants and remove any broader inherited/global privileges. These
runtime grants deliberately cannot create or alter tables.

Keep Apache stopped during provisioning. Temporarily configure a separate
maintenance account with the schema/data permissions needed for migrations and
seeding, scoped to `pediacare`; run from the project root:

```powershell
C:\xampp\php\php.exe bin/migrate.php
C:\xampp\php\php.exe bin/seed-who.php
C:\xampp\php\php.exe bin/seed-vaccines.php
C:\xampp\php\php.exe bin/seed.php
```

Before `seed.php`, set a unique first Admin name, email and strong password in
the local config. Remove the plaintext seed password after success. Replace
maintenance credentials with the restricted runtime account before starting
Apache, and revoke temporary maintenance access. Do not run `seed-demo.php` on
the live database. Admin adds the center's actual puroks and staff accounts.

The vaccine seed is **DRAFT**. The RHU must confirm the schedule, intervals and
configured FIC membership before use. A nurse/nutritionist must confirm the
project's growth category bands. The privacy contact must approve the collection
notice, access, retention and incident procedures; confirm the notice is actually
provided at collection. This guide does not supply or approve a privacy notice.

## Apache HTTPS and access restrictions

Use `public/` as DocumentRoot for every host that can serve Pediacare. Review
the default host, SSL host and all aliases as well as the named application host;
another host must not expose the project directory. Remove unused dashboard,
sample, status and CGI endpoints. Remove phpMyAdmin or enforce `Require local`
on its actual directory/alias. Check from a second LAN device, not only localhost.

Enable `mod_rewrite`, `mod_headers` and `mod_ssl`, and the required virtual-host
and SSL configuration includes. Preserve necessary module configuration while
replacing sample SSL hosts. Define `Listen 443` once. The following is a LAN
template, not a configuration to append unchanged: replace hostname, subnet and
paths with the center's approved values and provision the certificate first.

```apache
<VirtualHost *:80>
    ServerName pediacare.healthcenter.test
    Redirect permanent / https://pediacare.healthcenter.test/
</VirtualHost>

<VirtualHost *:443>
    ServerName pediacare.healthcenter.test
    DocumentRoot "C:/Pediacare/public"
    SSLEngine on
    SSLCertificateFile "D:/PediacarePrivate/TLS/server.crt"
    SSLCertificateKeyFile "D:/PediacarePrivate/TLS/server.key"

    <Directory "C:/Pediacare/public">
        Options -Indexes
        AllowOverride All
        Require ip 127.0.0.1 ::1 192.168.10.0/24
    </Directory>

    LogFormat "%t %m %U %H %>s %B" pediacare_private
    CustomLog "logs/pediacare-access.log" pediacare_private
    ErrorLog "logs/pediacare-error.log"
</VirtualHost>
```

This template enables the existing `.htaccess` front controller and security
headers. The HTTP host redirects before PHP handles requests. Log paths are
relative to Apache's ServerRoot. The access format omits queries, cookies and
referrers, which can contain names or search terms; do not also log these requests
with an inherited/global combined log. Paths still reveal record IDs: restrict
and retain logs under the center's policy. Review Apache error logs using fake
requests for accidental request details, and restrict them as sensitive metadata.
See Apache's [TLS configuration](https://httpd.apache.org/docs/2.4/ssl/ssl_howto.html)
and [log format fields](https://httpd.apache.org/docs/2.4/mod/mod_log_config.html).

For a closed LAN, a self-signed certificate is allowed by the plan; provision
device trust securely, with the correct hostname in the certificate. Staff should
not bypass certificate warnings. Keep its private key restricted and plan renewal.
Online use requires a publicly trusted certificate. The application detects HTTPS
from Apache's `HTTPS` server value, not `X-Forwarded-Proto`; a proxy deployment
requires a separate review of trusted TLS forwarding before use.

Validate before restarting Apache:

```powershell
C:\xampp\apache\bin\httpd.exe -t
C:\xampp\apache\bin\httpd.exe -S
C:\xampp\apache\bin\httpd.exe -M
```

## PHP, files and Windows services

In Apache's active PHP configuration, set `display_errors=Off`,
`display_startup_errors=Off`, `log_errors=Off` and `expose_php=Off`. The application
already disables automatic PHP error logging and writes sanitized type/location
diagnostics to `storage/logs/app.log`; raw exception messages can disclose health
data. Monitor that private log and HTTP failures. Restart Apache after changes,
and verify the configuration used by Apache rather than assuming CLI PHP uses
the same file. Remove diagnostic `phpinfo()` pages after setup.
See the [PHP error configuration manual](https://www.php.net/manual/en/errorfunc.configuration.php).

Set a private application-specific `session.save_path` outside every web root.
Set `session.gc_maxlifetime=1800` for that directory so PHP's default 24-minute
cleanup does not shorten the application's 30-minute idle window.
Restrict session files, local config, application logs, TLS keys, database files
and backup keys with NTFS ACLs. The Apache identity needs read access to code and
config and write access to logs and sessions; it should not be able to rewrite
application source. Give database and backup identities only their necessary
directories. Do not rely on PHP `chmod` to replace Windows ACLs. See the
[PHP session configuration manual](https://www.php.net/manual/en/session.configuration.php).

Stop and disable unused FileZilla, Mercury and Tomcat services. Run only required
Apache/database services under controlled accounts and verify startup after a
reboot. Keep the Windows firewall enabled: allow application HTTPS (and HTTP
redirect if used) only from approved LAN clients. Review IPv6 as well as IPv4,
router forwarding and any other listening services.

## Daily backups and updates

Complete [backup setup and a recovery drill](backup.md), including daily Task
Scheduler execution, encrypted off-server copies and separately protected keys.
The backup command reads the same local database credentials as the web app;
there is no separate backup-credential option. To keep the web account restricted,
use a private CLI-only copy of the same reviewed release, outside all web roots,
with its own local config and a separate backup account granted SELECT, SHOW VIEW
and TRIGGER on `pediacare`. Point the scheduled task at that copy. Its configured
database name must be the live source, and its backup directory must be outside
both project copies. Protect this copy and its credentials from other users;
keep it updated with the release. Recovery uses a separate empty database and
temporary recovery privileges, never the live runtime account.

Monitor the current 32 MiB SQL dump limit and backup failures. Before an update,
verify a fresh encrypted backup and recovery path, stop writes, preserve private
config/keys, deploy the reviewed release and run new migrations with maintenance
credentials. Restore runtime credentials before service resumes. Do not edit
applied migrations or blindly roll back code across schema changes. If recovery
is necessary, follow the separate-database verification and switch procedure in
`backup.md`; keep the old database protected.

## Go-live checklist (operator evidence required)

Leave each box unchecked until verified on the actual host. Record date, operator
and outcome without child identifiers or personal data.

- [ ] Hosting choice, responsible administrator and privacy contact recorded; clinical schedule and growth bands confirmed.
- [ ] Collection privacy notice provided and retention/access/incident procedures approved.
- [ ] Reviewed release passes `composer test`, T42 browser QA and T43 acceptance review on fake data.
- [ ] Root password set; application account has only SELECT/INSERT/UPDATE/DELETE on `pediacare`; temporary maintenance access revoked.
- [ ] Database listens only locally; firewall and router do not expose XAMPP to the internet.
- [ ] Every DocumentRoot/alias serves only intended public content; phpMyAdmin/dashboard unavailable to LAN clients.
- [ ] Trusted HTTPS works on staff devices; HTTP redirects; certificates and renewal are recorded.
- [ ] PHP display errors disabled; unused FileZilla/Mercury/Tomcat stopped and disabled.
- [ ] Private NTFS ACLs, session directory, sanitized application logs and query-free access logs verified.
- [ ] From a LAN client, private paths (`/config/config.local.php`, `/database/who/who2006.csv`, `/vendor/autoload.php`, `/.git/config`) never return file contents; directory listings and other PHP entry points are denied.
- [ ] Login cookie is Secure, HttpOnly and SameSite=Lax; login/logout renew sessions; 30-minute idle expiry works.
- [ ] Security headers present; unauthenticated child access redirects; Viewer cannot open individual records; missing CSRF returns 403.
- [ ] First Admin works; seed password removed; staff roles/puroks verified; live database contains no demo records.
- [ ] Daily encrypted task succeeds; off-server copy verified; recovery drill passes; keys recoverable separately; backup growth monitored.
- [ ] Reboot/startup and post-deployment smoke checks pass; administrator authorizes opening the system for real records.

Writing this guide does not complete these host checks. The
[local QA record](qa-checklist.md) and [acceptance review](acceptance-review.md)
document the completed verification and remaining gaps. Production deployment
remains an operator action.
