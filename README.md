# moodle-sandbox

Un Moodle real corriendo en Docker, para probar programas y agentes que trabajan con Moodle sin tocar una institución de verdad. Trae un curso de pruebas con cuentas de profesor y de alumnos ya creadas, y un cuestionario, una tarea y un foro listos para usar desde los dos roles.

Es desechable: se monta con un comando, se puede volver a sembrar y se borra entero con otro.

## Requisitos

- Docker con Docker Compose.
- Node.js 20 o superior (solo para `manage.mjs`; no hay dependencias que instalar).
- Conexión a internet la primera vez: descarga la imagen de Moodle y la de PostgreSQL.
- Unos 3 GB libres para la imagen de Moodle.

No hace falta cuenta de GitHub ni construir nada: la imagen de Moodle es pública.

## Empezar

```bash
npm run setup
```

Deja todo listo: descarga la imagen de Moodle, levanta los contenedores, instala el sitio y siembra el curso de pruebas, el curso de contenido de moodle-driver y el contenido de los plugins. La primera vez tarda unos minutos, sobre todo en descargar la imagen (2,4 GB; alrededor de un minuto con una buena conexión); las siguientes, segundos. Es seguro volver a ejecutarlo si se interrumpe o falla a mitad: cada paso se salta si ya está hecho.

Al terminar imprime la URL y las credenciales de admin, profesor y alumno, que también están en todo momento en `.env` y en `npm run info`. El sitio queda en <http://localhost:8080>.

Para trabajar como profesor sobre un aula con entregas que corregir, siembra además el trabajo del profesor:

```bash
npm run activity
```

## Comandos

| Comando | Qué hace |
| --- | --- |
| `npm run setup` | Lo deja todo listo desde cero, o completa lo que falte |
| `npm run up` | Descarga la versión más nueva de la imagen, levanta los contenedores y actualiza Moodle (y sus plugins) al código de la imagen |
| `npm run down` | Para los contenedores sin borrar nada |
| `npm run status` | Estado de los contenedores |
| `npm run info` | URL, id del curso de pruebas y credenciales; con `-- --json`, para programas y agentes |
| `npm run seed` | Vuelve a sembrar el curso de pruebas (no hace nada si ya existe) |
| `npm run activity` | Añade trabajo para un agente de profesor: alumnos, entregas y dudas en el foro |
| `npm run course -- <nombre-corto> ["Nombre completo"] [--json]` | Crea un curso vacío con el profesor y los alumnos matriculados |
| `npm run tasks` | Ejecuta ya las tareas pendientes de Moodle, sin esperar al cron |
| `npm run content` | Siembra el curso de contenido que lee la batería de moodle-driver (`mcp-content`); `setup` ya lo hace |
| `npm run plugins` | Siembra contenido que usa los plugins: un cuestionario con sus tipos de pregunta, un curso por formato y uno con sus actividades y bloques (ver [Plugins](#plugins)); `setup` ya lo hace |
| `npm run theme -- classic\|boost\|adaptable` | Cambia el tema del sitio (Boost por defecto; algunos campus usan Classic) y vacía las cachés |
| `npm run build` | Construye la imagen aquí en vez de descargarla (otra versión de Moodle, un cambio del `Dockerfile`); después, `npm run up` |
| `npm run reset -- --yes` | Borra el sitio entero (contenedores, base de datos, ficheros y `.env`) para empezar de cero |

`reset` es destructivo: pide `--yes` a propósito para no borrar nada sin querer. La imagen se conserva, así que el siguiente `setup` no la vuelve a descargar.

## Configuración

Variables de entorno opcionales, que también se pueden poner en `.env`:

| Variable | Por defecto | Para qué |
| --- | --- | --- |
| `MOODLE_VERSION` | `5.2.4` | Versión de Moodle de la imagen (`4.5.15` en el sitio de 4.5) |
| `MOODLE_SANDBOX_PORT` | `8080` | Puerto del sitio (`8081` en el de 4.5) |
| `MOODLE_SANDBOX_THEME` | (el de Moodle, Boost) | Tema que `setup` y `up` dejan en el sitio: `classic`, como el campus, `boost` o `adaptable` |
| `MOODLE_SANDBOX_PROJECT` | `moodle-sandbox` | Proyecto de Docker del sitio, y con él sus volúmenes y su `.env` (solo del entorno; ver [Moodle 4.5 al lado del 5.2](#moodle-45-al-lado-del-52)) |
| `MOODLE_SANDBOX_URL` | `http://localhost:<puerto>` | URL completa del sitio |

Si el 8080 ya está ocupado (por ejemplo, por otro Moodle), elige otro puerto, mejor antes de la primera instalación, porque los enlaces que Moodle guarda en los contenidos conservan el puerto con el que se crearon:

```bash
MOODLE_SANDBOX_PORT=8081 npm run setup
```

El puerto se guarda en `.env`, así que los demás comandos ya lo usan sin repetirlo.

## Moodle 4.5 al lado del 5.2

Además del sitio de Moodle 5.2, puede correr a la vez un Moodle 4.5, la versión de soporte extendido (hasta 2027) que usan muchos centros y campus, para probar un programa contra las dos. Cualquier comando con `--moodle 4.5` trabaja con ese sitio:

```bash
npm run setup -- --moodle 4.5
npm run activity -- --moodle 4.5
npm run info -- --moodle 4.5 --json
```

| Sitio | Moodle | URL | Proyecto de Docker | Credenciales |
| --- | --- | --- | --- | --- |
| Por defecto | 5.2.4 | <http://localhost:8080> | `moodle-sandbox` | `.env` |
| `--moodle 4.5` | 4.5.15 | <http://localhost:8081> | `moodle-sandbox-45` | `.env.moodle-sandbox-45` |

Cada uno tiene sus contenedores, sus volúmenes (su base de datos y sus ficheros) y sus contraseñas, así que se montan, se paran y se borran por separado: `npm run reset -- --moodle 4.5 --yes` borra solo el de 4.5. Sin `--moodle`, todo sigue como siempre: los agentes reciben el de 5.2. El curso de pruebas, el trabajo del profesor y `info` son los mismos en los dos; `info` dice cuál es en `moodle` y `project`.

La imagen de 4.5 es la misma receta (`Dockerfile`) con otra versión de Moodle: PHP 8.3, que 4.5 también admite, y `mod_questionnaire` en la versión hecha para 4.4 y 4.5. En 4.5 el código de Moodle no tiene la carpeta `public/` de 5.1 en adelante; la imagen base sirve la que haya.

`MOODLE_SANDBOX_PROJECT` (en el entorno) elige un sitio por su proyecto de Docker, y uno con otro nombre es un sitio más, con sus propios volúmenes y su `.env.<proyecto>`; con `MOODLE_VERSION` y `MOODLE_SANDBOX_PORT` puede llevar cualquier otra versión en otro puerto.

## Cómo funciona

Cuatro contenedores, en el proyecto de Docker `moodle-sandbox`:

- **`webserver`**: Moodle con Apache, desde la imagen del sandbox (`ghcr.io/falkenslab/moodle-sandbox:<versión de Moodle>`).
- **`cron`**: la misma imagen ejecutando el cron de Moodle cada minuto, como en un sitio de verdad. Gracias a él funcionan solas las copias de seguridad, las copias de cursos, las restauraciones y el envío de notificaciones.
- **`db`**: PostgreSQL 17.
- **`jobe`**: [Jobe](https://github.com/trampgeek/jobe), el servidor donde CodeRunner ejecuta el código de las respuestas. Solo lo usa Moodle: no tiene puerto en el equipo.

**El código de Moodle va dentro de la imagen** (`Dockerfile`): al construirla se clona una versión publicada de Moodle (una etiqueta de su repositorio, no una rama, para que todos tengan el mismo Moodle) y se instalan sus dependencias de Composer, con las de desarrollo, que las siembras necesitan. La imagen está publicada y es pública en el registro de GitHub, para amd64 y arm64 (también Mac con chip M): [ghcr.io/falkenslab/moodle-sandbox](https://github.com/falkenslab/moodle-sandbox/pkgs/container/moodle-sandbox). `npm run up` la descarga; solo si no puede (sin acceso a GitHub, detrás de un proxy, o con una `MOODLE_VERSION` sin publicar) la construye en el equipo con el `Dockerfile`, en unos 6 minutos. Antes el código se montaba desde una carpeta del equipo, y en Windows eso hacía cada página varias veces más lenta: leer los ~58.000 ficheros PHP de Moodle a través de Docker Desktop costaba segundos por petición. Con el código dentro de la imagen y la caché de PHP dimensionada para él (`php/zz-sandbox.ini`), una página del curso pasa de unos 5 s a 0,13 s.

**El sitio vive en dos volúmenes de Docker**: la base de datos (`db_data`) y los ficheros de Moodle (`moodledata_data`). `config.php` va en la imagen y lee la contraseña de la base de datos y la URL del entorno (`docker/config.php`), así que cambiar de imagen no toca los datos.

**PostgreSQL está ajustado para un sitio desechable**: no espera a que cada escritura llegue al disco (`fsync=off`, `synchronous_commit=off`), lo que hace las escrituras mucho más rápidas. A cambio, un corte de luz o un apagado brusco de Docker puede dejar la base de datos inservible. Si después de uno el sitio no arranca o Moodle da errores de base de datos, vuelve a montarlo: `npm run reset -- --yes`, `npm run setup` y, si lo usabas, `npm run activity`.

### Actualizar el sandbox

Cada cambio del sandbox sobre una misma versión de Moodle (plugins nuevos, otra configuración) se publica con la misma etiqueta de imagen (`5.2.4`). `npm run up` busca siempre la versión más nueva de la imagen publicada y, si hay una, recrea los contenedores con ella y actualiza el sitio: Moodle instala los plugins nuevos, `seed/configure-plugins.php` los deja listos. Después, `npm run plugins` siembra el contenido de los plugins nuevos (lo que ya está no lo toca). Sin conexión sigue con la imagen que tenga. Una imagen construida en el equipo (`npm run build`, que la marca como `local`) no se sustituye: para volver a la publicada, bórrala (`docker image rm ghcr.io/falkenslab/moodle-sandbox:5.2.4`) y `npm run up`.

### Cambiar de versión de Moodle

Con otra `MOODLE_VERSION` (en el entorno o en `.env`), `npm run up` descarga esa imagen, o la construye si no está publicada, recrea los contenedores con ella y actualiza la base de datos (`admin/cli/upgrade.php`). Moodle no deja volver a una versión anterior a la instalada: para eso hay que empezar de cero (`npm run reset -- --yes` y `npm run setup`).

### Publicar una versión de la imagen

La versión por defecto está en `docker-compose.yml` (`MOODLE_VERSION`) y en el `Dockerfile`. Para pasar a otra versión de Moodle, cámbiala en los dos, pruébala con `npm run build` y `npm run up`, y crea la etiqueta del repositorio con esa versión:

```bash
git tag v5.2.5 && git push origin v5.2.5
```

El workflow `Imagen` (`.github/workflows/image.yml`) la construye para amd64 y arm64 y la publica como `5.2.5`, y también como `5.2` (la última de la serie). Si cambia el sandbox sin cambiar Moodle (la configuración de PHP, `config.php`), la etiqueta lleva un sufijo, `v5.2.5-2`, que actualiza también `5.2.5`.

La de Moodle 4.5 se publica igual, con su etiqueta (`git tag v4.5.15`), desde el mismo `Dockerfile`; su versión por defecto está en `SITES` de `manage.mjs`. Un cambio del `Dockerfile` que afecte a las dos necesita una etiqueta para cada una.

Las versiones nuevas se publican en el mismo paquete, que ya es público, así que no hay que hacer nada más. Solo un paquete nuevo de la organización nace privado: para que pueda ser público, la organización tiene que permitirlo (*Organization settings* → *Packages* → *Package creation* → *Public*) y hay que cambiar su visibilidad una vez en los ajustes del paquete.

### Si venías de la versión anterior

Las versiones anteriores del sandbox clonaban Moodle en la carpeta `src/` y la montaban en el contenedor. Para pasar a la imagen basta con `git pull` y `npm run up`: descarga la imagen, actualiza Moodle al código de la imagen y arranca el cron. Los datos son los mismos. Después, la carpeta `src/` ya no se usa y se puede borrar.

## El curso de pruebas

`seed/seed-course.php` crea el curso `sandbox-course` con:

- un profesor (`profesor`) y un alumno (`alumno`) matriculados, con contraseñas aleatorias guardadas en `.env`;
- una página, un cuestionario con preguntas, una tarea y un foro.

### Trabajo para un agente de profesor

`npm run activity` (`seed/seed-teacher-activity.php`) convierte ese curso en un aula experimental con trabajo real que corregir y atender, en torno a un tema de introducción a la programación (variables y tipos de datos):

- contenido coherente en la página, la tarea (con sus criterios de evaluación sobre 10) y el foro;
- tres alumnos más (Lucía Martín, Marcos López y Sara Gil) que han **enviado** la tarea: una entrega completa, una floja y otra con un error conceptual; `alumno` no entrega nada;
- dos hilos en el foro: una duda sin responder y otra que un compañero ha contestado mal.

Los tres comparten una contraseña que se guarda en `.env` (`MOODLE_SEEDED_STUDENTS_PASSWORD`) y aparece en `npm run info`, para poder entrar como cualquiera de ellos. Se puede ejecutar más de una vez: si ya está sembrada, solo repara lo que falte (entregas en borrador, contraseñas).

### El curso de contenido de moodle-driver

`npm run content` (`seed/seed-content.php`, que `setup` ya ejecuta) crea `mcp-content`, «Contenido para moodle-mcp»: el curso que lee la batería de pruebas de moodle-driver sin escribir nunca en él. Tiene una actividad de cada tipo y datos en ellas:

- **Personas**: el profesor y cuatro alumnos (`alumno` y los tres sembrados, que crea si no existen), y un grupo.
- **Tarea MCP**: con la rúbrica «Rúbrica MCP», entregada por Lucía y Marcos y calificada con ella.
- **Cuestionario MCP**: seis preguntas con intentos terminados de tres alumnos; otro cuestionario sin intentos.
- **Encuestas**: un feedback anónimo (con una pregunta de varias opciones respondida con varias) y un questionnaire con un tipo de pregunta de cada, los dos respondidos por tres alumnos.
- **Foros**: uno de presentación y «Foro MCP», con ocho debates y sus respuestas.
- **El resto**: página, libro, enlace, etiqueta, fichero, carpeta, H5P, lección, consulta respondida, dos SCORM y, en 5.x, un banco de preguntas.
- **Visitas**: de los alumnos y el profesor, una hora antes (los informes de actividad y de participación tienen datos).

La batería encuentra el curso por su nombre corto y la tarea por su nombre, así que es el mismo en las dos series aunque cambien los ids. Si el curso ya existe, no lo toca.

### Cursos vacíos para construir

`npm run course -- docker-intro "Introducción a Docker"` crea un curso vacío con el profesor (como `editingteacher`) y los alumnos sembrados matriculados, para que un agente de profesor lo construya entero. Si ya existe un curso con ese nombre corto no lo toca y devuelve su id. Con `--json` imprime solo `{ id, shortname, fullname, url, created }`.

## Plugins

Un campus de verdad tiene plugins que Moodle no trae, y un programa que trabaja con Moodle tiene que entenderse con ellos. La imagen instala los de un campus real de Moodle 4.5 (falkenslab/moodle-sandbox#4), cada uno en la versión hecha para la serie de Moodle de la imagen. La lista, con la versión exacta de cada uno y por qué se eligió, está en `docker/plugins.txt`; `npm run info -- --json` dice cuáles tiene el sitio (`plugins`).

- **Actividades**: `mod_attendance`, `mod_board`, `mod_checklist`, `mod_choicegroup`, `mod_collabora`, `mod_evoting`, `mod_game`, `mod_hotpot`, `mod_hvp` (H5P), `mod_jitsi`, `mod_journal`, `mod_lightboxgallery`, `mod_offlinequiz`, `mod_publication` y `mod_questionnaire`; y `mod_chat` y `mod_survey`, que vienen con Moodle 4.5 y salieron de él en 5.0 (en 5.2 se instalan aparte). Moodle trae los dos desactivados; el sandbox los activa.
- **Tipos de pregunta**: `qtype_coderunner` (con `qbehaviour_adaptive_adapted_for_coderunner`), que corrige el código de las respuestas ejecutándolo contra sus casos de prueba en el contenedor `jobe`; `qtype_formulas` (con `qbehaviour_adaptivemultipart`), `qtype_gapfill` y `qtype_ddmatch`.
- **Bloques**: `block_completion_progress`, `block_configurable_reports`, `block_grade_me`, `block_quickmail` y `block_xp`.
- **Formatos de curso**: `format_grid`, `format_topcoll` (Collapsed Topics), `format_onetopic` y `format_tiles`.
- **Informes, editor y tema**: `report_coursesize`, `report_benchmark`, `tiny_fontcolor` (color de letra en TinyMCE) y `theme_adaptable`. El campus usa el tema Classic, que viene con Moodle: `npm run theme -- classic` (o `adaptable`, o `boost`, el de siempre).

**Lo que necesita un servicio de fuera** se instala sin él: las actividades de Collabora y de Jitsi se crean, pero no abren documentos ni videollamadas. H5P se instala sin hablar con su Hub: `docker-compose.yml` apunta el Hub a `127.0.0.1` en los contenedores de Moodle (si no, H5P registra el sitio al instalarse) y el `config.php` de la imagen apaga su consulta diaria y sus estadísticas de uso. Por eso no descarga tipos de contenido.

**En 5.2**, varios no tienen versión para 5.2 y llevan la más nueva que hay: `format_grid`, `mod_hotpot` (de 5.0), `format_onetopic`, `format_tiles`, `mod_choicegroup`, `mod_lightboxgallery`, `report_benchmark` (de 5.1), `block_grade_me`, `block_quickmail` y `report_coursesize` (de 4.5). Todos se instalan en 5.2 y sus páginas cargan sin errores. Lo único que falla: Onetopic y Tiles no abren sus ventanas emergentes (la ayuda de los iconos, las actividades configuradas para abrirse en una), porque usan un módulo de Moodle (`core/modal_factory`) que 5.2 ya no tiene. Ninguno se queda fuera de 5.2.

`npm run plugins` (`seed/seed-plugins.php`, que `setup` ya ejecuta) siembra contenido que los usa:

- en el curso de pruebas, el cuestionario «Cuestionario de plugins», con una pregunta de cada tipo de pregunta (las de ejemplo de cada plugin);
- un curso por formato (`formato-grid`, `formato-topcoll`, `formato-onetopic`, `formato-tiles`), con cuatro secciones con nombre, apuntes y una tarea en cada una, y un foro;
- el curso `plugins-actividades`, con una actividad de cada módulo y los cinco bloques. `mod_evoting`, `mod_hotpot`, `mod_hvp` y, en 4.5, `mod_offlinequiz` no tienen actividad: sus plugins no traen con qué crearla sin contenido de verdad (un paquete H5P, un fichero de Hot Potatoes…).

Los cursos tienen matriculados al profesor y a los alumnos sembrados. Lo que ya existe no lo vuelve a crear, así que se puede ejecutar de nuevo después de actualizar la imagen.

Cada vez que instala o actualiza el sitio, `manage.mjs` ejecuta `seed/configure-plugins.php`, que deja el sitio listo para estos plugins: CodeRunner apuntando a `http://jobe`, Chat y Encuesta activados, y los ajustes de plugins que no traen valor por defecto guardados (si no, Moodle manda al administrador a la página de ajustes nuevos). Para que Moodle pueda llegar a `jobe`, el `config.php` de la imagen quita las redes privadas de las direcciones que Moodle no deja consultar (`curlsecurityblockedhosts`): Docker pone sus contenedores en una. La imagen de Jobe solo existe para amd64: en un Mac con chip M funciona emulada, más despacio.

## Usarlo desde un programa o un agente

`npm run info -- --json` es el contrato: imprime solo este JSON y sale con error si el sandbox no está instalado, no está en marcha o falta el curso.

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

`plugins` son los de `docker/plugins.txt` que el sitio tiene instalados, con su versión: una batería de pruebas puede saltarse lo que una serie de Moodle no tenga. Con `--moodle 4.5`, el mismo JSON del sitio de 4.5.

Un programa debería depender solo de este comando, no de `.env` ni de la base de datos. La forma del JSON se mantiene estable: se añaden campos, no se renombran.

## Estructura

```
├── manage.mjs           # el script detrás de los comandos npm
├── Dockerfile           # la imagen: Moodle con su código y dependencias dentro
├── docker/config.php    # el config.php de la imagen (lee la contraseña y la URL del entorno)
├── docker/plugins.txt   # los plugins de la imagen, con su versión para cada serie de Moodle
├── docker/install-plugins.sh    # los instala al construir la imagen
├── php/zz-sandbox.ini   # PHP ajustado para Moodle (la caché de código)
├── docker-compose.yml   # Postgres, Moodle, su cron y Jobe (proyecto Docker "moodle-sandbox")
├── .github/workflows/image.yml  # publica la imagen al crear una etiqueta v<versión>
├── seed/
│   ├── seed-course.php            # crea el curso, el profesor, el alumno y las actividades
│   ├── seed-teacher-activity.php  # alumnos, entregas y dudas para un agente de profesor
│   ├── seed-empty-course.php      # curso vacío para construir (npm run course)
│   ├── seed-content.php           # el curso de contenido de moodle-driver (npm run content)
│   ├── seed-plugins.php           # contenido de los plugins: cuestionario, cursos por formato y de actividades (npm run plugins)
│   └── configure-plugins.php      # deja el sitio listo para los plugins (tras instalar o actualizar)
├── .env                 # credenciales generadas (se crea solo, no se sube al repo)
└── .env.moodle-sandbox-45       # las del sitio de Moodle 4.5, si lo hay
```
