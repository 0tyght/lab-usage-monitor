# Class admission and multi-hour booking review — 2026-08-31

## Users and primary tasks

Lecturers/admins create a one-off class, select continuous hours, display its QR, and explicitly control admission. Students only use the public QR link. Lesson/reservation times and actual attendance timestamps must not be rewritten when admission is reopened.

Hierarchy: class identity and lesson date/time → current admission policy/status → opening/closing controls → QR/print/download and attendance list. Creation uses one shared dialog from Classes, Calendar and Timetable. It shows loading/failed availability, retry, occupied slots, selected duration, field errors, retained drafts and save feedback.

## Corrections

- Added explicit `scheduled` and `manual` admission. Existing rows retain scheduled behavior; migration preserves statuses, QR tokens, lesson times and attendance. Scheduled admission uses a start-inclusive/end-exclusive interval. Manual admission opens immediately until a teacher closes it, including outside lesson hours.
- Expired automatic admission is labeled explicitly. Opening it in automatic mode fails instead of showing false success. A deliberate manual selection and confirmation allow reopening. Changing the selector alone does not change the database.
- Class list filters, dashboard, QR panel, student page and planning/report labels share the admission interpretation. Closing attendance does not prematurely free a room during its lesson; manually opening after a lesson does not reserve the room again.
- One-off and weekly graphical pickers support first/last-slot continuous ranges, reverse selection and reset. Exact time inputs support fractional hours. One-off availability rejects a selected range spanning a busy interval before save.
- All class creation endpoints check room, lecturer and recurring plans. Legacy form errors reopen the shared dialog with input retained.
- Admission capacity is checked under a database write lock. Student/class retries are idempotent even at capacity. A request ID reused for another class cannot falsely claim success. The returned receipt ID is the attendance ID, not the later audit ID.
- Recurring conflicts include overlapping academic terms, reject impossible dates, and avoid false conflicts when overlapping date ranges share no actual lesson day. Cancelled plans cannot produce new class QR codes.
- CSV imports reject duplicate headers and excess cells with readable messages. Conflict errors report actual CSV lines including blank rows, and roll back the whole import.

## Verification

- PHP syntax checks; JavaScript syntax checks; `git diff --check`.
- 41 UX + 38 planning/report + 22 one-off + 41 lifecycle/migration + 31 gateway + 64 HTTP isolation + 60 authenticated/public/import HTTP checks: 297 assertions.
- Separate concurrency scenario: 8 simultaneous processes, capacity 2, exactly 2 accepted and 6 capacity rejections; repeated successfully. All tests use disposable containers or temporary/in-memory databases, not production volumes.
- Desktop browser: 09:00–13:00 creation; past-class manual open and confirmation; actual student submission and refreshed roster; manual close; busy slots still reserved after close; spanning-conflict submit disabled; reverse 14:00–18:00 selection; 14:30–17:30 manual input; past-date/policy guidance; weekly Friday four-hour selection; reset clears `aria-pressed`; Escape restores focus to the opener. Main screens inspected at 1440px; creation also inspected at the app panel's narrower width. No browser warning/error logs during the checked weekly flow.
- An initial HTTP expectation omitted a Thai word; corrected to the actual closed-state copy and reran successfully. The concurrency expectation was likewise aligned with the capacity message and rerun twice.

## Limits

No physical printer/camera scan or real-student test. No large-scale load test or claim that all possible states are bug-free. Mobile-specific redesign/QA was not performed, per the user's desktop-first request. Weekly full-semester conflicts remain authoritative at server save; one-off conflicts also have live pre-submit feedback.

## Release safety

Back up the online SQLite database and verify integrity before additive migration. Rebuild only the online app, preserving its storage volume and existing tunnel. Verify the public gateway, deployed assets and pre-existing class/attendance data after release. Do not silently switch existing classes to manual admission; the lecturer chooses it in the QR popup.
