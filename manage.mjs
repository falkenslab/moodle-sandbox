#!/usr/bin/env node
/**
 * Manages the dockerized Moodle sandbox (building the image with Moodle's code, starting the
 * containers, installing the site and seeding a demo course) without bash: it runs straight
 * from Node, so on Windows it avoids Git Bash rewriting arguments that look like absolute Unix
 * paths (`/var/www/html`, etc.) when passing them to `docker compose exec`.
 *
 * Usage: node manage.mjs <env|build|up|install|seed|setup|down|reset|status|info|tasks|course|activity|theme|plugins|content|h5p>
 */
import { spawnSync } from "node:child_process";
import { existsSync, rmSync, writeFileSync, readFileSync, chmodSync, mkdirSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { randomBytes } from "node:crypto";

// Everything is relative to this script's folder (the repo root).
const moodleDir = path.dirname(fileURLToPath(import.meta.url));
// The Moodle checkout from before the image (see Dockerfile): no longer used; `reset` deletes it.
const srcDir = path.join(moodleDir, "src");

/**
 * The sites that can run side by side, one per Moodle series: each with its own Docker Compose
 * project (its containers and volumes), its port and its .env. 5.2 is the usual one and the one
 * the agents use by default; `--moodle 4.5`, in any position, picks the other.
 */
const SITES = {
  "5.2": { project: "moodle-sandbox", version: "5.2.4", port: "8080", name: "Moodle Sandbox" },
  "4.5": { project: "moodle-sandbox-45", version: "4.5.15", port: "8081", name: "Moodle Sandbox 4.5" },
};

/** Takes `--moodle <series>` (or `--moodle=<series>`) out of the arguments and returns the site.
 * Without it, MOODLE_SANDBOX_PROJECT (from the environment) picks the site by its project; if it
 * matches none, it's one more site with 5.2's values and its own .env. */
function selectSite(args) {
  let series;
  for (let i = 0; i < args.length; i++) {
    const match = args[i].match(/^--moodle(?:=(.*))?$/);
    if (!match) continue;
    series = match[1] ?? args[i + 1];
    args.splice(i, match[1] === undefined ? 2 : 1);
    break;
  }
  if (series !== undefined && !SITES[series]) {
    throw new Error(`No site for Moodle "${series}": ${Object.keys(SITES).join(", ")}.`);
  }
  const project = process.env.MOODLE_SANDBOX_PROJECT || SITES[series ?? "5.2"].project;
  const preset = SITES[series] ?? Object.values(SITES).find((s) => s.project === project) ?? SITES["5.2"];
  return {
    ...preset,
    project,
    // The usual one keeps .env; the others, .env.<project>.
    envPath: path.join(moodleDir, project === SITES["5.2"].project ? ".env" : `.env.${project}`),
  };
}

let site;
let envPath;
const seedScript = path.join(moodleDir, "seed", "seed-course.php");
const activityScript = path.join(moodleDir, "seed", "seed-teacher-activity.php");
const emptyCourseScript = path.join(moodleDir, "seed", "seed-empty-course.php");
const pluginsScript = path.join(moodleDir, "seed", "seed-plugins.php");
const contentScript = path.join(moodleDir, "seed", "seed-content.php");
const h5pScript = path.join(moodleDir, "seed", "install-h5p.php");
const h5pList = path.join(moodleDir, "docker", "h5p-content-types.txt");
// The H5P content type packages downloaded on this machine (outside git): see h5p().
const h5pCache = path.join(moodleDir, "seed", "h5p");
const configureScript = path.join(moodleDir, "seed", "configure-plugins.php");

function log(msg) {
  console.log(`[moodle] ${msg}`);
}

function run(cmd, args, opts = {}) {
  const result = spawnSync(cmd, args, { stdio: "inherit", cwd: moodleDir, ...opts });
  if (result.error) throw result.error;
  if (result.status !== 0) {
    throw new Error(`"${cmd} ${args.join(" ")}" exited with code ${result.status}`);
  }
}

function runCapture(cmd, args, opts = {}) {
  return spawnSync(cmd, args, { cwd: moodleDir, encoding: "utf-8", ...opts });
}

/** `docker compose` for the chosen site: its project and its .env (with the rest, MOODLE_VERSION
 * and MOODLE_SANDBOX_PORT, in the environment: see the end of the file). */
function composeBase() {
  return ["compose", "-p", site.project, ...(existsSync(envPath) ? ["--env-file", envPath] : [])];
}

function compose(args) {
  run("docker", [...composeBase(), ...args]);
}

/** `docker compose exec webserver <args>` — paths always relative to /var/www/html. */
function composeExec(args) {
  run("docker", [...composeBase(), "exec", "webserver", ...args]);
}

/** Copies a file from the repo into the webserver's Moodle code, under that name: the seeds
 * load `__DIR__ . '/config.php'`, so they go next to config.php. */
function putCode(file, name) {
  run("docker", [...composeBase(), "cp", file, `webserver:/var/www/html/${name}`]);
}

function randomPassword() {
  return randomBytes(18).toString("base64url");
}

function readEnvFile() {
  if (!existsSync(envPath)) return {};
  const raw = readFileSync(envPath, "utf-8");
  const vars = {};
  for (const line of raw.split(/\r?\n/)) {
    const match = line.match(/^([A-Z_][A-Z0-9_]*)=(.*)$/);
    if (match) vars[match[1]] = match[2].replace(/^"(.*)"$/, "$1");
  }
  return vars;
}

/** Host port: MOODLE_SANDBOX_PORT from the environment or from the site's .env (docker compose
 * reads it from there too); otherwise the site's (8080 for 5.2, 8081 for 4.5). */
function sandboxPort() {
  return process.env.MOODLE_SANDBOX_PORT || readEnvFile().MOODLE_SANDBOX_PORT || site.port;
}

/** The site's URL: MOODLE_SANDBOX_URL if given, otherwise localhost with the sandbox's port. */
function wwwroot() {
  return process.env.MOODLE_SANDBOX_URL || `http://localhost:${sandboxPort()}`;
}

/** Creates .env with random passwords if it doesn't exist yet. Never overwrites it. */
function ensureEnv() {
  if (existsSync(envPath)) {
    log(`${path.basename(envPath)} already exists, left as is.`);
    return;
  }
  const content = `MOODLE_SANDBOX_PORT=${sandboxPort()}
MOODLE_DB_PASSWORD=${randomPassword()}
MOODLE_ADMIN_USERNAME=admin
MOODLE_ADMIN_PASSWORD=${randomPassword()}
MOODLE_ADMIN_EMAIL=admin@example.com
MOODLE_SITE_NAME="${site.name}"

MOODLE_TEACHER_USERNAME=profesor
MOODLE_TEACHER_PASSWORD=${randomPassword()}
MOODLE_TEACHER_EMAIL=profesor@example.com

MOODLE_STUDENT_USERNAME=alumno
MOODLE_STUDENT_PASSWORD=${randomPassword()}
MOODLE_STUDENT_EMAIL=alumno@example.com
`;
  writeFileSync(envPath, content, "utf-8");
  try {
    chmodSync(envPath, 0o600);
  } catch {
    // best-effort, no-op on Windows
  }
  log(`${path.basename(envPath)} generated with new credentials at ${envPath}`);
}

/**
 * Builds the sandbox's image here (see Dockerfile): Moodle MOODLE_VERSION (5.2.4 by default)
 * with its Composer dependencies inside. Usually not needed: `up` pulls the published image
 * and only builds it when it can't find it. Useful to try another Moodle version or a change
 * to the Dockerfile; `up` then recreates the containers with it. The data (database,
 * moodledata) is left alone.
 */
function build() {
  log("Building the Moodle image (clones Moodle and installs its dependencies: a few minutes)...");
  compose(["build", "--pull", "webserver"]);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Starts the containers (building the image if missing). With the site already installed,
 * upgrades it to the image's code, which may be newer after a `build`. */
async function up() {
  pullImage();
  log("Starting containers (docker compose up -d)...");
  compose(["up", "-d"]);
  log("Waiting for the database to be healthy...");
  const healthy = await waitHealthy("db");
  log(healthy ? "Database ready." : "Warning: the database didn't report healthy within 60s; carrying on anyway.");
  // Jobe takes a few seconds to answer, and CodeRunner treats the server as down until then.
  log(await waitHealthy("jobe") ? "Jobe ready." : "Warning: Jobe didn't report healthy within 60s; CodeRunner won't grade until it does.");
  if (healthy && dbHasTables()) installSite();
}

/**
 * Pulls the newest version of the site's image, if it's a published one: the same tag (5.2.4)
 * gets every sandbox change on that Moodle, and compose only pulls it when missing. It doesn't
 * replace one built on this machine (label org.falkenslab.sandbox.build=local, see Dockerfile).
 * Offline, or with an unpublished version, it carries on with whatever is there (or `up` builds it).
 */
function pullImage() {
  const image = `ghcr.io/falkenslab/moodle-sandbox:${site.version}`;
  const build = runCapture("docker", ["image", "inspect", "--format", '{{index .Config.Labels "org.falkenslab.sandbox.build"}}', image]);
  if (build.status === 0 && build.stdout.trim() === "local") {
    log(`The image ${image} was built on this machine: not pulling another.`);
    return;
  }
  log(`Looking for the newest version of ${image}...`);
  const pull = runCapture("docker", ["pull", "--quiet", image]);
  if (pull.status !== 0) log(`Warning: couldn't pull ${image}; carrying on with whatever is there.`);
}

/** Waits up to 60 s for a service with a healthcheck to be healthy. */
async function waitHealthy(service) {
  const deadline = Date.now() + 60_000;
  while (Date.now() < deadline) {
    if (runCapture("docker", [...composeBase(), "ps", service, "--format", "{{.Health}}"]).stdout?.trim() === "healthy") return true;
    await sleep(1000);
  }
  return false;
}

function dbHasTables() {
  const result = runCapture("docker", [
    ...composeBase(), "exec", "-T", "db", "psql", "-U", "moodle", "-d", "moodle",
    "-tAc", "select 1 from information_schema.tables where table_name='mdl_config' limit 1",
  ]);
  return result.stdout?.trim() === "1";
}

/** Installs Moodle's schema in the database if it's empty, or upgrades it to the image's code
 * if it's already installed: a freshly built image brings the latest of its branch, and until
 * Moodle is upgraded it suspends cron and shows its upgrade notice. config.php already comes
 * in the image and reads the password and the URL from the environment (docker/config.php). */
function installSite() {
  if (dbHasTables()) {
    log("The site is already installed: upgrading it to the image's code if needed (admin/cli/upgrade.php)...");
    composeExec(["php", "admin/cli/upgrade.php", "--non-interactive"]);
    configurePlugins();
    return;
  }
  const vars = readEnvFile();
  log("Installing the site (admin/cli/install_database.php)...");
  composeExec([
    "php", "admin/cli/install_database.php",
    "--agree-license",
    `--fullname=${vars.MOODLE_SITE_NAME ?? "Moodle Sandbox"}`, "--shortname=sandbox",
    `--adminuser=${vars.MOODLE_ADMIN_USERNAME ?? "admin"}`,
    `--adminpass=${vars.MOODLE_ADMIN_PASSWORD ?? ""}`,
    `--adminemail=${vars.MOODLE_ADMIN_EMAIL ?? "admin@example.com"}`,
  ]);
  log("Site installed.");
  configurePlugins();
}

/** Gets the site ready for the image's plugins (seed/configure-plugins.php): CodeRunner
 * pointing at Jobe, Chat and Survey
 * enabled, and plugin settings without a default saved. Only changes what's missing. */
function configurePlugins() {
  putCode(configureScript, "configure-plugins.php");
  composeExec(["php", "configure-plugins.php"]);
  // The theme from MOODLE_SANDBOX_THEME (environment or .env), if given: like a campus on Classic.
  const wanted = process.env.MOODLE_SANDBOX_THEME || readEnvFile().MOODLE_SANDBOX_THEME;
  if (wanted && siteConfig("theme") !== wanted) theme([wanted]);
}

/** Copies seed/seed-course.php (versioned in the repo) into the container's Moodle code
 * — which is lost when the container is recreated — and runs it there. */
function seed() {
  if (!existsSync(seedScript)) {
    throw new Error(`Can't find ${seedScript}`);
  }
  putCode(seedScript, "seed-course.php");
  const vars = readEnvFile();
  log("Seeding the demo course (teacher, student, quiz, assignment, forum)...");
  composeExec([
    "env",
    `MOODLE_TEACHER_USERNAME=${vars.MOODLE_TEACHER_USERNAME ?? "profesor"}`,
    `MOODLE_TEACHER_PASSWORD=${vars.MOODLE_TEACHER_PASSWORD ?? ""}`,
    `MOODLE_TEACHER_EMAIL=${vars.MOODLE_TEACHER_EMAIL ?? "profesor@example.com"}`,
    `MOODLE_STUDENT_USERNAME=${vars.MOODLE_STUDENT_USERNAME ?? "alumno"}`,
    `MOODLE_STUDENT_PASSWORD=${vars.MOODLE_STUDENT_PASSWORD ?? ""}`,
    `MOODLE_STUDENT_EMAIL=${vars.MOODLE_STUDENT_EMAIL ?? "alumno@example.com"}`,
    "php", "seed-course.php",
  ]);
  runAdhocTasks();
}

/**
 * Runs Moodle's pending ad hoc tasks now, without waiting for the cron container (which runs
 * every minute). The seeds call it when they finish: in Moodle 5 some are essential, and
 * without transfer_question_categories/transfer_questions the course's question bank stays
 * locked ("Create a new question" doesn't show up).
 */
function runAdhocTasks() {
  log("Running Moodle's pending tasks...");
  composeExec(["php", "admin/cli/adhoc_task.php", "--execute"]);
}

/** Adds a missing variable to .env (leaving the others alone): .env is never rewritten. */
function ensureEnvKey(name, value) {
  if (readEnvFile()[name] !== undefined) return;
  const raw = existsSync(envPath) ? readFileSync(envPath, "utf-8") : "";
  const separator = raw === "" || raw.endsWith("\n") ? "" : "\n";
  writeFileSync(envPath, `${raw}${separator}${name}=${value}\n`, "utf-8");
}

/** Seeds content that uses the image's plugins: in the demo course, a quiz with one question of
 * each installed question type; and a course per installed course format, with the teacher and
 * the students enrolled. What already exists is left alone. */
function plugins() {
  putCode(pluginsScript, "seed-plugins.php");
  const vars = readEnvFile();
  log("Seeding the plugins' content (question types quiz, a course per format)...");
  composeExec([
    "env",
    `MOODLE_TEACHER_USERNAME=${vars.MOODLE_TEACHER_USERNAME ?? "profesor"}`,
    `MOODLE_STUDENT_USERNAME=${vars.MOODLE_STUDENT_USERNAME ?? "alumno"}`,
    "php", "seed-plugins.php",
  ]);
}

/**
 * Creates the content course moodle-driver's test suite reads ("mcp-content",
 * seed/seed-content.php): one activity of each type, with submissions, attempts, responses,
 * discussions, grades and views. Creates the seeded students if missing. Does nothing if the
 * course already exists.
 */
function content() {
  putCode(contentScript, "seed-content.php");
  // The seeded students' known password, as in `activity`.
  ensureEnvKey("MOODLE_SEEDED_STUDENTS_PASSWORD", randomPassword());
  const vars = readEnvFile();
  log("Seeding moodle-driver's content course (mcp-content)...");
  composeExec([
    "env",
    `MOODLE_TEACHER_USERNAME=${vars.MOODLE_TEACHER_USERNAME ?? "profesor"}`,
    `MOODLE_STUDENT_USERNAME=${vars.MOODLE_STUDENT_USERNAME ?? "alumno"}`,
    `MOODLE_SEEDED_STUDENTS_PASSWORD=${vars.MOODLE_SEEDED_STUDENTS_PASSWORD}`,
    "php", "seed-content.php",
  ]);
}

/**
 * Installs the H5P content types in docker/h5p-content-types.txt without the site talking to
 * h5p.org (docker-compose.yml points it at 127.0.0.1): downloads them here, on this machine,
 * into the seed/h5p/ cache if missing, copies them into the container and seed/install-h5p.php
 * installs them. Offline, it installs those already cached. To update one, delete its cached file.
 */
async function h5p() {
  const types = readFileSync(h5pList, "utf-8").split(/\r?\n/).map((line) => line.trim()).filter((line) => line && !line.startsWith("#"));
  mkdirSync(h5pCache, { recursive: true });
  for (const type of types) {
    const file = path.join(h5pCache, `${type}.h5p`);
    if (existsSync(file)) continue;
    log(`Downloading the H5P content type ${type} (hub-api.h5p.org, from this machine)...`);
    try {
      const response = await fetch(`https://hub-api.h5p.org/v1/content-types/${type}`);
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      writeFileSync(file, Buffer.from(await response.arrayBuffer()));
    } catch (error) {
      log(`Warning: couldn't download ${type} (${error.message}); installing whatever is cached.`);
    }
  }
  putCode(h5pScript, "install-h5p.php");
  composeExec(["rm", "-rf", "/tmp/h5p-content-types"]);
  run("docker", [...composeBase(), "cp", h5pCache, "webserver:/tmp/h5p-content-types"]);
  log("Installing the H5P content types...");
  composeExec(["php", "install-h5p.php", "/tmp/h5p-content-types"]);
  composeExec(["rm", "-rf", "/tmp/h5p-content-types"]);
}

/** Seeds, on top of the demo course, work for a teacher agent: coherent content, three more
 * students with submissions of varying quality, and doubts in the forum. */
function activity() {
  if (!existsSync(activityScript)) {
    throw new Error(`Can't find ${activityScript}`);
  }
  putCode(activityScript, "seed-teacher-activity.php");
  log("Seeding the teacher's workload (students, submissions and forum doubts)...");
  // A known password for the seeded students, so you can log in as them.
  ensureEnvKey("MOODLE_SEEDED_STUDENTS_PASSWORD", randomPassword());
  composeExec(["env", `MOODLE_SEEDED_STUDENTS_PASSWORD=${readEnvFile().MOODLE_SEEDED_STUDENTS_PASSWORD}`,
    "php", "seed-teacher-activity.php"]);
  // The plugins' courses are seeded after these students: that way they get enrolled too.
  plugins();
  runAdhocTasks();
}

/**
 * Creates an empty course (or returns the one that already has that short name) for a teacher
 * agent to build from scratch, with the seeded teacher and students enrolled.
 * `course <short-name> ["Full name"] [--json]`; with --json it prints only
 * {id, shortname, fullname, url, created}.
 */
function course(args) {
  const json = args.includes("--json");
  const [shortname, fullname] = args.filter((a) => a !== "--json");
  if (!shortname) throw new Error('Usage: npm run course -- <short-name> ["Full name"] [--json]');
  putCode(emptyCourseScript, "seed-empty-course.php");
  const vars = readEnvFile();
  const result = runCapture("docker", [
    ...composeBase(), "exec", "-T", "webserver", "env",
    `COURSE_SHORTNAME=${shortname}`, `COURSE_FULLNAME=${fullname ?? shortname}`,
    `MOODLE_TEACHER_USERNAME=${vars.MOODLE_TEACHER_USERNAME ?? "profesor"}`,
    `MOODLE_STUDENT_USERNAME=${vars.MOODLE_STUDENT_USERNAME ?? "alumno"}`,
    "php", "seed-empty-course.php",
  ]);
  if (result.status !== 0) throw new Error(`Couldn't create the course: ${(result.stderr || result.stdout).trim()}`);
  const created = JSON.parse(result.stdout.trim().split(/\r?\n/).at(-1));
  const data = { ...created, url: `${installedWwwroot()}/course/view.php?id=${created.id}` };
  if (json) {
    process.stdout.write(`${JSON.stringify(data, null, 2)}\n`);
    return;
  }
  log(`${data.created ? "Course created" : "The course already existed"}: ${data.fullname} (id ${data.id}) → ${data.url}`);
}

function down() {
  compose(["down"]);
}

/** Destructive: deletes containers + volumes (the database and moodledata) + .env
 * (and src/, the checkout from before the image, if still there). The image is kept:
 * `build` rebuilds it. Requires an explicit --yes so nothing is deleted by accident. */
function reset(args) {
  if (!args.includes("--yes")) {
    throw new Error(`This deletes the containers, the volumes (the whole ${site.project} site) and ${path.basename(envPath)}. Run it again with --yes if that's what you want.`);
  }
  log(`Deleting the containers and volumes of ${site.project}...`);
  compose(["down", "-v"]);
  log(`Deleting ${path.basename(envPath)} (and src/, if still there)...`);
  rmSync(srcDir, { recursive: true, force: true });
  rmSync(envPath, { force: true });
  log("Done. 'npm run setup' (with the same --moodle) to rebuild the sandbox from scratch.");
}

function status() {
  compose(["ps"]);
}

/** The site's URL: the one the container gives config.php (MOODLE_WWWROOT, which
 * docker-compose.yml derives the same way as wwwroot()). */
function installedWwwroot() {
  return wwwroot();
}

function isRunning() {
  const result = runCapture("docker", [...composeBase(), "ps", "webserver", "--format", "{{.State}}"]);
  return result.stdout?.trim() === "running";
}

function courseId(shortname = "sandbox-course") {
  const result = runCapture("docker", [
    ...composeBase(), "exec", "-T", "db", "psql", "-U", "moodle", "-d", "moodle",
    "-tAc", `select id from mdl_course where shortname='${shortname}'`,
  ]);
  const id = Number(result.stdout?.trim());
  return Number.isInteger(id) && id > 0 ? id : undefined;
}

/** A site setting (mdl_config), read from the database without starting Moodle. */
function siteConfig(name) {
  const result = runCapture("docker", [
    ...composeBase(), "exec", "-T", "db", "psql", "-U", "moodle", "-d", "moodle",
    "-tAc", `select value from mdl_config where name='${name}'`,
  ]);
  return result.stdout?.trim() || undefined;
}

/** The plugins of docker/plugins.txt the site has installed, with their versions:
 * { "qtype_coderunner": "2026051000", ... }. One the site's Moodle series doesn't have is left out. */
function installedPlugins() {
  const components = [...new Set(readFileSync(path.join(moodleDir, "docker", "plugins.txt"), "utf-8")
    .split(/\r?\n/).filter((line) => line.trim() && !line.startsWith("#")).map((line) => line.trim().split(/\s+/)[1]))];
  const result = runCapture("docker", [
    ...composeBase(), "exec", "-T", "db", "psql", "-U", "moodle", "-d", "moodle", "-tA", "-F", " ",
    "-c", `select plugin, value from mdl_config_plugins where name='version' and plugin in (${components.map((c) => `'${c}'`).join(",")}) order by plugin`,
  ]);
  return Object.fromEntries((result.stdout ?? "").trim().split(/\r?\n/).filter(Boolean).map((line) => line.split(" ")));
}

/** Changes the site's theme (boost, classic, which some campuses use, or adaptable, from the
 * image's plugins) and purges the caches. The first page afterwards is slow: it compiles the theme's CSS. */
function theme(args) {
  const [name] = args;
  if (!["boost", "classic", "adaptable"].includes(name)) throw new Error("Usage: npm run theme -- boost|classic|adaptable [--moodle 4.5]");
  composeExec(["php", "admin/cli/cfg.php", "--name=theme", `--set=${name}`]);
  composeExec(["php", "admin/cli/purge_caches.php"]);
  log(`Site theme: ${name}.`);
}

/**
 * The contract for the agents that use the sandbox: URL, seeded course and credentials,
 * without them having to read .env or query the database. `--json` prints only the JSON
 * to stdout; without it, the same in readable form. Exits with code 1 if the sandbox isn't
 * installed, isn't running or the course isn't seeded.
 */
function info(args) {
  const json = args.includes("--json");
  const fail = (message) => {
    throw new Error(`${message} (npm run setup / npm run up)`);
  };
  if (!existsSync(envPath)) fail("The sandbox isn't installed.");
  if (!isRunning()) fail("The sandbox isn't running.");
  if (!dbHasTables()) fail("The sandbox isn't installed.");
  const id = courseId();
  if (!id) fail('Can\'t find the course "sandbox-course": it needs seeding.');

  const vars = readEnvFile();
  const data = {
    url: installedWwwroot(),
    running: true,
    course: { id, shortname: "sandbox-course" },
    admin: { username: vars.MOODLE_ADMIN_USERNAME, password: vars.MOODLE_ADMIN_PASSWORD },
    teacher: { username: vars.MOODLE_TEACHER_USERNAME, password: vars.MOODLE_TEACHER_PASSWORD },
    student: { username: vars.MOODLE_STUDENT_USERNAME, password: vars.MOODLE_STUDENT_PASSWORD },
    // The students seeded by `npm run activity` (empty until it has run).
    students: vars.MOODLE_SEEDED_STUDENTS_PASSWORD
      ? ["lucia.martin", "marcos.lopez", "sara.gil"].map((username) => ({ username, password: vars.MOODLE_SEEDED_STUDENTS_PASSWORD }))
      : [],
    // Which of the sandboxes this is (`--moodle 4.5` picks the 4.5 one; 5.2 by default).
    moodle: { release: siteConfig("release"), series: site.version.split(".").slice(0, 2).join("."), theme: siteConfig("theme") },
    project: site.project,
    // The plugins of docker/plugins.txt this site has, with their versions, so a test suite can
    // skip what a Moodle series leaves out.
    plugins: installedPlugins(),
    // The content course moodle-driver's suite reads (`npm run content`; null until seeded).
    contentCourse: courseId("mcp-content") ? { id: courseId("mcp-content"), shortname: "mcp-content" } : null,
  };
  if (json) {
    process.stdout.write(`${JSON.stringify(data, null, 2)}\n`);
    return;
  }
  console.log(`URL:      ${data.url}`);
  console.log(`Moodle:   ${data.moodle.release} · theme ${data.moodle.theme} (project ${data.project})`);
  console.log(`Course:   ${data.course.shortname} (id ${data.course.id}) → ${data.url}/course/view.php?id=${data.course.id}`);
  console.log(`Admin:    ${data.admin.username} / ${data.admin.password}`);
  console.log(`Teacher:  ${data.teacher.username} / ${data.teacher.password}`);
  console.log(`Student:  ${data.student.username} / ${data.student.password}`);
  for (const s of data.students) console.log(`Student:  ${s.username} / ${s.password}`);
}

async function setup() {
  ensureEnv();
  await up();
  installSite();
  seed();
  // Before the plugins: its first activity is module 5 of a new site (see seed-content.php).
  content();
  plugins();
  await h5p();
  const vars = readEnvFile();
  log("Sandbox ready:");
  log(`  URL: ${wwwroot()}`);
  log(`  Admin:    ${vars.MOODLE_ADMIN_USERNAME} / ${vars.MOODLE_ADMIN_PASSWORD}`);
  log(`  Teacher:  ${vars.MOODLE_TEACHER_USERNAME} / ${vars.MOODLE_TEACHER_PASSWORD}`);
  log(`  Student:  ${vars.MOODLE_STUDENT_USERNAME} / ${vars.MOODLE_STUDENT_PASSWORD}`);
}

const COMMANDS = { env: ensureEnv, build, up, install: installSite, seed, activity, setup, down, reset, status, info, tasks: runAdhocTasks, course, theme, plugins, content, h5p };

const [, , cmd, ...rest] = process.argv;
if (!cmd || !(cmd in COMMANDS)) {
  console.error(`Usage: node manage.mjs <${Object.keys(COMMANDS).join("|")}> [--moodle ${Object.keys(SITES).join("|")}]`);
  process.exit(1);
}

try {
  site = selectSite(rest);
  envPath = site.envPath;
  // Another Moodle version, from the environment or the site's .env; otherwise the site's.
  site.version = process.env.MOODLE_VERSION || readEnvFile().MOODLE_VERSION || site.version;
  // For docker compose (docker-compose.yml): the site's project, image and port. The
  // environment wins over .env, and without this 4.5 would take the defaults (5.2's).
  process.env.MOODLE_SANDBOX_PROJECT = site.project;
  process.env.MOODLE_VERSION = site.version;
  process.env.MOODLE_SANDBOX_PORT = sandboxPort();
  await COMMANDS[cmd](rest);
} catch (error) {
  console.error(`[moodle] ${error.message}`);
  process.exit(1);
}
