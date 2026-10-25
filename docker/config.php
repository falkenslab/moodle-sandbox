<?php  // Moodle configuration file

// The sandbox's config.php, inside its image (see Dockerfile): the database password and the URL
// come from the container's environment (docker-compose.yml takes them from .env), so the same
// image serves any installation.

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

// The addresses Moodle won't request, set here so they already hold during the installation:
// without the private networks in Moodle's list, because the sandbox's containers (jobe, for
// CodeRunner) are on one, whichever Docker picks.
$CFG->curlsecurityblockedhosts = "169.254.169.254";

// A sandbox doesn't talk to outside services. H5P (mod_hvp) registers the site with its Hub when
// installed, whatever its settings: docker-compose.yml points the Hub at 127.0.0.1, and the request
// fails (blocking it here doesn't work: with Moodle's response, H5P gets stuck in a loop). And it
// doesn't query its Hub or send it usage statistics every day (its scheduled task does read these
// settings).
$CFG->forced_plugin_settings = [
    'mod_hvp' => ['hub_is_enabled' => 0, 'send_usage_statistics' => 0],
];

require_once(__DIR__ . '/lib/setup.php');

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!
