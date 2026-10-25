# moodle-sandbox guide

Everything about the sandbox beyond the [README](../README.md): every command, the configuration, the seeded courses, the plugins, the `info` contract and how it all works.

## 📋 Requirements

- Docker with Docker Compose.
- Node.js 20 or later (only for `manage.mjs`; there are no dependencies to install).
- An internet connection the first time, to download the Moodle and PostgreSQL images.
- About 3 GB free for the Moodle image.

No GitHub account and nothing to build: the Moodle image is public.

## 💻 Commands

| Command | What it does |
| --- | --- |
| `npm run setup` | Sets everything up from scratch, or finishes what's missing |
| `npm run up` | Pulls the newest image, starts the containers and upgrades Moodle (and its plugins) to the image's code |
| `npm run down` | Stops the containers without deleting anything |
| `npm run status` | The containers' state |
| `npm run info` | The URL, the demo course's id and the credentials; with `-- --json`, for programs and agents |
| `npm run seed` | Seeds the demo course again (does nothing if it exists) |
| `npm run activity` | Adds work for a teacher agent: students, submissions and forum questions |
| `npm run content` | Seeds the content course moodle-driver's test suite reads (`mcp-content`); `setup` already does it |
| `npm run plugins` | Seeds content that uses the plugins: a quiz with their question types, a course per course format and one with their activities and blocks; `setup` already does it |
| `npm run h5p` | Installs the H5P content types in `docker/h5p-content-types.txt`, downloaded on your computer; `setup` already does it |
| `npm run course -- <shortname> ["Full name"] [--json]` | Creates an empty course with the teacher and students enrolled |
| `npm run theme -- classic\|boost\|adaptable` | Changes the site's theme (Boost by default; some campuses use Classic) and purges the caches |
| `npm run tasks` | Runs Moodle's pending tasks now, without waiting for cron |
| `npm run build` | Builds the image here instead of pulling it (another Moodle version, a `Dockerfile` change); then `npm run up` |
| `npm run reset -- --yes` | Deletes the whole site (containers, database, files and `.env`) to start over |

`reset` is destructive: it asks for `--yes` on purpose. The image is kept, so the next `setup` doesn't download it again.

Every command takes `--moodle 4.5` to work on the [Moodle 4.5 site](#-moodle-45-next-to-52).

## 🔧 Configuration

Optional environment variables, which can also go in `.env`:

| Variable | Default | What for |
| --- | --- | --- |
| `MOODLE_VERSION` | `5.2.4` | The image's Moodle version (`4.5.15` on the 4.5 site) |
| `MOODLE_SANDBOX_PORT` | `8080` | The site's port (`8081` on the 4.5 site) |
| `MOODLE_SANDBOX_THEME` | Moodle's own (Boost) | The theme `setup` and `up` set: `classic`, as the campus uses, `boost` or `adaptable` |
| `MOODLE_SANDBOX_PROJECT` | `moodle-sandbox` | The site's Docker project, and with it its volumes and its `.env` (environment only; see [Moodle 4.5 next to 5.2](#-moodle-45-next-to-52)) |
| `MOODLE_SANDBOX_URL` | `http://localhost:<port>` | The site's full URL |

If port 8080 is taken (by another Moodle, for example), pick another one, better before the first install, since the links Moodle stores in content keep the port they were made with:

```bash
MOODLE_SANDBOX_PORT=8081 npm run setup
```

The port is saved in `.env`, so the other commands use it without repeating it.

## 🔀 Moodle 4.5 next to 5.2

Besides the Moodle 5.2 site, a Moodle 4.5 site can run at the same time: the long-term support release (until 2027) many schools and campuses use, to test a program against both. Any command with `--moodle 4.5` works on that site:

```bash
npm run setup -- --moodle 4.5
npm run activity -- --moodle 4.5
npm run info -- --moodle 4.5 --json
```

| Site | Moodle | URL | Docker project | Credentials |
| --- | --- | --- | --- | --- |
| Default | 5.2.4 | <http://localhost:8080> | `moodle-sandbox` | `.env` |
| `--moodle 4.5` | 4.5.15 | <http://localhost:8081> | `moodle-sandbox-45` | `.env.moodle-sandbox-45` |

Each has its own containers, volumes (its database and files) and passwords, so they're set up, stopped and deleted separately: `npm run reset -- --moodle 4.5 --yes` deletes only the 4.5 one. Without `--moodle` nothing changes: agents get the 5.2 site. The seeded courses and `info` are the same on both; `info` says which one it is in `moodle` and `project`.

The 4.5 image is the same recipe (`Dockerfile`) with another Moodle version: PHP 8.3, which 4.5 also supports, and each plugin in its release for 4.5. Moodle 4.5's code has no `public/` folder (5.1 and later do); the base image serves whichever there is.

`MOODLE_SANDBOX_PROJECT` (in the environment) picks a site by its Docker project, and one with another name is one more site, with its own volumes and its `.env.<project>`; with `MOODLE_VERSION` and `MOODLE_SANDBOX_PORT` it can run any other version on another port.

## 📚 The seeded courses

### The demo course

`seed/seed-course.php` creates `sandbox-course` with:

- a teacher (`profesor`) and a student (`alumno`), with random passwords saved in `.env`;
- a page, a quiz with questions, an assignment and a forum.

### Work for a teacher agent

`npm run activity` (`seed/seed-teacher-activity.php`) turns that course into a class with real work to grade and answer, around an introduction to programming (variables and data types):

- coherent content in the page, the assignment (with its marking criteria out of 10) and the forum;
- three more students (Lucía Martín, Marcos López and Sara Gil) who have **submitted** the assignment: a complete one, a thin one and one with a conceptual mistake; `alumno` submits nothing;
- two forum threads: an unanswered question and one a classmate answered wrongly.

The three share a password saved in `.env` (`MOODLE_SEEDED_STUDENTS_PASSWORD`) and shown by `npm run info`, so you can log in as any of them. It can run more than once: once seeded, it only repairs what's missing (draft submissions, passwords).

### moodle-driver's content course

`npm run content` (`seed/seed-content.php`, which `setup` runs) creates `mcp-content`, "Contenido para moodle-mcp": the course [moodle-driver](https://github.com/falkenslab/moodle-driver)'s test suite reads and never writes. It has one activity of each kind, with data in them:

- **People**: the teacher and four students (`alumno` and the three seeded ones, which it creates if missing), and a group.
- **Tarea MCP**: an assignment with the rubric "Rúbrica MCP", submitted by Lucía and Marcos and graded with it.
- **Cuestionario MCP**: six questions with finished attempts by three students; another quiz without attempts.
- **Surveys**: an anonymous feedback (with a multiple-choice question answered with several options) and a questionnaire with one question of each type, both answered by three students.
- **Forums**: an introductions forum and "Foro MCP", with eight threads and their replies.
- **The rest**: page, book, URL, label, file, folder, H5P, lesson, an answered choice, two SCORM packages and, on 5.x, a question bank.
- **Views**: by the students and the teacher, an hour earlier, so the activity and participation reports have data.

The suite finds the course by its short name and the assignment by its name, so it's the same on both series even though the ids change. If the course exists, it's left alone.

### Empty courses to build

`npm run course -- docker-intro "Introducción a Docker"` creates an empty course with the teacher (as `editingteacher`) and the seeded students enrolled, for a teacher agent to build from scratch. If a course with that short name exists, it's left alone and its id returned. With `--json` it prints only `{ id, shortname, fullname, url, created }`.

## 🧩 Plugins

A real campus has plugins Moodle doesn't ship, and a program that works with Moodle has to cope with them. The image installs those of a real Moodle 4.5 campus ([#4](https://github.com/falkenslab/moodle-sandbox/issues/4)), each in the release made for the image's Moodle series. The list, with each one's exact version and why it was chosen, is in [`docker/plugins.txt`](../docker/plugins.txt); `npm run info -- --json` lists the ones the site has (`plugins`).

- **Activities**: `mod_attendance`, `mod_board`, `mod_checklist`, `mod_choicegroup`, `mod_collabora`, `mod_evoting`, `mod_game`, `mod_hotpot`, `mod_hvp` (H5P), `mod_jitsi`, `mod_journal`, `mod_lightboxgallery`, `mod_offlinequiz`, `mod_publication` and `mod_questionnaire`; and `mod_chat` and `mod_survey`, which ship with Moodle 4.5 and left it in 5.0 (on 5.2 they're installed separately). Moodle ships both disabled; the sandbox enables them.
- **Question types**: `qtype_coderunner` (with `qbehaviour_adaptive_adapted_for_coderunner`), which grades code answers by running them against their test cases in the `jobe` container; `qtype_formulas` (with `qbehaviour_adaptivemultipart`), `qtype_gapfill` and `qtype_ddmatch`.
- **Blocks**: `block_completion_progress`, `block_configurable_reports`, `block_grade_me`, `block_quickmail` and `block_xp`.
- **Course formats**: `format_grid`, `format_topcoll` (Collapsed Topics), `format_onetopic` and `format_tiles`.
- **Reports, editor and theme**: `report_coursesize`, `report_benchmark`, `tiny_fontcolor` (text color in TinyMCE) and `theme_adaptable`. The campus uses the Classic theme, which ships with Moodle: `npm run theme -- classic` (or `adaptable`, or `boost`, the usual one).

**What needs an outside service** is installed without it: Collabora and Jitsi activities can be created but don't open documents or calls. H5P is installed without talking to its Hub: `docker-compose.yml` points the Hub at `127.0.0.1` in Moodle's containers (otherwise H5P registers the site when installed), and the image's `config.php` turns off its daily check and usage statistics. That's why the site can't download H5P content types: `npm run h5p` installs them (see below).

**On 5.2**, several plugins have no 5.2 release and run their newest one: `format_grid`, `mod_hotpot` (5.0), `format_onetopic`, `format_tiles`, `mod_choicegroup`, `mod_lightboxgallery`, `report_benchmark` (5.1), `block_grade_me`, `block_quickmail` and `report_coursesize` (4.5). All install on 5.2 and their pages load without errors. The only thing that fails: Onetopic and Tiles don't open their popups (the icons' help, activities set to open in one), because they use a Moodle module (`core/modal_factory`) that 5.2 no longer has. None is left out of 5.2.

`npm run plugins` (`seed/seed-plugins.php`, which `setup` runs) seeds content that uses them:

- in the demo course, the quiz "Cuestionario de plugins", with one question of each plugin question type (each plugin's example question);
- one course per format (`formato-grid`, `formato-topcoll`, `formato-onetopic`, `formato-tiles`), with four named sections, notes and an assignment in each, and a forum;
- the course `plugins-actividades`, with one activity of each module and the five blocks. `mod_evoting`, `mod_hotpot`, `mod_hvp` and, on 4.5, `mod_offlinequiz` have no activity: their plugins have no way to create one without real content (an H5P package, a Hot Potatoes file…).

The courses have the teacher and the seeded students enrolled. What exists isn't created again, so it can run again after an image update.

Every time the site is installed or upgraded, `manage.mjs` runs `seed/configure-plugins.php`, which gets the site ready for these plugins: CodeRunner pointed at `http://jobe`, Chat and Survey enabled, and plugin settings without a default saved (otherwise Moodle sends the administrator to the new-settings page). For Moodle to reach `jobe`, the image's `config.php` takes the private networks out of the addresses Moodle refuses (`curlsecurityblockedhosts`): Docker puts its containers in one. Jobe's image exists for amd64 only: on an Apple silicon Mac it runs emulated, more slowly.

## 🎬 H5P content types

The site doesn't talk to h5p.org, so it can't download H5P content types (what makes a content-only `.h5p` package work). `npm run h5p`, which `setup` runs, downloads them on your computer from the H5P Hub into the cache `seed/h5p/` (not in git), and installs them on the site from there, with their dependencies. They're the ones in [`docker/h5p-content-types.txt`](../docker/h5p-content-types.txt):

- `H5P.MultiChoice`, `H5P.TrueFalse`, `H5P.Blanks`, `H5P.DragText`, `H5P.Dialogcards`, `H5P.Flashcards`, `H5P.QuestionSet`, `H5P.CoursePresentation` and `H5P.InteractiveVideo`; others come along with them, such as `H5P.DragQuestion`, `H5P.MarkTheWords`, `H5P.SingleChoiceSet` or `H5P.Summary`.

To add one, write its machine name in the list and run `npm run h5p`. To update them, delete the cache (or that type's file) and run it again: a type the site already has in that version, or a newer one, is left alone. Offline, it installs what's in the cache.

## 🤖 Using it from a program or an agent

`npm run info -- --json` is the contract: it prints only this JSON, and exits with an error if the sandbox isn't installed, isn't running or lacks the demo course.

```json
{ "url": "http://localhost:8080", "running": true,
  "course": { "id": 2, "shortname": "sandbox-course" },
  "admin":   { "username": "admin",    "password": "..." },
  "teacher": { "username": "profesor", "password": "..." },
  "student": { "username": "alumno",   "password": "..." },
  "students": [ { "username": "lucia.martin", "password": "..." }, ... ],
  "moodle": { "release": "5.2.4 (Build: 20261005)", "series": "5.2", "theme": "boost" },
  "project": "moodle-sandbox",
  "plugins": { "qtype_coderunner": "2026051000", "qtype_formulas": "2026100800", ... },
  "contentCourse": { "id": 3, "shortname": "mcp-content" } }
```

`plugins` are the ones in `docker/plugins.txt` the site has installed, with their versions, so a test suite can skip what a Moodle series lacks. With `--moodle 4.5`, the same JSON for the 4.5 site.

A program should depend only on this command, not on `.env` or the database. The JSON's shape stays stable: fields are added, never renamed.

## 🐳 How it works

Four containers, in the Docker project `moodle-sandbox`:

- **`webserver`**: Moodle with Apache, from the sandbox's image (`ghcr.io/falkenslab/moodle-sandbox:<Moodle version>`).
- **`cron`**: the same image running Moodle's cron every minute, as a real site does, so backups, course copies, restores and notifications just work.
- **`db`**: PostgreSQL 17.
- **`jobe`**: [Jobe](https://github.com/trampgeek/jobe), the server where CodeRunner runs code answers. Only Moodle uses it: it has no port on your computer.

**Moodle's code is inside the image** (`Dockerfile`): building it clones a Moodle release (a tag of its repository, not a branch, so everyone gets the same Moodle) and installs its Composer dependencies, dev ones included, which the seeds need, and the plugins. The image is public on GitHub's registry, for amd64 and arm64 (Apple silicon too): [ghcr.io/falkenslab/moodle-sandbox](https://github.com/falkenslab/moodle-sandbox/pkgs/container/moodle-sandbox). `npm run up` pulls it; only if it can't (no access to GitHub, behind a proxy, or an unpublished `MOODLE_VERSION`) it builds it on your computer from the `Dockerfile`, in about 6 minutes. The code used to be mounted from a folder on the computer, and on Windows that made every page several times slower: reading Moodle's ~58,000 PHP files through Docker Desktop cost seconds per request. With the code in the image and PHP's cache sized for it (`php/zz-sandbox.ini`), a course page went from about 5 s to 0.13 s.

**The site lives in two Docker volumes**: the database (`db_data`) and Moodle's files (`moodledata_data`). `config.php` is in the image and reads the database password and the URL from the environment (`docker/config.php`), so changing the image doesn't touch the data.

**PostgreSQL is tuned for a disposable site**: it doesn't wait for each write to reach the disk (`fsync=off`, `synchronous_commit=off`), which makes writes much faster. In exchange, a power cut or a hard Docker stop can leave the database unusable. If the site won't start or Moodle reports database errors after one, set it up again: `npm run reset -- --yes`, `npm run setup` and, if you used it, `npm run activity`.

### Updating the sandbox

Every sandbox change on the same Moodle version (new plugins, other settings) is published under the same image tag (`5.2.4`). `npm run up` always looks for the newest published image and, if there's one, recreates the containers with it and upgrades the site: Moodle installs the new plugins and `seed/configure-plugins.php` gets them ready. Then `npm run plugins` seeds the new plugins' content (what's there stays). Offline, it carries on with the image it has. An image built on your computer (`npm run build`, which labels it `local`) isn't replaced: to go back to the published one, delete it (`docker image rm ghcr.io/falkenslab/moodle-sandbox:5.2.4`) and run `npm run up`.

### Changing the Moodle version

With another `MOODLE_VERSION` (in the environment or in `.env`), `npm run up` pulls that image, or builds it if it isn't published, recreates the containers with it and upgrades the database (`admin/cli/upgrade.php`). Moodle can't go back to an older version than the installed one: for that, start over (`npm run reset -- --yes` and `npm run setup`).

### Publishing an image

The default version is in `docker-compose.yml` (`MOODLE_VERSION`) and in the `Dockerfile`. To move to another Moodle version, change it in both, try it with `npm run build` and `npm run up`, and create the repository tag for that version:

```bash
git tag v5.2.5 && git push origin v5.2.5
```

The `Imagen` workflow (`.github/workflows/image.yml`) builds it for amd64 and arm64 and publishes it as `5.2.5`, and also as `5.2` (the newest of the series). If the sandbox changes without changing Moodle (PHP settings, `config.php`, the plugins), the tag gets a suffix, `v5.2.5-2`, which also updates `5.2.5`.

The Moodle 4.5 image is published the same way, with its own tag (`git tag v4.5.15`), from the same `Dockerfile`; its default version is in `SITES` in `manage.mjs`. A `Dockerfile` change that affects both needs one tag for each.

New versions go to the same package, which is already public, so there's nothing else to do. Only a new package of the organization starts private: for it to be public, the organization has to allow it (*Organization settings* → *Packages* → *Package creation* → *Public*) and its visibility has to be changed once in the package's settings.

### Coming from an older version

Older versions of the sandbox cloned Moodle into the `src/` folder and mounted it in the container. To move to the image, `git pull` and `npm run up` are enough: it pulls the image, upgrades Moodle to its code and starts cron. The data stays the same. After that, the `src/` folder is no longer used and can be deleted.

## 📁 Layout

```
├── manage.mjs           # the script behind the npm commands
├── Dockerfile           # the image: Moodle with its code, dependencies and plugins inside
├── docker/config.php    # the image's config.php (reads the password and URL from the environment)
├── docker/plugins.txt   # the image's plugins, with their version for each Moodle series
├── docker/install-plugins.sh    # installs them when the image is built
├── docker/h5p-content-types.txt # the H5P content types npm run h5p installs
├── php/zz-sandbox.ini   # PHP tuned for Moodle (the code cache)
├── docker-compose.yml   # Postgres, Moodle, its cron and Jobe (Docker project "moodle-sandbox")
├── .github/workflows/image.yml  # publishes the image when a v<version> tag is pushed
├── seed/
│   ├── seed-course.php            # the demo course, its teacher, student and activities
│   ├── seed-teacher-activity.php  # students, submissions and questions for a teacher agent
│   ├── seed-empty-course.php      # an empty course to build (npm run course)
│   ├── seed-content.php           # moodle-driver's content course (npm run content)
│   ├── seed-plugins.php           # the plugins' content: quiz, courses per format and of activities (npm run plugins)
│   ├── configure-plugins.php      # gets the site ready for the plugins (after install or upgrade)
│   ├── install-h5p.php            # installs the cached H5P content types (npm run h5p)
│   └── h5p/                       # the cache of downloaded H5P content types (not in git)
├── .env                 # generated credentials (created automatically, not committed)
└── .env.moodle-sandbox-45       # the 4.5 site's, if there is one
```
