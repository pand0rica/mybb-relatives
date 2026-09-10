<?php
/**
 * CLI-Lifecycle-Helper fuer "relatives". Installiert/deinstalliert das Plugin ohne
 * ACP-Login und beweist den vollstaendigen Cleanup. Spiegelt exakt, was der
 * ACP-Plugin-Manager tut (Lifecycle-Funktionen + plugins-Datacache).
 *
 * Aufruf (auf dem Server, im httpdocs-Root): php tests/cli-plugin.php install|uninstall|status
 */

if (PHP_SAPI !== 'cli') {
    die('CLI only.');
}
define('IN_MYBB', 1);
chdir(dirname(__DIR__)); // Skript liegt in httpdocs/tests/
require_once './inc/init.php';

if (!defined('MYBB_ADMIN_DIR')) {
    $admin_dir = isset($config['admin_dir']) ? $config['admin_dir'] : 'admin';
    define('MYBB_ADMIN_DIR', MYBB_ROOT . $admin_dir . '/');
}
require_once MYBB_ROOT . 'inc/plugins/relatives.php';

function relatives_cli_set_active(bool $active): void
{
    global $cache;
    $c = $cache->read('plugins');
    if (!is_array($c)) {
        $c = [];
    }
    if (!isset($c['active']) || !is_array($c['active'])) {
        $c['active'] = [];
    }
    if ($active) {
        $c['active']['relatives'] = 'relatives';
    } else {
        unset($c['active']['relatives']);
    }
    $cache->update('plugins', $c);
}

function relatives_cli_status(): void
{
    global $db;

    $tables = ['relatives_persons', 'relatives_relationship_types', 'relatives_attribute_groups', 'relatives_attribute_options', 'relatives_person_attributes'];
    echo "Tables:\n";
    foreach ($tables as $table) {
        echo "  {$table}: " . ($db->table_exists($table) ? 'EXISTS' : 'MISSING') . "\n";
    }

    $settings_count = (int)$db->fetch_field($db->simple_select('settings', 'COUNT(*) AS c', "name LIKE 'relatives_%'"), 'c');
    $settinggroup_count = (int)$db->fetch_field($db->simple_select('settinggroups', 'COUNT(*) AS c', "name = 'relatives'"), 'c');
    $template_count = (int)$db->fetch_field($db->simple_select('templates', 'COUNT(*) AS c', "title LIKE 'relatives%'"), 'c');
    $stylesheet_count = (int)$db->fetch_field($db->simple_select('themestylesheets', 'COUNT(*) AS c', "name = 'relatives.css' OR name = 'relatives-tree.css'"), 'c');

    echo "Settings (name LIKE 'relatives_%'): {$settings_count}\n";
    echo "Settinggroup 'relatives': {$settinggroup_count}\n";
    echo "Templates (title LIKE 'relatives%'): {$template_count}\n";
    echo "Stylesheets (relatives.css / relatives-tree.css, all themes): {$stylesheet_count}\n";
    echo "is_installed(): " . (relatives_is_installed() ? 'true' : 'false') . "\n";
}

$cmd = isset($argv[1]) ? $argv[1] : 'status';

if ($cmd === 'install') {
    relatives_install();
    relatives_activate();
    relatives_cli_set_active(true);
    echo "Installed + activated.\n";
} elseif ($cmd === 'uninstall') {
    relatives_deactivate();
    relatives_uninstall();
    relatives_cli_set_active(false);
    echo "Deactivated + uninstalled.\n";
}

relatives_cli_status();
