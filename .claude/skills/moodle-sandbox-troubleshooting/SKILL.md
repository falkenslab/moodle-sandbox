---
name: moodle-sandbox-troubleshooting
description: Gotchas and non-obvious fixes for this Docker-based Moodle sandbox. Use whenever setting up, rebuilding, debugging, or extending manage.mjs, docker-compose.yml or the seed (install failures, 500s, quiz grading, seeding, Windows-specific hangs).
---

# Moodle sandbox gotchas

None of this is discoverable from the Moodle docs alone — it cost real iteration to work
out while building `manage.mjs`. Read this before touching the sandbox again.

- `bitnami/moodle` is discontinued (zero tags on Docker Hub); the working setup uses the
  official `moodlehq/moodle-php-apache` image plus a shallow clone of Moodle core.
- Moodle 5.x restructured the repo: the actual web docroot is `<checkout>/public`, while
  `config.php` and the CLI scripts under `admin/cli/` stay at the checkout root. The
  image holds the *whole* checkout at `/var/www/html` and sets
  `APACHE_DOCUMENT_ROOT=/var/www/html/public`, because CLI scripts still need the full
  tree next to `public/`.
- Moodle 4.5 has no `public/`: the docroot is the checkout itself. Don't set
  `APACHE_DOCUMENT_ROOT` in compose: `moodle-php-apache`'s entrypoint
  (`/system-docker-entrypoint.d/10-wwwroot.sh`) picks `/var/www/html/public` when it exists
  and `/var/www/html` otherwise, so one compose file serves both. Plugins in the `Dockerfile`
  go to `public/mod/...` or `mod/...` the same way. In a `RUN` chain, test for it with
  `if [ -d public ]; then ...; fi`, not `[ -d public ] && ...`, which exits 1 on 4.5 and
  stops the `&&` chain.
- `admin/cli/install.php` writes `config.php` as `640 root:root`, which Apache's `www-data`
  can't read. The image ships its own `config.php` (`docker/config.php`, readable, the
  database password and URL from the environment), so the install only runs
  `admin/cli/install_database.php`.
- Adding quiz questions with the core `quiz_add_quiz_question()` function does **not**
  recompute the quiz's total grade. Without an explicit
  `\mod_quiz\grade_calculator::create($quizobj)->recompute_quiz_sumgrades()` call
  afterward, `sumgrades` stays `0` and Moodle refuses to let anyone start an attempt.
  `seed/seed-course.php` already does this; keep the pattern if adding more quizzes.
- The question-bank generator (`core_question_generator::create_question()`) transitively
  requires PHPUnit (`question/engine/tests/helpers.php`), which isn't in this image by
  default: the sandbox's image runs `composer install` with dev dependencies when it's
  built (see `Dockerfile`).
- `config.php` existing does **not** mean the database is installed: it's always in the
  image. `manage.mjs install` checks the database itself (queries
  `information_schema.tables` for `mdl_config`) and runs `admin/cli/install_database.php`
  only when it's empty.
- Don't bind-mount Moodle's code from the host. On Windows, Docker Desktop's bind-mount I/O
  for tens of thousands of small PHP files made `install.php` take tens of minutes, made
  `composer install` exceed its 300 s per-process timeout, and made every page take
  seconds (a login page 9–10 s with a few test runs on it). That's why the code lives in the
  image (`Dockerfile`), with an opcache sized for it (`php/zz-sandbox.ini`: Moodle 5.2 has
  ~58000 PHP files, the default opcache holds 10000 and kept recompiling). A new Moodle is
  a new `MOODLE_VERSION` and `npm run up`.
- The image clones a Moodle release tag (`v5.2.4`), not a branch: a branch build gave each
  machine whatever the branch held that day, so two sandboxes ran different Moodles. A first
  image built from `MOODLE_502_STABLE` left the site "upgrade pending" (cron suspended,
  Moodle's upgrade notice instead of pages) until `admin/cli/upgrade.php` ran; `up` now
  runs it whenever the site is installed. Moodle refuses to go back to an older version
  than the database's (`mdl_config.version`): an older `MOODLE_VERSION` needs `reset`.
- Killing a background `docker compose exec ... php ...` process (e.g. via a task-runner's
  "stop task", or a shell timeout) does not kill the process inside the container — it
  keeps running server-side detached from whatever spawned it (confirmed by watching it in
  `docker compose exec webserver ps aux` well after the local wrapper had already reported
  a timeout/exit code). Check for a still-running `install.php`/`install_database.php`
  before concluding a step failed and retrying it. Separately, on Windows a killed
  `npx @playwright/mcp` → Chromium tree also doesn't die with its parent — find and kill it
  by matching `CommandLine` via `Get-CimInstance Win32_Process` if it lingers after a run.

- Port 8080 may already be taken (e.g. another Moodle from an older compose project).
  `MOODLE_SANDBOX_PORT` (environment or `.env`, which `docker compose` also reads) moves
  the host port, and both `manage.mjs` and `docker-compose.yml` derive the site's
  `wwwroot` from it (`MOODLE_WWWROOT` for the image's `config.php`). Changing it on an
  installed site works for the configuration, but URLs Moodle stored in content keep the
  old port.
- Postgres runs with `fsync=off` and `synchronous_commit=off` (`docker-compose.yml`): writes
  are much faster, but a power cut or a hard Docker stop can corrupt the database. If it
  won't start or Moodle reports database errors after one, `npm run reset -- --yes` and
  `npm run setup` (and `activity` if the teacher workload is needed).
- The `cron` container runs Moodle's cron every minute (scheduled and ad hoc tasks:
  backups, course copies, restores, notifications); `docker compose logs cron` shows it.
  `npm run tasks` still runs the ad hoc ones at once.

- The `mod_assign` generator creates assignments with `submissiondrafts = 1`, and its
  `create_submission()` goes through `save_submission()`, which leaves the submission as a
  **draft** whatever `status` it's given — so a teacher sees nothing to grade. Submit it the
  way the student would: `\core\session\manager::set_user($student)` and
  `$assign->submit_for_grading((object) ['userid' => $student->id], [])` (see
  `submit_drafts()` in `seed/seed-teacher-activity.php`), then restore the admin user.

- There's no cron in the sandbox, and Moodle 5 depends on ad-hoc tasks right after install:
  until `mod_qbank	ask	ransfer_question_categories` (and the `transfer_questions` it
  queues) run, the course question bank refuses to manage questions — "Create a new question"
  just isn't there, which an agent reads as "this Moodle can't create questions". `seed` and
  `activity` now end with `admin/cli/adhoc_task.php --execute` (`npm run tasks` on its own);
  it takes a minute or two on Windows.

If you learn a new one, add it here rather than to `CLAUDE.md` directly — this content is
long and only relevant when someone is actually touching the sandbox, which is exactly what
a skill (loaded on demand) is for instead of the always-loaded project file.
