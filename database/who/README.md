# WHO LMS reference data

Downloaded on **2026-10-10** from the official WHO SPSS macro archives:

- [WHO Child Growth Standards software](https://www.who.int/toolkits/child-growth-standards/software):
  [2006 archive](https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/software/igrowup-spss.zip).
- [WHO Growth Reference application tools](https://www.who.int/tools/growth-reference-data-for-5to19-years/application-tools):
  [2007 archive](https://cdn.who.int/media/docs/default-source/child-growth/growth-reference-5-19-years/download-spss-marco.zip).

Only population reference parameters are included. The archives' sample surveys,
patient records, unrelated indicators, and executable macros are not bundled.

## Columns and units

Each CSV has `reference,indicator,sex,age_or_size,l,m,s`. `M` and `F` mean male
and female; the original WHO sex codes are 1 and 2 respectively. LMS parameters
retain the published precision (L and M: four decimal places; S: five).

**Do not treat every `age_or_size` as months.** Its unit depends on the reference
and indicator. The original coordinate is preserved, without converting days to
months or interpolating the source data.

| CSV | Indicator | Original table | Coordinate unit and inclusive range | Rows, both sexes |
|---|---|---|---|---|
| who2006.csv | WFA (weight-for-age) | wazlms.sav | Days, 0–1856 | 3714 |
| who2006.csv | HFA (length/height-for-age) | hazlms.sav | Days, 0–1856 | 3714 |
| who2006.csv | BFA (BMI-for-age) | bmilms.sav | Days, 0–1856 | 3714 |
| who2006.csv | WFL (weight-for-length) | wfllms.sav | cm, 45–110, step 0.1 | 1302 |
| who2006.csv | WFH (weight-for-height) | wfhlms.sav | cm, 65–120, step 0.1 | 1102 |
| who2007.csv | WFA | wfawho2007.sav | Months, 61–121 | 122 |
| who2007.csv | HFA | hfawho2007.sav | Months, 61–229 | 338 |
| who2007.csv | BFA | bfawho2007.sav | Months, 61–229 | 338 |

Total: **14,344 rows** (13,546 WHO2006 + 798 WHO2007). Age-based tables have
one row per day/month per sex. The 2006 HFA and BFA tables already incorporate
the length-to-height transition in their daily parameters; do not combine two
separate age tables or smooth that transition during import.

The [official reference coverage](https://www.who.int/tools/growth-reference-data-for-5to19-years)
is WHO2006 through 60 months, then WHO2007 for 61–228 months. The WHO2007
macro tables also contain month 229 (HFA/BFA) and month 121 (WFA): retain these
as interpolation endpoints, not as an extension of the clinical age limits.
Reference selection, interpolation, position adjustment, and classification
belong to the later growth-domain tasks. Registration and OPT+ reporting still
cover 0–59 completed months.

## Conversion and integrity

The numeric SPSS system-file records were decoded locally with Python's standard
library (`struct`), including their byte-code compression. Extract only the
five/three reference tables above, in the listed order, preserving their source
row order. Map `SEX` to M/F; map `AGEDAYS2`, `AGEMOS`, `LENGTH2`, or `HEIGHT2`
to `age_or_size`; write coordinates with two decimals and L/M/S with 4/4/5
decimals, UTF-8 without a BOM and LF line endings. This removes binary float
representation noise in the SPSS files; the difference from every original
numeric parameter was verified to be less than 0.00001. No reference curves
were fitted or invented. Python/SPSS are not application dependencies.
As an independent conversion check, all 168 girls' BMI rows for months 61–228
were compared with the [official expanded WHO2007 spreadsheet](https://cdn.who.int/media/docs/default-source/child-growth/growth-reference-5-19-years/bmi-for-age-%285-19-years%29/bmi-girls-z-who-2007-exp.xlsx):
the L/M/S parameters match to within 0.000000000001.

Downloaded archive SHA-256 values:

```text
igrowup-spss.zip       1143fe907b38a95b478866d2d6a8570d685176ae27950d18f20f3fd266d0443f
download-spss-marco.zip 6185cdcb02a4886b3e30a97609910c0b2f724bf65332f29e115334d95496701f
```

`manifest.json` records each generated CSV's SHA-256 and row count. The importer
verifies both files, headers, allowed indicators/sexes, decimal precision,
positive M/S, and unique coordinates before starting a database transaction.
The root `.gitattributes` preserves CSV LF endings on Windows so a checkout
does not change their checksums.
Tests verify coverage, integrity, source-value checkpoints, and database values.
Treat deliberate reference updates as reviewed data changes: verify against
WHO, update source/date documentation, regenerate the manifest, and run tests.

## Import

Run from the project root after configuring the database:

```powershell
php bin/migrate.php
php bin/seed-who.php
```

For the separate test database use `--test` on both commands. Import works
offline using these bundled CSVs. All SQL uses prepared statements in
`WhoLmsRepository`; the service owns one transaction for both files. Any SQL
failure rolls back the entire import. Re-running updates the same reference
coordinates without duplicate rows or changed IDs. No patient tables are
changed, and the first-Admin seed remains a separate command.
