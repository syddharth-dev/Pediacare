# AGENTS.md — Pediacare

Standing instructions for Codex (or any coding agent) working in this repository.

## Project summary
Pediacare is a web-based child health record management system for a barangay health center
in the Philippines. It records children's demographics, growth measurements (weight, height,
BMI, nutritional status), immunization history, and health visits, and produces reports for
the center's staff.

## Skills (always check first)
This project keeps agent skills in the `.agents/` folder at the project root (e.g. `.agents/skills/<skill-name>/SKILL.md`).
1. At the start of **every** task, before reading specs or writing code, list the contents of `.agents/`
   and read the `SKILL.md` of every skill whose name or description relates to the task
   (e.g. PHP, MySQL, security, testing, frontend/UI, accessibility, documentation).
2. Follow the instructions in those skills while doing the task. If several skills apply, use all of them.
3. When you finish a task, state which skills you used, e.g. `Skills used: php-best-practices, mysql`.
4. If no skill applies, say so and continue.
5. **Precedence:** if a skill conflicts with this `AGENTS.md` or with the files in `/specs`
   (for example, a skill suggests a framework, ORM, or different folder structure),
   **this file and the specs win**. Point out the conflict instead of following the skill.
6. Never modify, delete, or add files in `.agents/` unless explicitly asked.

## Workflow rules (spec-driven development)
1. Before writing any code, read the relevant spec folder under `/specs` in this order:
   `spec.md` → `plan.md` → `tasks.md`.
2. Only implement what is described in `spec.md`. If something is not in the spec, do not build it.
   Suggest it instead.
3. If the spec is ambiguous or contradicts the plan, STOP and ask. Do not guess.
4. Work through `tasks.md` in order, one task at a time. When a task is done and its tests pass,
   change `- [ ]` to `- [x]` and stop for review unless told to continue.
5. If you discover the spec or plan needs to change, propose the edit to the Markdown file first.
   Do not silently diverge in code.

## Technical rules
- Stack: **vanilla PHP 8.1+ (no framework)**, **MySQL/MariaDB via XAMPP**, plain HTML/CSS/JavaScript.
- Composer is used **only** for PSR-4 autoloading and PHPUnit (dev). Do not add other packages
  without asking.
- Follow the architecture and design patterns in `specs/001-pediacare-mvp/plan.md` §2–§3.
  Respect the layer rules:
  - Controllers never run SQL and never contain business rules.
  - Only Repositories execute SQL. Core `Database` creates and configures PDO;
    Services use the shared connection only to control transactions.
    `AuditLogger` and `RecordNumberGenerator` delegate SQL to Repositories.
  - Domain classes (`src/Domain`) are pure PHP: no database, no `$_SESSION`, no `$_POST`, no `echo`.
  - Views only display data; no queries or business logic in views.
- Every file starts with `declare(strict_types=1);`. Use type declarations on all parameters and return types.
- Every SQL statement uses PDO **prepared statements**. Never concatenate user input into SQL.
- Every value printed in a view goes through the `e()` escaping helper.
- Every POST form includes a CSRF token, and every POST handler verifies it.
- Database changes go through numbered SQL files in `database/migrations/` run by `bin/migrate.php`.
  Never change the schema by hand in phpMyAdmin.
- Every feature includes PHPUnit tests that map to acceptance criteria (AC-xx) in `spec.md`.
  Reference the AC id in the test method name, e.g. `test_AC07_flags_overdue_vaccines()`.
- Run `composer test` before marking a task done.
- Never commit secrets. `config/config.local.php` is gitignored; keep `config/config.example.php` updated.

## Privacy and security rules (non-negotiable)
This app stores health data of minors, which is sensitive personal information under the
Philippine Data Privacy Act of 2012 (RA 10173).
- Every route that touches child data must pass through the Auth and Role middleware.
- Every create/update/archive/restore of child health data must write an audit log entry
  inside the same database transaction.
- Never log personal data (names, birthdates, addresses) to error logs.
- Never use real child data in seeds, fixtures, or tests. Use obviously fake data.
- Prefer soft delete (archiving) over hard delete for health records.

## Medical logic rules
- Growth classifications must use WHO Child Growth Standards / WHO 2007 references via the
  LMS method, as described in `plan.md`. Do not invent formulas or thresholds.
- The vaccine schedule is data in the database, not hard-coded logic.
- Any medical threshold or schedule in code must cite its source in a comment.

## Style
- PSR-12 coding style. Class names in PascalCase, database columns in snake_case.
- Small, focused commits, e.g. `feat(growth): compute BMI-for-age z-score`.
- UI text in English. Keep labels plain and simple for health workers.
