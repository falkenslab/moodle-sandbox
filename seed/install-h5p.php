<?php
/**
 * Instala en el sitio los tipos de contenido H5P de una carpeta de paquetes (.h5p de solo librerías,
 * como los da el Hub de H5P), sin que el sitio hable con h5p.org: lo mismo que hace
 * core_h5p\core::fetch_content_type() después de descargar uno, desde el fichero local. Lo ejecuta
 * manage.mjs (`npm run h5p`), que descarga los paquetes en el equipo y copia la carpeta al contenedor:
 *
 *   docker compose exec webserver php install-h5p.php /tmp/h5p-content-types
 *
 * Un tipo que el sitio ya tiene en esa versión (o una más nueva) se salta, así que se puede ejecutar
 * cuantas veces haga falta.
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/clilib.php');

use core_h5p\factory;
use core_h5p\helper;

$dir = $argv[1] ?? '';
if (!is_dir($dir)) {
    cli_error("Uso: php install-h5p.php <carpeta con los .h5p>");
}

// Como el administrador: instalar librerías H5P es cosa suya.
\core\session\manager::set_user(get_admin());
$fs = get_file_storage();
$systemcontext = context_system::instance();
$failed = 0;

foreach (glob("$dir/*.h5p") as $path) {
    $machinename = basename($path, '.h5p');
    // La versión que trae el paquete: la de su library.json (en la carpeta <machineName>-<x>.<y>).
    $zip = new ZipArchive();
    $version = null;
    if ($zip->open($path) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match('#^' . preg_quote($machinename, '#') . '-\d+\.\d+/library\.json$#', $zip->getNameIndex($i))) {
                $version = json_decode($zip->getFromIndex($i));
                break;
            }
        }
        $zip->close();
    }
    if (!$version) {
        cli_writeln("$machinename: el paquete no trae su library.json; se salta.");
        $failed++;
        continue;
    }
    $label = "$machinename {$version->majorVersion}.{$version->minorVersion}.{$version->patchVersion}";
    $installed = $DB->record_exists_select('h5p_libraries',
        'machinename = ? AND runnable = 1 AND (majorversion > ? OR (majorversion = ? AND (minorversion > ? OR ' .
        '(minorversion = ? AND patchversion >= ?))))',
        [$machinename, $version->majorVersion, $version->majorVersion, $version->minorVersion, $version->minorVersion,
            $version->patchVersion]);
    if ($installed) {
        cli_writeln("$label: ya está.");
        continue;
    }

    // Como fetch_content_type(): el paquete en library_sources, save_h5p() solo con las librerías, y fuera.
    $filerecord = ['contextid' => $systemcontext->id, 'component' => 'core_h5p', 'filearea' => 'library_sources',
        'itemid' => 0, 'filepath' => '/', 'filename' => $machinename];
    if ($existing = $fs->get_file(...array_values($filerecord))) {
        $existing->delete();
    }
    $file = $fs->create_file_from_pathname($filerecord, $path);
    $factory = new factory();
    helper::save_h5p($factory, $file, (object) [], false, true);
    $file->delete();

    $runnable = $DB->record_exists('h5p_libraries', ['machinename' => $machinename, 'runnable' => 1,
        'majorversion' => $version->majorVersion, 'minorversion' => $version->minorVersion]);
    cli_writeln($runnable ? "$label: instalado." : "$label: no se pudo instalar.");
    $failed += $runnable ? 0 : 1;
}

if ($failed) {
    cli_error("$failed tipos de contenido no se instalaron.");
}
cli_writeln('OK');
