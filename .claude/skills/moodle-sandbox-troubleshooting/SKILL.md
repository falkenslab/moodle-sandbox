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
  compose file mounts the *whole* checkout at `/var/www/html` and sets
  `APACHE_DOCUMENT_ROOT=/var/www/html/public` rather than mounting `public/` alone,
  because CLI scripts still need the full tree next to `public/`.
- `admin/cli/install.php` writes `config.php` as `640 root:root`, which Apache's `www-data`
  can't read — `chmod 644` it after install or every page 500s.
- Adding quiz questions with the core `quiz_add_quiz_question()` function does **not**
  recompute the quiz's total grade. Without an explicit
  `\mod_quiz\grade_calculator::create($quizobj)->recompute_quiz_sumgrades()` call
  afterward, `sumgrades` stays `0` and Moodle refuses to let anyone start an attempt.
  `seed/seed-course.php` already does this; keep the pattern if adding more quizzes.
- The question-bank generator (`core_question_generator::create_question()`) transitively
  requires PHPUnit (`question/engine/tests/helpers.php`), which isn't in this image by
  default — Composer with dev dependencies has to be installed once inside the container
  first. Composer itself isn't in the image either (`manage.mjs composer` downloads
  `composer.phar` straight into `src/`, i.e. the bind mount, so it survives container
  recreation instead of being reinstalled every time).
- `config.php` existing does **not** mean the database is installed — `docker compose down
  -v` wipes the Postgres volume but leaves `src/` (and its `config.php`) untouched, so a
  fresh volume paired with a stale `config.php` makes `admin/cli/install.php` refuse to run
  ("config.php already exists") while the database has nothing in it. `manage.mjs install`
  checks for this explicitly (queries `information_schema.tables` for `mdl_config`) and
  falls back to `admin/cli/install_database.php` — which reuses the existing `config.php`
  instead of writing a new one — rather than assuming `config.php`'s presence means done.
- On Windows, Docker Desktop's bind-mount I/O for the `./src:/var/www/html` mount (tens of
  thousands of small PHP files) is slow enough that `admin/cli/install.php`/
  `install_database.php` can take **much** longer than the few minutes it'd take natively —
  tens of minutes is normal, not a hang. `manage.mjs` doesn't try to work around this; it
  just runs the command and waits.
- Killing a background `docker compose exec ... php ...` process (e.g. via a task-runner's
  "stop task", or a shell timeout) does not kill the process inside the container — it
  keeps running server-side detached from whatever spawned it (confirmed by watching it in
  `docker compose exec webserver ps aux` well after the local wrapper had already reported
  a timeout/exit code). Check for a still-running `install.php`/`install_database.php`
  before concluding a step failed and retrying it. Separately, on Windows a killed
  `npx @playwright/mcp` → Chromium tree also doesn't die with its parent — find and kill it
  by matching `CommandLine` via `Get-CimInstance Win32_Process` if it lingers after a run.

- On Windows, the same bind-mount slowness hits `composer install`: unzipping one package
  into `src/vendor/` can exceed Composer's default 300 s per-process timeout ("exceeded
  the timeout of 300 seconds"), leaving a half-filled `vendor/`. `manage.mjs composer`
  runs Composer with `COMPOSER_PROCESS_TIMEOUT=0` and only treats it as done once
  `vendor/autoload.php` exists — checking `vendor/` alone skipped the half-done install on
  retry, and seeding then failed on the missing autoloader.
- Port 8080 may already be taken (e.g. another Moodle from an older compose project).
  `MOODLE_SANDBOX_PORT` (environment or `.env`, which `docker compose` also reads) moves
  the host port, and `manage.mjs` derives the site's `wwwroot` from it. Set it *before*
  the first `install`: `wwwroot` is baked into `config.php` at install time.

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
