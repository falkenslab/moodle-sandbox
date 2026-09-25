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
npm run reset -- --yes   # borra todo (contenedores, datos y código de Moodle) para empezar de cero
```

`reset` es destructivo: pide `--yes` a propósito para no borrar nada sin querer.

Variables de entorno opcionales: `MOODLE_BRANCH` (rama de Moodle a clonar, por defecto
`MOODLE_502_STABLE`) y `MOODLE_SANDBOX_URL` (URL del sitio, por defecto
`http://localhost:8080`).

## El curso de pruebas

`seed/seed-course.php` crea el curso `sandbox-course` con:

- un profesor (`profesor`) y un alumno (`alumno`) matriculados, con contraseñas aleatorias
  guardadas en `.env`;
- una página, un cuestionario con preguntas, una tarea y un foro.

Para usarlo desde un agente, apunta su configuración a `http://localhost:8080`, el ID del
curso que muestra Moodle y las credenciales del rol correspondiente de `.env`.

## Estructura

```
├── manage.mjs           # el script detrás de los comandos npm
├── docker-compose.yml   # Postgres + Moodle (proyecto Docker "moodle-sandbox")
├── seed/
│   └── seed-course.php  # crea el curso, el profesor, el alumno y las actividades
├── .env                 # credenciales generadas (se crea solo, no se sube al repo)
└── src/                 # código de Moodle descargado (se crea solo, no se sube al repo)
```
