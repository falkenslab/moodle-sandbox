<?php
/**
 * Deja el sitio listo para usar los plugins de la imagen (docker/plugins.txt). Lo ejecuta manage.mjs
 * después de cada instalación o actualización del sitio:
 *
 *   docker compose exec webserver php configure-plugins.php
 *
 * Solo cambia lo que falte, así que se puede ejecutar cuantas veces haga falta, y se salta lo de un
 * plugin que la imagen no tenga.
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/adminlib.php');
// Como el administrador: sin usuario, el árbol de administración solo tiene lo que ve cualquiera.
\core\session\manager::set_user(get_admin());

/** Cambia un ajuste si no tiene ya ese valor, y lo dice. */
function set_config_if(string $name, string $value, ?string $plugin, string $why): void {
    if ((string) get_config($plugin ?? 'core', $name) !== $value) {
        set_config($name, $value, $plugin);
        cli_writeln(($plugin ? "$plugin/" : '') . "$name: $why");
    }
}

// CodeRunner ejecuta el código de las respuestas en el contenedor jobe (docker-compose.yml).
if (core_component::get_component_directory('qtype_coderunner')) {
    set_config_if('jobe_host', 'jobe', 'qtype_coderunner', 'CodeRunner ejecuta el código en el contenedor jobe.');
}

// Que Moodle llegue a jobe (las redes privadas) y que H5P no hable con su Hub lo fija docker/config.php,
// para que valga ya durante la instalación.

// Chat y Encuesta predefinida vienen desactivados (Moodle los retira), pero los campus los usan.
foreach (['chat', 'survey'] as $module) {
    if (core_component::get_component_directory("mod_$module") && !$DB->get_field('modules', 'visible', ['name' => $module])) {
        \core\plugininfo\mod::enable_plugin($module, 1);
        cli_writeln("mod_$module: activado.");
    }
}

// Ajustes de plugins sin valor por defecto: hasta que alguien los guarde, Moodle manda al
// administrador a la página de ajustes nuevos. Se guardan con su primera opción (un desplegable) o
// vacíos; write_setting() no guarda un valor que no admite, y entonces se escribe vacío sin más.
$pending = [];
$walk = function (part_of_admin_tree $node) use (&$walk, &$pending) {
    if ($node instanceof admin_category) {
        foreach ($node->get_children() as $child) {
            $walk($child);
        }
    } else if ($node instanceof admin_settingpage) {
        foreach ($node->settings as $setting) {
            if ($setting->get_setting() === null) {
                $pending[] = $setting;
            }
        }
    }
};
$walk(admin_get_root(true, true));
foreach ($pending as $setting) {
    $value = $setting->get_defaultsetting() ?? '';
    if (method_exists($setting, 'load_choices') && $setting->load_choices() && !empty($setting->choices)) {
        $value = array_key_first($setting->choices);
    }
    $setting->write_setting($value);
    if ($setting->get_setting() === null) {
        set_config($setting->name, '', $setting->plugin);
    }
    $name = ($setting->plugin ? "$setting->plugin/" : '') . $setting->name;
    cli_writeln($setting->get_setting() === null ? "$name: no se pudo guardar." : "$name: guardado (no tenía valor por defecto).");
}

cli_writeln('OK');
