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

const MOODLE_REPO = "https://github.com/moodle/moodle.git";
const MOODLE_BRANCH = process.env.MOODLE_BRANCH || "MOODLE_502_STABLE";
const WWWROOT = process.env.MOODLE_SANDBOX_URL || "http://localhost:8080";

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

/** Crea .env con contraseñas aleatorias si no existe todavía. Nunca lo sobrescribe. */
function ensureEnv() {
  if (existsSync(envPath)) {
    log(".env ya existe, no se toca.");
    return;
  }
  const content = `MOODLE_DB_PASSWORD=${randomPassword()}
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
 * como se instala dentro de src/ (bind-mount), sobrevive a un `docker compose down`. */
function composerInstall() {
  if (existsSync(path.join(srcDir, "vendor"))) {
    log("vendor/ ya existe, se omite composer install (borra src/vendor para forzarlo).");
    return;
  }
  log("Instalando Composer y dependencias (incluye dev, hace falta para sembrar el curso)...");
  composeExec([
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
      `--wwwroot=${WWWROOT}`,
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

async function setup() {
  ensureEnv();
  ensureClone();
  await up();
  composerInstall();
  installSite();
  seed();
  const vars = readEnvFile();
  log("Sandbox listo:");
  log(`  URL: ${WWWROOT}`);
  log(`  Admin:    ${vars.MOODLE_ADMIN_USERNAME} / ${vars.MOODLE_ADMIN_PASSWORD}`);
  log(`  Profesor: ${vars.MOODLE_TEACHER_USERNAME} / ${vars.MOODLE_TEACHER_PASSWORD}`);
  log(`  Alumno:   ${vars.MOODLE_STUDENT_USERNAME} / ${vars.MOODLE_STUDENT_PASSWORD}`);
}

const COMMANDS = { clone: ensureClone, env: ensureEnv, up, composer: composerInstall, install: installSite, seed, setup, down, reset, status };

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
