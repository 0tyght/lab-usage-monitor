# Academic terms, calendar and reports — 2026-08-31

## Users and workflow

Administrators configure academic years and terms. Administrators and lecturers inspect room usage and export reports within their existing access scope. Student QR check-in is unchanged.

- Term creation stays in a modal. Choose a Buddhist Era academic year and term 1, 2 or 3 (summer); the code is generated automatically. The timetable shows three slots for the selected year, including terms whose dates have not been configured. It does not create records with guessed dates.
- Monthly calendar highlights planned/created usage, offers a hover/focus summary, and opens a daily timetable dialog with a room selector. The dialog also provides a readable event list and a filtered report link.
- Reports support date range, room, term, daily time window, source, search, grouping, ordering, and hours/equivalent periods. CSV and print/PDF use the same filtered data. Changed-but-unapplied filters disable export until the report is refreshed.
- Empty results and validation errors give a recovery action. Submission feedback and existing lecturer authorization remain in place.

## Verified Naresuan University dates

Source: [official NU academic calendar 2568, regular undergraduate programs, page 3](https://reg6.nu.ac.th/publish/NUREG_calendar2568_U20250408.pdf), checked 2026-08-31.

| Academic term | Opening day | Inclusive end used by LUMS |
| --- | --- | --- |
| 2568/1 | 23 June 2025 | 26 October 2025 |
| 2568/2 | 17 November 2025 | 22 March 2026 |
| 2568/3 | 30 March 2026 | 31 May 2026 |

Opening dates are from the opening-day row. LUMS uses the last final-exam day/day before the semester break as its inclusive end. Thus the interval includes examinations, not just teaching days. Weekly teaching schedules can end earlier; holidays and exam exceptions are not automatically inferred.

Only 2568 was verified for this program. No dates are extrapolated for 2569 or other years. Other years and manually changed dates require administrator confirmation. Existing configured terms are preserved, not silently replaced. Dates elsewhere in the PDF for graduate/special programs must not be used as regular undergraduate dates.

## Calculation and compatibility

- Database semester `summer` is retained for compatibility and displayed as term 3. Existing term names are normalized on read to year/term; no database migration or destructive rewrite.
- Linked classes replace their weekly planned occurrence. Cancellations suppress the plan; moving a class does not leave a phantom reservation in its old room.
- Overnight classes are split by local Thai day. Duration is clipped to the selected daily clock window. Attendance is a whole-class count assigned once, not measured attendance during that clock window.
- Equivalent periods are minutes divided by the selected 50/60/90-minute length, without rounding up. These are configurable reporting units, not a claim about NU's official teaching-period standard.
- Report durations are scheduled durations, not measured physical occupancy. Default source is created classes; plans can be included explicitly. Exports include active filters and calculation caveats.
- Report limit: 366 days and an occurrence guard of 20,000; CSV/print includes all matching rows, while screen pages contain 50 rows.

## Verification

- PHP lint and JavaScript syntax checks passed.
- 41 UX regressions, 38 planning/calculation regressions, 31 gateway checks, 58 HTTP/security checks, and 27 term/calendar/report HTTP checks passed in disposable containers.
- HTTP tests compare CSV rows with parsed report table rows, including date/room/time/search/ordering and period calculation; also test invalid export filters, formula safety, empty search, and print markup.
- Desktop calendar/day dialog and report layout inspected at a measured 1440px viewport. Room changes, empty daily state, and Escape/focus restoration checked.
- Some mobile checks were performed before the user explicitly stopped mobile work. Mobile/tablet polish is not a delivery requirement for this revision.
- Native print output on actual printers, screen-reader behavior, large real university datasets, and every physical browser/device were not tested. Print/PDF uses the browser print dialog, not a server-generated PDF.
- All test data was isolated from the online persistent volume. Live verification must remain read-only.
