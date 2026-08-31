# Unified class creation — 31 August 2026

## User and task

Lecturers and administrators create a laboratory class from a single entry point. The first choice is once-only or the entire semester; it is not a choice between unrelated screens. Students continue using class-specific QR links only.

## Interaction and saved result

- `สร้างคลาสเรียน` opens one dialog on timetable, calendar and class screens. Duplicate top-bar actions on these screens were removed.
- `ครั้งเดียว` uses one date, preserves the selected multi-hour range, and creates a class with its own QR.
- `ทั้งภาคเรียน` requires a configured term, weekday, room, lecturer, course and time range. It creates a recurring room reservation that appears throughout the term in the timetable and calendar.
- Semester bounds are derived server-side from the configured term. Forged shorter posted dates cannot evade a conflict outside the visible week.
- The footer continuously shows room, time, occurrence count and first/last lesson dates. Semester dates include the configured examination period; this is stated explicitly. Each lesson date gets its own QR when prepared from the timetable.
- Switching modes keeps shared fields and the selected calendar date/weekday. Inactive controls are disabled, so their validation cannot block the selected mode.
- No-term and missing-field states prevent an empty semester schedule. Loading and errors disable save. A failed connection offers retry and retains the draft. CSRF and request replay protection apply to both creation modes.

## Verification

- Browser inspection at 1440px and 390px: dialog containment, readable mode selection, scrolling body and visible summary/submit, no page-width overflow.
- Selected Wednesday 09:00–13:00 on the timetable; confirmed exact date, room, weekday and four-hour range in both modes.
- Created a full-term class through the UI, checked its saved term/date range and generated its occurrence-specific QR. Created a four-hour once-only class through the same UI and verified its QR panel.
- Checked full-term conflict feedback, disabled submission, no-term guidance, retained draft when switching, and focus restoration on close.
- Interrupted only an isolated test server: save stayed disabled, retry appeared and the course draft remained intact; restored server and retried.
- HTTP suite: 106 checks, including full-term persisted room/day/time, first/last-month calendar occurrences, missing-room rejection, forged dates, cross-week conflicts and replay protection.
- Existing suites: 41 UX, 68 planning/report, 22 one-off, 41 lifecycle, 10 timetable geometry, 66 HTTP deployment, 31 gateway checks; concurrent attendance accepts exactly the room capacity (2 of 8).
- PHP lint, JavaScript syntax checks and Git whitespace checks. No repository formatter or TypeScript checker is configured.

All mutation tests use synthetic records in isolated Docker containers without mounted production volumes. Production verification uses health, deployed assets and read-only integrity/count checks; no synthetic reservations are created online.
