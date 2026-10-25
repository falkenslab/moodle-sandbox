#!/usr/bin/env node
/**
 * Gestiona el sandbox de Moodle dockerizado (construir la imagen con el código de
 * Moodle, levantar los contenedores, instalar el sitio y sembrar un curso de demo)
 * sin depender de bash — se invoca con Node directamente, así que en Windows evita
 * el problema de Git Bash reescribiendo argumentos que parecen rutas absolutas de
 * Unix (`/var/www/html`, etc.) al pasarlos a `docker compose exec`.
 *
 * Uso: node manage.mjs <env|build|up|install|seed|setup|down|reset|status|info|tasks|course|activity|theme|plugins|content>
 */
import { spawnSync } from "node:child_process";
import { existsSync, rmSync, writeFileSync, readFileSync, chmodSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { randomBytes } from "node:crypto";

// Todo es relativo a la carpeta de este script (la raíz del repo).
const moodleDir = path.dirname(fileURLToPath(import.meta.url));
// El checkout de Moodle de antes de la imagen (ver Dockerfile): ya no se usa; `reset` lo borra.
const srcDir = path.join(moodleDir, "src");

/**
 * Los sitios que pueden convivir, uno por serie de Moodle: cada uno con su proyecto de Docker
 * Compose (sus contenedores y volúmenes), su puerto y su .env. El 5.2 es el de siempre y el que
 * usan los agentes por defecto; `--moodle 4.5` en cualquier orden elige el otro.
 */
const SITES = {
  "5.2": { project: "moodle-sandbox", version: "5.2.4", port: "8080", name: "Moodle Sandbox" },
  "4.5": { project: "moodle-sandbox-45", version: "4.5.15", port: "8081", name: "Moodle Sandbox 4.5" },
};

/** Saca `--moodle <serie>` (o `--moodle=<serie>`) de los argumentos y devuelve el sitio. Sin él,
 * MOODLE_SANDBOX_PROJECT (del entorno) elige el sitio por su proyecto; si no es de ninguno, es un
 * sitio más con los valores del 5.2 y su propio .env. */
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
    throw new Error(`No hay sitio para Moodle "${series}": ${Object.keys(SITES).join(", ")}.`);
  }
  const project = process.env.MOODLE_SANDBOX_PROJECT || SITES[series ?? "5.2"].project;
  const preset = SITES[series] ?? Object.values(SITES).find((s) => s.project === project) ?? SITES["5.2"];
  return {
    ...preset,
    project,
    // El de siempre conserva .env; los demás, .env.<proyecto>.
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
const configureScript = path.join(moodleDir, "seed", "configure-plugins.php");

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

/** `docker compose` del sitio elegido: su proyecto y su .env (con lo demás, MOODLE_VERSION y
 * MOODLE_SANDBOX_PORT, en el entorno: ver el final del fichero). */
function composeBase() {
  return ["compose", "-p", site.project, ...(existsSync(envPath) ? ["--env-file", envPath] : [])];
}

function compose(args) {
  run("docker", [...composeBase(), ...args]);
}

/** `docker compose exec webserver <args>` — rutas siempre relativas a /var/www/html. */
function composeExec(args) {
  run("docker", [...composeBase(), "exec", "webserver", ...args]);
}

/** Copia un fichero del repo al código de Moodle del webserver, con ese nombre: las
 * siembras se cargan como `__DIR__ . '/config.php'`, así que van junto a config.php. */
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

/** Puerto del host: MOODLE_SANDBOX_PORT del entorno o del .env del sitio (docker compose
 * también lo lee de ahí); si no, el del sitio (8080 el 5.2, 8081 el 4.5). */
function sandboxPort() {
  return process.env.MOODLE_SANDBOX_PORT || readEnvFile().MOODLE_SANDBOX_PORT || site.port;
}

/** URL del sitio: MOODLE_SANDBOX_URL si se da, si no localhost con el puerto del sandbox. */
function wwwroot() {
  return process.env.MOODLE_SANDBOX_URL || `http://localhost:${sandboxPort()}`;
}

/** Crea .env con contraseñas aleatorias si no existe todavía. Nunca lo sobrescribe. */
function ensureEnv() {
  if (existsSync(envPath)) {
    log(`${path.basename(envPath)} ya existe, no se toca.`);
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
    // best-effort, no-op en Windows
  }
  log(`${path.basename(envPath)} generado con credenciales nuevas en ${envPath}`);
}

/**
 * Construye aquí la imagen del sandbox (ver Dockerfile): Moodle MOODLE_VERSION (5.2.4 por
 * defecto) con sus dependencias de Composer dentro. Normalmente no hace falta: `up` descarga
 * la imagen publicada y solo la construye si no la encuentra. Sirve para probar otra versión
 * de Moodle o un cambio del Dockerfile; luego `up` recrea los contenedores con ella. Los
 * datos (base de datos, moodledata) no se tocan.
 */
function build() {
  log("Construyendo la imagen de Moodle (clona Moodle e instala sus dependencias: unos minutos)...");
  compose(["build", "--pull", "webserver"]);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Levanta los contenedores (construyendo la imagen si falta). Con el sitio ya instalado,
 * lo actualiza al código de la imagen, que puede ser más nuevo tras un `build`. */
async function up() {
  pullImage();
  log("Levantando contenedores (docker compose up -d)...");
  compose(["up", "-d"]);
  log("Esperando a que la base de datos esté healthy...");
  const healthy = await waitHealthy("db");
  log(healthy ? "Base de datos lista." : "Aviso: la base de datos no confirmó estar healthy en 60s, se continúa igualmente.");
  // Jobe tarda unos segundos en atender, y CodeRunner da por caído el servidor hasta entonces.
  log(await waitHealthy("jobe") ? "Jobe listo." : "Aviso: Jobe no confirmó estar healthy en 60s; CodeRunner no corregirá hasta que lo esté.");
  if (healthy && dbHasTables()) installSite();
}

/**
 * Descarga la versión más nueva de la imagen del sitio, si es una publicada: una misma etiqueta
 * (5.2.4) recibe cada cambio del sandbox sobre ese Moodle, y compose solo la descarga si falta. No
 * pisa una construida en el equipo (etiqueta org.falkenslab.sandbox.build=local, ver Dockerfile).
 * Sin conexión, o con una versión sin publicar, sigue con la que haya (o `up` la construye).
 */
function pullImage() {
  const image = `ghcr.io/falkenslab/moodle-sandbox:${site.version}`;
  const build = runCapture("docker", ["image", "inspect", "--format", '{{index .Config.Labels "org.falkenslab.sandbox.build"}}', image]);
  if (build.status === 0 && build.stdout.trim() === "local") {
    log(`La imagen ${image} está construida en este equipo: no se descarga otra.`);
    return;
  }
  log(`Buscando la versión más nueva de ${image}...`);
  const pull = runCapture("docker", ["pull", "--quiet", image]);
  if (pull.status !== 0) log(`Aviso: no se pudo descargar ${image}; se sigue con la que haya.`);
}

/** Espera hasta 60 s a que un servicio con healthcheck esté healthy. */
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

/** Instala el esquema de Moodle en la base de datos si está vacía, o lo actualiza al código
 * de la imagen si ya está instalado: una imagen recién construida trae lo último de su rama,
 * y hasta actualizar Moodle suspende el cron y muestra su aviso de actualización. config.php
 * ya viene en la imagen y lee la contraseña y la URL del entorno (docker/config.php). */
function installSite() {
  if (dbHasTables()) {
    log("El sitio ya está instalado: se actualiza al código de la imagen si hace falta (admin/cli/upgrade.php)...");
    composeExec(["php", "admin/cli/upgrade.php", "--non-interactive"]);
    configurePlugins();
    return;
  }
  const vars = readEnvFile();
  log("Instalando el sitio (admin/cli/install_database.php)...");
  composeExec([
    "php", "admin/cli/install_database.php",
    "--agree-license",
    `--fullname=${vars.MOODLE_SITE_NAME ?? "Moodle Sandbox"}`, "--shortname=sandbox",
    `--adminuser=${vars.MOODLE_ADMIN_USERNAME ?? "admin"}`,
    `--adminpass=${vars.MOODLE_ADMIN_PASSWORD ?? ""}`,
    `--adminemail=${vars.MOODLE_ADMIN_EMAIL ?? "admin@example.com"}`,
  ]);
  log("Sitio instalado.");
  configurePlugins();
}

/** Deja el sitio listo para los plugins de la imagen (seed/configure-plugins.php): CodeRunner
 * apuntando a Jobe, Chat y Encuesta
 * activados y los ajustes de plugins sin valor por defecto guardados. Solo cambia lo que falte. */
function configurePlugins() {
  putCode(configureScript, "configure-plugins.php");
  composeExec(["php", "configure-plugins.php"]);
  // El tema de MOODLE_SANDBOX_THEME (entorno o .env), si se da: como un campus con Classic.
  const wanted = process.env.MOODLE_SANDBOX_THEME || readEnvFile().MOODLE_SANDBOX_THEME;
  if (wanted && siteConfig("theme") !== wanted) theme([wanted]);
}

/** Copia seed/seed-course.php (versionado en el repo) al código de Moodle del contenedor
 * — que se pierde al recrearlo — y lo ejecuta dentro. */
function seed() {
  if (!existsSync(seedScript)) {
    throw new Error(`No se encuentra ${seedScript}`);
  }
  putCode(seedScript, "seed-course.php");
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
 * Ejecuta ya las tareas ad hoc pendientes de Moodle, sin esperar al contenedor de cron (que
 * pasa cada minuto). Las siembras la llaman al terminar: en Moodle 5 algunas son
 * imprescindibles, y sin transfer_question_categories/transfer_questions el banco de
 * preguntas del curso queda bloqueado ("Create a new question" no aparece).
 */
function runAdhocTasks() {
  log("Ejecutando las tareas pendientes de Moodle...");
  composeExec(["php", "admin/cli/adhoc_task.php", "--execute"]);
}

/** Siembra, sobre el curso de pruebas, trabajo para un agente de profesor: contenido
 * coherente, tres alumnos más con entregas de calidad distinta y dudas en el foro. */
/** Añade a .env una variable que falte (sin tocar las demás): .env nunca se reescribe. */
function ensureEnvKey(name, value) {
  if (readEnvFile()[name] !== undefined) return;
  const raw = existsSync(envPath) ? readFileSync(envPath, "utf-8") : "";
  const separator = raw === "" || raw.endsWith("\n") ? "" : "\n";
  writeFileSync(envPath, `${raw}${separator}${name}=${value}\n`, "utf-8");
}

/** Siembra contenido que usa los plugins de la imagen: en el curso de pruebas, un cuestionario con
 * una pregunta de cada tipo de pregunta instalado; y un curso por formato de curso instalado, con
 * el profesor y los alumnos matriculados. Lo que ya existe no se toca. */
function plugins() {
  putCode(pluginsScript, "seed-plugins.php");
  const vars = readEnvFile();
  log("Sembrando contenido de los plugins (cuestionario de tipos de pregunta, un curso por formato)...");
  composeExec([
    "env",
    `MOODLE_TEACHER_USERNAME=${vars.MOODLE_TEACHER_USERNAME ?? "profesor"}`,
    `MOODLE_STUDENT_USERNAME=${vars.MOODLE_STUDENT_USERNAME ?? "alumno"}`,
    "php", "seed-plugins.php",
  ]);
}

/**
 * Crea el curso de contenido que lee la batería de pruebas de moodle-driver ("mcp-content",
 * seed/seed-content.php): una actividad de cada tipo, con entregas, intentos, respuestas, debates,
 * notas y visitas. Crea a los alumnos sembrados si faltan. No hace nada si el curso ya existe.
 */
function content() {
  putCode(contentScript, "seed-content.php");
  // La contraseña conocida de los alumnos sembrados, como en `activity`.
  ensureEnvKey("MOODLE_SEEDED_STUDENTS_PASSWORD", randomPassword());
  const vars = readEnvFile();
  log("Sembrando el curso de contenido de moodle-driver (mcp-content)...");
  composeExec([
    "env",
    `MOODLE_TEACHER_USERNAME=${vars.MOODLE_TEACHER_USERNAME ?? "profesor"}`,
    `MOODLE_STUDENT_USERNAME=${vars.MOODLE_STUDENT_USERNAME ?? "alumno"}`,
    `MOODLE_SEEDED_STUDENTS_PASSWORD=${vars.MOODLE_SEEDED_STUDENTS_PASSWORD}`,
    "php", "seed-content.php",
  ]);
}

function activity() {
  if (!existsSync(activityScript)) {
    throw new Error(`No se encuentra ${activityScript}`);
  }
  putCode(activityScript, "seed-teacher-activity.php");
  log("Sembrando actividad para el profesor (alumnos, entregas y dudas en el foro)...");
  // Una contraseña conocida para los alumnos sembrados, para poder entrar como ellos.
  ensureEnvKey("MOODLE_SEEDED_STUDENTS_PASSWORD", randomPassword());
  composeExec(["env", `MOODLE_SEEDED_STUDENTS_PASSWORD=${readEnvFile().MOODLE_SEEDED_STUDENTS_PASSWORD}`,
    "php", "seed-teacher-activity.php"]);
  // Los cursos de los plugins se siembran antes que estos alumnos: así quedan matriculados también.
  plugins();
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
  putCode(emptyCourseScript, "seed-empty-course.php");
  const vars = readEnvFile();
  const result = runCapture("docker", [
    ...composeBase(), "exec", "-T", "webserver", "env",
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

/** Destructivo: borra contenedores + volúmenes (la base de datos y moodledata) + .env
 * (y src/, el checkout de antes de la imagen, si queda). La imagen se conserva: la
 * reconstruye `build`. Requiere --yes explícito para no borrar nada por accidente. */
function reset(args) {
  if (!args.includes("--yes")) {
    throw new Error(`Esto borra los contenedores, los volúmenes (el sitio ${site.project} entero) y ${path.basename(envPath)}. Repite con --yes si es lo que quieres.`);
  }
  log(`Borrando contenedores y volúmenes de ${site.project}...`);
  compose(["down", "-v"]);
  log(`Borrando ${path.basename(envPath)} (y src/, si queda)...`);
  rmSync(srcDir, { recursive: true, force: true });
  rmSync(envPath, { force: true });
  log("Listo. 'npm run setup' (con el mismo --moodle) para volver a montar el sandbox desde cero.");
}

function status() {
  compose(["ps"]);
}

/** La URL del sitio: la que el contenedor da a config.php (MOODLE_WWWROOT, que
 * docker-compose.yml deriva igual que wwwroot()). */
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

/** Un ajuste del sitio (mdl_config), leído de la base de datos sin arrancar Moodle. */
function siteConfig(name) {
  const result = runCapture("docker", [
    ...composeBase(), "exec", "-T", "db", "psql", "-U", "moodle", "-d", "moodle",
    "-tAc", `select value from mdl_config where name='${name}'`,
  ]);
  return result.stdout?.trim() || undefined;
}

/** Los plugins de docker/plugins.txt que el sitio tiene instalados, con su versión:
 * { "qtype_coderunner": "2026051000", ... }. Uno que la serie de Moodle del sitio no tiene, no está. */
function installedPlugins() {
  const components = [...new Set(readFileSync(path.join(moodleDir, "docker", "plugins.txt"), "utf-8")
    .split(/\r?\n/).filter((line) => line.trim() && !line.startsWith("#")).map((line) => line.trim().split(/\s+/)[1]))];
  const result = runCapture("docker", [
    ...composeBase(), "exec", "-T", "db", "psql", "-U", "moodle", "-d", "moodle", "-tA", "-F", " ",
    "-c", `select plugin, value from mdl_config_plugins where name='version' and plugin in (${components.map((c) => `'${c}'`).join(",")}) order by plugin`,
  ]);
  return Object.fromEntries((result.stdout ?? "").trim().split(/\r?\n/).filter(Boolean).map((line) => line.split(" ")));
}

/** Cambia el tema del sitio (boost, classic, que usan algunos campus, o adaptable, de los plugins de
 * la imagen) y vacía las cachés. La primera página después tarda: compila el CSS del tema. */
function theme(args) {
  const [name] = args;
  if (!["boost", "classic", "adaptable"].includes(name)) throw new Error("Uso: npm run theme -- boost|classic|adaptable [--moodle 4.5]");
  composeExec(["php", "admin/cli/cfg.php", "--name=theme", `--set=${name}`]);
  composeExec(["php", "admin/cli/purge_caches.php"]);
  log(`Tema del sitio: ${name}.`);
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
  if (!existsSync(envPath)) fail("El sandbox no está instalado.");
  if (!isRunning()) fail("El sandbox no está en marcha.");
  if (!dbHasTables()) fail("El sandbox no está instalado.");
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
  console.log(`Moodle:   ${data.moodle.release} · tema ${data.moodle.theme} (proyecto ${data.project})`);
  console.log(`Curso:    ${data.course.shortname} (id ${data.course.id}) → ${data.url}/course/view.php?id=${data.course.id}`);
  console.log(`Admin:    ${data.admin.username} / ${data.admin.password}`);
  console.log(`Profesor: ${data.teacher.username} / ${data.teacher.password}`);
  console.log(`Alumno:   ${data.student.username} / ${data.student.password}`);
  for (const s of data.students) console.log(`Alumno:   ${s.username} / ${s.password}`);
}

async function setup() {
  ensureEnv();
  await up();
  installSite();
  seed();
  // Antes que los plugins: su primera actividad es el módulo 5 de un sitio nuevo (ver seed-content.php).
  content();
  plugins();
  const vars = readEnvFile();
  log("Sandbox listo:");
  log(`  URL: ${wwwroot()}`);
  log(`  Admin:    ${vars.MOODLE_ADMIN_USERNAME} / ${vars.MOODLE_ADMIN_PASSWORD}`);
  log(`  Profesor: ${vars.MOODLE_TEACHER_USERNAME} / ${vars.MOODLE_TEACHER_PASSWORD}`);
  log(`  Alumno:   ${vars.MOODLE_STUDENT_USERNAME} / ${vars.MOODLE_STUDENT_PASSWORD}`);
}

const COMMANDS = { env: ensureEnv, build, up, install: installSite, seed, activity, setup, down, reset, status, info, tasks: runAdhocTasks, course, theme, plugins, content };

const [, , cmd, ...rest] = process.argv;
if (!cmd || !(cmd in COMMANDS)) {
  console.error(`Uso: node manage.mjs <${Object.keys(COMMANDS).join("|")}> [--moodle ${Object.keys(SITES).join("|")}]`);
  process.exit(1);
}

try {
  site = selectSite(rest);
  envPath = site.envPath;
  // Otra versión de Moodle, del entorno o del .env del sitio; si no, la del sitio.
  site.version = process.env.MOODLE_VERSION || readEnvFile().MOODLE_VERSION || site.version;
  // Para docker compose (docker-compose.yml): el proyecto, la imagen y el puerto del sitio. El
  // entorno manda sobre el .env, y sin esto el 4.5 tomaría los valores por defecto (los del 5.2).
  process.env.MOODLE_SANDBOX_PROJECT = site.project;
  process.env.MOODLE_VERSION = site.version;
  process.env.MOODLE_SANDBOX_PORT = sandboxPort();
  await COMMANDS[cmd](rest);
} catch (error) {
  console.error(`[moodle] ${error.message}`);
  process.exit(1);
}
