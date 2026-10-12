# moodle-sandbox

Un Moodle real corriendo en Docker, para probar programas y agentes que trabajan con Moodle sin tocar una institución de verdad. Trae un curso de pruebas con cuentas de profesor y de alumnos ya creadas, y un cuestionario, una tarea y un foro listos para usar desde los dos roles.

Es desechable: se monta con un comando, se puede volver a sembrar y se borra entero con otro.

## Requisitos

- Docker con Docker Compose.
- Node.js 20 o superior (solo para `manage.mjs`; no hay dependencias que instalar).
- Conexión a internet la primera vez: descarga el código de Moodle y las imágenes de Docker.
- Unos 3 GB libres para la imagen de Moodle.

## Empezar

```bash
npm run setup
```

Deja todo listo: descarga la imagen de Moodle, levanta los contenedores, instala el sitio y siembra el curso de pruebas. La primera vez tarda unos minutos, sobre todo en descargar la imagen; las siguientes, segundos. Es seguro volver a ejecutarlo si se interrumpe o falla a mitad: cada paso se salta si ya está hecho.

Al terminar imprime la URL y las credenciales de admin, profesor y alumno, que también están en todo momento en `.env` y en `npm run info`. El sitio queda en <http://localhost:8080>.

Para trabajar como profesor sobre un aula con entregas que corregir, siembra además el trabajo del profesor:

```bash
npm run activity
```

## Comandos

| Comando | Qué hace |
| --- | --- |
| `npm run setup` | Lo deja todo listo desde cero, o completa lo que falte |
| `npm run up` | Levanta los contenedores (descarga la imagen si falta) y actualiza Moodle al código de la imagen |
| `npm run down` | Para los contenedores sin borrar nada |
| `npm run status` | Estado de los contenedores |
| `npm run info` | URL, id del curso de pruebas y credenciales; con `-- --json`, para programas y agentes |
| `npm run seed` | Vuelve a sembrar el curso de pruebas (no hace nada si ya existe) |
| `npm run activity` | Añade trabajo para un agente de profesor: alumnos, entregas y dudas en el foro |
| `npm run course -- <nombre-corto> ["Nombre completo"] [--json]` | Crea un curso vacío con el profesor y los alumnos matriculados |
| `npm run tasks` | Ejecuta ya las tareas pendientes de Moodle, sin esperar al cron |
| `npm run build` | Construye la imagen aquí en vez de descargarla (otra versión de Moodle, un cambio del `Dockerfile`); después, `npm run up` |
| `npm run reset -- --yes` | Borra el sitio entero (contenedores, base de datos, ficheros y `.env`) para empezar de cero |

`reset` es destructivo: pide `--yes` a propósito para no borrar nada sin querer. La imagen se conserva, así que el siguiente `setup` no la vuelve a descargar.

## Configuración

Variables de entorno opcionales, que también se pueden poner en `.env`:

| Variable | Por defecto | Para qué |
| --- | --- | --- |
| `MOODLE_VERSION` | `5.2.4` | Versión de Moodle de la imagen |
| `MOODLE_SANDBOX_PORT` | `8080` | Puerto del sitio |
| `MOODLE_SANDBOX_URL` | `http://localhost:<puerto>` | URL completa del sitio |

Si el 8080 ya está ocupado (por ejemplo, por otro Moodle), elige otro puerto, mejor antes de la primera instalación, porque los enlaces que Moodle guarda en los contenidos conservan el puerto con el que se crearon:

```bash
MOODLE_SANDBOX_PORT=8081 npm run setup
```

El puerto se guarda en `.env`, así que los demás comandos ya lo usan sin repetirlo.

## Cómo funciona

Tres contenedores, en el proyecto de Docker `moodle-sandbox`:

- **`webserver`**: Moodle con Apache, desde la imagen del sandbox (`ghcr.io/falkenslab/moodle-sandbox:<versión de Moodle>`).
- **`cron`**: la misma imagen ejecutando el cron de Moodle cada minuto, como en un sitio de verdad. Gracias a él funcionan solas las copias de seguridad, las copias de cursos, las restauraciones y el envío de notificaciones.
- **`db`**: PostgreSQL 17.

**El código de Moodle va dentro de la imagen** (`Dockerfile`): al construirla se clona una versión publicada de Moodle (una etiqueta de su repositorio, no una rama, para que todos tengan el mismo Moodle) y se instalan sus dependencias de Composer, con las de desarrollo, que las siembras necesitan. La imagen está publicada en el registro de GitHub para amd64 y arm64; `npm run up` la descarga, y solo si no la encuentra la construye en el equipo con el `Dockerfile`. Antes el código se montaba desde una carpeta del equipo, y en Windows eso hacía cada página varias veces más lenta: leer los ~58.000 ficheros PHP de Moodle a través de Docker Desktop costaba segundos por petición. Con el código dentro de la imagen y la caché de PHP dimensionada para él (`php/zz-sandbox.ini`), una página del curso pasa de unos 5 s a 0,13 s.

**El sitio vive en dos volúmenes de Docker**: la base de datos (`db_data`) y los ficheros de Moodle (`moodledata_data`). `config.php` va en la imagen y lee la contraseña de la base de datos y la URL del entorno (`docker/config.php`), así que cambiar de imagen no toca los datos.

**PostgreSQL está ajustado para un sitio desechable**: no espera a que cada escritura llegue al disco (`fsync=off`, `synchronous_commit=off`), lo que hace las escrituras mucho más rápidas. A cambio, un corte de luz o un apagado brusco de Docker puede dejar la base de datos inservible. Si después de uno el sitio no arranca o Moodle da errores de base de datos, vuelve a montarlo: `npm run reset -- --yes`, `npm run setup` y, si lo usabas, `npm run activity`.

### Cambiar de versión de Moodle

Con otra `MOODLE_VERSION` (en el entorno o en `.env`), `npm run up` descarga esa imagen, o la construye si no está publicada, recrea los contenedores con ella y actualiza la base de datos (`admin/cli/upgrade.php`). Moodle no deja volver a una versión anterior a la instalada: para eso hay que empezar de cero (`npm run reset -- --yes` y `npm run setup`).

### Publicar una versión de la imagen

La versión por defecto está en `docker-compose.yml` (`MOODLE_VERSION`) y en el `Dockerfile`. Para pasar a otra versión de Moodle, cámbiala en los dos, pruébala con `npm run build` y `npm run up`, y crea la etiqueta del repositorio con esa versión:

```bash
git tag v5.2.5 && git push origin v5.2.5
```

El workflow `Imagen` (`.github/workflows/image.yml`) la construye para amd64 y arm64 y la publica como `5.2.5`, y también como `5.2` (la última de la serie). Si cambia el sandbox sin cambiar Moodle (la configuración de PHP, `config.php`), la etiqueta lleva un sufijo, `v5.2.5-2`, que actualiza también `5.2.5`.

### Si venías de la versión anterior

Las versiones anteriores del sandbox clonaban Moodle en la carpeta `src/` y la montaban en el contenedor. Para pasar a la imagen basta con `git pull` y `npm run up`: construye la imagen (unos minutos), actualiza Moodle al código de la imagen y arranca el cron. Los datos son los mismos. Después, la carpeta `src/` ya no se usa y se puede borrar.

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

### Cursos vacíos para construir

`npm run course -- docker-intro "Introducción a Docker"` crea un curso vacío con el profesor (como `editingteacher`) y los alumnos sembrados matriculados, para que un agente de profesor lo construya entero. Si ya existe un curso con ese nombre corto no lo toca y devuelve su id. Con `--json` imprime solo `{ id, shortname, fullname, url, created }`.

## Usarlo desde un programa o un agente

`npm run info -- --json` es el contrato: imprime solo este JSON y sale con error si el sandbox no está instalado, no está en marcha o falta el curso.

```json
{ "url": "http://localhost:8080", "running": true,
  "course": { "id": 2, "shortname": "sandbox-course" },
  "admin":   { "username": "admin",    "password": "..." },
  "teacher": { "username": "profesor", "password": "..." },
  "student": { "username": "alumno",   "password": "..." },
  "students": [ { "username": "lucia.martin", "password": "..." }, ... ] }
```

Un programa debería depender solo de este comando, no de `.env` ni de la base de datos. La forma del JSON se mantiene estable: se añaden campos, no se renombran.

## Estructura

```
├── manage.mjs           # el script detrás de los comandos npm
├── Dockerfile           # la imagen: Moodle con su código y dependencias dentro
├── docker/config.php    # el config.php de la imagen (lee la contraseña y la URL del entorno)
├── php/zz-sandbox.ini   # PHP ajustado para Moodle (la caché de código)
├── docker-compose.yml   # Postgres, Moodle y su cron (proyecto Docker "moodle-sandbox")
├── .github/workflows/image.yml  # publica la imagen al crear una etiqueta v<versión>
├── seed/
│   ├── seed-course.php            # crea el curso, el profesor, el alumno y las actividades
│   ├── seed-teacher-activity.php  # alumnos, entregas y dudas para un agente de profesor
│   └── seed-empty-course.php      # curso vacío para construir (npm run course)
└── .env                 # credenciales generadas (se crea solo, no se sube al repo)
```
