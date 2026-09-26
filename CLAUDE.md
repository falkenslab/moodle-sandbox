# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A disposable, Docker-based Moodle 5.x instance with a seeded demo course, used to exercise Moodle agents (a student agent, and later a teacher agent) end to end without touching a real institution's Moodle. There's no application code here: just the lifecycle script, the compose file and the course seeder.

## Commands

```
npm run setup            # env + clone + up + composer + install + seed, all idempotent
npm run up | down | status
npm run seed             # re-seed the demo course (no-op if it already exists)
npm run activity         # teacher workload on top of it: students, submissions, forum doubts
npm run info -- --json   # the agents' contract: url, course id, credentials (exit 1 if not ready)
npm run course -- <short> ["Full name"] --json   # an empty course with the teacher and students enrolled
npm run reset -- --yes   # wipes containers, volumes, src/ and .env
```

`manage.mjs` (plain Node, no dependencies) resolves every path relative to its own folder and spawns `docker`/`git` through `child_process`, so Git Bash never rewrites container paths like `/var/www/html` on Windows. Each `setup` step skips work already done, so re-running after a partial run is safe.

## Layout

- `manage.mjs` — the whole lifecycle; one function per step.
- `docker-compose.yml` — Postgres 17 + `moodlehq/moodle-php-apache`, fixed project name `moodle-sandbox`, port 8080, the Moodle checkout bind-mounted from `src/`.
- `seed/seed-teacher-activity.php` — turns the demo course into a teacher's workload: coherent content, three more students whose submissions (good, thin, conceptually wrong) are *submitted*, and forum threads (one unanswered, one answered wrongly by a classmate). Idempotent; copied into `src/` like the course seed.
- `info` in `manage.mjs` — the contract other agents depend on (student-agent's and teacher-agent's `sandbox-e2e` skills): keep its JSON shape stable, and add fields rather than renaming them.
- `seed/seed-course.php` — the durable, versioned source of the demo course (teacher, student, page, quiz, assignment, forum). `manage.mjs seed` copies it into `src/` before running it, because `src/` is disposable and anything living only there is lost on the next re-clone.
- `src/` (Moodle core clone) and `.env` (generated passwords) are gitignored and disposable; `.env` is never overwritten once generated.

## Before touching the sandbox

Load the `moodle-sandbox-troubleshooting` project skill: install gotchas, 500s after install, quiz grading, seeding dependencies and Windows-specific slowness that aren't discoverable from Moodle's own docs. Add new ones there, not here.
