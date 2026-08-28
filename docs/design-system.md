# LUMS design system

## Design principles

1. **Fast at the point of use** — check-in and room lookup should require minimal reading and few actions.
2. **Trustworthy records** — timestamps, identity, room, purpose, and status must be unambiguous.
3. **Quiet operations** — prioritize scanning, comparison, and repeated work over visual decoration.
4. **Thai-first clarity** — write concise, natural Thai and avoid unnecessary technical language.
5. **Accessible by default** — keyboard, touch, contrast, focus, and error recovery are core behavior.

## Foundations

### Color tokens

| Token | Value | Usage |
|---|---:|---|
| `--color-brand-900` | `#0B2545` | Navigation, strong headings |
| `--color-brand-700` | `#164E63` | Primary hover/active |
| `--color-brand-600` | `#0F766E` | Primary actions |
| `--color-brand-100` | `#CCFBF1` | Selected and informational surfaces |
| `--color-bg` | `#F6F8FA` | Application background |
| `--color-surface` | `#FFFFFF` | Main working surface |
| `--color-border` | `#D8DEE6` | Borders and dividers |
| `--color-text` | `#17212B` | Primary text |
| `--color-text-muted` | `#5F6B78` | Secondary text |
| `--color-success` | `#15803D` | Confirmed/available |
| `--color-warning` | `#A16207` | Attention/pending |
| `--color-danger` | `#B42318` | Error/destructive |
| `--color-info` | `#1D4ED8` | Informational status |

Use semantic tokens in code rather than scattering raw color values. Every status needs visible text in addition to color.

### Typography

- Font family: `"Noto Sans Thai", "Segoe UI", sans-serif`
- Page title: 28px / 36px, weight 700
- Section title: 20px / 28px, weight 650–700
- Subsection title: 16px / 24px, weight 600
- Body: 15px / 24px, weight 400
- Label and table header: 13px / 20px, weight 600
- Caption: 12px / 18px, weight 400
- Numeric metrics should use tabular numerals where supported.

### Spacing, shape, and elevation

- Spacing scale: 4, 8, 12, 16, 24, 32, 48px
- Default control height: 40px; compact table control: 36px; touch target: at least 44px
- Radius: 4px for compact controls, 8px for panels and dialogs, fully round only for avatars or status dots
- Borders: 1px neutral border
- Shadows: use only for overlays, menus, and dialogs; avoid shadows on routine content sections
- Content width: fluid application shell; constrain long-form text to approximately 72 characters

## Application structure

### Desktop navigation

- Persistent left navigation: ภาพรวม, เช็กอิน, ห้องปฏิบัติการ, ประวัติการใช้งาน, รายงาน, ผู้ใช้งาน, ตั้งค่า
- Compact top bar: current context, notifications if required, user menu
- Page header: title, short operational context, primary action aligned to the end

### Mobile navigation

- Prioritize ภาพรวม, เช็กอิน, ประวัติ, and เพิ่มเติม
- Keep QR check-in reachable in one tap.
- Replace wide tables with a concise record list and a detail drawer/page.

## Core screen guidance

### Dashboard

- Show today's room status, active sessions, pending issues, and recent usage.
- Use compact metrics with labels and comparisons; avoid large decorative statistics.
- Put actionable exceptions above general charts.
- Charts must include units, time range, legend, accessible colors, and an empty-data explanation.

### QR check-in

- Make camera scanning the primary action and manual code entry the fallback.
- Confirm room name, user, timestamp, and purpose before final submission when identity or room context is uncertain.
- Success state must clearly show that the record was saved and provide the next action.

### Usage records

- Default columns: date/time, room, user, role, purpose, check-in method, duration/status, actions.
- Filters: date range, room, role, status, and check-in method.
- Row actions belong in a compact menu unless one action is overwhelmingly common.

### Reports

- Always show active date range and filters.
- Start with operational questions: busiest rooms, usage over time, peak periods, missing checkout, and data completeness.
- Exported data must match the current filter state.

## Content style

- Use direct Thai labels: `สแกน QR Code`, `บันทึกการเข้าใช้`, `ลองอีกครั้ง`, `ดาวน์โหลดรายงาน`.
- Errors should say what happened and how to recover.
- Avoid vague labels such as `ตกลง`, `ดำเนินการ`, or `ส่ง` when a specific verb is available.
- Dates use Thai locale consistently; store and transmit timestamps in an unambiguous standard format.

## Review checklist

- Can the main task be identified within five seconds?
- Is the primary action visually clear without dominating the page?
- Are related controls grouped and aligned?
- Is the interface still useful with no data, much data, slow data, and failed data?
- Can the workflow be completed with keyboard only?
- Does the mobile layout preserve task priority rather than merely stack desktop content?
- Is every status understandable without relying on color?

