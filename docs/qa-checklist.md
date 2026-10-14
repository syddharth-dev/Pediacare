# Release QA checklist

Run the core workflow below on a separate staging installation before each
release. Use only obviously fake records and `example.invalid` email addresses.
Record the date, release, browser, operator and result. Leave unchecked items
pending; a passing local run does not approve a live health-data deployment.

## Prepare the run

- [ ] Use a separate application copy and database; confirm its host, port and database name before running commands. Never copy live child records or credentials into staging.
- [ ] Install locked dependencies, run numbered migrations, import WHO data and seed the draft vaccine schedule using the commands in [README.md](../README.md).
- [ ] Create a fake Admin with a unique test password. Through Admin pages, add a fake purok and fake Staff, BHW and Viewer accounts. Remove the seed password after setup.
- [ ] Run `composer test` against a separate `pediacare_test` database. Record tests, assertions, failures and skips; skipped integration tests are not a passing database check.
- [ ] Start a new browser session. Use desktop and phone widths, and keep a simple written tally of the fake records, measurements and doses created during this run.

Do not use the 200-child demo seed for the small manual tally below. It is useful
for a separate populated-data exercise, but it changes expected totals. If demo
data is used, calculate expectations from that dataset explicitly.

## Core workflow: login to reports

1. **Access and login (AC-01).** While logged out, request `/children` and a child
   profile URL. Both must redirect to `/login` without displaying records. Sign
   in as Admin; the account page must say “You are signed in.” Open Children.

2. **Register and find a child (AC-04, AC-05).** Register one fake female child
   aged one year, completing required family and contact fields. Use
   `FAKE-NOT-A-PHONE` instead of a real number. Confirm a `PC-YYYY-NNNNN` record
   number and a readable profile. Search by name and open the result. Submit the
   same full name and birth date again: a duplicate warning must appear before
   a second record is created. Cancel this submission. Edit the original's notes
   and confirm the change appears. Text resembling HTML must display as text.

3. **Measure growth (AC-06).** From the search result, open the profile and Add
   measurement. Save a valid date, weight, measured length/height and position.
   Confirm the history has BMI, applicable z-scores, statuses, date and recorder.
   The chart must render a saved point and WHO curves. Check the numerical
   reference cases through `composer test` and the documented
   [WHO fixtures](../tests/Fixtures/who-anthro-growth.md); visual inspection alone
   does not establish the required ±0.01 accuracy.

4. **Confirm an implausible measurement (AC-14).** On the same fake child, submit
   a deliberately implausible but syntactically valid measurement. Confirm the
   warning appears and the record is not saved yet. Explicitly check the
   confirmation and save again; confirm exactly one additional history entry.
   Keep this fake observation in the tally so the latest-measurement report can
   be checked. The confirmation is an entry check, not clinical approval.

5. **Record a vaccine (AC-07, AC-08).** Open Immunization. At least one overdue
   unrecorded dose should show Overdue for the one-year-old under the draft
   schedule. Record BCG as given elsewhere, including the fake facility and lot.
   Confirm Given, date, facility and elsewhere flag. FIC must remain Not complete.
   Record all other configured FIC doses using dates that meet recommended ages
   and previous-dose intervals; FIC must become Complete only after the final
   required dose. Use the configured schedule, not an assumed clinical schedule.

6. **Record a visit.** Save a fake reason, findings and actions, a referral to a
   fake RHU, and one supplement with both dose text and date. Confirm the Visits
   tab's combined timeline contains the visit, vaccine and measurements in date
   order, with the referral and supplement details.

7. **Distinguish upcoming from overdue (AC-05, AC-09).** Register a second fake
   male child born today. Confirm a different record number. Leave this child
   unmeasured and unvaccinated. Its scheduled later doses must be Upcoming, its
   FIC incomplete, and it must not appear in defaulters. The first child should
   still appear while any non-FIC scheduled doses remain overdue. Compare the
   defaulter list and CSV with the two-child tally.

8. **Count and export reports (AC-10, AC-13).** Set the nutritional period to
   include the first child's measurements. Count that child once using its
   latest measurement in the period, separately per indicator; the unmeasured
   newborn contributes zero. Compare barangay/purok totals and sex splits with
   the written tally and CSV. Check the monthly immunization summary against
   doses given in that month, including elsewhere doses, and the date the final
   configured FIC dose was recorded. Compare its CSV. Compare malnutrition and
   defaulter list membership with their CSVs too. Open Print view; verify filters
   and navigation are omitted while report headings and totals remain readable.

9. **Archive and restore (AC-12).** Archive the first child with a fake reason
   and explicit confirmation. Its profile and history must remain available to
   authorized users. Default search, defaulters and nutritional totals must
   exclude it. Selecting Include archived must bring it back in search and
   applicable reports. Restore with a reason and confirm it returns to default
   results with the same record number and history.

10. **Inspect audit history (AC-11).** As Admin, filter by entity and open changed
    fields. Confirm child creation, edit, archive and restore, measurements,
    vaccines and the visit/supplement have actor, timestamp and appropriate
    changes. Do not copy health details into release notes or diagnostic logs.

11. **Check roles (AC-02, AC-03).** Sign out and repeat access as BHW: child
    records and measurement forms are accessible; archive, user management and
    schedule management controls are hidden. Direct management requests and an
    archive POST with a valid CSRF token must return 403. Viewer must open
    nutritional/immunization aggregates and their CSVs without child identifiers
    or purok breakdowns; child profiles, malnutrition and defaulter lists must
    return 403. Staff must be able to open the measurement form.

12. **Check forms and logout.** Submit a POST without a CSRF token and confirm
    403. Sign out through the account page; a new child-profile request must
    redirect to login. Check response security headers. Verify keyboard Tab
    reaches email, password and submit in order with a visible focus indicator;
    registration and measurement controls must have associated labels. Inspect
    desktop and phone layouts, chart rendering, long names and scrollable tables
    without page-wide horizontal overflow. Watch for application JavaScript
    errors during the workflow.

## Recorded local run — 2026-10-11

Operator: Codex, using an isolated application copy, disposable MariaDB 10.4.32
and PHP 8.2.12. Chrome 156.0.8078.12 headless ran the real application forms via its DevTools
protocol; DOM results and desktop/phone screenshots were inspected. No browser
automation package was added. The PHP development server listened only on
loopback over HTTP with a private session directory; this did not exercise
Apache rewrites or production HTTPS. Viewports were 1280 × 900 and 390 × 844.

The first fake child was female, born 2025-10-11. A 2026-10-11 lying measurement
of 9.00 kg and 74.0 cm displayed BMI 16.44, WFA/WFH/BMI z-scores 0.05 and HFA
0.00, all Normal. A subsequent 1.00 kg measurement required explicit confirmation
and became the latest observation. The second fake child was an unmeasured male
newborn. These are synthetic QA inputs, not care recommendations.

| Check | Result and evidence |
| --- | --- |
| Access, login/logout and accounts | Passed: Admin created purok and three role accounts; logged-out child requests redirected. |
| Registry, duplicates and edits | Passed: two distinct sequential record numbers; duplicate warning before save; name search; edited HTML-like notes escaped. |
| Growth and confirmation | Passed: saved results and rendered chart; implausible warning received focus; confirmed observation saved. WHO reference accuracy covered by the passing PHPUnit fixtures. |
| Immunization and FIC | Passed: overdue/given/upcoming states; elsewhere facility retained; completion after all nine configured FIC doses. |
| Visit and timeline | Passed: referral and supplement saved; visit, vaccine and measurement events displayed in Visits. |
| Nutritional tally and CSV | Passed: one female, zero males for each indicator, in barangay and fake purok totals; unmeasured newborn excluded. Eight exported Total rows matched those groups and indicators. |
| Monthly immunization tally and CSV | Passed: October contained two doses (BCG and final measles-containing dose), both female; one female FIC completion. Earlier series doses were outside October. |
| Lists and exports | Passed: only the first child appeared in malnutrition/defaulter results and their CSVs; newborn excluded. |
| Archive/restore and audit | Passed: default lists/totals excluded archived child; opt-in totals restored it; restore preserved history; CREATE/UPDATE/ARCHIVE/RESTORE visible in the audit viewer. |
| Role and request checks | Passed: BHW management/archive denied with valid CSRF; Viewer aggregates/CSV excluded child/purok details and individual routes were denied; Staff measurement access; missing CSRF denied. |
| Browser usability | Passed for inspected pages: desktop/phone login, registration, growth and measurement layouts; chart and contained table scrolling; associated form labels; keyboard Tab and visible focus. No uncaught application JavaScript exceptions in the completed checks. |
| Print output | Passed: nutritional print CSS hid navigation/filters; screenshot inspected and PDF generated. Physical printer pagination was not checked. |
| Regression suite | `composer test`: 1,112 tests, 8,309 assertions, no failures or skips. |

Temporary browsers, servers, fake databases, profiles, scripts and screenshots
were removed after review. Live configuration and production data were untouched.

## Checks still required before go-live

- [ ] Repeat the workflow on the actual hardened Apache/HTTPS staging host, including certificate trust, Secure cookies, 30-minute idle expiry and reboot/startup behavior; use [deploy.md](deploy.md).
- [ ] Verify daily encrypted scheduling, off-server copies and a recovery drill using [backup.md](backup.md).
- [ ] Confirm the center's privacy notice is provided at collection, its clinical schedule and growth bands are approved, and the required RHU report formats are accepted. These were not approved by this run.
- [ ] Exercise actual phone/tablet devices, screen-reader use, browser zoom, contrast and physical printing with the center's equipment. Emulated widths and label/focus checks do not establish full accessibility compliance.
- [ ] Measure search performance with 10,000 synthetic children on representative hardware and record the under-two-second result. The two-child workflow does not establish that target.
- [ ] Have a health worker time record lookup (under 30 seconds) and verify find-child to add-measurement takes no more than three screens.
- [ ] Review the [final acceptance audit](acceptance-review.md) and resolve its recorded specification gaps before approving release.

Accessibility review used the applicable form, focus, semantics and layout checks
from the [Web Interface Guidelines](https://raw.githubusercontent.com/vercel-labs/web-interface-guidelines/main/command.md).
The project specs govern behavior and stack choices; this review did not add
frameworks, dependencies or new product features.

## UI refresh verification (2026-10-11)

The UI refresh adds navy navigation, teal actions, a two-column login with a
local SVG illustration, permission-filtered workspace shortcuts, grouped forms,
clearer tables, status badges and a visual health timeline. Account, registry,
growth and immunization behavior still uses the existing services and permissions.

- PHP syntax checks passed for all changed PHP files.
- All 1,036 unit tests passed (4,843 assertions), including view escaping,
  authentication and permission checks. After the signed-in page title change,
  the affected authentication/view tests were rerun separately.
- Chrome screenshots inspected the live anonymous login at desktop and
  emulated 390px widths. Registry and workspace screenshots used temporary
  rendered templates with obviously fake data, without database writes.
- Browser measurements found no page-wide horizontal overflow on the login,
  registry or workspace previews; tables and mobile navigation scroll locally.
- Keyboard Tab navigation on the live login reached the skip link, brand, email,
  password and submit button with visible 3px focus outlines. Primary button,
  body and secondary text palette pairs exceed 4.5:1 contrast; input borders
  exceed 3:1 against their white surface. This is not a complete contrast audit.
- `composer test` attempted all 1,112 tests. It reported 75 database integration
  errors because the local application account lacks access to `pediacare_test`.
  This run does not establish passing database integration coverage for the refresh.
  Application database credentials and permissions were left unchanged.

The desktop/mobile visual review is not a new full workflow sign-off. Repeat the
real browser workflow, physical printing and accessibility checks before release.

## Reference style refresh verification (2026-10-11)

The supplied dashboard reference replaces the earlier navy/teal styling with a
white sidebar, outline navigation icons, blue actions, cool gray canvas, rounded
workspace cards and compact report filters. Nutrition and immunization metric
cards display the existing report totals. No new dependencies were added.

- Changed PHP templates and report tests passed syntax checks.
- All 1,036 unit tests passed (4,843 assertions). Existing report acceptance
  assertions were adapted to the metric card markup while retaining count checks.
- Chrome reviewed the anonymous login and fake-data workspace/report previews at
  1,440px and 390px widths. These checks found no page-wide horizontal overflow.
  The final compact report filter layout was checked again at both widths.
- Keyboard focus remained visible with a 3px outline. Print media hides the
  sidebar and retains all four immunization metric cards.
- The final `composer test` run attempted 1,112 tests (4,891 assertions), with
  75 database integration errors because the local account cannot access
  `pediacare_test`. It reported no assertion failures. Database integration
  coverage remains unverified; application credentials and grants were unchanged.

Authenticated visual previews used obviously fake data rendered outside the
repository. Full authenticated workflows, physical printing and a complete
accessibility audit still need review before release.

## Form and vaccine layout verification (2026-10-11)

- Replaced nested vaccine dose cards with compact table rows; each vaccine has
  a separate header and manage action. All age, interval, grace, FIC and order
  values remain visible. Three-dose fake-data cards measured 301px high on a
  1,366px desktop viewport.
- Vaccine and visit forms use wider panels, grouped sections, paired fields and
  separate cancel/save actions. Preventive services use a two-column section
  grid. Registration, measurement and dose forms share consistent section styling.
- Child immunization records show the date given with expandable vaccination
  details. Account cards, purok rename forms and workspace shortcuts are more compact.
- Changed PHP files passed syntax checks. All 1,036 unit tests passed with
  4,843 assertions. The visit form test still verifies all four preventive-service
  fieldsets, labeled inputs, escaped data and the CSRF token after regrouping.
- Chrome reviewed fake-data schedule, vaccine, visit and registration templates
  at 1,440px, 1,366px and 390px widths. No page-wide horizontal overflow was found.
  All rendered form controls had matching labels; keyboard focus had a 3px outline.
- `composer test` attempted 1,112 tests, with 75 integration errors caused by
  missing access to `pediacare_test` and no assertion failures. Real authenticated
  submission workflows remain unverified in this environment.

These previews used temporary files and obviously fake data, with no database
writes. Full workflow and physical printing acceptance checks remain pending.

## Navigation and record usability refinement (2026-10-13)

- Kept the existing palette, including primary blue `#245cf5` and canvas
  `#f5f6f8`; browser checks confirmed both computed values.
- Grouped permission-filtered links into care records, reports and administration.
  On phones, navigation uses a native expandable menu. It opens with Enter and
  retains a visible 3px keyboard outline. Without JavaScript the menu stays open,
  so navigation remains available. The workspace footer fits a 900px desktop height.
- Child profiles now show an identity summary and care actions before expandable
  demographics. All existing demographic values remain available in the panel.
- Tightened search filters, linked child names to their records, refined empty
  states and added section links to registration. Every registration section link
  was checked against a rendered fieldset ID.
- Chrome reviewed fake-data profile, registry, workspace and registration pages
  at 1,366px and 390px. No page-wide horizontal overflow was found, including
  expanded mobile navigation and expanded demographics.
- Changed PHP templates and the new layout JavaScript passed syntax checks.
  All 1,036 unit tests passed (4,843 assertions). `composer test` attempted
  1,112 tests with 75 database integration errors and no assertion failures;
  access to the separate test database remains unavailable.

Previews used temporary files and obviously fake records, without database
writes. Authenticated submission workflows and physical printing were not checked.

## Visual finish verification (2026-10-13)

- Refined typography, navigation icons, card shadows, buttons, form section
  headers, input focus, table headers and status badges. The workspace intro
  reuses the local care-record SVG as a decorative background. Existing controls,
  routes and workflows are retained.
- The primary blue remains `#245cf5` and canvas remains `#f5f6f8`; browser
  assertions confirmed these values on all reviewed pages.
- Chrome inspected fake-data workspace, registry, child profile, vaccine form,
  monthly report and login at 1,366px, 768px and 390px. No page-wide horizontal
  overflow was found. Desktop and phone screenshots were visually reviewed.
- Keyboard focus retained a visible 3px outline. Reduced-motion checks confirmed
  zero input transition duration. Print-media checks confirmed the sidebar is
  hidden and screen-only report metric shadows are excluded.
- Layout PHP syntax passed. `composer test` attempted 1,112 tests with 4,891
  assertions, no assertion failures and 75 database integration errors because
  the separate test database remains inaccessible.

Checks used temporary pages and obviously fake data without database writes.
Real authenticated submission workflows and physical printing were not repeated.
