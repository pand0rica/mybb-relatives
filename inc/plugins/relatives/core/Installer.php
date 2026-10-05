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
        'relatives_person_owners',
        'relatives_relations',
        'relatives_relation_conflicts',
        'relatives_families',
        'relatives_relationship_types',
        'relatives_attribute_groups',
        'relatives_attribute_options',
        'relatives_person_attributes',
        'relatives_family_attributes',
        'relatives_delete_requests',
        'relatives_delete_votes',
        'relatives_relation_requests',
    ];

    public static function install(): void
    {
        global $db;

        // Feste Kollation statt $db->build_create_table_collation() (Konvention aus
        // boilerplate.md, funktioniert identisch auf MySQL/MariaDB in allen 1.8.x-Setups).
        $collation = " DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";

        // Personen-Stammdaten (fuer alle Besitzer*innen gleich). Wer die Person in
        // seiner/ihrer Liste hat und in welchem Verhaeltnis, steht in
        // relatives_person_owners -- eine Person kann mehreren Charakteren gehoeren.
        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "relatives_persons` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `gender` TINYINT(2) NOT NULL DEFAULT -1,
            `age` INT(11) DEFAULT NULL,
            `birth_year` INT(11) DEFAULT NULL,
            `origin` VARCHAR(255) DEFAULT NULL,
            `avatar` VARCHAR(255) DEFAULT NULL,
            `description` TEXT,
            `is_wanted` TINYINT(1) NOT NULL DEFAULT 0,
            `wanted_thread_link` VARCHAR(255) DEFAULT NULL,
            `is_deceased` TINYINT(1) NOT NULL DEFAULT 0,
            `death_note` VARCHAR(64) DEFAULT NULL,
            `linked_uid` INT(11) DEFAULT NULL,
            `link_status` TINYINT(2) NOT NULL DEFAULT 0,
            `link_requested_by` INT(11) DEFAULT NULL,
            `link_confirmed_at` INT(11) DEFAULT NULL,
            `legacy_id` INT(11) DEFAULT NULL,
            `legacy_source` VARCHAR(20) DEFAULT NULL,
            `created_at` INT(11) NOT NULL DEFAULT 0,
            `updated_at` INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `name` (`name`(191)),
            KEY `linked_uid` (`linked_uid`),
            KEY `legacy` (`legacy_source`, `legacy_id`)
        ) ENGINE=InnoDB{$collation};");

        self::create_person_owners_table();
        self::create_relations_table();
        self::create_conflicts_table();
        self::create_families_table();
        self::create_delete_requests_tables();
        self::create_relation_requests_table();

        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "relatives_relationship_types` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `concept_key` VARCHAR(50) NOT NULL DEFAULT '',
            `gender` TINYINT(2) NOT NULL DEFAULT 2,
            `label` VARCHAR(100) NOT NULL DEFAULT '',
            `generation_offset` INT(11) NOT NULL DEFAULT 0,
            `line_style` VARCHAR(12) NOT NULL DEFAULT 'solid',
            `sort_order` INT(11) NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `concept_key` (`concept_key`),
            KEY `gender` (`gender`)
        ) ENGINE=InnoDB{$collation};");

        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "relatives_attribute_groups` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `label` VARCHAR(100) NOT NULL DEFAULT '',
            `applies_to` VARCHAR(10) NOT NULL DEFAULT 'person',
            `field_type` VARCHAR(10) NOT NULL DEFAULT 'select',
            `is_multiple` TINYINT(1) NOT NULL DEFAULT 0,
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

        // Settings haengen an install()/uninstall(), NICHT an activate()/deactivate():
        // sie sind Nutzerdaten (z.B. relatives_max_persons_per_user) und duerfen bei
        // einem simplen Deaktivieren/Reaktivieren nicht auf die Defaults zurueckfallen.
        self::install_settings();

        // relatives.php stays locked until the shared network is merged. A fresh forum
        // without legacy tables has nothing to merge -- open it right away instead of
        // forcing an ACP merge on empty tables. With legacy data the admin still runs
        // Migration and then "Merge network now". Upgrades never pass through install().
        if (!in_array(true, Relatives_Migration::legacy_tables_available(), true)) {
            $db->update_query('settings', ['value' => TIME_NOW], "name = 'relatives_graph_merged'");
        }
        rebuild_settings();
    }

    public static function is_installed(): bool
    {
        global $db;
        return $db->table_exists('relatives_persons');
    }

    public static function uninstall(): void
    {
        global $db;
        Relatives_Alerts::unregister_types();
        foreach (self::$tables as $table) {
            $db->drop_table($table);
        }

        // Spiegelbild zu install_settings(): Settings sind Nutzerdaten, werden also erst
        // beim vollstaendigen Deinstallieren entfernt, nicht schon bei jedem Deaktivieren.
        $db->delete_query('settings', "name LIKE 'relatives_%'");
        $db->delete_query('settinggroups', "name = 'relatives'");
        rebuild_settings();
    }

    public static function activate(): void
    {
        global $db;

        self::upgrade_schema();
        // Idempotent (siehe install_settings()): legt bei Frischinstallationen nichts
        // Neues an (das erledigt schon install()), ruestet aber bei bestehenden
        // Installationen neue Settings spaeterer Versionen nach -- ohne vorhandene Werte
        // zu ueberschreiben.
        self::install_settings();
        self::install_templates();
        self::install_stylesheets();
        self::attach_usercp_stylesheet();
        self::install_usercp_nav_misc_link();
        self::install_profile_variable();
        // Alert-Typen bleiben beim Deaktivieren bestehen (siehe Relatives_Alerts::unregister_types()).
        Relatives_Alerts::register_types();

        rebuild_settings();
    }

    /**
     * Zuordnung Person <-> Besitzer*in (n:m). Alles, was aus Sicht eines einzelnen
     * Charakters gilt und genau einmal je Person existiert, liegt hier: der eigene
     * Beziehungstext und der Cache der berechneten Verwandtschaft zu dir
     * (computed_*, siehe Relatives_Kinship). Die Beziehungsaussagen selbst
     * ("X ist <Grad> von Y") liegen seit v2.1 in relatives_relations, weil eine
     * Person mehrere davon haben kann (leibliche + Adoptiveltern, Partner, ...).
     */
    private static function create_person_owners_table(): void
    {
        global $db;

        $collation = " DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "relatives_person_owners` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `person_id` INT(11) NOT NULL DEFAULT 0,
            `owner_uid` INT(11) NOT NULL DEFAULT 0,
            `description` TEXT,
            `computed_concept_key` VARCHAR(50) DEFAULT NULL,
            `computed_label` VARCHAR(255) DEFAULT NULL,
            `computed_up` TINYINT(4) DEFAULT NULL,
            `computed_down` TINYINT(4) DEFAULT NULL,
            `computed_category` VARCHAR(24) DEFAULT NULL,
            `computed_via_person_id` INT(11) DEFAULT NULL,
            `computed_at` INT(11) NOT NULL DEFAULT 0,
            `legacy_id` INT(11) DEFAULT NULL,
            `created_at` INT(11) NOT NULL DEFAULT 0,
            `updated_at` INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `person_owner` (`person_id`, `owner_uid`),
            KEY `owner_uid` (`owner_uid`),
            KEY `computed_concept_key` (`computed_concept_key`)
        ) ENGINE=InnoDB{$collation};");
    }

    /**
     * Kantentabelle des Verwandtschaftsgraphen. Eine Zeile = eine Aussage im Baum
     * einer Besitzer*in: "person_id ist <relationship_type_id> von
     * related_to_person_id" (bzw. von related_to_family_id, dem Familien-Kasten;
     * beide NULL = relativ zu dir selbst). Anders als frueher darf es beliebig viele
     * Aussagen je Person geben -- nur so lassen sich leibliche UND Adoptiveltern,
     * Partner*innen und heimliche Abstammung gleichzeitig abbilden.
     * is_primary markiert die Kante, an der das Baum-Layout die Person aufhaengt.
     */
    private static function create_relations_table(): void
    {
        global $db;

        $collation = " DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "relatives_relations` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `owner_uid` INT(11) NOT NULL DEFAULT 0,
            `person_id` INT(11) NOT NULL DEFAULT 0,
            `related_to_person_id` INT(11) DEFAULT NULL,
            `related_to_family_id` INT(11) DEFAULT NULL,
            `relationship_type_id` INT(11) DEFAULT NULL,
            `relationship_custom_label` VARCHAR(255) DEFAULT NULL,
            `is_secret` TINYINT(1) NOT NULL DEFAULT 0,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `sort_order` INT(11) NOT NULL DEFAULT 0,
            `created_by` INT(11) NOT NULL DEFAULT 0,
            `created_at` INT(11) NOT NULL DEFAULT 0,
            `updated_at` INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `person_id` (`person_id`),
            KEY `owner_person` (`owner_uid`, `person_id`),
            KEY `related_to_person_id` (`related_to_person_id`),
            KEY `related_to_family_id` (`related_to_family_id`),
            KEY `relationship_type_id` (`relationship_type_id`)
        ) ENGINE=InnoDB{$collation};");
    }

    /**
     * Widersprueche, die beim Zusammenfuehren der frueheren Einzel-Listen zu einem
     * gemeinsamen Geflecht auftreten ("X ist Mutter von Y" vs. "X ist Schwester von Y").
     * Die behaltene Aussage steht in relatives_relations (kept_relation_id), die
     * verworfene hier -- das Team kann sie im ACP uebernehmen oder endgueltig loeschen.
     */
    private static function create_conflicts_table(): void
    {
        global $db;

        $collation = " DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "relatives_relation_conflicts` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `person_id` INT(11) NOT NULL DEFAULT 0,
            `related_to_person_id` INT(11) DEFAULT NULL,
            `related_to_family_id` INT(11) DEFAULT NULL,
            `relationship_type_id` INT(11) DEFAULT NULL,
            `relationship_custom_label` VARCHAR(255) DEFAULT NULL,
            `is_secret` TINYINT(1) NOT NULL DEFAULT 0,
            `source_uid` INT(11) NOT NULL DEFAULT 0,
            `kept_relation_id` INT(11) NOT NULL DEFAULT 0,
            `created_at` INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `kept_relation_id` (`kept_relation_id`)
        ) ENGINE=InnoDB{$collation};");
    }

    /**
     * Familien-Kaesten ("Familie Flemming"). Forumsweit geteilt wie Personen: ein
     * Kasten kann in beliebig vielen Stammbaeumen als Eltern-Ersatz haengen, sodass
     * man fuer eine Geschwistergruppe keine einzelnen Vorfahren anlegen muss.
     */
    private static function create_families_table(): void
    {
        global $db;

        $collation = " DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "relatives_families` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL DEFAULT '',
            `color` VARCHAR(7) NOT NULL DEFAULT '#7d8894',
            `description` TEXT,
            `welcomes_new_members` TINYINT(1) NOT NULL DEFAULT 1,
            `created_by` INT(11) NOT NULL DEFAULT 0,
            `created_at` INT(11) NOT NULL DEFAULT 0,
            `updated_at` INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `name` (`name`)
        ) ENGINE=InnoDB{$collation};");

        // Attributwerte je Familie (ACP-Gruppen mit applies_to = 'family'): eine Zeile je
        // Familie und Gruppe -- bei Vorauswahl mit option_id, bei Freitext mit value.
        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "relatives_family_attributes` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `family_id` INT(11) NOT NULL DEFAULT 0,
            `group_id` INT(11) NOT NULL DEFAULT 0,
            `option_id` INT(11) NOT NULL DEFAULT 0,
            `value` VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            UNIQUE KEY `family_group` (`family_id`, `group_id`),
            KEY `option_id` (`option_id`)
        ) ENGINE=InnoDB{$collation};");
    }

    /**
     * Loeschantraege fuer NPCs (Relatives_DeleteRequests): ein Antrag je Person und
     * Vorgang, dazu je betroffener Besitzer*in eine Stimme. person_name haelt den Namen
     * fuer die Abschluss-Benachrichtigung fest, wenn die Person schon geloescht ist.
     */
    private static function create_delete_requests_tables(): void
    {
        global $db;

        $collation = " DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "relatives_delete_requests` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `person_id` INT(11) NOT NULL DEFAULT 0,
            `person_name` VARCHAR(255) NOT NULL DEFAULT '',
            `requested_by` INT(11) NOT NULL DEFAULT 0,
            `status` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` INT(11) NOT NULL DEFAULT 0,
            `resolved_at` INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `person_status` (`person_id`, `status`),
            KEY `requested_by` (`requested_by`)
        ) ENGINE=InnoDB{$collation};");

        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "relatives_delete_votes` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `request_id` INT(11) NOT NULL DEFAULT 0,
            `uid` INT(11) NOT NULL DEFAULT 0,
            `vote` TINYINT(1) NOT NULL DEFAULT 0,
            `answered_at` INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `request_uid` (`request_id`, `uid`),
            KEY `uid_vote` (`uid`, `vote`)
        ) ENGINE=InnoDB{$collation};");
    }

    /**
     * Verbindungsanfragen zu fremden gespielten Charakteren (Relatives_RelationRequests).
     * 0 statt NULL fuer "kein Anker"/"neue Kante", damit Vergleiche einfach bleiben.
     */
    private static function create_relation_requests_table(): void
    {
        global $db;

        $collation = " DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "relatives_relation_requests` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `person_id` INT(11) NOT NULL DEFAULT 0,
            `related_to_person_id` INT(11) NOT NULL DEFAULT 0,
            `related_to_family_id` INT(11) NOT NULL DEFAULT 0,
            `relationship_type_id` INT(11) NOT NULL DEFAULT 0,
            `relationship_custom_label` VARCHAR(255) NOT NULL DEFAULT '',
            `is_secret` TINYINT(1) NOT NULL DEFAULT 0,
            `replaces_relation_id` INT(11) NOT NULL DEFAULT 0,
            `requested_by` INT(11) NOT NULL DEFAULT 0,
            `target_uid` INT(11) NOT NULL DEFAULT 0,
            `status` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` INT(11) NOT NULL DEFAULT 0,
            `resolved_at` INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `target_status` (`target_uid`, `status`),
            KEY `requested_status` (`requested_by`, `status`),
            KEY `person_id` (`person_id`)
        ) ENGINE=InnoDB{$collation};");
    }

    /**
     * Ruestet Spalten spaeterer Versionen an bestehenden Tabellen nach. Idempotent
     * ueber field_exists(), damit activate() beliebig oft laufen darf.
     */
    private static function add_missing_columns(): void
    {
        global $db;

        $columns = [
            'relatives_persons' => [
                'is_deceased' => "ADD COLUMN `is_deceased` TINYINT(1) NOT NULL DEFAULT 0",
                'death_note'  => "ADD COLUMN `death_note` VARCHAR(64) DEFAULT NULL",
                'birth_year'  => "ADD COLUMN `birth_year` INT(11) DEFAULT NULL",
            ],
            'relatives_person_owners' => [
                'computed_concept_key' => "ADD COLUMN `computed_concept_key` VARCHAR(50) DEFAULT NULL",
                'computed_label'       => "ADD COLUMN `computed_label` VARCHAR(255) DEFAULT NULL",
                'computed_up'          => "ADD COLUMN `computed_up` TINYINT(4) DEFAULT NULL",
                'computed_down'        => "ADD COLUMN `computed_down` TINYINT(4) DEFAULT NULL",
                'computed_category'    => "ADD COLUMN `computed_category` VARCHAR(24) DEFAULT NULL",
                'computed_via_person_id' => "ADD COLUMN `computed_via_person_id` INT(11) DEFAULT NULL",
                'computed_at'          => "ADD COLUMN `computed_at` INT(11) NOT NULL DEFAULT 0",
            ],
            'relatives_relationship_types' => [
                'line_style' => "ADD COLUMN `line_style` VARCHAR(12) NOT NULL DEFAULT 'solid'",
            ],
            'relatives_relations' => [
                'created_by' => "ADD COLUMN `created_by` INT(11) NOT NULL DEFAULT 0",
            ],
            'relatives_attribute_groups' => [
                'applies_to' => "ADD COLUMN `applies_to` VARCHAR(10) NOT NULL DEFAULT 'person'",
                'field_type' => "ADD COLUMN `field_type` VARCHAR(10) NOT NULL DEFAULT 'select'",
                'is_multiple' => "ADD COLUMN `is_multiple` TINYINT(1) NOT NULL DEFAULT 0",
            ],
            'relatives_families' => [
                'welcomes_new_members' => "ADD COLUMN `welcomes_new_members` TINYINT(1) NOT NULL DEFAULT 1",
            ],
        ];

        foreach ($columns as $table => $definitions) {
            if (!$db->table_exists($table)) {
                continue;
            }
            $adds = [];
            foreach ($definitions as $field => $sql) {
                if (!$db->field_exists($field, $table)) {
                    $adds[] = $sql;
                }
            }
            if ($adds) {
                $db->write_query("ALTER TABLE `" . TABLE_PREFIX . $table . "` " . implode(', ', $adds));
            }
        }
    }

    /**
     * Idempotente Schema-Nachruestung fuer bereits installierte Instanzen. Bei einer
     * Frischinstallation ist hier nichts zu tun (install() legt das aktuelle Schema an).
     *
     * Zwei aufeinander folgende Umbauten, beide idempotent und in dieser Reihenfolge:
     * 1. v2.0: die Besitzer-Spalten von relatives_persons wandern nach
     *    relatives_person_owners (mehrere Besitzer*innen je Person).
     * 2. v2.1: die Beziehungsspalten von relatives_person_owners wandern nach
     *    relatives_relations (mehrere Beziehungsaussagen je Person).
     */
    private static function upgrade_schema(): void
    {
        global $db;

        if (!$db->table_exists('relatives_persons')) {
            return;
        }

        self::create_person_owners_table();
        self::create_relations_table();
        self::create_conflicts_table();
        self::create_families_table();
        self::create_delete_requests_tables();
        self::create_relation_requests_table();
        // Groups that already hold several values per person must stay multi-select once
        // the column exists -- otherwise the next save would silently drop values.
        $adds_is_multiple = $db->table_exists('relatives_attribute_groups')
            && !$db->field_exists('is_multiple', 'relatives_attribute_groups');
        self::add_missing_columns();
        if ($adds_is_multiple) {
            Relatives_Attributes::flag_multi_value_groups();
        }

        if ($db->field_exists('owner_uid', 'relatives_persons')) {
            $has_related = $db->field_exists('related_to_person_id', 'relatives_persons');
            $related_select = $has_related ? 'p.related_to_person_id' : 'NULL';

            $db->write_query("INSERT INTO `" . TABLE_PREFIX . "relatives_person_owners`
                (person_id, owner_uid, created_at, updated_at)
                SELECT p.id, p.owner_uid, p.created_at, p.updated_at
                FROM `" . TABLE_PREFIX . "relatives_persons` p
                WHERE p.owner_uid > 0
                  AND NOT EXISTS (
                    SELECT 1 FROM `" . TABLE_PREFIX . "relatives_person_owners` o
                    WHERE o.person_id = p.id AND o.owner_uid = p.owner_uid
                  )");

            // Der Grad wandert direkt in die Kantentabelle (Schritt 2 findet die
            // Spalten auf person_owners dann gar nicht mehr vor).
            $db->write_query("INSERT INTO `" . TABLE_PREFIX . "relatives_relations`
                (owner_uid, person_id, related_to_person_id, relationship_type_id, relationship_custom_label, is_primary, created_at, updated_at)
                SELECT p.owner_uid, p.id, {$related_select}, p.relationship_type_id, p.relationship_custom_label, 1, p.created_at, p.updated_at
                FROM `" . TABLE_PREFIX . "relatives_persons` p
                WHERE p.owner_uid > 0
                  AND (p.relationship_type_id IS NOT NULL OR (p.relationship_custom_label IS NOT NULL AND p.relationship_custom_label != ''))
                  AND NOT EXISTS (
                    SELECT 1 FROM `" . TABLE_PREFIX . "relatives_relations` r
                    WHERE r.person_id = p.id AND r.owner_uid = p.owner_uid
                  )");

            $drops = ['DROP COLUMN `owner_uid`', 'DROP COLUMN `relationship_type_id`', 'DROP COLUMN `relationship_custom_label`'];
            if ($has_related) {
                $drops[] = 'DROP COLUMN `related_to_person_id`';
            }
            $db->write_query("ALTER TABLE `" . TABLE_PREFIX . "relatives_persons` " . implode(', ', $drops));
        }

        if ($db->field_exists('relationship_type_id', 'relatives_person_owners')) {
            $db->write_query("INSERT INTO `" . TABLE_PREFIX . "relatives_relations`
                (owner_uid, person_id, related_to_person_id, relationship_type_id, relationship_custom_label, is_primary, created_at, updated_at)
                SELECT o.owner_uid, o.person_id, o.related_to_person_id, o.relationship_type_id, o.relationship_custom_label, 1, o.created_at, o.updated_at
                FROM `" . TABLE_PREFIX . "relatives_person_owners` o
                WHERE (o.relationship_type_id IS NOT NULL
                       OR (o.relationship_custom_label IS NOT NULL AND o.relationship_custom_label != '')
                       OR o.related_to_person_id IS NOT NULL)
                  AND NOT EXISTS (
                    SELECT 1 FROM `" . TABLE_PREFIX . "relatives_relations` r
                    WHERE r.person_id = o.person_id AND r.owner_uid = o.owner_uid
                  )");

            $db->write_query("ALTER TABLE `" . TABLE_PREFIX . "relatives_person_owners`
                DROP COLUMN `relationship_type_id`,
                DROP COLUMN `relationship_custom_label`,
                DROP COLUMN `related_to_person_id`");
        }

        if (!$db->index_exists('relatives_person_owners', 'computed_concept_key')) {
            $db->write_query("ALTER TABLE `" . TABLE_PREFIX . "relatives_person_owners` ADD KEY `computed_concept_key` (`computed_concept_key`)");
        }

        if (!$db->index_exists('relatives_persons', 'name')) {
            $db->write_query("ALTER TABLE `" . TABLE_PREFIX . "relatives_persons` ADD KEY `name` (`name`(191))");
        }
        if (!$db->index_exists('relatives_persons', 'legacy')) {
            $db->write_query("ALTER TABLE `" . TABLE_PREFIX . "relatives_persons` ADD KEY `legacy` (`legacy_source`, `legacy_id`)");
        }
    }

    public static function deactivate(): void
    {
        global $db;

        require_once MYBB_ROOT . "inc/adminfunctions_templates.php";
        require_once MYBB_ADMIN_DIR . "inc/functions_themes.php";

        // Templates
        $db->delete_query('templates', "title LIKE 'relatives%'");

        // Settings + Gruppe bleiben beim Deaktivieren erhalten (siehe uninstall()) --
        // Deaktivieren ist reversibel, Uninstall nicht.

        // Stylesheets aus allen Themes
        $db->delete_query("themestylesheets", "name = 'relatives.css'");
        $db->delete_query("themestylesheets", "name = 'relatives-tree.css'");
        self::detach_usercp_stylesheet();
        self::uninstall_usercp_nav_misc_link();
        self::uninstall_profile_variable();
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
                'title'       => 'Maximale Personen pro Nutzer*in',
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
            [
                'name'        => 'relatives_graph_merged',
                'title'       => 'Gemeinsames Geflecht zusammengefuehrt (Unix-Zeitstempel)',
                'description' => 'Wird automatisch gesetzt, sobald die frueheren Einzel-Listen im ACP zu einem gemeinsamen Stammbaum-Geflecht zusammengefuehrt wurden. 0 = noch nicht. Nicht manuell aendern.',
                'optionscode' => 'text',
                'value'       => '0',
                'disporder'   => 6,
            ],
            [
                'name'        => 'relatives_family_list_guests',
                'title'       => 'Familienliste fuer Gaeste',
                'description' => 'Duerfen Gaeste die oeffentliche Familienliste (relatives.php?action=family_list) sehen? Die Gruppenberechtigung "Forum ansehen" gilt zusaetzlich.',
                'optionscode' => 'yesno',
                'value'       => '1',
                'disporder'   => 7,
            ],
            [
                'name'        => 'relatives_family_list_layout_template',
                'title'       => 'Familienliste: Rahmen-Template (optional)',
                'description' => 'Name eines vorhandenen Templates, in das die Familienliste eingebettet wird, z. B. der Rahmen eurer uebrigen Listen ("listen"). Es erhaelt den Inhalt als {$listen_content} bzw. {$relatives_family_list_content}, die Navigation als {$listen_nav} bzw. {$relatives_list_nav} und den Titel als {$headline}. Leer = eigene Seite des Plugins.',
                'optionscode' => 'text',
                'value'       => '',
                'disporder'   => 8,
            ],
            [
                'name'        => 'relatives_family_list_nav_template',
                'title'       => 'Familienliste: Navigations-Template (optional)',
                'description' => 'Name eines vorhandenen Templates mit der Listen-Navigation, z. B. "listen_nav". Nur zusammen mit dem Rahmen-Template wirksam.',
                'optionscode' => 'text',
                'value'       => '',
                'disporder'   => 9,
            ],
            [
                'name'        => 'relatives_tree_link_profile',
                'title'       => 'Stammbaum-Link im Profil',
                'description' => 'Zeigt im Profil einen Link zum Stammbaum des Charakters. Template-Variable {$relatives_tree_link} im Template member_profile.',
                'optionscode' => 'yesno',
                'value'       => '1',
                'disporder'   => 10,
            ],
            [
                'name'        => 'relatives_tree_link_postbit',
                'title'       => 'Stammbaum-Link im Postbit',
                'description' => 'Zeigt im Postbit einen Link zum Stammbaum der Autor*in. Template-Variable {$post[\'relatives_tree_link\']} in den Templates postbit und postbit_classic.',
                'optionscode' => 'yesno',
                'value'       => '0',
                'disporder'   => 11,
            ],
            [
                'name'        => 'relatives_tree_link_memberlist',
                'title'       => 'Stammbaum-Link in der Mitgliederliste',
                'description' => 'Zeigt in der Mitgliederliste je Mitglied einen Link zum Stammbaum. Template-Variable {$user[\'relatives_tree_link\']} im Template memberlist_user.',
                'optionscode' => 'yesno',
                'value'       => '0',
                'disporder'   => 12,
            ],
            [
                'name'        => 'relatives_inplay_year',
                'title'       => 'Aktuelles Inplay-Jahr',
                'description' => 'Jahr, in dem das Inplay gerade spielt. Ist es gesetzt, tragt ihr bei Personen ein Geburtsjahr ein und das Alter wird daraus berechnet. Leer = altes Feld "Alter".',
                'optionscode' => 'text',
                'value'       => '',
                'disporder'   => 13,
            ],
        ];

        // Aufraeumen: Setting aus einem kurzlebigen Entwicklungsstand.
        $db->delete_query('settings', "name = 'relatives_wanted_box_templates'");

        foreach ($settings as $setting) {
            if ($db->fetch_array($db->simple_select('settings', 'sid', "name = '" . $db->escape_string($setting['name']) . "'"))) {
                continue; // idempotent: bereits vorhanden (Upgrade-Pfad)
            }
            // insert_query() escaped nicht selbst -- Beschreibungen enthalten Apostrophe
            // ({$post['relatives_tree_link']}).
            $setting = array_map(static function ($value) use ($db) {
                return is_string($value) ? $db->escape_string($value) : $value;
            }, $setting);
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

    /**
     * relatives.php rendert die usercp_nav-Seitenleiste selbst (Baum-/Listenansicht), aber
     * MyBB laedt das Theme-Stylesheet "usercp.css" (das die usercp_nav_*-Icon-Regeln und das
     * .usercp_nav_item-Grundlayout enthaelt) nur auf Seiten, an die es per "attachedto"
     * gebunden ist -- default nur "usercp.php". Ohne diesen Eintrag fehlt auf relatives.php
     * das komplette Sidebar-Styling (nicht nur unser eigener Menuepunkt). Gleiches Muster wie
     * im PHP5-Vorgaengerplugin.
     */
    private static function attach_usercp_stylesheet(): void
    {
        global $db;

        require_once MYBB_ADMIN_DIR . "inc/functions_themes.php";

        $themes = $db->simple_select("themes", "tid");
        while ($theme = $db->fetch_array($themes)) {
            $tid = (int)$theme["tid"];
            $stylesheet = $db->fetch_array($db->simple_select(
                "themestylesheets",
                "sid, attachedto",
                "name = 'usercp.css' AND tid = " . $tid
            ));
            if (!$stylesheet) {
                continue;
            }
            $pages = array_filter(explode('|', (string)$stylesheet['attachedto']), 'strlen');
            if (in_array('relatives.php', $pages, true)) {
                continue;
            }
            $pages[] = 'relatives.php';
            $db->update_query("themestylesheets", [
                "attachedto" => implode('|', $pages),
            ], "sid=" . (int)$stylesheet['sid'], 1);
            update_theme_stylesheet_list($tid);
        }
    }

    private static function detach_usercp_stylesheet(): void
    {
        global $db;

        $themes = $db->simple_select("themes", "tid");
        while ($theme = $db->fetch_array($themes)) {
            $tid = (int)$theme["tid"];
            $stylesheet = $db->fetch_array($db->simple_select(
                "themestylesheets",
                "sid, attachedto",
                "name = 'usercp.css' AND tid = " . $tid
            ));
            if (!$stylesheet) {
                continue;
            }
            $pages = array_filter(
                explode('|', (string)$stylesheet['attachedto']),
                static function (string $page): bool {
                    return $page !== '' && $page !== 'relatives.php';
                }
            );
            $db->update_query("themestylesheets", [
                "attachedto" => implode('|', $pages),
            ], "sid=" . (int)$stylesheet['sid'], 1);
        }
    }

    /**
     * Verankert den UserCP-Menuepunkt in der Core-Box "Sonstiges" (Template usercp_nav_misc)
     * statt in der obersten Optionen-Box, in die der Hook "usercp_menu" per Default
     * (ueber $usercpmenu) einhaengt.
     *
     * Die komplette Zeile wird STATISCH ins Template gebacken (nur {$lang->...} fuer das
     * Label wird zur Laufzeit aufgeloest) -- exakt das Muster von logbook/char_archive/
     * favorites, die in derselben Box bereits nachweislich funktionieren. KEIN eigenes
     * Platzhalter-Global (z.B. {$relatives_usercp_row}) mehr: relatives.php ruft die
     * MyBB-Core-FUNKTION usercp_menu() direkt auf (siehe relatives.php:34), die
     * usercp_nav_misc innerhalb ihres eigenen Funktions-Scopes eval()t. Ein per Hook per
     * "global $xyz;" gesetztes Custom-Global ist dort nicht sichtbar, weil usercp_menu()
     * diese Variable nie selbst "global" deklariert -- nur Objekte wie $lang/$mybb
     * funktionieren plugin-uebergreifend (Methodenaufrufe veraendern die eine geteilte
     * Instanz, unabhaengig vom Scope). Das war der eigentliche Grund, warum der Punkt nie
     * erschien, obwohl Template UND Einstellung korrekt waren. relatives_enabled bleibt
     * trotzdem wirksam: es blendet die Zeile nicht mehr aus, sondern relatives.php zeigt
     * beim Aufruf $lang->relatives_disabled (siehe relatives.php:22-24) -- selbes Verhalten
     * wie bei jedem anderen hier ueblichen Master-Schalter.
     *
     * Anker ist die unveraenderte Kern-Zeile fuer "Entwuerfe".
     */
    private static function usercp_nav_misc_anchor(): string
    {
        return '<tr><td class="trow1 smalltext"><a href="usercp.php?action=drafts" class="usercp_nav_item usercp_nav_drafts">{$draftcount}</a></td></tr>';
    }

    private static function usercp_nav_misc_row(): string
    {
        return '<tr><td class="trow1 smalltext"><a href="relatives.php?action=list" class="usercp_nav_item usercp_nav_relatives" style="background-repeat: no-repeat; background-position: 0 50%; padding-left: 23px; background-image: url(\'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2216%22 height=%2216%22 viewBox=%220 0 16 16%22%3E%3Ccircle cx=%224%22 cy=%224%22 r=%222.2%22 fill=%22%23555%22/%3E%3Ccircle cx=%2212%22 cy=%224%22 r=%222.2%22 fill=%22%23555%22/%3E%3Ccircle cx=%228%22 cy=%2211%22 r=%222.6%22 fill=%22%23555%22/%3E%3Cpath d=%22M4 6.5V8a2 2 0 0 0 2 2M12 6.5V8a2 2 0 0 1-2 2%22 stroke=%22%23555%22 stroke-width=%221%22 fill=%22none%22/%3E%3C/svg%3E\');">{$lang->relatives_usercp_menu_item}</a></td></tr>';
    }

    private static function install_usercp_nav_misc_link(): void
    {
        require_once MYBB_ROOT . "inc/adminfunctions_templates.php";

        // Idempotent: zuerst entfernen, dann neu einhaengen (verhindert Duplikate bei
        // wiederholtem Aktivieren).
        self::uninstall_usercp_nav_misc_link();

        $anchor = self::usercp_nav_misc_anchor();
        find_replace_templatesets(
            'usercp_nav_misc',
            '#' . preg_quote($anchor, '#') . '#i',
            $anchor . self::usercp_nav_misc_row()
        );
    }

    private static function uninstall_usercp_nav_misc_link(): void
    {
        require_once MYBB_ROOT . "inc/adminfunctions_templates.php";

        // Match by href + class instead of the exact row, so rows baked by older
        // versions (e.g. different inline style) are removed as well.
        find_replace_templatesets(
            'usercp_nav_misc',
            '#<tr><td[^>]*><a href="relatives\.php\?action=list" class="usercp_nav_item usercp_nav_relatives"[^>]*>.*?</a></td></tr>#i',
            '',
            0
        );

        // Migrations-Aufraeumer: entfernt den leeren Platzhalter-Rest aus frueheren
        // Entwicklungsstaenden dieser Methode (bare {$relatives_usercp_row}-Variable statt
        // statisch gebackener Zeile), falls er auf einer bereits laufenden Installation
        // noch im Template haengt.
        find_replace_templatesets(
            'usercp_nav_misc',
            '#' . preg_quote('{$relatives_usercp_row}', '#') . '#i',
            '',
            0
        );
    }

    /**
     * Setzt {$relatives_wanted_box} (Gesucht-Karten) ins Template member_profile, je
     * Template-Set einzeln: vor {$signature}, ohne Signatur vor {$footer}. Nur in Sets,
     * deren member_profile die Variable noch nicht enthaelt -- eine von Hand verschobene
     * Variable bleibt beim erneuten Aktivieren an ihrem Platz. Andere Templates
     * (member_profile_*) fasst das Plugin bewusst nicht an.
     */
    private static function install_profile_variable(): void
    {
        require_once MYBB_ROOT . "inc/adminfunctions_templates.php";

        // Lookahead ab Textanfang: greift nur, wenn die Variable im Template noch fehlt.
        $missing = '^(?!.*' . preg_quote('{$relatives_wanted_box}', '#') . ')(.*?)';
        foreach (['{$signature}', '{$footer}'] as $anchor) {
            find_replace_templatesets(
                'member_profile',
                '#' . $missing . preg_quote($anchor, '#') . '#is',
                '${1}{$relatives_wanted_box}' . $anchor
            );
        }
    }

    private static function uninstall_profile_variable(): void
    {
        require_once MYBB_ROOT . "inc/adminfunctions_templates.php";

        find_replace_templatesets(
            'member_profile',
            '#' . preg_quote('{$relatives_wanted_box}', '#') . '#i',
            '',
            0
        );
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
