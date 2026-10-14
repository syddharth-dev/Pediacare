# T38 security review

Reviewed on 2026-10-11 against `AGENTS.md`, the MVP specification (including
AC-01, AC-02, AC-03 and AC-11), and the security controls in the implementation plan.

## Findings

No application defects were identified in the five required checks below.
The review did identify a regression coverage gap: existing authorization tests
covered selected routes and report formats, rather than the entire route table.
`tests/Unit/RouteSecurityReviewTest.php` now checks all 36 registered routes against
the specification's role matrix, including GET/HEAD, logged-out requests and
unknown roles. It also checks all 18 POST endpoints with missing, empty, mismatched,
array-valued and cross-session CSRF tokens. No production code changes were needed.

| Check | Reviewed behavior and evidence |
| --- | --- |
| Route permissions | `src/routes.php` applies Auth and Role middleware to every route except the public login page and login handler. Admin pages require Admin; BHW cannot edit/archive/restore children or record vaccines; Viewer can access aggregate reports and logout. Individual reports and child records exclude Viewer. `public/index.php` additionally checks authentication before routing. Route matrix tests verify denied requests never reach the substituted controller. Existing report export tests cover CSV and print permissions with real controllers. |
| Prepared SQL | SQL execution is confined to repositories. Statements use PDO preparation with bound values and native prepares enabled by `Core/Database.php`. Dynamic SQL fragments choose fixed clauses or construct placeholder lists; request values are not inserted into SQL text. Migration SQL comes from numbered local migration files, not HTTP input. |
| Escaped output | Dynamic HTML text, attributes, form values, validation messages and chart JSON use `e()`. The remaining direct template expressions emit fixed attribute strings such as `selected`, `checked` and `required`, or the `csrf_field()` helper, which escapes its token. Chart JavaScript parses escaped data attributes and uses `textContent` for fallback messages. CSV uses `fputcsv` and formula-prefix protection. Existing escaping and feature view tests exercise malicious fake values. |
| POST CSRF | Every POST form includes `csrf_field()`, including login, logout and Admin forms. The front controller applies `CsrfMiddleware` before authentication and routing. Tokens are random and session-bound, compared with `hash_equals`. The new endpoint tests confirm invalid tokens stop before authentication or controller resolution; existing CSRF tests verify token generation and helper escaping. |
| Personal data in error logs | `Core/ErrorHandler.php` disables PHP error display and automatic error logging after registration. Application log entries contain only timestamp, exception class, source basename and line. They omit messages, traces, request bodies and query strings. Error-handler tests exercise exceptions and warnings with fake private data and confirm generic responses and sanitized logs. Controllers expose only expected validation messages; database exceptions reach the generic handler. |

Child registration, changes, archive/restore, measurements, immunizations, visits
and supplements were also checked for audit entries inside their write transaction.
Existing integration tests exercise rollback when audit persistence fails. Audit
records intentionally contain changed health fields and are restricted to Admin;
they are distinct from diagnostic error logs.

## Validation and limits

The new route review tests pass with 929 assertions. `composer test` passes with
1,079 tests and 6,349 assertions, with no skipped tests, against a disposable local
MariaDB instance using fake data. The new PHP test file also passes syntax checking.

This is a source review and automated regression check, not an external penetration
test or approval of a deployed health-data system. A live Apache/XAMPP configuration
was not audited here. HTTPS, web-root and database account restrictions, PHP settings,
server/access-log handling and network exposure remain deployment checks for T41.
Encrypted backup and restore verification remain T40. Browser QA remains T42.
No packages or PHPStan tooling were added; project PHP 8.1+ and vanilla architecture
take precedence over the PHP skill's framework and tooling suggestions.
