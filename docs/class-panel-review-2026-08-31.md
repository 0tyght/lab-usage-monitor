# Calendar and in-context class QR review — 2026-08-31

## Task and hierarchy

- Audience: administrators and lecturers; student check-in stays on the public class URL.
- Calendar: month overview without filters. Previous/current/next month remain. Room selection belongs only in the daily timetable popup.
- Class hub: open `QR / รายชื่อ` from a class row. The popup puts class identity and status first, then QR actions and attendance side by side.
- Calendar classes open the same popup without leaving the day. Planned occurrences can explicitly prepare their own QR; this does not happen merely by viewing a day.
- Existing QR tokens, role/ownership checks and legacy class-detail bookmarks are preserved. Legacy bookmarks redirect to the class hub popup.

## Export and print

- Monthly CSV covers the displayed month; daily CSV/report links follow the selected date and room.
- QR PNG includes course, room, date/time and availability warning where applicable, with a white quiet zone. It excludes attendance.
- Dedicated QR print/PDF styling hides attendance, navigation, controls and flash messages. Ctrl+P selects the same print mode when the class popup is open.
- Monthly and daily timetable print modes hide controls; the daily print includes the selected room label.
- Content-hashed CSS/JS URLs avoid mixed versions after deployment.

## States and verification

All runtime fixtures were confined to disposable Docker containers without production storage mounts.

- PHP lint across the image; JavaScript syntax checks; `git diff --check`.
- 41 UX regression, 38 planning/report, 22 one-off, 31 gateway tests passed.
- 64 HTTP deployment/isolation checks and 45 authenticated workflow checks passed, including anonymous/other-lecturer access denial, legacy redirect, filter-free calendar and versioned assets.
- Browser skill inspection at an actual 1440px viewport: calendar, daily room/empty state, class hub, QR popup with empty and populated attendance, PNG download action and success message, refresh, close confirmation (cancel and confirm), invalid class state, nested popup Escape/focus restoration, Shift+Tab wrapping and scroll lock release.
- No page-level horizontal overflow on the inspected desktop calendar. The class-row QR action is visible without horizontal scrolling.
- Download feedback was moved next to the export buttons after visual inspection. Class action feedback is visible inside the popup after a POST.
- Print buttons were clicked without application errors. The in-app test browser did not expose a native print preview or physical printer; final printed/PDF layout and physical QR scanning remain manual checks. PNG generation triggered successfully, but the saved file was not reopened from the browser download location.
- Loading/timeout/retry handling is implemented; forced network failure was not exercised in the browser. Unauthorized and missing-class responses were tested over HTTP.
- No mobile changes/testing in this turn, following the user's desktop-first instruction.

## Release

Commit and push to the configured GitHub remote, rebuild only the online app service, retain the current tunnel and production storage, then inspect the permanent online gateway. Never create test attendance or classes in production for this release check.
