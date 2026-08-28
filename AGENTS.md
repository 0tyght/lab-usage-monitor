# LUMS project instructions

## Product context

LUMS (Laboratory Usage Monitoring System) is a Thai-first university system for planning laboratory timetables and recording attendance through class-specific QR codes and digital forms. The back office is for lecturers and system administrators; students use only the public class link opened from a QR code.

Prioritize fast check-in, accurate records, traceability, clear operational status, and useful reports. This is an operational application, not a marketing website.

## UX workflow

- Before implementation, identify the target user, primary task, information hierarchy, and expected empty/loading/error/success states.
- Preserve familiar workflows and existing design conventions when modifying an established screen.
- Build the actual working interface first. Do not add a landing page, hero section, feature advertising, or decorative copy unless explicitly requested.
- Prefer a quiet, information-focused interface suited to repeated daily use.
- Keep common actions visible and reduce the number of steps needed for check-in, room lookup, record review, and report export.
- Timetable work must make room/time conflicts visible before submission. Weekly views should support semester, room, week, weekday/weekend, and search filters.
- Bulk semester imports must be atomic: if one row fails validation or conflicts, do not keep partial rows.
- Use Thai as the primary interface language. Keep established technical terms or identifiers in English when clearer.
- Use realistic Thai sample data during prototyping, but never include real personal data.

## Visual direction

- Follow `docs/design-system.md` as the source of truth.
- Use Noto Sans Thai as the primary typeface, with a system sans-serif fallback.
- Use an 8px spacing system and a maximum default corner radius of 8px.
- Use navy and teal as primary colors, with restrained neutral surfaces.
- Avoid gradients, glassmorphism, excessive shadows, oversized headings, pill-shaped containers, card-heavy layouts, emoji icons, and decorative illustrations in operational screens.
- Use Lucide icons when an icon library is available. Pair unfamiliar icons with tooltips.
- Prefer tables, filters, tabs, segmented controls, and compact summary blocks over decorative cards.
- Do not place every section inside a bordered container. Use spacing, typography, and dividers to establish hierarchy.

## Components and states

- Forms require persistent labels, clear helper text when needed, validation messages, disabled states, and submission feedback.
- Data tables require search, relevant filters, sorting, pagination or an explicit result count, loading state, empty state, and error state.
- Status must never rely on color alone; include a label and, where useful, an icon.
- Destructive actions require a clear confirmation step and must explain their impact.
- QR check-in must provide camera permission guidance, manual fallback, duplicate-submission protection, success confirmation, and retry behavior.
- Reports must state the selected date range and active filters, and exports must use the same filtered data shown on screen.

## Responsive and accessibility

- Support desktop at 1440px, tablet at 768px, and mobile at 390px.
- Keep touch targets at least 44x44px.
- Provide visible keyboard focus, logical tab order, semantic markup, associated form labels, and sufficient color contrast.
- On small screens, preserve primary actions and key status; convert wide tables to prioritized rows or detail views rather than shrinking text.
- Respect reduced-motion preferences and avoid animation that delays work.

## Definition of done for UI work

- Run the relevant formatter, type check, and tests available in the repository.
- Open the implemented screen and inspect it at 1440px and 390px.
- Check hierarchy, alignment, spacing, typography, contrast, overflow, keyboard focus, and all main states.
- Fix visible issues found during inspection. Do not treat the first rendered version as complete.
- Summarize what was verified and call out any screen or state that could not be tested.

## Git and deployment workflow

- After a requested change passes the relevant checks, commit it to Git, push it to the configured remote, and verify the deployed online URL on every implementation turn.
- Never commit `.env`, SQLite databases, session files, credentials, or deployment secrets.
- Keep local Docker verification separate from production data and persistent volumes.
- If the Git remote, commit identity, hosting provider, or deployment credentials are not configured, stop before publishing and report the exact missing configuration.
