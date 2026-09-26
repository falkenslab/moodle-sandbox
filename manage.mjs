#!/usr/bin/env node
/**
 * Gestiona el sandbox de Moodle dockerizado (clonar el core, levantar los
 * contenedores, instalar el sitio y sembrar un curso de demo) sin depender de
 * bash — se invoca con Node directamente, así que en Windows evita el problema de
 * Git Bash reescribiendo argumentos que parecen rutas absolutas de Unix
 * (`/var/www/html`, etc.) al pasarlos a `docker compose exec`.
 *
 * Uso: node manage.mjs <clone|env|up|composer|install|seed|setup|down|reset|status>
 */
import { spawnSync } from "node:child_process";
import { existsSync, mkdirSync, readdirSync, rmSync, copyFileSync, writeFileSync, readFileSync, chmodSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { randomBytes } from "node:crypto";

// Todo es relativo a la carpeta de este script (la raíz del repo).
const moodleDir = path.dirname(fileURLToPath(import.meta.url));
const srcDir = path.join(moodleDir, "src");
const envPath = path.join(moodleDir, ".env");
const seedScript = path.join(moodleDir, "seed", "seed-course.php");
const activityScript = path.join(moodleDir, "seed", "seed-teacher-activity.php");
const emptyCourseScript = path.join(moodleDir, "seed", "seed-empty-course.php");

const MOODLE_REPO = "https://github.com/moodle/moodle.git";
const MOODLE_BRANCH = process.env.MOODLE_BRANCH || "MOODLE_502_STABLE";

function log(msg) {
  console.log(`[moodle] ${msg}`);
}

function run(cmd, args, opts = {}) {
  const result = spawnSync(cmd, args, { stdio: "inherit", cwd: moodleDir, ...opts });
  if (result.error) throw result.error;
  if (result.status !== 0) {
    throw new Error(`"${cmd} ${args.join(" ")}" salió con código ${result.status}`);
  }
}

function runCapture(cmd, args, opts = {}) {
  return spawnSync(cmd, args, { cwd: moodleDir, encoding: "utf-8", ...opts });
}

function compose(args) {
  run("docker", ["compose", ...args]);
}

/** `docker compose exec webserver <args>` — rutas siempre relativas a /var/www/html. */
function composeExec(args) {
  run("docker", ["compose", "exec", "webserver", ...args]);
}

function isEmptyDir(dir) {
  return !existsSync(dir) || readdirSync(dir).length === 0;
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

/** Puerto del host: MOODLE_SANDBOX_PORT del entorno o de .env (docker compose también
 * lo lee de .env), 8080 por defecto. */
function sandboxPort() {
  return process.env.MOODLE_SANDBOX_PORT || readEnvFile().MOODLE_SANDBOX_PORT || "8080";
}

/** URL del sitio: MOODLE_SANDBOX_URL si se da, si no localhost con el puerto del sandbox. */
function wwwroot() {
  return process.env.MOODLE_SANDBOX_URL || `http://localhost:${sandboxPort()}`;
}

/** Crea .env con contraseñas aleatorias si no existe todavía. Nunca lo sobrescribe. */
function ensureEnv() {
  if (existsSync(envPath)) {
    log(".env ya existe, no se toca.");
    return;
  }
  const content = `MOODLE_SANDBOX_PORT=${sandboxPort()}
MOODLE_DB_PASSWORD=${randomPassword()}
MOODLE_ADMIN_USERNAME=admin
MOODLE_ADMIN_PASSWORD=${randomPassword()}
MOODLE_ADMIN_EMAIL=admin@example.com
MOODLE_SITE_NAME="Moodle Sandbox"

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
    // best-effort, no-op en Windows
  }
  log(`.env generado con credenciales nuevas en ${envPath}`);
}

/** Clona el core de Moodle (shallow, una sola rama) si src/ está vacío. */
function ensureClone() {
  if (!isEmptyDir(srcDir)) {
    log("src/ ya tiene contenido, no se clona de nuevo (usa 'reset' para empezar de cero).");
    return;
  }
  mkdirSync(srcDir, { recursive: true });
  log(`Clonando ${MOODLE_REPO} (rama ${MOODLE_BRANCH}, shallow) en src/ — puede tardar unos minutos...`);
  run("git", ["clone", "--depth", "1", "--branch", MOODLE_BRANCH, MOODLE_REPO, "src"]);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function up() {
  log("Levantando contenedores (docker compose up -d)...");
  compose(["up", "-d"]);
  log("Esperando a que la base de datos esté healthy...");
  const deadline = Date.now() + 60_000;
  while (Date.now() < deadline) {
    const result = runCapture("docker", ["compose", "ps", "db", "--format", "{{.Health}}"]);
    if (result.stdout?.trim() === "healthy") {
      log("Base de datos lista.");
      return;
    }
    await sleep(1000);
  }
  log("Aviso: la base de datos no confirmó estar healthy en 60s, se continúa igualmente.");
}

/** Composer no viene en la imagen y no persiste entre contenedores nuevos, pero
 * como se instala dentro de src/ (bind-mount), sobrevive a un `docker compose down`.
 * Se da por hecho solo con vendor/autoload.php (lo último que escribe): un vendor/ a
 * medias, de un install interrumpido, se completa en vez de saltarse. */
function composerInstall() {
  if (existsSync(path.join(srcDir, "vendor", "autoload.php"))) {
    log("vendor/ ya está instalado, se omite composer install (borra src/vendor para forzarlo).");
    return;
  }
  log("Instalando Composer y dependencias (incluye dev, hace falta para sembrar el curso)...");
  // Sin límite de tiempo por proceso: en Windows, descomprimir un paquete sobre el
  // bind-mount de src/ puede pasar de los 300 s por defecto de Composer.
  composeExec([
    "env", "COMPOSER_PROCESS_TIMEOUT=0",
    "bash", "-c",
    "test -f composer.phar || (curl -sS https://getcomposer.org/installer -o /tmp/cs.php " +
      "&& php /tmp/cs.php --install-dir=. --filename=composer.phar); " +
      "php composer.phar install --no-interaction",
  ]);
}

function dbHasTables() {
  const result = runCapture("docker", [
    "compose", "exec", "-T", "db", "psql", "-U", "moodle", "-d", "moodle",
    "-tAc", "select 1 from information_schema.tables where table_name='mdl_config' limit 1",
  ]);
  return result.stdout?.trim() === "1";
}

/** Instala el sitio (config.php + esquema de base de datos) si hace falta. Cubre
 * también el caso en que config.php existe pero la base de datos está vacía
 * (p.ej. tras un `docker compose down -v` sin volver a clonar src/). */
function installSite() {
  const vars = readEnvFile();
  const configExists = existsSync(path.join(srcDir, "config.php"));

  if (!configExists) {
    log("Instalando el sitio (admin/cli/install.php) — la primera vez puede tardar bastante...");
    composeExec([
      "php", "admin/cli/install.php",
      "--non-interactive", "--agree-license",
      `--wwwroot=${wwwroot()}`,
      "--dbtype=pgsql", "--dbhost=db", "--dbname=moodle", "--dbuser=moodle",
      `--dbpass=${vars.MOODLE_DB_PASSWORD ?? ""}`, "--dbport=5432",
      `--fullname=${vars.MOODLE_SITE_NAME ?? "Moodle Sandbox"}`, "--shortname=sandbox",
      `--adminuser=${vars.MOODLE_ADMIN_USERNAME ?? "admin"}`,
      `--adminpass=${vars.MOODLE_ADMIN_PASSWORD ?? ""}`,
      `--adminemail=${vars.MOODLE_ADMIN_EMAIL ?? "admin@example.com"}`,
    ]);
  } else if (!dbHasTables()) {
    log("config.php ya existe pero la base de datos está vacía — instalando el esquema (admin/cli/install_database.php)...");
    composeExec([
      "php", "admin/cli/install_database.php",
      "--agree-license",
      `--fullname=${vars.MOODLE_SITE_NAME ?? "Moodle Sandbox"}`, "--shortname=sandbox",
      `--adminuser=${vars.MOODLE_ADMIN_USERNAME ?? "admin"}`,
      `--adminpass=${vars.MOODLE_ADMIN_PASSWORD ?? ""}`,
      `--adminemail=${vars.MOODLE_ADMIN_EMAIL ?? "admin@example.com"}`,
    ]);
  } else {
    log("El sitio ya está instalado, no se hace nada.");
    return;
  }

  // admin/cli/install.php dejó config.php como 640 root:root, ilegible para
  // www-data: sin esto, cualquier página da 500.
  composeExec(["chmod", "644", "config.php"]);
  log("Sitio instalado.");
}

/** Copia seed/seed-course.php (versionado en el repo) a src/ — que se pierde
 * cada vez que se re-clona el core — y lo ejecuta dentro del contenedor. */
function seed() {
  if (!existsSync(seedScript)) {
    throw new Error(`No se encuentra ${seedScript}`);
  }
  copyFileSync(seedScript, path.join(srcDir, "seed-course.php"));
  const vars = readEnvFile();
  log("Sembrando curso de demo (profesor, alumno, quiz, tarea, foro)...");
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
 * Ejecuta las tareas ad hoc pendientes de Moodle. El sandbox no tiene cron, y en Moodle 5
 * algunas son imprescindibles: sin transfer_question_categories/transfer_questions, el
 * banco de preguntas del curso queda bloqueado ("Create a new question" no aparece).
 */
function runAdhocTasks() {
  log("Ejecutando las tareas pendientes de Moodle (no hay cron en el sandbox)...");
  composeExec(["php", "admin/cli/adhoc_task.php", "--execute"]);
}

/** Siembra, sobre el curso de pruebas, trabajo para un agente de profesor: contenido
 * coherente, tres alumnos más con entregas de calidad distinta y dudas en el foro. */
function activity() {
  if (!existsSync(activityScript)) {
    throw new Error(`No se encuentra ${activityScript}`);
  }
  copyFileSync(activityScript, path.join(srcDir, "seed-teacher-activity.php"));
  log("Sembrando actividad para el profesor (alumnos, entregas y dudas en el foro)...");
  composeExec(["php", "seed-teacher-activity.php"]);
  runAdhocTasks();
}

/**
 * Crea un curso vacío (o devuelve el que ya tiene ese nombre corto) para que un agente de
 * profesor lo construya entero, con el profesor y los alumnos sembrados matriculados.
 * `course <nombre-corto> ["Nombre completo"] [--json]`; con --json imprime solo
 * {id, shortname, fullname, url, created}.
 */
function course(args) {
  const json = args.includes("--json");
  const [shortname, fullname] = args.filter((a) => a !== "--json");
  if (!shortname) throw new Error('Uso: npm run course -- <nombre-corto> ["Nombre completo"] [--json]');
  copyFileSync(emptyCourseScript, path.join(srcDir, "seed-empty-course.php"));
  const vars = readEnvFile();
  const result = runCapture("docker", [
    "compose", "exec", "-T", "webserver", "env",
    `COURSE_SHORTNAME=${shortname}`, `COURSE_FULLNAME=${fullname ?? shortname}`,
    `MOODLE_TEACHER_USERNAME=${vars.MOODLE_TEACHER_USERNAME ?? "profesor"}`,
    `MOODLE_STUDENT_USERNAME=${vars.MOODLE_STUDENT_USERNAME ?? "alumno"}`,
    "php", "seed-empty-course.php",
  ]);
  if (result.status !== 0) throw new Error(`No se pudo crear el curso: ${(result.stderr || result.stdout).trim()}`);
  const created = JSON.parse(result.stdout.trim().split(/\r?\n/).at(-1));
  const data = { ...created, url: `${installedWwwroot()}/course/view.php?id=${created.id}` };
  if (json) {
    process.stdout.write(`${JSON.stringify(data, null, 2)}\n`);
    return;
  }
  log(`${data.created ? "Curso creado" : "El curso ya existía"}: ${data.fullname} (id ${data.id}) → ${data.url}`);
}

function down() {
  compose(["down"]);
}

/** Destructivo: borra contenedores + volúmenes + el checkout de Moodle + .env.
 * Requiere --yes explícito para no borrar nada por accidente. */
function reset(args) {
  if (!args.includes("--yes")) {
    throw new Error("Esto borra los contenedores, los volúmenes, src/ y .env. Repite con --yes si es lo que quieres.");
  }
  log("Borrando contenedores y volúmenes...");
  compose(["down", "-v"]);
  log("Borrando src/ y .env...");
  rmSync(srcDir, { recursive: true, force: true });
  rmSync(envPath, { force: true });
  log("Listo. 'npm run setup' para volver a montar el sandbox desde cero.");
}

function status() {
  compose(["ps"]);
}

/** La URL con la que se instaló el sitio (config.php manda: wwwroot queda fijado al
 * instalar), o la que se usaría si aún no está instalado. */
function installedWwwroot() {
  const configPath = path.join(srcDir, "config.php");
  if (existsSync(configPath)) {
    const match = readFileSync(configPath, "utf-8").match(/\$CFG->wwwroot\s*=\s*'([^']+)'/);
    if (match) return match[1];
  }
  return wwwroot();
}

function isRunning() {
  const result = runCapture("docker", ["compose", "ps", "webserver", "--format", "{{.State}}"]);
  return result.stdout?.trim() === "running";
}

function courseId() {
  const result = runCapture("docker", [
    "compose", "exec", "-T", "db", "psql", "-U", "moodle", "-d", "moodle",
    "-tAc", "select id from mdl_course where shortname='sandbox-course'",
  ]);
  const id = Number(result.stdout?.trim());
  return Number.isInteger(id) && id > 0 ? id : undefined;
}

/**
 * El contrato para los agentes que usan el sandbox: URL, curso sembrado y credenciales,
 * sin que tengan que leer .env ni consultar la base de datos. `--json` imprime solo el
 * JSON en stdout; sin él, lo mismo legible. Sale con código 1 si el sandbox no está
 * instalado, no está en marcha o el curso no está sembrado.
 */
function info(args) {
  const json = args.includes("--json");
  const fail = (message) => {
    throw new Error(`${message} (npm run setup / npm run up)`);
  };
  if (!existsSync(envPath) || !existsSync(path.join(srcDir, "config.php"))) fail("El sandbox no está instalado.");
  if (!isRunning()) fail("El sandbox está instalado pero no está en marcha.");
  const id = courseId();
  if (!id) fail('No se encuentra el curso "sandbox-course": falta sembrarlo.');

  const vars = readEnvFile();
  const data = {
    url: installedWwwroot(),
    running: true,
    course: { id, shortname: "sandbox-course" },
    admin: { username: vars.MOODLE_ADMIN_USERNAME, password: vars.MOODLE_ADMIN_PASSWORD },
    teacher: { username: vars.MOODLE_TEACHER_USERNAME, password: vars.MOODLE_TEACHER_PASSWORD },
    student: { username: vars.MOODLE_STUDENT_USERNAME, password: vars.MOODLE_STUDENT_PASSWORD },
  };
  if (json) {
    process.stdout.write(`${JSON.stringify(data, null, 2)}\n`);
    return;
  }
  console.log(`URL:      ${data.url}`);
  console.log(`Curso:    ${data.course.shortname} (id ${data.course.id}) → ${data.url}/course/view.php?id=${data.course.id}`);
  console.log(`Admin:    ${data.admin.username} / ${data.admin.password}`);
  console.log(`Profesor: ${data.teacher.username} / ${data.teacher.password}`);
  console.log(`Alumno:   ${data.student.username} / ${data.student.password}`);
}

async function setup() {
  ensureEnv();
  ensureClone();
  await up();
  composerInstall();
  installSite();
  seed();
  const vars = readEnvFile();
  log("Sandbox listo:");
  log(`  URL: ${wwwroot()}`);
  log(`  Admin:    ${vars.MOODLE_ADMIN_USERNAME} / ${vars.MOODLE_ADMIN_PASSWORD}`);
  log(`  Profesor: ${vars.MOODLE_TEACHER_USERNAME} / ${vars.MOODLE_TEACHER_PASSWORD}`);
  log(`  Alumno:   ${vars.MOODLE_STUDENT_USERNAME} / ${vars.MOODLE_STUDENT_PASSWORD}`);
}

const COMMANDS = { clone: ensureClone, env: ensureEnv, up, composer: composerInstall, install: installSite, seed, activity, setup, down, reset, status, info, tasks: runAdhocTasks, course };

const [, , cmd, ...rest] = process.argv;
if (!cmd || !(cmd in COMMANDS)) {
  console.error(`Uso: node manage.mjs <${Object.keys(COMMANDS).join("|")}>`);
  process.exit(1);
}

try {
  await COMMANDS[cmd](rest);
} catch (error) {
  console.error(`[moodle] ${error.message}`);
  process.exit(1);
}
