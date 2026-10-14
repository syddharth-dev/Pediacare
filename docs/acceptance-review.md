# Final acceptance coverage review

T43 review of `specs/001-pediacare-mvp/spec.md` section 7, dated 2026-10-11.
All fourteen acceptance IDs have executable test coverage and supporting local
QA evidence. This verifies the implemented workflows; the specification and
deployment gaps below prevent treating the finished task list as go-live approval.

## Evidence and limits

The source assertions were reviewed, not just the AC identifiers in method names.
`composer test` was rerun with a disposable MariaDB 10.4.32 server, PHP 8.2.12,
an isolated `pediacare_test` database and fake data. A temporary JUnit report was
used to confirm the cited methods actually ran without failures or skips.
Final result: **1,112 tests, 8,309 assertions; no failures or skips**.

The [T42 QA record](qa-checklist.md#recorded-local-run--2026-10-11) supplies the
completed Chrome form workflow and desktop/phone screenshot review. It used a
loopback PHP development server; it did not validate a live Apache/HTTPS host.
The [T38 source security review](security-review.md) and
[T40 backup/recovery drill](backup.md) provide additional evidence.

## Acceptance matrix

Paths below link to the test source. Method names identify representative
assertions; they are not an exhaustive inventory of every related test.

| AC | Reviewed executable evidence | Supporting T42 QA |
| --- | --- | --- |
| AC-01 | [RouteSecurityReviewTest](../tests/Unit/RouteSecurityReviewTest.php): `test_AC01_AC02_AC03_all_registered_routes_enforce_the_spec_role_matrix` checks every registered route for logged-out redirects and role gates, including HEAD. | Steps 1 and 12: logged-out child access and post-logout redirect. |
| AC-02 | [ChildRegistrationTest](../tests/Unit/ChildRegistrationTest.php): `test_AC02_bhw_and_viewer_cannot_edit_archive_or_restore_through_direct_requests`; [UserManagementTest](../tests/Unit/UserManagementTest.php): `test_AC02_non_admin_has_no_management_ui_and_all_user_routes_return_403`; [VaccineScheduleManagementTest](../tests/Unit/VaccineScheduleManagementTest.php): `test_AC02_schedule_navigation_and_all_routes_are_admin_only`. UI hiding and direct POST rejection use valid CSRF tokens. | Step 11: BHW controls hidden; management requests and archive POST denied. |
| AC-03 | [ChildRegistrationTest](../tests/Unit/ChildRegistrationTest.php): `test_AC03_viewer_is_denied_every_profile_tab_before_any_child_query`; [ReportExportAccessTest](../tests/Unit/ReportExportAccessTest.php): `test_AC01_AC03_AC13_every_report_format_preserves_permissions`. Aggregate service/repository tests also exclude child and purok data from Viewer results. | Step 11: Viewer aggregate pages/CSV allowed; individual profiles and named lists denied. |
| AC-04 | [ChildRegistrationRepositoryTest](../tests/Integration/ChildRegistrationRepositoryTest.php): `test_AC04_duplicate_including_archived_child_warns_before_save_and_confirmation_can_continue`; [ChildRegistrationTest](../tests/Unit/ChildRegistrationTest.php): `test_AC04_duplicate_requires_issued_confirmation_and_changing_details_warns_again`. | Step 2: same full name and birth date produced a warning before another save. |
| AC-05 | [RecordNumberTest](../tests/Integration/RecordNumberTest.php): `test_AC05_sequence_persists_across_transactions_and_years_are_independent` and `test_AC05_concurrent_allocations_wait_and_return_distinct_numbers`. [RegistryFoundationTest](../tests/Integration/RegistryFoundationTest.php) verifies the database rejects duplicate record numbers. | Steps 2 and 7: distinct `PC-YYYY-NNNNN` numbers for two new children. |
| AC-06 | [GrowthReferenceTest](../tests/Unit/Domain/GrowthReferenceTest.php): `test_AC06_results_match_official_WHO_Anthro_and_AnthroPlus_calculation_output`; [MeasurementRepositoryTest](../tests/Integration/MeasurementRepositoryTest.php): `test_AC06_AC11_all_24_WHO_fixtures_persist_expected_BMI_scores_statuses_and_actor_with_audit`. The 24 independent WHO numerical fixtures check available z-scores within 0.01 and actual saved values. [GrowthClassificationTest](../tests/Unit/Domain/GrowthClassificationTest.php) separately checks explicit category boundaries. | Step 3: computed values, saved history and WHO chart rendered. Numeric reference accuracy comes from the tests, not the screenshot. |
| AC-07 | [DoseStatusCalculatorTest](../tests/Unit/Domain/DoseStatusCalculatorTest.php): `test_AC07_age_and_grace_boundaries_are_inclusive`; [ImmunizationWorkflowTest](../tests/Unit/ImmunizationWorkflowTest.php): `test_AC07_AC08_profile_shows_all_four_statuses_and_configured_fic_completion`. Boundary cases distinguish Due from Overdue without changing status for a blocked minimum interval. | Steps 5 and 7: overdue, recorded and upcoming doses displayed. |
| AC-08 | [FicCheckerTest](../tests/Unit/Domain/FicCheckerTest.php): `test_AC08_only_configured_fic_doses_are_required` and `test_AC08_approved_draft_requires_all_nine_flagged_doses_including_elsewhere_records`. Removing any required dose makes completion false; empty configuration is also false. | Step 5: first dose did not complete FIC; all nine configured doses did. |
| AC-09 | [ImmunizationRepositoryTest](../tests/Integration/ImmunizationRepositoryTest.php): `test_AC09_AC12_defaulters_exclude_archived_given_due_and_upcoming_in_real_database` and `test_AC09_real_keyset_batches_find_every_defaulter_without_duplicates`. The latter verifies all 202 matches across batch/page boundaries and the complete export. | Steps 7–9: overdue child included, newborn and archived child excluded. |
| AC-10 | [NutritionalReportRepositoryTest](../tests/Integration/NutritionalReportRepositoryTest.php): `test_AC10_AC12_summary_matches_manual_cohort_with_period_age_ties_and_archival_boundaries`. Explicit expected sex/purok/category counts check latest-in-period selection, same-date ID ties, 59/60-month limits and missing measurements. | Step 8: one measured female counted once per indicator; unmeasured male excluded. |
| AC-11 | [ChildRegistrationRepositoryTest](../tests/Integration/ChildRegistrationRepositoryTest.php): `test_AC11_registration_commits_demographics_record_number_and_audit_together` and `test_AC11_edit_preserves_identity_and_logs_only_changed_demographics_for_older_children`; [MeasurementRepositoryTest](../tests/Integration/MeasurementRepositoryTest.php): the 24-fixture persistence method above; [ImmunizationRepositoryTest](../tests/Integration/ImmunizationRepositoryTest.php): `test_AC07_AC08_AC11_real_records_store_elsewhere_details_audit_changes_and_complete_fic`; [VisitRepositoryTest](../tests/Integration/VisitRepositoryTest.php): `test_AC11_mysql_stores_visit_all_preventive_services_and_matching_audit_entries`. Tests verify actor, timestamp and changed data, with real transaction rollback on audit failures. **Coverage is for implemented writes; saved health-record editing is absent (G-02).** | Steps 6 and 9–10: writes, edit, archive and restore visible with audit details. |
| AC-12 | [ChildRegistrationRepositoryTest](../tests/Integration/ChildRegistrationRepositoryTest.php): `test_AC12_search_matches_names_record_numbers_and_guardians_and_excludes_archived_by_default` and `test_AC12_archive_hides_child_and_restore_returns_it_with_both_reasons_in_audit`; nutritional, monthly immunization and malnutrition repository tests check archive filters. Defaulters remain non-archived as AC-09 requires. | Step 9: default search/report exclusion, Include archived opt-in and restoration. |
| AC-13 | [NutritionalReportTest](../tests/Unit/NutritionalReportTest.php): `test_AC03_AC13_nutritional_csv_and_print_match_screen_counts_and_scope`; [MonthlyImmunizationReportTest](../tests/Unit/MonthlyImmunizationReportTest.php): `test_AC03_AC13_immunization_csv_contains_matching_dose_and_fic_totals`; [DefaulterListTest](../tests/Unit/DefaulterListTest.php): `test_AC13_defaulter_export_contains_all_matching_records`; [MalnutritionReportTest](../tests/Unit/MalnutritionReportTest.php): `test_AC03_AC13_list_export_requests_all_matching_children`. | Step 8: nutritional and monthly manual totals agree with CSV; both named lists export the same record set. |
| AC-14 | [MeasurementRepositoryTest](../tests/Integration/MeasurementRepositoryTest.php): `test_AC14_warning_writes_nothing_and_checkbox_alone_cannot_bypass_confirmation`; [GrowthClassificationTest](../tests/Unit/Domain/GrowthClassificationTest.php): `test_AC14_only_values_strictly_outside_the_flag_limits_need_confirmation`. An issued token plus explicit confirmation is required before the transaction writes anything. | Step 4: warning first, then one explicitly confirmed history entry. |

AC-01 describes protected page access. The global CSRF guard intentionally
returns 403 for malformed POSTs before authentication, including logged-out
requests; this security behavior has separate route-wide tests. `/login` remains
public. The matrix does not claim that every unknown URL redirects or that a
missing-CSRF POST bypasses the CSRF guard.

## Specification gaps requiring review

These were found by comparing scope with the implemented routes and views. They
are not resolved by the presence of passing AC-tagged tests. No spec, plan or
product behavior was changed during this audit.

| Gap | Evidence and required decision |
| --- | --- |
| G-01: Collection privacy notice absent from the application | Spec section 6 requires a privacy notice for collection. [Registration view](../views/children/register.php) has no notice, and no notice content/route was found in `views`, `public` or `src`. Deployment instructions require operator approval but do not supply the notice. Obtain center-approved wording and contact details and decide how the notice is provided before real data collection. Do not claim privacy compliance from technical controls alone. |
| G-02: Saved health records have no edit workflow | Spec section 3 says Health Staff can create and edit measurements, immunizations and visits. [Permissions](../src/Support/Permissions.php) declares their update permissions, but [routes](../src/routes.php), controllers/services and the corresponding repositories implement creation/history without update handlers. T25/T30/T32 specify add/record forms only. Review and reconcile this spec/task mismatch in the Markdown requirements before implementing edits; edits would also need recalculation/validation and transactional audit tests. AC-11 currently proves supported writes, not these unavailable updates. |
| G-03: Due-only vaccine listing absent | Spec section 4.4 asks for a list of children with due or overdue vaccines. [ImmunizationService::defaulters](../src/Services/ImmunizationService.php) includes only `OVERDUE`; T31 and AC-09 require that overdue-only defaulter behavior. Due is shown in individual profiles, but there is no due-inclusive list/status filter. Review how the broader scope should be met without changing AC-09's overdue-only list definition. |

## Remaining validation and operational gaps

- The 10,000-child search test **did run and pass**:
  [ChildRegistrationRepositoryTest](../tests/Integration/ChildRegistrationRepositoryTest.php),
  `test_AC12_search_returns_in_under_two_seconds_for_ten_thousand_fake_children`.
  It measures one repository/service query against the isolated local server,
  asserting total, first-page size and elapsed time below two seconds. It does
  not measure complete browser/network latency, multiple users or deployment
  hardware. Repeat representative end-to-end timing before go-live.
- Health-worker lookup timing and the three-screen workflow target have not been
  measured by a health worker. The local browser run is functional evidence.
- Actual phone/tablet devices, screen readers, browser zoom, a complete contrast
  review and physical printing remain pending. T42 checked emulated layouts,
  labels, focus, a rendered chart and nutritional print CSS/PDF generation.
- The host choice, actual Apache/HTTPS configuration, certificates, restricted
  runtime database account, NTFS ACLs, private logs and service/firewall settings
  still require the operator's [deployment checklist](deploy.md). Test servers
  used isolated administrative credentials; they did not certify production grants.
- Automated daily scheduling and verified off-server storage/key recovery remain
  host setup. T40 proves encrypted backup and separate-database restoration; it
  does not prove the production scheduler runs. Monitor the documented 32 MiB
  dump limit and complete the host recovery drill in [backup.md](backup.md).
- The RHU vaccine schedule/FIC configuration, nurse/nutritionist category bands,
  RHU report formats and designated privacy contact remain unconfirmed. Draft
  schedule completion and numeric WHO fixture checks are software evidence,
  not clinical approval or official reporting certification.
- The spec still says Draft and leaves its owner unset. Record the owner's
  review and resolve the relevant open questions before release approval.

No acceptance ID lacks a passing representative test. No new tests were needed
merely to duplicate those assertions. PHPStan and percentage code coverage were
not run: they are not installed project tools, and the repository's dependency
rules take precedence over the PHP skill's tooling suggestions.

T43 completes the requested coverage audit and gap inventory. It does not close
G-01–G-03 or the operational checklist. Those require review and follow-up work
before the application is used for real child records.
