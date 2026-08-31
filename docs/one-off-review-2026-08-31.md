# One-off teaching periods — 2026-08-31

## Workflow

Target users: administrators booking for a lecturer, and lecturers booking for themselves. The primary task is to reserve one date/time without setting up an academic term or weekly recurrence.

- `เพิ่มคาบครั้งเดียว` is visible on the timetable and calendar pages, including when no term exists.
- A modal collects date, available room, lecturer, course/activity, optional section/notes, and start/end times. Administrators choose an active lecturer; lecturer accounts cannot assign another identity.
- The time grid offers 08:00–20:00 one-hour shortcuts. Manual times allow other same-day intervals, up to 12 hours. Occupied cells say `ไม่ว่าง`; conflicts are checked before submission and rechecked on the server.
- Availability includes room and lecturer commitments from actual classes and recurring plans. It exposes only anonymous busy intervals, not other lecturers' course names, identities or QR tokens. Cancelled occurrences release their specific date.
- Loading disables save; failure offers retry; validation retains the submitted draft. Keyboard Escape closes the modal and restores focus. A session-scoped request token makes repeated POSTs return the same saved class.
- Saving creates exactly one existing `class_sessions` record with its own QR and no `schedule_id`. No term, recurring schedule, schema migration or production data rewrite is required.
- The class appears in the calendar, a separate one-off weekly summary, existing class management, and filtered reports. Future QR codes retain the existing draft/open workflow.
- Creating/importing a recurring schedule checks standalone bookings before insertion, including lecturer clashes in a different room.

## Verification

- 22 one-off unit/regression checks, 38 planning checks, 41 existing UX checks.
- 59 serving/security HTTP checks and 36 term/planning/one-off HTTP checks, including duplicate POST, conflict draft retention, anonymous availability rejection, calendar and weekly display.
- PHP lint, JavaScript syntax and existing gateway checks.
- Actual desktop browser flow at 1440px: inspect modal layout, busy recurring slots, manually choose a free interval, save synthetic class, inspect QR success, verify calendar/day timeline, prefilled day action, and Escape/focus return. No browser console warnings/errors observed.
- No production records were inserted for testing. Docker tests use disposable container filesystems with no production mounts.
- Mobile work remains deferred per the user's instruction. Physical screen readers and concurrent production load were not tested.
