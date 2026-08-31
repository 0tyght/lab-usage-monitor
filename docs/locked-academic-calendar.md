# Locked NU academic calendar

Verified on 31 August 2026. Applies to Naresuan University **regular undergraduate** programs, not graduate, special, or annual programs. End dates include final examinations and stop the day before the semester break.

| Academic term | First day | Inclusive last day | Official source |
| --- | --- | --- | --- |
| 2569/1 | 2026-06-22 | 2026-10-25 | [Registrar calendar, group 1000, semester 1](https://reg6.nu.ac.th/registrar/calendar.asp?schedulegroupid=1000&acadyear=2569&semester=1) |
| 2569/2 | 2026-11-16 | 2027-03-21 | [Registrar calendar, group 1000, semester 2](https://reg6.nu.ac.th/registrar/calendar.asp?schedulegroupid=1000&acadyear=2569&semester=2) |
| 2569/3 | 2027-03-29 | 2027-05-30 | [Registrar calendar, group 1000, semester 3](https://reg6.nu.ac.th/registrar/calendar.asp?schedulegroupid=1000&acadyear=2569&semester=3) |
| 2568/1 | 2025-06-23 | 2025-10-26 | [Official PDF, page 3](https://reg6.nu.ac.th/publish/NUREG_calendar2568_U20250408.pdf) |
| 2568/2 | 2025-11-17 | 2026-03-22 | Same PDF |
| 2568/3 | 2026-03-30 | 2026-05-31 | Same PDF |

The registrar's `วันเปิดภาคการศึกษา` row gives the start/end span. These end dates also match `ช่วงวันสอบปลายภาค` and the following `วันปิดภาคการศึกษา` row. The 2569 semester breaks start 26 October 2026, 22 March 2027, and 31 May 2027, respectively.

## Behavior

- Administrators select year and semester only; the code and dates follow automatically.
- Date displays are outputs, not editable inputs. There is no verification/confirmation step.
- `create_academic_term()` derives both dates from `nu_academic_presets()`, ignoring obsolete or forged date/confirmation fields.
- Only catalog years are offered. Unknown years are rejected server-side even if manual dates are supplied. A future year requires verified official dates and a catalog release, not extrapolation.
- One record per year/semester; existing terms are marked unavailable. If all three exist, choose another supported year.
- Per-course scheduling can use a shorter span but cannot extend outside the term; imports remain atomic.
- This change does not rewrite saved terms, class schedules, attendance, QR tokens, or production storage. Legacy dates require a deliberate reviewed migration if any are present.

## Regression coverage

All six catalog entries, numeric summer alias, missing/invalid year and semester, forged dates, duplicate creation, no visible date fields or confirmation, HTTP form flow, and import boundaries are covered. Arbitrary date ranges used by conflict tests are explicitly seeded only into in-memory CLI databases; the production service has no test bypass.
