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
- Plugins (`docker/plugins.txt`): `upgrade.php` refuses the whole upgrade ("Dependencies
  check failed for qtype_formulas") when one plugin's `$plugin->dependencies` isn't in the
  image; check each `version.php` before adding a plugin (Formulas needs
  `qbehaviour_adaptivemultipart`, CodeRunner its own behaviour, ddmatch `qtype_match`). The
  plugins directory's zips name their top folder anything (`moodle-qtype_gapfill-2.32`), so
  `install-plugins.sh` moves whatever single folder is inside.
- CodeRunner uses Moodle's `curl` class, which refuses private addresses
  (`curlsecurityblockedhosts`, `172.16.0.0/12` included, where Docker puts the `jobe`
  container): it fails with "The sandbox may be down". `docker/config.php` sets
  `$CFG->curlsecurityblockedhosts` without the private ranges (Docker may also pick
  `192.168.0.0/16`); `seed/configure-plugins.php` sets `jobe_host=jobe`.
- CodeRunner looks for its prototypes (python3...) from the question's *course*: in Moodle 5.x,
  the site's system question bank plus the course's banks. Graded from the system context (a
  test script with `context_system`), it says "Broken question (missing or duplicate prototype
  'python3')"; use the quiz's module context.
- CLI scripts that touch the admin tree must run as admin (`set_user(get_admin())`): without a
  user, `admin_get_root()` holds 11 settings instead of ~3000, so pending settings look like
  none. `write_setting()` silently ignores a value its setting doesn't accept (a select given
  `''`): check `get_setting()` afterwards (`seed/configure-plugins.php`).
- A data generator call that throws inside `add_moduleinfo()` leaves its delegated transaction
  open: everything created after it in the same script lands inside it and is rolled back at
  shutdown, while the script prints it as created. Roll back right away on failure
  (`rollback_failed_transaction()` in `seed/seed-plugins.php`). Plugin generators may also need
  a user (calendar events: run as admin) or fields they don't default (Collabora's `format`).
- `mod_chat` and `mod_survey` exist but are disabled (`mdl_modules.visible = 0`): their pages
  say "Invalid course module ID". `\core\plugininfo\mod::enable_plugin()` turns them on.
- `mod_hvp` registers the site with the H5P Hub during install, before and regardless of
  `hub_is_enabled` (`fetchLibrariesMetadata()` calls the hub first and checks the setting
  after). Don't block it with `curlsecurityblockedhosts`: Moodle's `curl` then returns an
  error *text* with no errno, H5P takes it for a reply, finds no uuid and retries forever
  (an install stuck printing `Attempt to read property "uuid" on null` hundreds of thousands
  of times). `docker-compose.yml` points `hub-api.h5p.org` at 127.0.0.1 (`extra_hosts`): the
  connection is refused, curl sets an errno and H5P gives up cleanly. `docker/config.php`
  forces `hub_is_enabled`/`send_usage_statistics` off (its daily task does respect those).
- A plugin release made for an older Moodle (`supported` not covering 5.2) still installs: Moodle
  only refuses `requires` above the site's version or an `incompatible` one. Check it in a real
  browser, with its console open: on 5.2, `format_onetopic` (5.1) and `format_tiles` (5.1) throw
  "No define call for core/modal_factory" (removed in 5.2), which only breaks their popups.
- Checking the site in a browser: a fresh student gets a user tour ("Find your way around")
  that swallows the first click, so a tile or tab that "doesn't open" may just need a second
  click; both sites share `localhost`'s `MoodleSession` cookie, so logging in to one logs you
  out of the other; and the 5.2 login page re-renders after loading, so fill it in once it's
  settled.
- Data generators outside PHPUnit (`seed/seed-content.php`):
  - the quiz generator's `submit_responses()` calls `get_question_usage()`, which refuses
    outside tests: load the usage with `question_engine::load_questions_usage_by_activity()` and
    finish with `process_submit()` + `process_grade_submission()` (5.x) or `process_finish()`
    (4.5). Responses are the summary strings ("True", "One", "frog"), not arrays;
  - example questions need a `get_<qtype>_question_form_data_<name>` helper: `true`,
    `one_of_four`, `frogtoad`, `pi` exist on both series (`frogonly` doesn't);
  - the rubric generator needs `testing_util` (4.5: require `lib/testing/classes/util.php`;
    5.x: autoloaded as `core\test\testing_util`, that file is gone) and a user, and its levels are
    `description => score`;
  - the feedback generator answers a multichoice with one option only: for several, use
    `mod_feedback_completion` with `multichoice_<itemid> => [1, 3]`. The questionnaire
    generator stores yes/no answers as text: insert them in `questionnaire_response_bool`;
  - 4.5 lacks `mod_choice`'s and `mod_scorm`'s response generators (`choice_user_submit_response()`
    works on both);
  - events triggered in a CLI script sit in the log store's buffer until shutdown:
    `get_log_manager()->dispose()` before touching `logstore_standard_log`.
- Telling a locally built image from a pulled one: not by `RepoDigests`. With Docker Desktop's
  containerd image store a local build has them too. The `Dockerfile` labels every image
  `org.falkenslab.sandbox.build` (`local` unless the workflow passes `SANDBOX_BUILD=<git tag>`),
  and `pullImage()` in `manage.mjs` skips pulling over a `local` one.
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
