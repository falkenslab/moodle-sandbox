# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A disposable, Docker-based Moodle 5.x instance with a seeded demo course, used to exercise Moodle agents (a student agent, and later a teacher agent) end to end without touching a real institution's Moodle. There's no application code here: just the lifecycle script, the compose file and the course seeder.

## Commands

```
npm run setup            # env + up (builds the image if missing) + install + seed, all idempotent
npm run build            # build the Moodle image here instead of pulling it; `up` then recreates the containers
npm run up | down | status
npm run seed             # re-seed the demo course (no-op if it already exists)
npm run activity         # teacher workload on top of it: students, submissions, forum doubts
npm run info -- --json   # the agents' contract: url, course id, credentials (exit 1 if not ready)
npm run course -- <short> ["Full name"] --json   # an empty course with the teacher and students enrolled
npm run theme -- classic|boost|adaptable   # the site theme (Boost by default), caches purged; MOODLE_SANDBOX_THEME (env or .env) makes setup/up apply one
npm run plugins          # content using the image's plugins: a quiz with one question per plugin qtype, a course per course format; setup runs it
npm run reset -- --yes   # wipes containers, volumes (the whole site) and .env; keeps the image
```

Any command takes `--moodle 4.5` to work on the Moodle 4.5 site instead (`SITES` in `manage.mjs`): its own compose project (`moodle-sandbox-45`), port 8081 and `.env.moodle-sandbox-45`, running next to the 5.2 one, which stays the default.

`manage.mjs` (plain Node, no dependencies) resolves every path relative to its own folder and spawns `docker`/`git` through `child_process`, so Git Bash never rewrites container paths like `/var/www/html` on Windows. Each `setup` step skips work already done, so re-running after a partial run is safe.

## Layout

- `manage.mjs` — the whole lifecycle; one function per step.
- `Dockerfile` — the sandbox's image: `moodlehq/moodle-php-apache` with Moodle's code (a shallow clone of the Moodle tag `v$MOODLE_VERSION`, `5.2.4` by default: a release tag, not a branch, so every build gives the same Moodle) and its Composer dependencies (dev included: the seeds use Moodle's data generators) inside, `docker/config.php` (database password and URL from the environment) and `php/zz-sandbox.ini` (an opcache sized for Moodle's ~58000 PHP files, no per-request file checks). No bind mount of the code: through Docker Desktop on Windows it made every page several times slower. Published as `ghcr.io/falkenslab/moodle-sandbox:<moodle version>` (amd64 and arm64) by `.github/workflows/image.yml` when a `v<x.y.z>` tag is pushed (`v<x.y.z>-<n>` for a sandbox change on the same Moodle; it also updates `<x.y.z>` and `<x.y>`). `up` pulls the newest published image first (`pullImage()`: best effort, so it still works offline) unless the local one was built here (label `org.falkenslab.sandbox.build=local`; the workflow sets it to the git tag); compose itself only pulls when missing (`pull_policy: missing`) and builds from the `Dockerfile` when it can't. A new Moodle is a new `MOODLE_VERSION` (default in both `docker-compose.yml` and the `Dockerfile`, and in `SITES` in `manage.mjs`) and a new tag; the site's data stays in its volumes. The same `Dockerfile` builds 4.5 (`v4.5.x` tags): plugins go under `public/` only when Moodle has it (5.1+), and each plugin's release is picked by Moodle series.
- `docker/plugins.txt` — the third-party plugins of a real 4.5 campus (issue #4), one line per plugin and Moodle series, pinned (a plugins-directory zip with its md5, or a git tag/commit); `docker/install-plugins.sh` installs them at build time, after `composer install` so changing the list doesn't redo that layer. `info --json` reports the ones the site has (`plugins`). `seed/configure-plugins.php` runs (as admin) after every install/upgrade and only changes what's missing: CodeRunner's `jobe_host` → the `jobe` container, `mod_chat`/`mod_survey` enabled (Moodle ships them disabled), and plugin settings without a default saved (or Moodle sends the admin to the new-settings page). `docker/config.php` sets what must hold during the install itself: `curlsecurityblockedhosts` without the private ranges (Docker's networks, `jobe`), and H5P's hub and usage stats forced off. mod_hvp registers the site with its hub at install time whatever its settings: `docker-compose.yml` points `hub-api.h5p.org` at 127.0.0.1 (`extra_hosts`) so that request fails. Docs on what each plugin needs and which 5.2 releases are older: comments in `plugins.txt` and the README's Plugins section.
- `docker-compose.yml` — project name `moodle-sandbox` (`MOODLE_SANDBOX_PROJECT`, which `manage.mjs` sets per site, with `-p` and `--env-file`), port 8080: Postgres 17 tuned for a disposable site (`fsync=off`, `synchronous_commit=off`: fast writes, but a power cut or a hard Docker stop can corrupt it; `reset` and `setup` rebuild it), the webserver, a `jobe` container (CodeRunner's sandbox, pinned by digest, amd64 only), and a `cron` container running Moodle's cron every minute (scheduled and ad hoc tasks: backups, course copies, restores, notifications), all from the same image.
- `seed/seed-teacher-activity.php` — turns the demo course into a teacher's workload: coherent content, three more students whose submissions (good, thin, conceptually wrong) are *submitted*, and forum threads (one unanswered, one answered wrongly by a classmate). Idempotent; copied into the container like the course seed.
- `info` in `manage.mjs` — the contract other agents depend on (student-agent's and teacher-agent's `sandbox-e2e` skills): keep its JSON shape stable, and add fields rather than renaming them.
- `seed/seed-course.php` — the durable, versioned source of the demo course (teacher, student, page, quiz, assignment, forum). `manage.mjs seed` copies it into the webserver's code (next to `config.php`, which the seeds require by `__DIR__`) before running it: the container's code is disposable, rebuilt from the image.
- `.env` (generated passwords) is gitignored and never overwritten once generated. `src/`, the Moodle checkout bind-mounted before the image existed, is no longer used (gitignored; `reset` deletes it).

## Before touching the sandbox

Load the `moodle-sandbox-troubleshooting` project skill: install gotchas, 500s after install, quiz grading, seeding dependencies and Windows-specific slowness that aren't discoverable from Moodle's own docs. Add new ones there, not here.
