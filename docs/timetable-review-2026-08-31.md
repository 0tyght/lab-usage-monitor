# Horizontal timetable UX revision

## User and primary task

Lecturers and administrators need to find a room/time and create or review a recurring class plan quickly. Students continue using their class QR link; no public check-in changes.

## Information hierarchy

1. One page heading with distinct one-off and recurring creation actions.
2. One applied semester, room and search filter row, with the locked term dates underneath.
3. Week navigation and weekday/seven-day choice.
4. Full-width timetable: hours left-to-right, named days/dates top-to-bottom.
5. Selection summary and an explicit action to open the prefilled editor; secondary standalone list is collapsible.

The empty permanent sidebar, duplicate semester-card navigation, duplicate top-bar creation button and below-the-fold creation/import forms are removed. Those operations use dialogs. Existing sidebar, navy/teal palette, Thai labels, QR and report workflows remain intact.

## Behavior and safety

- Exact minute offsets/durations, dynamically extended axes for early/late/overnight events, deterministic separate lanes for overlapping rooms.
- Uses existing event reconciliation so a generated class replaces its planned occurrence, cancelled occurrences stay absent and moved classes do not leave ghost reservations.
- Single-hour, multi-hour and reverse-direction selection; changing day restarts the selection. Filtering requires applying changes before selecting time.
- The editor previews whole-range room and lecturer conflicts through an authenticated, CSRF-protected, read-only endpoint. No preview writes records or returns QR/student data. Lecturer conflict feedback does not disclose another lecturer's course or name.
- Debounced preview requests discard stale responses; loading/error/retry and save-disabled states are explicit. The existing service validates again when saving.
- Successful creation preserves the room/week context (or moves to the first occurrence if outside its dates); failures reopen the editor with the draft. Import validation remains atomic.
- Existing terms, classes, attendance, QR tokens and production volumes are not migrated or rewritten.

## Verification

- PHP and JavaScript syntax checks; existing status, calendar/report, one-off, lifecycle, capacity-concurrency and gateway suites.
- Ten pure layout checks cover true four-hour widths, half-hour starts, lane separation/reuse and midnight slices.
- HTTP workflow checks cover horizontal headers/day rows, modal entry/error recovery, preview authorization/CSRF/read-only behavior, conflicts, parallel room schedules and preserved room/week context.
- Desktop browser review covers filters, overlap visibility, 1/4-hour selection in both directions, live conflict feedback and recovery, actual save/details, seven-day view and import dialog.
