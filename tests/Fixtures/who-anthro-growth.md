# WHO growth calculation fixtures

`who-anthro-growth.json` contains 24 synthetic observations, generated on
2026-10-10. No patient records or WHO example patient databases were used.
The expected numbers are fixed outputs of the official WHO software's
calculation routines, not outputs copied from Pediacare's implementation.

Sources:

- [WHO Anthro installer](https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/software/who_anthro_setup.zip),
  containing `WHOAnthroII.BLL.PC.dll` version 3.2.2.29426.
- [WHO AnthroPlus installer](https://cdn.who.int/media/docs/default-source/child-growth/growth-reference-5-19-years/who_anthroplus_setup.zip),
  containing `WHOAnthroPlus.BLL.PC.dll` version 1.0.4.21881.
- [AnthroPlus manual](https://www.who.int/docs/default-source/child-growth/growth-reference-5-19-years/who-anthroplus-manual.pdf),
  sections 3.2.1 and 3.2.3, for exact age and measurement conventions.
- [WHO LMS computation guide](https://www.who.int/docs/default-source/child-growth/growth-reference-5-19-years/computation.pdf)
  for the restricted tails.
- The official parameters bundled in `database/who/`; its README and manifest
  document their original WHO source files and integrity checks.

The DLLs were extracted to a temporary directory without installing the apps.
Windows .NET reflection called `Core.AnthroComputation.CalculateZandP` with
`ReferenceData` (Anthro) or `StdOrRefDataPoint` (AnthroPlus): `X` is the reference
coordinate, `Y` the observed measurement, and `L`, `M`, `S` the official
parameters. The Boolean argument was true for weight-based indicators and false
for height-for-age. Expected z-scores are the returned `IndicatorValue.Z`.
Anthro's `GetAdjustedLengthOrHeight` and each app's `ComputeExactBMI` supplied
adjusted size and BMI. Above 60 completed WHO months, measured height was used
without conversion, as the AnthroPlus manual specifies.

WHO2006 age coordinates are exact days. WHO2007 coordinates are decimal months
(`days / 30.4375`), with each LMS parameter interpolated between the adjacent
monthly rows before invoking the WHO routine. Size parameters are similarly
interpolated between adjacent 0.1 cm rows. Coordinates outside an indicator's
published range produce null; there is no extrapolation. WFA is unavailable
from 121 completed WHO months, and WFL/WFH is unavailable for WHO2007.

This checks the WHO numeric routines with injected published parameters; it is
not a GUI export or a test of the WHO software's database loader. Pediacare's
interpolation, age selection, unavailable indicators, and repository loader
have separate unit and integration assertions. The fixtures include both sexes,
days 730/731, days 1856/1857, the WFA upper boundary, fractional age/size,
out-of-range sizes, and weights beyond both ends of the restricted LMS range.
AC-06 permits a z-score difference of 0.01; BMI and adjusted size are checked
without premature rounding.

SHA-256 of the calculation assemblies:

| Assembly | SHA-256 |
|---|---|
| WHOAnthroII.BLL.PC.dll | `7c33662a20896ea3f5c56f97598bc04c3119177d264aa3fe26663515e6a4b14d` |
| WHOAnthroPlus.BLL.PC.dll | `ae26283e8603f1de7bce5797850d2120c98eb77892e2fc0ca9970d3b7af86452` |

Downloaded ZIP checksums:

```text
who_anthro_setup.zip     e5dcbd4f11eba3fd523d9a30c06f4ca69c25ce8865eb0e0d2a280197f1dc1b60
who_anthroplus_setup.zip ca716cbd3f427c1ab8405ad58135b5d4edf791325d9ae8cdc24cb8acce2f1785
```

The WHO binaries and extraction utility are not application dependencies and
are not included in this repository. Running the tests requires only the
bundled JSON/CSV data, PHP, and the existing PHPUnit dependency.
