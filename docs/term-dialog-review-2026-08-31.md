# Academic-term creation dialog

## Workflow

- User: university administrator. Lecturers cannot create academic terms.
- Task: add a year/semester and valid date range, then plan its timetable.
- Primary action is now visible in the schedule header and the empty-term state.
- Native modal keeps the timetable in context. Close and Cancel retain the draft while staying on the page; Esc closes and restores focus. Tab/Shift+Tab stay inside the dialog.
- Invalid input shows Thai field errors. A server rejection reopens the dialog with the draft and timetable filters preserved; errors no longer appear in the unrelated schedule form.
- Submit uses the shared busy label and duplicate-submit guard. Successful POST/redirect/GET selects the new term and shows a success message outside the closed dialog.
- Direct links provide a server-rendered open-form fallback when JavaScript is unavailable. Date validation also runs on the server, including impossible calendar dates and duplicate year/semester detection.
- No-term state hides unusable schedule/import actions. No production records are created during UI verification.

## Verification

- Browser inspection at 1440, 768 and 390 pixels: hierarchy, alignment, visible focus, 44px controls and no page/dialog horizontal overflow.
- Browser: open, empty validation, reversed dates, duplicate term, retained draft, close/reopen, successful save, Esc and bidirectional focus wrap.
- Regression tests: 42 service/UX checks, 31 gateway checks, 50 serving/security checks and 16 new HTTP term-dialog checks in disposable Docker containers.
- HTTP tests cover the initial empty state, no-JavaScript response, escaped retained input, filter preservation and repeated POST without duplicate records.
- Real-device virtual keyboards, screen-reader announcements and interrupted-network submission were not tested. Native browser navigation handles network failures; this change does not introduce background saves.
