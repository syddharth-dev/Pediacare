# Pediacare

Child health records for a barangay health center, built with vanilla PHP 8.1+,
MySQL/MariaDB, and server-rendered HTML, CSS, and JavaScript.

To check Pediacare on your laptop, follow the
[easy XAMPP setup guide](docs/xampp-local-setup.md). It explains installing
Composer, configuring the database, creating the first administrator and opening
the app. For real records, follow the linked
[final local deployment details](docs/xampp-local-deployment-details.md).

For a live installation, follow the [deployment and XAMPP hardening guide](docs/deploy.md)
and complete its operator checklist before entering real health records.
Use the [release QA checklist](docs/qa-checklist.md) for the staging workflow and
recorded local browser validation.
The [final acceptance review](docs/acceptance-review.md) maps AC-01 through AC-14
to tests and lists specification gaps and remaining go-live checks.

## Development setup (Windows / XAMPP)

### 1. Prepare PHP and Composer

Install XAMPP with PHP 8.1 or newer and install Composer. The examples assume
XAMPP is at `C:\xampp`. Add `C:\xampp\php` and Composer's executable directory
to your PATH, then open a new terminal in the project root.

```powershell
php --version
composer --version
composer install
```

Enable `pdo_mysql`, `mbstring`, and `zip` in `C:\xampp\php\php.ini` if needed.
PHPUnit also requires DOM, XMLWriter, and the other extensions checked by
`composer check-platform-reqs`. Restart Apache after changing its PHP configuration.

### 2. Create the development databases

Start MySQL from the XAMPP Control Panel. Open its CLI with an administrator
account (enter the password at the prompt):

```powershell
& C:\xampp\mysql\bin\mysql.exe -u root -p
```

Create two empty databases. Tables are created by numbered migrations through
`bin/migrate.php`; do not create or edit tables manually.

```sql
CREATE DATABASE IF NOT EXISTS pediacare
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS pediacare_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Create a dedicated development account. Replace `CHANGE_ME_LOCAL_ONLY` with a
unique local password before running these statements:

```sql
CREATE USER 'pediacare_app'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME_LOCAL_ONLY';
GRANT ALL PRIVILEGES ON pediacare.* TO 'pediacare_app'@'127.0.0.1';
GRANT ALL PRIVILEGES ON pediacare_test.* TO 'pediacare_app'@'127.0.0.1';
```

These development grants allow migrations to create tables in these two databases.
Production runtime permissions are limited as described in `plan.md` §8.
Keep `pediacare_test` separate: integration tests will reset test data. Use only
fake child data during development and testing.

### 3. Set local configuration

```powershell
Copy-Item config/config.example.php config/config.local.php
```

Edit `config/config.local.php` with your database host, port, username, and password.
Use `pediacare` for `database.name` and `pediacare_test` for `database.test_name`.
The file must return a PHP array, as the example does. It is gitignored; never
commit credentials. Keep shared configuration keys in `config/config.example.php`.

`src/bootstrap.php` loads this local array, sets the timezone, and returns a
dependency container. Configuration is available as `$container->config`.
The shared PDO connection is created lazily through `$container->get(PDO::class)`.
A missing local file or a non-array return value raises a configuration error;
the example is never used as a fallback.

Unhandled exceptions render a generic HTTP 500 page. Diagnostics go to
`storage/logs/app.log`, which must be writable by PHP. Logs contain the error type,
timestamp, filename, and line number; messages, stack traces, and request data
are excluded to protect health data and credentials. PHP's automatic error
display and logging are disabled by bootstrap.

The container also provides `View` and `Flash`. Controllers render a template
with `View::render($template, $data)` and put the returned HTML in a `Response`.
The default layout is `layouts/main`; pass `null` for a standalone template.
Templates use `e()` for every scalar output and `$view->partial()` for shared
fragments. Navigation items are supplied by controllers after permission checks.

`Flash::add($message, $type)` stores a message in the session array;
`Flash::pull()` consumes queued messages once. Controllers pass these messages
to the view rather than reading sessions inside templates. The front controller
starts the secure session before resolving flash messages or CSRF tokens.

Sessions use HttpOnly and SameSite=Lax cookies, with Secure enabled on HTTPS,
strict session IDs, and a 30-minute idle timeout. Expiry clears all session data
and renews the ID. HTTPS detection uses Apache's `HTTPS` value; deployments behind
a proxy must configure Apache to receive the correct HTTPS state.
Configure a private, application-specific `session.save_path` in PHP so other
applications cannot access these sessions or remove them with shorter garbage
collection settings (see the [PHP session configuration manual](https://www.php.net/manual/en/session.configuration.php)).

Every POST is checked by `CsrfMiddleware` before routing. Controllers obtain a
token from the container's `Csrf` instance and pass it to views. Render
`csrf_field($csrfToken)` inside every POST form; this helper escapes the token.
Missing, malformed, mismatched, or expired tokens return HTTP 403.

### 4. Configure Apache's VirtualHost

In `C:\xampp\apache\conf\httpd.conf`, enable these lines if commented:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
Include conf/extra/httpd-vhosts.conf
```

Add the following development host to
`C:\xampp\apache\conf\extra\httpd-vhosts.conf`, adjusting the project path.
Preserve any existing VirtualHosts.

```apache
<VirtualHost *:80>
    ServerName pediacare.test
    DocumentRoot "C:/Users/brian/OneDrive/Documents/Pediacare/public"

    <Directory "C:/Users/brian/OneDrive/Documents/Pediacare/public">
        Options -Indexes
        AllowOverride All
        Require local
    </Directory>
</VirtualHost>
```

Serve only `public/` so configuration, source files, and database files stay
outside the web root. This follows Apache's
[name-based VirtualHost documentation](https://httpd.apache.org/docs/2.4/vhosts/name-based.html).

Using an administrator editor, add this entry to
`C:\Windows\System32\drivers\etc\hosts`:

```text
127.0.0.1 pediacare.test
```

Check Apache's configuration and restart Apache from the XAMPP Control Panel:

```powershell
& C:\xampp\apache\bin\httpd.exe -t
```

This HTTP host is for localhost development with fake data only. Deployment
requires HTTPS and the hardening measures in `specs/001-pediacare-mvp/plan.md` §8.
The web entry point is `public/index.php`; `.htaccess` sends application requests
through it and serves existing files under `public/assets/` directly. The route
table in `src/routes.php` provides `/login` and POST-only `/logout`. Other feature
routes are added as their tasks are implemented.

## Database migrations

After configuring the local credentials, run from the project root:

```powershell
php bin/migrate.php
php bin/migrate.php --test
```

The default command uses `database.name`; `--test` uses `database.test_name` and
refuses to use the same database as the default. It never creates databases.
`000_create_migrations.sql` initializes the tracking table. Files use unique
three-digit numbers followed by a description, for example `001_create_users.sql`.
They run in filename order, and previously recorded filenames are skipped.

Use one SQL statement per file, compatible with MySQL native prepared statements.
Do not edit or rename applied files; add another numbered migration instead.
Only run one migration process at a time. A failed statement stops the runner
and is not recorded. If recording fails after successful DDL, inspect the schema
before retrying: MySQL DDL can commit independently of migration tracking.
See [MySQL implicit commits](https://dev.mysql.com/doc/refman/8.4/en/implicit-commit.html).

## First Admin

After running migrations, set `first_admin.name`, `first_admin.email`, and
`first_admin.password` in `config/config.local.php`. Use a unique strong password.
There are no default credentials. Then run:

```powershell
php bin/seed.php
```

The CLI command creates one active Admin with a hashed password in the configured
application database. Run only one seed process at a time. If any Admin already
exists (including an inactive one), it leaves all accounts unchanged. A conflicting
email fails rather than replacing an existing account. Remove the seed password
from local configuration after success. Login uses email only at `/login`.

Login accepts active accounts, verifies passwords, updates the password hash when
needed, and records the login time. Successful login and logout renew the session
ID and clear previous session data, including CSRF tokens. Logout requires POST
with the current token. The login page shows a signed-in confirmation and sign-out
button until later tasks supply application pages.

Five failed logins for either an email address or a client IP within the previous
15 minutes block further checks until failures leave that window. Blocked requests
do not extend the window. Database advisory locks serialize checks and recording
across sessions. Client IP comes from Apache's `REMOTE_ADDR`, not forwarded headers.
Unknown accounts, inactive accounts, incorrect passwords, and blocked attempts
receive the same message.

## Authentication and permissions

After session startup and CSRF verification, `AuthMiddleware` runs before routing.
Only `/login` is public. Logged-out, expired, missing-account, or inactive-account
requests to app URLs redirect to `/login`, including unknown URLs. Invalid POST
tokens still return 403 before authentication, following the middleware order.

Every non-login route declares `[AuthMiddleware::class, RoleMiddleware::class]`
and allowed `roles` from `Permissions::roles($permission)`. Missing or empty roles
deny access. Middleware reads the account's current active flag and role from the
database; session data holds only its ID. Deactivation and role changes therefore
apply to the next request.

`src/Support/Permissions.php` mirrors the spec's role table. Controllers can use
`Permissions::allows($role, $permission)` to supply visibility flags to templates;
the route middleware remains the access guard. Viewers can access aggregate
reports and sign out, but cannot access individual records. Future named child
lists must use `children.read`. Tests cover the shared guards and matrix; feature
pages will add their own UI and route checks as they are implemented.

## Manage accounts

Admins can open **Manage users** from the signed-in account page, or visit
`/admin/users`. Create an account with its name, unique email, initial password,
and role. Share the initial password securely with the account holder.

Each account has a role form and, while active, a deactivation confirmation.
Deactivation keeps the account and linked records; it prevents login and removes
access on the next request. Role changes also apply to existing sessions on their
next request. All changes require POST with a CSRF token and Admin middleware.
Other roles have no management navigation and receive 403 on direct requests.
Validation errors preserve name, email, and role input; passwords are never
redisplayed. Successful changes redirect back with a one-time message.

## Security headers

Every PHP response, redirect, and handled error includes `X-Frame-Options: DENY`,
`X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, and a Content
Security Policy allowing resources only from the same origin. Scripts and styles
must use local external files; inline scripts, inline styles, plugin objects,
and framing are blocked. Forms submit to the same origin.

Apache's `mod_headers` applies the same policy to static assets and server errors.
Keep `public/.htaccess` synchronized with `Core/SecurityHeaders.php`; a test checks
the values. Both Apache header tables are handled to avoid duplicate headers, as
described in the [mod_headers documentation](https://httpd.apache.org/docs/2.4/mod/mod_headers.html).
The PHP development server does not process `.htaccess`, so static asset headers
must be verified through Apache.

## Audit logging

Migration `003_create_audit_logs.sql` adds audit entries with actor, action,
entity ID, changed fields, client IP, and timestamp. `AuditLogger` delegates SQL
to `AuditRepository`, using the container's shared PDO connection. Its `log()`
method accepts `AuditAction::CREATE`, `UPDATE`, `ARCHIVE`, or `RESTORE`.

Services must begin a transaction, make the record change, call `log()`, then
commit. On any failure, roll back and rethrow; the logger never swallows failures
or starts its own transaction. The repository rejects writes outside a transaction.
The JSON contains `before` and `after` objects with only changed fields. Missing
fields remain absent, explicit nulls remain null, and unchanged fields are omitted.
Every call records an entry even when both objects are empty.

The logger receives the server's `REMOTE_ADDR` through bootstrap; CLI calls can
omit IP (stored as null). Audit values are stored in the database and never sent
to diagnostic logs. Health-record services will add calls as their tasks are
implemented. Registration tests verify that the child, record counter, and audit
entry commit together, and that a failure rolls all three back.

Admins can open **Audit log** from the sidebar at `/admin/audit`. Filter by user
(including inactive accounts), entity, start date, or inclusive end date. Filters
combine and remain in the URL. Entries appear newest first and show the actor,
action, entity and record ID, timestamp, IP, and expandable before/after values.
Other roles cannot see the link or access the route. The viewer is read-only;
it does not edit or delete audit entries.

## Child registry foundation

Run `php bin/migrate.php` to add `puroks`, `children`, and `record_counters`
(migrations 004–006). The child table includes the demographics in the spec,
nullable optional fields, archive timestamp/reason, a unique record number,
and the planned name, birth date, and purok indexes. Its purok and creator
foreign keys prevent deletion of referenced records. No child records are seeded.

Admins can open **Manage puroks** at `/admin/puroks` to add a purok or sitio
and rename an existing one. Names must be unique and up to 255 characters;
renaming preserves the ID and existing child relationships. Other roles cannot
see the link or use the routes. All writes require CSRF and redirect after saving.
Admins, Staff, and BHWs can open **Register child** at `/children/new`. New
registrations accept children aged 0–59 completed months; future birth dates
and children aged five or older are rejected. Required demographics and a valid
purok must be supplied; optional birth measurements use kilograms and centimeters.
Viewers cannot access the form or submit registrations.

A matching full name and birth date shows a warning before saving, including
matches in archived records. Staff must explicitly confirm a different child.
Confirmation is bound to the session and submitted details; changing details
requires another duplicate check. Successful registration redirects to the new
child’s profile with a one-time message containing the assigned record number.

Open **Children** at `/children` to search by name, record number, or guardian.
Search treats `%`, `_`, and `!` as literal characters. Purok, sex, and age filters
combine with the search. Age groups use completed months today: 0–11, 12–23,
24–35, 36–47, 48–59, and 60+ for children who age beyond five.
Archived children stay hidden unless **Include archived** is selected.
Results sort by last name, first name, then ID, with 25 records per page;
Previous/Next links preserve all filters. New searches start at page one.
The list uses the same Admin/Staff/BHW access rules as registration; Viewers
cannot see the link or access its records. Responses disallow caching.

Select a record number in the list or duplicate warning to open `/children/{id}`.
The profile displays demographics, family and guardian details, optional birth
measurements, and notes. Missing optional fields display **Not recorded**.
Archived profiles remain readable to authorized staff and show the archive
date and reason; their back link includes archived records in the list.
Growth, Immunization, and Visits tabs use `?tab=growth`, `?tab=immunization`,
and `?tab=visits`, so links work without JavaScript and support browser history.
These sections state that they are not yet available; later tasks add their
records, charts, and forms. Profile responses disallow caching, invalid or
missing child IDs return 404, and invalid tabs return 400. Viewers receive 403
before any child data is loaded.

Admins and Staff can select **Edit child details** on a profile. Required fields,
dates, puroks, and optional birth measurements are validated as in registration;
the new-registration age limit does not prevent edits to children who have aged
beyond five. Validation errors keep the entered details. Record number, creator,
and archive status are preserved when editing demographics.

The profile also offers **Archive child record** for active records or **Restore
child record** for archived ones. Both require a reason, explicit confirmation,
and a CSRF token. BHWs and Viewers cannot use these routes or see their controls.
Archiving keeps the record and hides it from default lists; restoration returns
it to those lists. The archive reason stays in historical audit entries, and the
restore reason is recorded in the RESTORE entry. Repeating an already completed
status change is rejected. Successful changes redirect to the profile.

Each change locks the current child row, writes only the allowed fields, and
records its before/after values in the same transaction. An audit failure rolls
back the record change. No child record is hard-deleted by these actions.

`RecordNumberGenerator::generate()` returns `PC-YYYY-NNNNN`, using the current
year in the application's timezone (or an explicit four-digit year). It must run
inside the registration service's transaction on the shared PDO connection.
`RecordCounterRepository` initializes the year's counter safely, locks it with
`SELECT ... FOR UPDATE`, and saves the increment. It does not commit; a rollback
undoes the increment, including first-year initialization. Concurrent callers
wait for the same year's lock. The generator rejects allocation after 99999
rather than wrapping or changing the specified format. Tests cover parallel
connections for both new and existing counters.

## WHO growth reference data

After applying migrations, load the bundled WHO LMS tables:

```powershell
php bin/seed-who.php
```

Use `php bin/seed-who.php --test` for the separate test database. Migration 007
creates `who_lms`. The import verifies file integrity and loads both references
in one transaction; rerunning it updates the same coordinates without duplicates.
It works offline and does not change child records or Admin accounts. See
[reference sources, units, coverage, and import details](database/who/README.md).
WHO z-score calculations are implemented in the Domain layer; measurement
forms and saving measurements are implemented in later tasks.

The T22 domain helpers are pure PHP and require no database:
`AgeInMonths::between()` retains completed calendar months and exact elapsed
days between the supplied birth and measurement dates, ignoring time of day.
It rejects measurements before birth. `LengthHeight::adjust()` applies the
WHO length/height position correction using the exact 730/731-day boundary;
`Bmi::calculate()` accepts kilograms and the adjusted length/height in centimetres.
Both reject nonpositive or nonfinite inputs and preserve calculation precision
without rounding for display or storage. The medical source is the
[WHO Anthro manual, sections 3.2.3 and 3.3.1](https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/software/anthro-pc-manual-v322.pdf).
Completed calendar months describe age; exact days determine the WHO2006 table
coordinate and position transition, so these values can differ at anniversaries.
`AgeInMonths::whoMonths()` provides exact days divided by 30.4375 for clinical
reference selection. WHO2006 covers days 0–1856; WHO2007 starts at day 1857.
`WhoLmsRepository::load()` passes an immutable `LmsTable` to the pure
`Who2006Standard` and `Who2007Reference` strategies used by `GrowthCalculator`.
Calculations preserve precision, interpolate reference parameters, and apply
WHO's restricted LMS tails for weight-based indicators. Unavailable indicators
return null; missing required data and ages outside reference coverage fail
explicitly. See the [WHO calculation fixtures](tests/Fixtures/who-anthro-growth.md).
`Classifier::classify()` maps a `GrowthResult` to immutable nutritional status
enums and lists the indicators outside the implausibility limits. Its
`GrowthClassification::needsConfirmation()` tells the future measurement
service whether confirmation is needed. Unavailable scores retain null labels;
nonfinite scores are rejected. Categories and exact boundary rules follow the
approved plan §6.3, including reference-specific BMI labels and “High
weight-for-age” rather than an overweight diagnosis based on WFA alone.
Tests cover category and flag boundaries without rounding scores first.
Clinical approval remains required before go-live.

Migration 008 adds `measurements`. Authorized Admin, Staff, and BHW accounts can
select **Add measurement** on an active child's Growth tab. The form records
date, weight, measured length/height, position, and optional MUAC; the signed-in
staff member is recorded automatically. BMI, applicable scores, age, and status
labels are calculated on the server. The entered height is preserved; WHO
position corrections are used for calculations. Computation and classification
precede storage rounding, so boundary checks use full precision.

Implausible results require a review followed by explicit checkbox confirmation.
A session token binds confirmation to the child, staff member, values,
demographics, and calculated results. Changing those values requires another
review if the result remains implausible. Warning responses write no records.
Saving locks the child row and inserts the measurement and audit entry in one
transaction; audit failures roll back both. Archived children cannot receive
measurements until restored. Apply migrations and import WHO data before use.
The Growth tab shows saved measurement dates, ages, values, scores, classifications,
and staff attribution, including archived records. Its BMI-for-age chart uses a
local Chart.js bundle and sex-specific WHO LMS curves (median and −3, −2, +1, +2,
+3 SD). Reference segments remain separate at clinical boundaries. The chart
uses current demographics; saved results are never recalculated when viewing.
Measurements outside chart age coverage remain in the table. The table also
works without JavaScript. Clinical sign-off remains required before live use.

## Draft vaccine schedule

Seed the draft vaccine schedule after applying migrations 009–011:

```powershell
php bin/seed-vaccines.php
php bin/seed-vaccines.php --test
```

The seed contains seven vaccines and 15 doses; reruns preserve existing edits.
Nine doses count toward the configured FIC completion flag. Calendar-month ages
and intervals are stored separately from day-based values. Zero grace days is
an editable software default. Confirm this draft with the center before live use;
the completion flag does not implement DOH's age-limited FIC reporting definition,
and date-only records cannot prove the birth dose was given within 24 hours.
Admins can open **Vaccine schedule** from their navigation or visit `/admin/schedule`
to add vaccines and doses, rename or deactivate vaccines, and edit recommended ages,
minimum intervals, grace periods, display order and FIC membership. Ages and intervals
each use days or calendar months. Vaccine codes remain fixed, and a dose with recorded
vaccinations cannot be renumbered. Schedule changes are audited in the same transaction.
Open a child's **Immunization** tab to see Given, Due, Overdue and Upcoming doses,
their earliest eligible dates, recorded details and configured FIC completion.
Admin and Staff can choose **Record dose** and enter the vaccination date, place,
given-elsewhere flag, and optional lot or site. Attribution uses the signed-in staff
account. BHW can view history; Viewer cannot access individual records.
The server rejects duplicate doses, future dates, dates before birth, and dates
before the configured age or minimum interval. Record previous doses first,
including those given elsewhere. Archived children must be restored before adding
records. Inactive vaccines retain their history but cannot receive new records.

Admin, Staff and BHW can open **Vaccine defaulters** from their navigation
(`/immunizations/defaulters`) and filter by purok or vaccine. Each child appears
once with the matching overdue doses. Archived children are excluded, and Viewer
cannot access this list of individual records. Results are paginated in groups
of 25; the calculation scans children and vaccination records in batches.

## Health visits

After applying migrations 012–013, Admin, Staff and BHW can open a child's
**Visits** tab and choose **Record visit**. Enter the visit date, reason,
findings, actions or treatment, referral destination when applicable, and notes.
For Vitamin A, deworming, iron or micronutrient powder, enter both the dose
(including its unit) and date given; leave unused service fields blank.
Attended-by attribution uses the signed-in account. Future dates and dates before
birth are rejected. Archived children must be restored before adding visits.
The visit, supplements and their audit entries save together or all roll back.
The **Visits** tab also shows a combined health timeline of saved measurements,
vaccinations and visits, with referral details and supplements attached to their
visit. Events appear newest first in pages of 25. Events sharing a date are
grouped by type; the ordering does not establish their time of day. Saved growth
results remain as recorded. Admin, Staff and BHW can read the timeline, including
retained history on an archived child's profile; Viewer cannot access it.

## Nutritional status summary

Admin, Staff and Viewer can open **Nutritional summary** from their staff account
navigation (`/reports/nutrition`). Choose inclusive start and end dates; the default
is the current month through today. Each child's latest measurement in that period
is selected, with greater measurement IDs breaking same-date ties. Only children
aged 0–59 completed calendar months on that selected date are counted. A newer
out-of-range measurement prevents fallback to an older one.
The report counts saved classifications for each growth indicator separately,
shows missing labels as **Not available**, and excludes archived children unless
selected. It uses current sex, purok and birthdate. Admin and Staff see purok
breakdowns; Viewer sees barangay-wide totals by sex and no child records.
Use Download CSV or Print view to export these same filtered counts.

The monthly immunization summary at `/reports/immunization` counts doses by
date given, including doses given elsewhere and inactive vaccines. It counts
children completing all currently configured FIC doses during the selected
month, rather than cumulative FIC completions. Admin, Staff and Viewer see
barangay-wide totals by sex. Archived children are excluded unless selected.
Schedule edits can change historical FIC counts; these are configured completion
counts, not DOH age-limited reporting figures.

The malnutrition list at `/reports/malnutrition` is available to Admin, Staff
and BHW. It lists children of all ages whose latest saved measurement has any
non-Normal classification, including Tall and High weight-for-age. These labels
alone are not a malnutrition diagnosis. Missing classifications and children
without measurements do not qualify. The greatest measurement ID resolves
same-date ties. Archived children are excluded unless selected. Results use
25-child pages with links to growth profiles. Viewer cannot access this
individual-child list; aggregate nutritional reports remain available.

All four reports offer **Download CSV (all results)** and **Print view (all results)**.
Downloads and print views retain the current validated filters and include every
matching record across list pages. Print view provides a Print report button;
the browser's print dialog also works. Printed reports hide navigation, filter
forms and pagination, repeat table headings and use landscape A4 layout.
CSV files use UTF-8 and spreadsheet formula-leading text is prefixed with an
apostrophe. Viewer can download and print barangay-wide aggregate nutrition and
immunization reports, while individual malnutrition and defaulter lists remain
restricted to Admin, Staff and BHW. Downloads and print pages use no-store headers.

## Fake demo data

For T39, `bin/seed-demo.php` creates 200 clearly labelled **FAKE** children aged
0–59 months across five fictional puroks. It adds measurement histories, normal
and non-Normal growth results, incomplete and completed vaccine histories,
given-elsewhere records, visits, supplements, referrals and ten archived children.
Some children have no measurements or vaccines so empty histories can be demonstrated.
Names, addresses and contacts are visibly fictional; phone and PhilHealth identifiers
are never generated as real-looking contact or membership numbers.

Use a disposable demo database with the usual configuration and setup steps.
Apply migrations, create an Admin with `bin/seed.php`, load WHO data with
`bin/seed-who.php`, and seed/configure vaccines with `bin/seed-vaccines.php` first.
Then run:

```powershell
php bin/seed-demo.php --demo
```

`--demo` explicitly selects this operation. The command refuses a registry containing
any children, including archived records, and requires an existing active Admin for
audit attribution. It does not create login credentials or change accounts, existing
puroks, WHO references or the vaccine schedule. Do not use the live health database
for demonstrations. To target the separate `pediacare_test` database, add `--test`;
that database must have its own migrations, references, schedule and active Admin.

Birthdates and histories are relative to the run date, with current measurements
and visits for current-period reports. Measurements are classified by the existing
WHO service; vaccine dates follow the configured eligibility rules. Supplement dose
text is explicitly fake and is not medical guidance. Draft vaccine schedules still
require clinical confirmation before live use.

The command writes each health record and its audit entry together through the
existing services. A rerun refuses to add duplicates. If interrupted, completed
records remain; rebuild only the disposable demo database using the normal setup
steps before retrying. The script never clears a database automatically.

## Database backups

T40 provides `bin/backup.php` for encrypted database dumps and `bin/restore.php`
for recovery into a separate empty database. Configure a private directory outside
this project and a random encryption key in `config.local.php` first.
See [backup and recovery instructions](docs/backup.md) for setup, daily scheduling,
off-site copies, key recovery and the tested restore procedure.

## Tests

```powershell
composer check-platform-reqs
composer test
```

Configuration tests use isolated temporary directories and never read or change
your real `config.local.php`. Setup and authentication tests reference T02–T09 because they have no matching
feature acceptance criterion; feature tests will reference AC-xx.

MySQL integration tests require configured access to `pediacare_test`; they are
skipped when local configuration is absent. `PEDIACARE_TEST_CONFIG` can point to
an alternate PHP configuration file with the same shape for an isolated test
server. Integration tests refuse a test database named anything other than
`pediacare_test`, or one matching the application database.

Database creation syntax: [MariaDB CREATE DATABASE documentation](https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/create-database).

## Development workflow

Follow `AGENTS.md` and read the MVP spec, plan, and tasks in that order. Complete
one task, run `composer test`, check the task off, and stop for review.
