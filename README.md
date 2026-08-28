# LUMS

Laboratory Usage Monitoring System — ระบบตรวจสอบและบันทึกการเข้าใช้งานห้องเรียนปฏิบัติการโดยเชื่อมต่อกับแบบฟอร์มดิจิทัล

## Project documents

- `AGENTS.md` — persistent implementation and UX/UI rules for Codex
- `docs/product-brief.md` — users, MVP scope, success indicators, and navigation
- `docs/design-system.md` — visual tokens, components, responsive behavior, and review checklist

## Run locally

Requirements: XAMPP with PHP 8.2 or newer.

```powershell
cd C:\xampp\htdocs\lab-usage-monitor
.\start-local.ps1
```

Then open `http://127.0.0.1:8085`.

Demo back-office account (the main site accepts `admin` and `lecturer` roles only):

- Email: `admin@lums.local`
- Password: `admin123`

The local environment uses SQLite at `storage/lums.sqlite`. Session files are kept inside `storage/sessions` so the project does not depend on XAMPP's global session folder.

Students do not sign in to the back office. They open the class-specific page generated from **คลาสเรียนและ QR** and submit their student code and name there.

## Current status

The working vertical slice includes admin/lecturer authentication, academic terms, a graphic weekly laboratory timetable, clickable time-slot selection, room and lecturer conflict detection, atomic full-semester CSV import, class creation from a scheduled occurrence, a unique QR code per class, a public mobile student check-in page, duplicate-attendance protection, attendance history, room status, and report summaries. See `docs/schedule-import.md` for the import format.

## Run with Docker

Docker Compose runs the application on port `8086` and stores SQLite data in a persistent named volume.

```powershell
cd C:\xampp\htdocs\lab-usage-monitor
docker compose up --build -d
docker compose ps
```

Open `http://localhost:8086`. Stop the containers without deleting data:

```powershell
docker compose down
```

To rebuild after changing application code:

```powershell
docker compose up --build -d
```

Do not add `-v` to `docker compose down` unless you intentionally want to delete the Docker database volume.

`APP_URL` is embedded in each generated QR link. For phone testing on the same network, change it in `compose.yaml` from `http://localhost:8086` to the computer's LAN URL (for example `http://192.168.1.20:8086`) and rebuild. On the real server, set it to the public HTTPS domain.

## Deployment pipeline

The `main` branch is the deployment source of truth. GitHub Actions builds the Docker image, checks every PHP file and the JavaScript bundle, verifies a clean production bootstrap, and publishes successful `main` images to `ghcr.io/0tyght/lab-usage-monitor`.

### Render preview

`render.yaml` defines a Docker web service with a persistent disk mounted at `/var/www/html/storage`. Connect the repository as a Render Blueprint and provide these secret values in the Render dashboard:

- `LUMS_ADMIN_EMAIL`
- `LUMS_ADMIN_PASSWORD` — at least 12 characters

Render receives `APP_URL` from `RENDER_EXTERNAL_URL`. Demo accounts and demo records are not created when `APP_ENV=production`.

### University server or VPS

Copy `.env.production.example` to a secret `.env.production` file on the server, replace every placeholder, then run behind an HTTPS reverse proxy:

```bash
docker compose --env-file .env.production -f compose.production.yaml up --build -d
```

The production Compose service binds to `127.0.0.1:8086`, so Nginx, Caddy, or the university reverse proxy should terminate HTTPS and forward requests to that port. Keep the named volume and back it up; never run `docker compose down -v` on production.

## Recommended next step

Recommended next implementation slice:

1. Add university holidays, cancelled/make-up classes, and examination-week exceptions
2. Add printable class QR sheets and CSV/XLSX attendance export
3. Add user/role and room administration with institutional identity
4. Add report charts, notifications, audit review, and research satisfaction questionnaires
