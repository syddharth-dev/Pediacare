# Spec 001 — Pediacare MVP

| Field   | Value                          |
|---------|--------------------------------|
| Status  | Draft — needs review           |
| Owner   | _(your name)_                  |
| Users   | Barangay health center staff   |
| Version | 0.1                            |

> This is the **what** and **why** (the PRD). The **how** lives in `plan.md`.

---

## 1. Problem
The barangay health center tracks children's health using paper logbooks, Target Client Lists
(TCLs), and spreadsheets. This makes it slow to:
- find a child's full history (growth, vaccines, visits) in one place,
- know which children are due or overdue for vaccines,
- identify children who are underweight, stunted, wasted, or overweight,
- prepare monthly and Operation Timbang Plus (OPT+) reports.

Records can also be lost, damaged, or read by people who should not see them.

## 2. Goal
A secure web app where authorized health center staff can record, find, and report on the health
of every child in the barangay, starting with growth monitoring and immunization.

### Success measures
- A child's complete record can be found in under 30 seconds.
- Staff can produce the monthly immunization summary and OPT+ nutritional status summary
  without manual tallying.
- A list of children overdue for vaccines can be generated at any time.

## 3. Users and roles

| Role | Who | Can do |
|------|-----|--------|
| **Admin** | Head nurse / designated officer | Everything, plus manage user accounts, puroks/sitios, and the vaccine schedule |
| **Health Staff** | Nurse, midwife | Create and edit all child records, measurements, immunizations, and visits; view all reports |
| **BHW** | Barangay Health Worker | Register children, record measurements and visits; view records; cannot delete or archive |
| **Viewer** | e.g. Barangay official | View reports and aggregate numbers only; cannot see individual child records |

## 4. Scope (MVP)

### 4.1 Authentication and accounts
- Staff log in with email and password.
- Admin creates, deactivates, and assigns roles to accounts. There is no public sign-up.
- Sessions expire after a period of inactivity.

### 4.2 Child registry
- New registrations cover children aged 0–59 completed months (under five years)
  on the registration date. Future birthdates and children aged five or older are rejected.
- Register a child with: full name, sex, date of birth, place of birth, purok/sitio, address,
  mother's name, father's name (optional), guardian name and relationship, contact number,
  PhilHealth number (optional), birth weight and birth length (optional), and notes.
- Each child gets an auto-generated record number (e.g. `PC-2026-00123`).
- Search by name, record number, or guardian name. Filter by purok, sex, and age group.
  Age groups use completed months on the current date: 0–11, 12–23, 24–35,
  36–47, 48–59, and 60+ months (including children who age beyond five).
- Warn about a possible duplicate when the same name and date of birth already exist.
- Archive (soft delete) a child, e.g. moved away or deceased, with a reason. Archived children
  are hidden from lists and reports by default but kept in the database.

### 4.3 Growth monitoring
- Record a measurement: date, weight (kg), length/height (cm), measured lying or standing,
  MUAC (cm, optional), and who measured.
- The system automatically computes and stores:
  - age in months at measurement,
  - BMI,
  - z-scores: weight-for-age, length/height-for-age, weight-for-length/height, BMI-for-age,
  - nutritional status labels (see 4.3.1).
- Show a child's measurement history as a table and a growth chart against WHO reference curves.
- Flag measurements that are biologically implausible (likely data entry errors) and ask for confirmation.

#### 4.3.1 Nutritional status classification
For children 0–60 completed WHO months, use WHO Child Growth Standards. From 61 completed WHO months,
use WHO 2007 Growth Reference (BMI-for-age, height-for-age), through 19 years.
This reference boundary follows the official WHO tables; new registrations and OPT+ reports
remain limited to 0–59 completed calendar months.
Clinical reference selection and interpolation use exact elapsed days and WHO age in months
(days divided by 30.4375), as in WHO Anthro/AnthroPlus. Registry age groups and report eligibility
continue to use completed calendar months.
Exact cut-offs are listed in `plan.md` and **must be confirmed by the center's nurse/nutritionist**
before go-live.

### 4.4 Immunization
- The vaccine schedule (vaccine name, dose number, recommended age, minimum interval) is stored
  as data and editable by Admin, so it can follow current DOH guidance.
- Record a vaccine given: vaccine, dose number, date given, lot/batch number (optional),
  site (optional), given by, and place given (this center or elsewhere).
- Record vaccines given elsewhere (e.g. hospital birth doses) with a "given elsewhere" flag.
- For each child, show every scheduled dose as one of: **Given**, **Due**, **Overdue**, **Upcoming**.
- Show whether a child is **Fully Immunized (FIC)** per the configured schedule.
- Generate a list of children with due or overdue vaccines, filterable by purok and vaccine.

### 4.5 Health visits
- Record a visit: date, reason/chief complaint, findings, actions or treatment, referral (yes/no and where),
  attended by, and notes.
- Record micronutrient and preventive services as part of a visit: Vitamin A supplementation,
  deworming, iron/micronutrient powder, with date and dose.
- A child's profile shows a combined timeline of measurements, vaccines, and visits.

### 4.6 Reports
- **Nutritional status summary (OPT+ style):** counts of children 0–59 months by status category,
  by sex and by purok, for a chosen period.
- **Immunization summary:** doses given per vaccine for a chosen month, and FIC count.
- **Defaulter list:** children overdue for any vaccine.
- **Malnutrition list:** children whose latest measurement is outside normal range.
- Reports can be exported to CSV and printed.

### 4.7 Audit log
- Every create, update, archive, and restore of child data is logged with: who, what, when,
  and old/new values.
- Admin can view and filter the audit log.

## 5. Non-goals (not in MVP)
- Parent/guardian portal or mobile app.
- SMS or email reminders.
- Offline mode / sync. _(Candidate for spec 002.)_
- Integration with DOH systems (e.g. iClinicSys, eTCL) or PhilHealth.
- Adult patients, prenatal care, family planning, or other programs.
- Billing, inventory, or vaccine stock management.
- Multiple barangays / multi-tenant use.

## 6. Non-functional requirements
- **Privacy:** compliant with the Data Privacy Act of 2012 (RA 10173): role-based access,
  audit logging, HTTPS only, encrypted backups, and a privacy notice for data collection.
- **Usability:** usable on a basic laptop and on a phone or tablet browser. Large, clear forms.
  Common tasks (find child → add measurement) take no more than 3 screens.
- **Performance:** search results in under 2 seconds for up to 10,000 children.
- **Reliability:** automated daily database backup.
- **Accessibility:** readable font sizes, sufficient contrast, keyboard-navigable forms.

## 7. Acceptance criteria

| ID | Criterion |
|----|-----------|
| AC-01 | A logged-out user who visits any app page is redirected to the login page. |
| AC-02 | A BHW cannot archive a child, manage users, or edit the vaccine schedule (UI hidden and API returns 403). |
| AC-03 | A Viewer can open reports but receives 403 when requesting any individual child record. |
| AC-04 | Registering a child with the same full name and date of birth as an existing child shows a duplicate warning before saving. |
| AC-05 | Each new child receives a unique record number in the format `PC-YYYY-NNNNN`. |
| AC-06 | Saving a measurement automatically stores BMI, the applicable z-scores, and status labels, and these match WHO reference values within ±0.01 for the test cases in `plan.md`. |
| AC-07 | A child's immunization view marks a dose **Overdue** when the child is past the recommended age plus the configured grace period and the dose is not recorded. |
| AC-08 | A child is marked **FIC** only when all doses configured as part of FIC are recorded. |
| AC-09 | The defaulter list includes every non-archived child with at least one overdue dose and no others. |
| AC-10 | The nutritional status summary counts for a period match a manual count of the same test dataset. |
| AC-11 | Every create/update/archive of a child, measurement, immunization, or visit produces an audit log entry with user, timestamp, and changed fields. |
| AC-12 | Archived children do not appear in search results, lists, or reports unless "include archived" is selected. |
| AC-13 | Reports export to CSV with the same numbers shown on screen. |
| AC-14 | A measurement with an implausible value (e.g. weight-for-age z-score below −6 or above +5) requires explicit confirmation before saving. |

## 8. Open questions
_Answer these before or during planning. Codex should not guess them._
1. **Resolved:** new registrations cover 0–59 completed months, as confirmed by the owner.
2. Which exact vaccine schedule does the center currently follow (confirm with RHU/DOH)?
3. Which report formats does the RHU/municipal health office require? Can we get sample forms?
4. Where will the app be hosted (cloud vs. a computer at the health center)? How reliable is the internet?
5. Who is the designated Data Protection Officer for the barangay/LGU?
6. Is there existing data (spreadsheets, TCLs) that must be imported? If yes, this becomes a separate spec.
7. **Resolved:** Viewers see barangay-wide totals split by sex, without purok breakdowns.
