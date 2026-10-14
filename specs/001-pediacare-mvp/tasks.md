# Tasks 001 — Pediacare MVP

> Work top to bottom. One task at a time. Each task is done only when its tests pass
> (`composer test`). Check it off (`- [x]`) and stop for review.
> Acceptance criteria references (AC-xx) point to `spec.md` §7. Architecture references point to `plan.md`.

## Phase 0 — Setup
- [x] **T01** Create the folder structure from `plan.md` §4. Add `composer.json` with PSR-4 autoload
      (`Pediacare\\` → `src/`) and PHPUnit as a dev dependency. Add a `composer test` script and `.gitignore`.
- [x] **T02** Add `config/config.example.php` and loading of `config/config.local.php`.
      Write setup steps in `README.md` (XAMPP, VirtualHost pointing to `public/`, create databases).
- [x] **T03** Core: `Database` (PDO factory with safe options), `Container` (simple DI), `bootstrap.php`
      with error handling (generic error page; details only in logs).
- [x] **T04** Core: `Request`, `Response`, `Router` with route table in `routes.php`, Front Controller
      `public/index.php`, and `.htaccess` rewrite. Unit-test the router.
- [x] **T05** Core: `View` renderer with layouts and partials, the `e()` helper, `Flash` messages,
      and a base layout (sidebar, header, local Bootstrap/CSS).
- [x] **T06** `bin/migrate.php`: applies new SQL files from `database/migrations/` in order and records
      them in a `migrations` table. Also supports the `pediacare_test` database.

## Phase 1 — Auth, security core, and roles
- [x] **T07** Core: `Session` (secure cookie settings, idle timeout) and `Csrf` with `CsrfMiddleware`.
- [x] **T08** Migration for `users` and `login_attempts`. `bin/seed.php` creates the first Admin from config.
- [x] **T09** `AuthService` + `AuthController`: login/logout with `password_hash`/`password_verify`,
      session regeneration, and login rate limiting.
- [x] **T10** `AuthMiddleware`, `RoleMiddleware`, and `Permissions` matrix. All app routes protected. _(AC-01)_
- [x] **T11** Admin: user management (create, deactivate, change role). _(AC-02)_
- [x] **T12** Security headers sent on every response.

## Phase 2 — Audit log foundation
- [x] **T13** Migration for `audit_logs`. `AuditLogger` that stores only changed fields. Unit tests.
- [x] **T14** Admin: audit log viewer with filters (user, entity, date range). _(AC-11)_

## Phase 3 — Child registry
- [x] **T15** Migrations for `puroks`, `children`, `record_counters`. Admin page to manage puroks.
- [x] **T16** `RecordNumberGenerator` using `SELECT ... FOR UPDATE`, with an integration test. _(AC-05)_
- [x] **T17** `ChildRepository`, `ChildValidator`, `ChildService::register()` with duplicate check and
      audit entry. Register-child form using Post/Redirect/Get. _(AC-04, AC-11)_
- [x] **T18** Children list: search (name, record no., guardian), filters (purok, sex, age group), pagination.
- [x] **T19** Child profile page: demographics plus tabs for Growth, Immunization, Visits.
- [x] **T20** Edit child; archive/restore with reason (Admin/Staff only), with audit entries.
      Archived children excluded by default. _(AC-02, AC-11, AC-12)_

## Phase 4 — Growth monitoring
- [x] **T21** Add WHO LMS CSVs to `database/who/` with a README citing the source. Migration for
      `who_lms` and a seed script that loads the CSVs.
- [x] **T22** Domain: `AgeInMonths`, BMI, and length/height adjustment as value objects / pure functions. Unit tests.
- [x] **T23** Domain: `GrowthReference` interface with `Who2006Standard` and `Who2007Reference`
      strategies, and the LMS z-score calculation. Reference tests against WHO Anthro output. _(AC-06)_
- [x] **T24** Domain: classifiers per `plan.md` §6.3 and implausible-value checks. Unit tests. _(AC-14)_
- [x] **T25** Migration for `measurements`. `MeasurementService::record()` computes and stores results,
      requires confirmation for implausible values, and writes an audit entry. Add-measurement form. _(AC-06, AC-11, AC-14)_
- [x] **T26** Growth tab: measurement history table and Chart.js growth chart with WHO reference curves.

## Phase 5 — Immunization
- [x] **T27** Migrations for `vaccines`, `schedule_doses`, `immunizations`. Seed the draft schedule from `plan.md` §6.4.
- [x] **T28** Admin: vaccine schedule editor.
- [x] **T29** Domain: `DoseStatusCalculator` and `FicChecker`, with unit tests for due, overdue,
      upcoming, minimum interval, and FIC cases. _(AC-07, AC-08)_
- [x] **T30** Immunization tab: dose status grid and record-vaccine form (including "given elsewhere"),
      with audit entry. _(AC-11)_
- [x] **T31** Defaulter list page filtered by purok and vaccine. _(AC-09)_

## Phase 6 — Visits
- [x] **T32** Migrations for `visits` and `supplements`. `VisitService` and add-visit form with
      supplements (Vitamin A, deworming, iron/MNP), with audit entry. _(AC-11)_
- [x] **T33** Child profile: combined timeline of measurements, vaccines, and visits.

## Phase 7 — Reports
- [x] **T34** `ReportService`: nutritional status summary (0–59 months) by status, sex, and purok for a period. _(AC-10)_
- [x] **T35** Monthly immunization summary: doses given per vaccine, plus FIC count.
- [x] **T36** Malnutrition list from each child's latest measurement.
- [x] **T37** CSV export (`fputcsv`) and print-friendly CSS for all reports. Viewer role can open reports
      but not individual records. _(AC-03, AC-13)_

## Phase 8 — Hardening and release
- [x] **T38** Security review: every route has the right middleware, every query is prepared, every
      view output is escaped, every POST form has CSRF, no personal data in logs. Fix and list findings.
- [x] **T39** Seed script with realistic **fake** data (around 200 children) for demos and testing.
- [x] **T40** `bin/backup.php` (mysqldump + encryption) and a tested restore. Document in `docs/backup.md`.
- [x] **T41** Write `docs/deploy.md` including the XAMPP hardening checklist from `plan.md` §8.
- [x] **T42** Write `docs/qa-checklist.md` and run it once end to end.
- [x] **T43** Final check: every acceptance criterion AC-01 to AC-14 has a passing test or QA checklist item. List any gaps.
