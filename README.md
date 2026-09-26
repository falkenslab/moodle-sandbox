# moodle-sandbox

Un Moodle real corriendo en Docker, para probar agentes que trabajan con Moodle sin tocar
una institución de verdad. Incluye un curso de pruebas con cuentas de profesor y de alumno
ya creadas, y un cuestionario, una tarea y un foro listos para usar desde los dos roles.

## Requisitos

- Docker con Docker Compose.
- Node.js 20 o superior (solo para `manage.mjs`; no hay dependencias que instalar).
- Conexión a internet la primera vez (descarga el código de Moodle y las imágenes de Docker).

## Uso

```bash
npm run setup     # deja todo listo: descarga, levanta e instala el sitio y el curso de pruebas
```

La primera vez tarda bastante (puede ser media hora o más, sobre todo en Windows). Es
seguro volver a ejecutarlo si se interrumpe o falla a mitad: cada paso se salta solo si ya
está hecho.

Al terminar, imprime la URL y las credenciales de admin, profesor y alumno, que también
están en todo momento en `.env`.

```
http://localhost:8080
```

### Resto de comandos

```bash
npm run up            # levanta los contenedores si ya estaban instalados
npm run down          # los para (sin borrar nada)
npm run status        # estado de los contenedores
npm run seed          # vuelve a sembrar el curso de pruebas (no hace nada si ya existe)
npm run activity      # añade trabajo para un agente de profesor (ver más abajo)
npm run info          # URL, id del curso y credenciales (con -- --json, para agentes)
npm run tasks         # ejecuta las tareas pendientes de Moodle (el sandbox no tiene cron)
npm run reset -- --yes   # borra todo (contenedores, datos y código de Moodle) para empezar de cero
```

`reset` es destructivo: pide `--yes` a propósito para no borrar nada sin querer.

Variables de entorno opcionales: `MOODLE_BRANCH` (rama de Moodle a clonar, por defecto
`MOODLE_502_STABLE`), `MOODLE_SANDBOX_PORT` (puerto del sitio, por defecto `8080`) y
`MOODLE_SANDBOX_URL` (URL completa del sitio, por defecto `http://localhost:<puerto>`).

Si el 8080 ya está ocupado (por ejemplo, por otro Moodle), elige otro puerto **antes** de la
primera instalación, porque la URL queda fijada al instalar:

```bash
MOODLE_SANDBOX_PORT=8081 npm run setup
```

El puerto se guarda en `.env`, así que los demás comandos ya lo usan sin repetirlo.

## El curso de pruebas

`seed/seed-course.php` crea el curso `sandbox-course` con:

- un profesor (`profesor`) y un alumno (`alumno`) matriculados, con contraseñas aleatorias
  guardadas en `.env`;
- una página, un cuestionario con preguntas, una tarea y un foro.

### Trabajo para un agente de profesor

`npm run activity` (`seed/seed-teacher-activity.php`) convierte ese curso en un aula
experimental con trabajo real que corregir y atender, en torno a un tema de introducción a la
programación (variables y tipos de datos):

- contenido coherente en la página, la tarea (con sus criterios de evaluación sobre 10) y el
  foro;
- tres alumnos más (Lucía Martín, Marcos López y Sara Gil) que han **enviado** la tarea: una
  entrega completa, una floja y otra con un error conceptual; `alumno` no entrega nada;
- dos hilos en el foro: una duda sin responder y otra que un compañero ha contestado mal.

Se puede ejecutar más de una vez: si ya está sembrada, no hace nada.

### Usarlo desde un agente

`npm run info -- --json` es el contrato para los agentes: imprime solo este JSON y sale con
error si el sandbox no está instalado, no está en marcha o falta el curso.

```json
{ "url": "http://localhost:8080", "running": true,
  "course": { "id": 2, "shortname": "sandbox-course" },
  "admin":   { "username": "admin",    "password": "..." },
  "teacher": { "username": "profesor", "password": "..." },
  "student": { "username": "alumno",   "password": "..." } }
```

Un agente debería depender solo de este comando, no de `.env` ni de la base de datos.

## Estructura

```
├── manage.mjs           # el script detrás de los comandos npm
├── docker-compose.yml   # Postgres + Moodle (proyecto Docker "moodle-sandbox")
├── seed/
│   ├── seed-course.php  # crea el curso, el profesor, el alumno y las actividades
│   └── seed-teacher-activity.php  # alumnos, entregas y dudas para un agente de profesor
├── .env                 # credenciales generadas (se crea solo, no se sube al repo)
└── src/                 # código de Moodle descargado (se crea solo, no se sube al repo)
```
