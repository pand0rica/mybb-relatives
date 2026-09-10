<?php
/**
 * relatives - Installer
 *
 * Kapselt die komplette Lifecycle-Logik (install/uninstall/activate/deactivate).
 * Wird ausschliesslich von den globalen relatives_install()/relatives_activate()/...
 * Funktionen in inc/plugins/relatives.php aufgerufen (MyBB ruft nur diese globalen
 * Funktionsnamen selbst auf, daher muessen sie dort existieren).
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_Installer
{
    /** Liste aller Plugin-Tabellen ohne TABLE_PREFIX (fuer table_exists()/drop_table()). */
    private static array $tables = [
        'relatives_persons',
        'relatives_relationship_types',
        'relatives_attribute_groups',
        'relatives_attribute_options',
        'relatives_person_attributes',
    ];

    public static function install(): void
    {
        global $db;

        // Feste Kollation statt $db->build_create_table_collation() (Konvention aus
        // boilerplate.md, funktioniert identisch auf MySQL/MariaDB in allen 1.8.x-Setups).
        $collation = " DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";

        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "relatives_persons` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `owner_uid` INT(11) NOT NULL DEFAULT 0,
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `gender` TINYINT(2) NOT NULL DEFAULT -1,
            `relationship_type_id` INT(11) DEFAULT NULL,
            `relationship_custom_label` VARCHAR(255) DEFAULT NULL,
            `age` INT(11) DEFAULT NULL,
            `origin` VARCHAR(255) DEFAULT NULL,
            `avatar` VARCHAR(255) DEFAULT NULL,
            `description` TEXT,
            `is_wanted` TINYINT(1) NOT NULL DEFAULT 0,
            `wanted_thread_link` VARCHAR(255) DEFAULT NULL,
            `linked_uid` INT(11) DEFAULT NULL,
            `link_status` TINYINT(2) NOT NULL DEFAULT 0,
            `link_requested_by` INT(11) DEFAULT NULL,
            `link_confirmed_at` INT(11) DEFAULT NULL,
            `legacy_id` INT(11) DEFAULT NULL,
            `legacy_source` VARCHAR(20) DEFAULT NULL,
            `created_at` INT(11) NOT NULL DEFAULT 0,
            `updated_at` INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `owner_uid` (`owner_uid`),
            KEY `linked_uid` (`linked_uid`)
        ) ENGINE=InnoDB{$collation};");

        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "relatives_relationship_types` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `concept_key` VARCHAR(50) NOT NULL DEFAULT '',
            `gender` TINYINT(2) NOT NULL DEFAULT 2,
            `label` VARCHAR(100) NOT NULL DEFAULT '',
            `generation_offset` INT(11) NOT NULL DEFAULT 0,
            `sort_order` INT(11) NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `concept_key` (`concept_key`),
            KEY `gender` (`gender`)
        ) ENGINE=InnoDB{$collation};");

        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "relatives_attribute_groups` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `label` VARCHAR(100) NOT NULL DEFAULT '',
            `sort_order` INT(11) NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB{$collation};");

        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "relatives_attribute_options` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `group_id` INT(11) NOT NULL DEFAULT 0,
            `label` VARCHAR(100) NOT NULL DEFAULT '',
            `sort_order` INT(11) NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `group_id` (`group_id`)
        ) ENGINE=InnoDB{$collation};");

        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "relatives_person_attributes` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `person_id` INT(11) NOT NULL DEFAULT 0,
            `group_id` INT(11) NOT NULL DEFAULT 0,
            `option_id` INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `person_id` (`person_id`),
            KEY `option_id` (`option_id`)
        ) ENGINE=InnoDB{$collation};");
    }

    public static function is_installed(): bool
    {
        global $db;
        return $db->table_exists('relatives_persons');
    }

    public static function uninstall(): void
    {
        global $db;
        foreach (self::$tables as $table) {
            $db->drop_table($table);
        }
    }

    public static function activate(): void
    {
        global $db;

        self::install_settings();
        self::install_templates();
        self::install_stylesheets();

        rebuild_settings();
    }

    public static function deactivate(): void
    {
        global $db;

        require_once MYBB_ROOT . "inc/adminfunctions_templates.php";
        require_once MYBB_ADMIN_DIR . "inc/functions_themes.php";

        // Templates
        $db->delete_query('templates', "title LIKE 'relatives%'");

        // Settings + Gruppe
        $db->delete_query('settings', "name LIKE 'relatives_%'");
        $db->delete_query('settinggroups', "name = 'relatives'");

        // Stylesheets aus allen Themes
        $db->delete_query("themestylesheets", "name = 'relatives.css'");
        $db->delete_query("themestylesheets", "name = 'relatives-tree.css'");
        $themes = $db->simple_select("themes", "tid");
        while ($theme = $db->fetch_array($themes)) {
            update_theme_stylesheet_list((int)$theme["tid"]);
        }

        rebuild_settings();
    }

    private static function install_settings(): void
    {
        global $db;

        $existing = $db->fetch_array($db->simple_select('settinggroups', 'gid', "name = 'relatives'"));
        if ($existing) {
            $gid = (int)$existing['gid'];
        } else {
            $gid = (int)$db->insert_query('settinggroups', [
                'name'        => 'relatives',
                'title'       => 'Verwandtschaften & Stammbaum',
                'description' => 'Einstellungen fuer das Verwandtschaften-&-Stammbaum-Plugin.',
                'disporder'   => 1,
                'isdefault'   => 0,
            ]);
        }

        $settings = [
            [
                'name'        => 'relatives_enabled',
                'title'       => 'Plugin aktiv',
                'description' => 'Schaltet das Plugin komplett ein/aus (Master-Schalter).',
                'optionscode' => 'yesno',
                'value'       => '1',
                'disporder'   => 1,
            ],
            [
                'name'        => 'relatives_max_persons_per_user',
                'title'       => 'Maximale Personen pro Nutzer:in',
                'description' => 'Missbrauchsschutz. 0 = unbegrenzt.',
                'optionscode' => 'numeric',
                'value'       => '0',
                'disporder'   => 2,
            ],
            [
                'name'        => 'relatives_origin_mode',
                'title'       => 'Herkunfts-Quelle',
                'description' => 'Woher stammt die "Herkunft" einer Person: freies Textfeld oder ein bestehendes Profilfeld.',
                'optionscode' => "select\nfreetext=Freitext\nprofilefield=Profilfeld",
                'value'       => 'freetext',
                'disporder'   => 3,
            ],
            [
                'name'        => 'relatives_origin_profilefield_id',
                'title'       => 'Herkunfts-Profilfeld (fid)',
                'description' => 'fid des MyBB-Profilfelds, das als Herkunfts-Optionsliste dient. Nur relevant, wenn "Herkunfts-Quelle" = Profilfeld.',
                'optionscode' => 'numeric',
                'value'       => '0',
                'disporder'   => 4,
            ],
            [
                'name'        => 'relatives_legacy_migrated',
                'title'       => 'Letzte Legacy-Migration (Unix-Zeitstempel)',
                'description' => 'Wird automatisch vom Migrations-Tool gesetzt. 0 = noch nie migriert. Nicht manuell aendern.',
                'optionscode' => 'text',
                'value'       => '0',
                'disporder'   => 5,
            ],
        ];

        foreach ($settings as $setting) {
            if ($db->fetch_array($db->simple_select('settings', 'sid', "name = '" . $db->escape_string($setting['name']) . "'"))) {
                continue; // idempotent: bereits vorhanden (Upgrade-Pfad)
            }
            $setting['gid'] = $gid;
            $db->insert_query('settings', $setting);
        }
    }

    private static function install_templates(): void
    {
        global $db;

        $template_dir = MYBB_ROOT . "inc/plugins/relatives/templates/";
        if (!is_dir($template_dir)) {
            return;
        }
        $handle = opendir($template_dir);
        if (!$handle) {
            return;
        }
        while (($file = readdir($handle)) !== false) {
            if (substr($file, -4) !== '.tpl') {
                continue;
            }
            $title = substr($file, 0, -4);
            $content = (string)file_get_contents($template_dir . $file);

            // Idempotent: vorhandenes globales Template (sid=-1) ersetzen statt duplizieren.
            $db->delete_query('templates', "title = '" . $db->escape_string($title) . "' AND sid = -1");
            $db->insert_query('templates', [
                'title'    => $db->escape_string($title),
                'template' => $db->escape_string($content),
                'sid'      => -1,
                'version'  => '',
                'dateline' => TIME_NOW,
            ]);
        }
        closedir($handle);
    }

    private static function install_stylesheets(): void
    {
        global $db;

        require_once MYBB_ROOT . "inc/adminfunctions_templates.php";
        require_once MYBB_ADMIN_DIR . "inc/functions_themes.php";

        $css_files = [
            'relatives.css'      => MYBB_ROOT . "inc/plugins/relatives/assets/relatives.css",
            'relatives-tree.css' => MYBB_ROOT . "inc/plugins/relatives/assets/relatives-tree.css",
        ];

        foreach ($css_files as $css_name => $css_path) {
            if (!file_exists($css_path)) {
                continue;
            }
            $css = $db->escape_string((string)file_get_contents($css_path));
            $themes = $db->simple_select("themes", "tid");
            while ($theme = $db->fetch_array($themes)) {
                $tid = (int)$theme["tid"];
                $existing = $db->fetch_array($db->simple_select(
                    "themestylesheets",
                    "sid",
                    "name = '" . $db->escape_string($css_name) . "' AND tid = " . $tid
                ));
                if ($existing) {
                    $db->update_query("themestylesheets", [
                        "stylesheet"   => $css,
                        "lastmodified" => TIME_NOW,
                    ], "sid=" . (int)$existing["sid"], 1);
                } else {
                    $sid = $db->insert_query("themestylesheets", [
                        "name"         => $css_name,
                        "tid"          => $tid,
                        "attachedto"   => "",
                        "stylesheet"   => $css,
                        "lastmodified" => TIME_NOW,
                        "cachefile"    => $css_name,
                    ]);
                    $db->update_query("themestylesheets",
                        ["cachefile" => "css.php?stylesheet={$sid}"], "sid=" . (int)$sid, 1);
                }
                update_theme_stylesheet_list($tid);
            }
        }
    }
}
