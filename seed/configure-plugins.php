<?php
/**
 * Gets the site ready to use the image's plugins (docker/plugins.txt). manage.mjs runs it after
 * every install or upgrade of the site:
 *
 *   docker compose exec webserver php configure-plugins.php
 *
 * It only changes what's missing, so it can run as many times as needed, and it skips whatever
 * belongs to a plugin the image doesn't have.
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/adminlib.php');
// As the admin: without a user, the admin tree only holds what anyone can see.
\core\session\manager::set_user(get_admin());

/** Changes a setting unless it already has that value, and says so. */
function set_config_if(string $name, string $value, ?string $plugin, string $why): void {
    if ((string) get_config($plugin ?? 'core', $name) !== $value) {
        set_config($name, $value, $plugin);
        cli_writeln(($plugin ? "$plugin/" : '') . "$name: $why");
    }
}

// CodeRunner runs the answers' code in the jobe container (docker-compose.yml).
if (core_component::get_component_directory('qtype_coderunner')) {
    set_config_if('jobe_host', 'jobe', 'qtype_coderunner', 'CodeRunner runs the code in the jobe container.');
}

// Moodle reaching jobe (the private networks) and H5P not talking to its Hub are set in
// docker/config.php, so they already hold during the install.

// Chat and Survey ship disabled (Moodle is retiring them), but campuses use them.
foreach (['chat', 'survey'] as $module) {
    if (core_component::get_component_directory("mod_$module") && !$DB->get_field('modules', 'visible', ['name' => $module])) {
        \core\plugininfo\mod::enable_plugin($module, 1);
        cli_writeln("mod_$module: enabled.");
    }
}

// Plugin settings without a default: until someone saves them, Moodle sends the admin to the
// new-settings page. They're saved with their first choice (a dropdown) or empty; write_setting()
// doesn't save a value it doesn't accept, and then an empty one is written directly.
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
    cli_writeln($setting->get_setting() === null ? "$name: could not be saved." : "$name: saved (it had no default).");
}

cli_writeln('OK');
