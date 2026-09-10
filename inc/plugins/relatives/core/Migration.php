<?php
/**
 * relatives - Migration
 *
 * Legacy-Import aus mybb_relatives / mybb_wanted / mybb_relatives_options (Altplugin
 * "relatives" v1.0). Siehe PROJECT_CONTEXT.md "Migration (Legacy -> Neu)" fuer die
 * genaue Feld-/Mapping-Spezifikation. Re-Run-Schutz ueber Setting
 * relatives_legacy_migrated (Unix-Zeitstempel, 0 = noch nie migriert).
 *
 * Quelltabellen werden ueber $db->table_exists() OHNE TABLE_PREFIX angesprochen
 * (table_exists() ergaenzt TABLE_PREFIX selbst -- das entspricht auf dem Live-Forum
 * exakt 'mybb_relatives' usw., siehe Dev-Kit conventions.md §4).
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_Migration
{
    /**
     * Festes Mapping-Woerterbuch: alter, deutscher Options-Text (aus
     * mybb_relatives_options, option_name='status') -> [concept_key, gender,
     * generation_offset]. gender hier ist relatives_relationship_types.gender
     * (0=maennlich, 1=weiblich, 2=neutral).
     */
    public const RELATIONSHIP_MAP = [
        'Mutter'   => ['parent', 1, -1],
        'Vater'    => ['parent', 0, -1],
        'Stiefmutter' => ['parent_step', 1, -1],
        'Stiefvater'  => ['parent_step', 0, -1],
        'Adoptivmutter' => ['parent_adoptive', 1, -1],
        'Adoptivvater'  => ['parent_adoptive', 0, -1],

        'Schwester' => ['sibling', 1, 0],
        'Bruder'    => ['sibling', 0, 0],
        'Halbschwester' => ['sibling_half', 1, 0],
        'Halbbruder'    => ['sibling_half', 0, 0],
        'Stiefschwester' => ['sibling_step', 1, 0],
        'Stiefbruder'    => ['sibling_step', 0, 0],
        'Adoptivschwester' => ['sibling_adoptive', 1, 0],
        'Adoptivbruder'    => ['sibling_adoptive', 0, 0],

        'Cousine' => ['cousin', 1, 0],
        'Cousin'  => ['cousin', 0, 0],
        'Cousine (Stief-, Adoptiv-,...)' => ['cousin_other', 1, 0],
        'Cousin (Stief-, Adoptiv-,...)'  => ['cousin_other', 0, 0],

        'Onkel mütterlicherseits' => ['aunt_uncle_maternal', 0, -1],
        'Tante mütterlicherseits' => ['aunt_uncle_maternal', 1, -1],
        'Onkel mütterlicherseits (Stief-, Adoptiv-,...)' => ['aunt_uncle_maternal_other', 0, -1],
        'Tante mütterlicherseits (Stief-, Adoptiv-,...)' => ['aunt_uncle_maternal_other', 1, -1],
        'Onkel väterlicherseits' => ['aunt_uncle_paternal', 0, -1],
        'Tante väterlicherseits' => ['aunt_uncle_paternal', 1, -1],
        'Onkel väterlicherseits (Stief-, Adoptiv-,...)' => ['aunt_uncle_paternal_other', 0, -1],
        'Tante väterlicherseits (Stief-, Adoptiv-,...)' => ['aunt_uncle_paternal_other', 1, -1],

        'Sohn'    => ['child', 0, 1],
        'Tochter' => ['child', 1, 1],
        'Adoptivsohn'  => ['child_adoptive', 0, 1],
        'Adoptivtochter' => ['child_adoptive', 1, 1],
        'Stiefsohn'  => ['child_step', 0, 1],
        'Stieftochter' => ['child_step', 1, 1],

        'Ehemann' => ['spouse', 0, 0],
        'Ehefrau' => ['spouse', 1, 0],
        'Ex-Mann' => ['ex_spouse', 0, 0],
        'Ex-Frau' => ['ex_spouse', 1, 0],

        'Großmutter mütterlicherseits' => ['grandparent_maternal', 1, -2],
        'Großvater mütterlicherseits'  => ['grandparent_maternal', 0, -2],
        'Großmutter mütterlicherseits (Stief-, Adoptiv-,...)' => ['grandparent_maternal_other', 1, -2],
        'Großvater mütterlicherseits (Stief-, Adoptiv-,...)'  => ['grandparent_maternal_other', 0, -2],
        'Großmutter väterlicherseits' => ['grandparent_paternal', 1, -2],
        'Großvater väterlicherseits'  => ['grandparent_paternal', 0, -2],
        'Großmutter väterlicherseits (Stief-, Adoptiv-,...)' => ['grandparent_paternal_other', 1, -2],
        'Großvater väterlicherseits (Stief-, Adoptiv-,...)'  => ['grandparent_paternal_other', 0, -2],

        'NPC' => ['other', 2, 0],
    ];

    /** option_name -> Label der neuen relatives_attribute_groups-Zeile. */
    private const ATTRIBUTE_GROUP_MAP = [
        'haarfarbe'  => 'Haarfarbe',
        'augenfarbe' => 'Augenfarbe',
        'ethnik'     => 'Ethnie',
        'blutstatus' => 'Blutstatus',
    ];

    public static function legacy_tables_available(): array
    {
        global $db;
        return [
            'relatives'         => $db->table_exists('relatives'),
            'wanted'            => $db->table_exists('wanted'),
            'relatives_options' => $db->table_exists('relatives_options'),
        ];
    }

    public static function already_migrated(): bool
    {
        global $mybb;
        return isset($mybb->settings['relatives_legacy_migrated']) && (int)$mybb->settings['relatives_legacy_migrated'] > 0;
    }

    /**
     * Fuehrt die komplette Migration aus. $force = true erlaubt einen erneuten Lauf,
     * auch wenn bereits migriert wurde (explizite ACP-Bestaetigung noetig).
     *
     * Rueckgabe: Abschlussbericht-Array (nicht persistiert), u.a.
     * 'missing_neutral_concepts' => Liste der concept_keys ohne gender=2-Variante.
     */
    public static function run(bool $force = false): array
    {
        global $db;

        $report = [
            'ran'                       => false,
            'reason'                    => '',
            'relationship_types_created' => 0,
            'attribute_groups_created'  => 0,
            'attribute_options_created' => 0,
            'persons_migrated'          => 0,
            'wanted_migrated'           => 0,
            'skipped_rows'              => 0,
            'missing_neutral_concepts'  => [],
        ];

        if (self::already_migrated() && !$force) {
            $report['reason'] = 'already_migrated';
            return $report;
        }

        $available = self::legacy_tables_available();
        if (!$available['relatives_options'] && !$available['relatives'] && !$available['wanted']) {
            $report['reason'] = 'no_legacy_tables';
            return $report;
        }

        $type_id_map = [];   // legacy relatives_options.id (status) -> neue relationship_type_id
        $attribute_option_id_map = []; // legacy relatives_options.id (attribut) -> neue attribute_option_id

        if ($available['relatives_options']) {
            self::migrate_options($type_id_map, $attribute_option_id_map, $report);
        }

        if ($available['relatives']) {
            $report['persons_migrated'] = self::migrate_persons_table('relatives', $type_id_map, $report);
        }
        if ($available['wanted']) {
            $report['wanted_migrated'] = self::migrate_persons_table('wanted', $type_id_map, $report);
        }

        $report['missing_neutral_concepts'] = self::find_missing_neutral_concepts();

        $db->update_query('settings', ['value' => TIME_NOW], "name = 'relatives_legacy_migrated'");
        rebuild_settings();

        $report['ran'] = true;
        return $report;
    }

    private static function migrate_options(array &$type_id_map, array &$attribute_option_id_map, array &$report): void
    {
        global $db;

        // Attribut-Gruppen vorab anlegen (idempotent: per Label suchen)
        $group_id_by_name = [];
        foreach (self::ATTRIBUTE_GROUP_MAP as $option_name => $label) {
            $existing = $db->fetch_array($db->simple_select('relatives_attribute_groups', 'id', "label = '" . $db->escape_string($label) . "'", ['limit' => 1]));
            if ($existing) {
                $group_id_by_name[$option_name] = (int)$existing['id'];
            } else {
                $gid = Relatives_Attributes::create_group(['label' => $label, 'sort_order' => 0, 'is_active' => 1]);
                $group_id_by_name[$option_name] = $gid;
                $report['attribute_groups_created']++;
            }
        }

        $query = $db->simple_select('relatives_options', '*');
        while ($row = $db->fetch_array($query)) {
            if (!isset($row['option_name'], $row['option_value'], $row['id'])) {
                $report['skipped_rows']++;
                continue;
            }
            $legacy_id = (int)$row['id'];
            $option_name = trim((string)$row['option_name']);
            $option_value = trim((string)$row['option_value']);

            if ($option_name === 'status') {
                if (isset(self::RELATIONSHIP_MAP[$option_value])) {
                    [$concept_key, $gender, $offset] = self::RELATIONSHIP_MAP[$option_value];
                } else {
                    // Nicht paarbarer Wert: neutral, offset 0, concept_key aus Label ableiten.
                    $concept_key = 'legacy_' . substr(preg_replace('/[^a-z0-9]+/', '_', strtolower($option_value)), 0, 40);
                    $gender = 2;
                    $offset = 0;
                }

                $existing = $db->fetch_array($db->simple_select(
                    'relatives_relationship_types',
                    'id',
                    "concept_key = '" . $db->escape_string($concept_key) . "' AND gender = " . (int)$gender,
                    ['limit' => 1]
                ));
                if ($existing) {
                    $type_id_map[$legacy_id] = (int)$existing['id'];
                    continue;
                }

                $new_id = Relatives_RelationshipTypes::create([
                    'concept_key'       => $concept_key,
                    'gender'            => $gender,
                    'label'             => $option_value,
                    'generation_offset' => $offset,
                    'sort_order'        => 0,
                    'is_active'         => 1,
                ]);
                $type_id_map[$legacy_id] = $new_id;
                $report['relationship_types_created']++;
            } elseif (isset($group_id_by_name[$option_name])) {
                $group_id = $group_id_by_name[$option_name];
                $existing = $db->fetch_array($db->simple_select(
                    'relatives_attribute_options',
                    'id',
                    "group_id = " . (int)$group_id . " AND label = '" . $db->escape_string($option_value) . "'",
                    ['limit' => 1]
                ));
                if ($existing) {
                    $attribute_option_id_map[$legacy_id] = (int)$existing['id'];
                    continue;
                }
                $new_id = Relatives_Attributes::create_option([
                    'group_id'   => $group_id,
                    'label'      => $option_value,
                    'sort_order' => 0,
                    'is_active'  => 1,
                ]);
                $attribute_option_id_map[$legacy_id] = $new_id;
                $report['attribute_options_created']++;
            } else {
                $report['skipped_rows']++;
            }
        }
    }

    /**
     * Migriert die Zeilen einer der beiden Quelltabellen ('relatives' oder 'wanted')
     * nach relatives_persons. Beide Tabellen haben laut Altplugin dieselben zentralen
     * Spalten (owner_uid, name, geschlecht, alter/age, herkunft, beziehung, avatar,
     * gesucht, link, status, beruf); 'beruf' wird bewusst NICHT migriert (Aufgabe der
     * Jobliste-Migration). Fehlende Spalten werden per isset()-Guard uebersprungen statt
     * einen Fehler zu werfen (Schema-Varianten zwischen den beiden Alt-Tabellen).
     */
    private static function migrate_persons_table(string $table, array $type_id_map, array &$report): int
    {
        global $db;

        $count = 0;
        $legacy_source = $table === 'wanted' ? 'wanted' : 'relatives';

        $query = $db->simple_select($table, '*');
        while ($row = $db->fetch_array($query)) {
            if (!isset($row['id'])) {
                $report['skipped_rows']++;
                continue;
            }

            $legacy_id = (int)$row['id'];

            // Re-Run-Schutz auf Zeilenebene: bereits importierte legacy_id/source ueberspringen.
            $already = $db->fetch_array($db->simple_select(
                'relatives_persons',
                'id',
                "legacy_id = " . (int)$legacy_id . " AND legacy_source = '" . $db->escape_string($legacy_source) . "'",
                ['limit' => 1]
            ));
            if ($already) {
                continue;
            }

            $owner_uid = isset($row['owner_uid']) ? (int)$row['owner_uid'] : (isset($row['uid']) ? (int)$row['uid'] : 0);
            if ($owner_uid <= 0 || !isset($row['name']) || trim((string)$row['name']) === '') {
                $report['skipped_rows']++;
                continue;
            }

            $geschlecht = isset($row['geschlecht']) ? (int)$row['geschlecht'] : -1;
            if (!in_array($geschlecht, [-1, 0, 1], true)) {
                $geschlecht = -1;
            }

            $relationship_type_id = null;
            $relationship_custom_label = null;
            if ($table === 'wanted') {
                $relationship_custom_label = isset($row['status']) ? trim((string)$row['status']) : null;
                if ($relationship_custom_label === '') {
                    $relationship_custom_label = null;
                }
            } else {
                $legacy_status_id = isset($row['status']) ? (int)$row['status'] : 0;
                if ($legacy_status_id > 0 && isset($type_id_map[$legacy_status_id])) {
                    $relationship_type_id = $type_id_map[$legacy_status_id];
                }
            }

            $data = [
                'owner_uid'                 => $owner_uid,
                'name'                      => (string)$row['name'],
                'gender'                    => $geschlecht,
                'relationship_type_id'      => $relationship_type_id,
                'relationship_custom_label' => $relationship_custom_label,
                'age'                       => isset($row['age']) ? (int)$row['age'] : (isset($row['alter']) ? (int)$row['alter'] : null),
                'origin'                    => isset($row['herkunft']) ? (string)$row['herkunft'] : null,
                'avatar'                    => isset($row['avatar']) ? (string)$row['avatar'] : null,
                'description'               => isset($row['beziehung']) ? (string)$row['beziehung'] : null,
                'is_wanted'                 => isset($row['gesucht']) ? (int)(bool)$row['gesucht'] : ($table === 'wanted' ? 1 : 0),
                'wanted_thread_link'        => isset($row['link']) ? (string)$row['link'] : null,
                'legacy_id'                 => $legacy_id,
                'legacy_source'             => $legacy_source,
            ];

            Relatives_Database::create_person($data);
            $count++;
        }

        return $count;
    }

    /**
     * Fuer den Abschlussbericht: alle concept_keys, die keine neutrale (gender=2)
     * Variante haben.
     */
    public static function find_missing_neutral_concepts(): array
    {
        $grouped = Relatives_RelationshipTypes::get_grouped_by_concept();
        $missing = [];
        foreach ($grouped as $concept_key => $variants) {
            if ($variants[Relatives_RelationshipTypes::LABEL_NEUTRAL] === null) {
                $missing[] = $concept_key;
            }
        }
        return $missing;
    }
}
