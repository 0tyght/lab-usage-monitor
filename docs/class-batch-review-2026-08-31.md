# Multi-slot class workflow review — 31 August 2026

## Agreed workflow

- Lecturers/admins create a class via one dialog; students use only its public QR link.
- Choose once or semester at the top. Year/semester are catalog choices, not a separate empty-term creation step.
- Select multiple weekday/date/time/room ranges together. A first/last click selects continuous hours; each selected row can be edited or removed before saving.
- Save all occurrences atomically. Every lesson immediately has a distinct QR, automatically accepting ten minutes before its start and ending at lesson end. The owner may close early or explicitly reopen. Existing classes keep their old admission policy.
- Include every weekly date, including holidays/exams. No attendance is valid monitoring data; scheduled hours are not proof of actual physical use.
- Default class list: today, with week/all, date, room, search, admission status, sorting and 50-row pagination. QR, attendance and printing remain in the class popup.
- Edit/cancel this lesson, this and following, or the whole created series. Bulk time changes apply the selected lesson's time delta to other ranges, preserving their differences and original dates. Changing the room applies the new room to affected lessons. The preview states count and resulting times before confirmation.
- Keep already-started, attended and already-cancelled lessons unchanged. Retain QR identifiers, attendance and audit before/after values; never delete history.
- CSV imports can select any catalog term without creating a placeholder class first. Term, reservations, lessons and audits roll back together if any row fails. Maximum 100 CSV rows.

## Verification

Isolated Docker containers only, with synthetic accounts and no production volume mounts.

- PHP regression suites: UX filters/scope, planning/report totals, one-off conflicts, timetable geometry, admission lifecycle, concurrent attendance capacity and multi-slot/scoped changes.
- HTTP workflows: authentication/CSRF, no empty terms, fixed dates, all-or-nothing CSV import, individual QRs, replay protection, public admission, QR/printing controls, report export parity, legacy-route recovery and catalog import.
- JavaScript syntax and the 31 gateway tests; PHP lint and public/private file isolation.
- Real browser at 1440×1000: create two three-hour ranges in different rooms, save 36 lessons, open QR, shift all 36 times after a count preview/confirmation, cancel only one occurrence. QR remains stable on edit and stops admission on cancellation.
- Real browser at 390×844: modal and selected rows stay inside the viewport; wide graphical timetable scrolls within its container. Desktop remains the main workflow.
- Simulated server outage: selected ranges/course fields remain intact, save disabled, retry shown; starting the test server and retrying revalidates the same 36 lessons.
- Checked keyboard focus, empty/loading/success/conflict states, disabled actions and the confirmation popup. Physical printer hardware and real student camera devices were not exercised.

## Deployment safeguards

Back up the live SQLite database inside its private storage volume before additive migration. Keep the public tunnel unchanged, recreate only the app service, verify counts/integrity and public assets, and leave all synthetic test records out of production.
