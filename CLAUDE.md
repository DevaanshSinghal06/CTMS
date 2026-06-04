# CTMS — Clinical Trial Management System

Internal PHP web app for a research office to manage clinical studies, users,
and an audit trail. No framework — plain PHP with PDO/MySQL and server-rendered
HTML pages.

## Tech stack
- PHP (procedural, no framework)
- MySQL via PDO (prepared statements, exceptions enabled)
- Server-rendered HTML; shared CSS at `Public/Assets/CSS/style.css`
- Local dev runs on MAMP (DB `ctms_dev`, host `localhost:8889`)

## Directory layout
```
App/
  Config/
    bootstrap.php      # defines ROOT_PATH + BASE_URL, requires db + helpers
    db.php             # PDO connection ($pdo)
  Auth/
    auth.php           # require_login / require_role / require_any_role
  Helpers/
    audit.php          # log_action()
    study_code.php     # generate_study_code()
Public/                # web root — BASE_URL is "/Public"
  index.php
  Auth/                # login.php, logout.php, portal.php
  Dashboards/          # admin_dashboard.php, coordinator_dashboard.php
  Studies/             # studies.php, study_edit.php, study_archive.php,
                       # study_restore.php, archived_studies.php
  Users/               # users.php, user_toggle_active.php
  Audit/               # audit_logs.php
(migrations)/          # numbered SQL files 001_*.sql … 007_*.sql
```

## Conventions to follow
- **Every page** starts with `require_once .../App/Config/bootstrap.php`.
  Pages inside `Public/<Area>/` go up two levels (`../../`); `Public/index.php`
  goes up one (`../`).
- **Access control** comes first, right after bootstrap:
  - `require_role("admin")` or `require_role("coordinator")` for single-role pages
  - `require_any_role(["admin", "coordinator"])` for shared pages
  These redirect and `exit` on failure — never gate access with inline `if`s.
- **Roles** are exactly `admin` and `coordinator` (see `users` table ENUM).
- **All links/redirects** are prefixed with `BASE_URL`, e.g.
  `header("Location: " . BASE_URL . "/Studies/studies.php")`.
- **Database access** uses the global `$pdo` with prepared statements and bound
  params — never string-interpolate user input into SQL.
- **State-changing actions** (archive, restore, toggle-active, create) are
  POST-only and must check `$_SERVER["REQUEST_METHOD"] === "POST"`.
- **Audit logging**: after any create/update/archive/restore/activate action,
  call `log_action($action, $entityType, $entityId, $description)`.
  Audit failures are swallowed by design (must not break the user action).
- **Study codes** are generated server-side via `generate_study_code($pdo)`
  (format `STUDY-YYYY-NNN`). Never accept a study code from user input.
- **Study statuses** (current workflow values): `enrolling`,
  `closed_to_enrollment`, `terminated`, `archived`. Archiving/restoring is done
  through the dedicated archive/restore scripts, not by free-form status edits.
- **Output escaping**: wrap all dynamic output in `htmlspecialchars(...)`.

## Things to be careful about
- `App/Config/db.php` currently holds the DB credentials inline (MAMP defaults).
  Preferred direction: move them into a `.env` (which is in `.claudeignore`) and
  read them at runtime. Do NOT commit real production credentials.
- The seed/reset migration SQL contains bcrypt hashes for **test** users only.
  Don't reuse those in any real environment.
- Don't introduce a framework or new dependencies without asking — the app is
  intentionally dependency-free right now.

## Roadmap context
Planned modules not yet built: subjects/screening, visit scheduling,
coordinator task lists, and reporting. Dashboards already have placeholder cards
for these.
