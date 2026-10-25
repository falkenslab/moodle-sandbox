<?php
/**
 * Installs on the site the H5P content types of a folder of packages (libraries-only .h5p files,
 * as the H5P Hub serves them), without the site talking to h5p.org: what
 * core_h5p\core::fetch_content_type() does after downloading one, from the local file. manage.mjs
 * runs it (`npm run h5p`), after downloading the packages on the host and copying the folder in:
 *
 *   docker compose exec webserver php install-h5p.php /tmp/h5p-content-types
 *
 * A type the site already has in that version (or a newer one) is skipped, so it can run as many
 * times as needed.
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/clilib.php');

use core_h5p\factory;
use core_h5p\helper;

$dir = $argv[1] ?? '';
if (!is_dir($dir)) {
    cli_error("Usage: php install-h5p.php <folder with the .h5p files>");
}

// As the admin: installing H5P libraries is the admin's job.
\core\session\manager::set_user(get_admin());
$fs = get_file_storage();
$systemcontext = context_system::instance();
$failed = 0;

foreach (glob("$dir/*.h5p") as $path) {
    $machinename = basename($path, '.h5p');
    // The package's version: the one in its library.json (in the <machineName>-<x>.<y> folder).
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
        cli_writeln("$machinename: the package has no library.json; skipped.");
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
        cli_writeln("$label: already installed.");
        continue;
    }

    // Like fetch_content_type(): the package in library_sources, save_h5p() with only the libraries, then deleted.
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
    cli_writeln($runnable ? "$label: installed." : "$label: could not be installed.");
    $failed += $runnable ? 0 : 1;
}

if ($failed) {
    cli_error("$failed content types were not installed.");
}
cli_writeln('OK');
