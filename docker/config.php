<?php  // Moodle configuration file

// El config.php del sandbox, dentro de su imagen (ver Dockerfile): la contraseña de la base de
// datos y la URL vienen del entorno del contenedor (docker-compose.yml las toma de .env), así
// que la misma imagen sirve a cualquier instalación.

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'db';
$CFG->dbname    = 'moodle';
$CFG->dbuser    = 'moodle';
$CFG->dbpass    = getenv('MOODLE_DB_PASSWORD') ?: '';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array (
  'dbpersist' => 0,
  'dbport' => 5432,
  'dbsocket' => '',
);

$CFG->wwwroot   = getenv('MOODLE_WWWROOT') ?: 'http://localhost:8080';
$CFG->dataroot  = '/var/www/moodledata';
$CFG->admin     = 'admin';

$CFG->directorypermissions = 02777;

// Las direcciones que Moodle no deja pedir, fijadas aquí para que valgan ya durante la instalación:
// sin las redes privadas de la lista de Moodle, porque los contenedores del sandbox (jobe, para
// CodeRunner) están en una, la que Docker elija.
$CFG->curlsecurityblockedhosts = "169.254.169.254";

// Un sandbox no habla con servicios de fuera. H5P (mod_hvp) registra el sitio en su Hub al instalarse,
// sin mirar ningún ajuste: docker-compose.yml apunta el Hub a 127.0.0.1, y la petición falla (no
// sirve bloquearlo aquí: con la respuesta de Moodle, H5P se queda en un bucle). Y no consulta su
// Hub ni le manda estadísticas de uso cada día (su tarea programada sí mira estos ajustes).
$CFG->forced_plugin_settings = [
    'mod_hvp' => ['hub_is_enabled' => 0, 'send_usage_statistics' => 0],
];

require_once(__DIR__ . '/lib/setup.php');

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!
