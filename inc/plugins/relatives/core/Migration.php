<?php
/**
 * relatives - Migration
 *
 * Legacy-Import aus dem Altplugin "relatives" (Stand 2021). Quellen:
 * - mybb_relatives / mybb_wanted: Personen-Stammdaten (name, geschlecht, age, herkunft,
 *   avatar = Avatarperson, gesucht, link, gesuchstext, Attribut-Spalten haarfarbe/
 *   augenfarbe/ethnik/blutstatus als relatives_options-ids)
 * - mybb_relatives_status / mybb_wanted_status: Zuordnung Person <-> Besitzer*in
 *   (owner_uid, status, beziehung). status ist bei relatives eine relatives_options-id,
 *   bei wanted Freitext. Eine Person kann mehreren Charakteren gehoeren.
 * - mybb_relatives_options: Verwandtschaftsgrade (option_name='status') und Attribut-Werte.
 *
 * Siehe PROJECT_CONTEXT.md "Migration (Legacy -> Neu)". Re-Run-Schutz ueber Setting
 * relatives_legacy_migrated (Unix-Zeitstempel, 0 = noch nie migriert) sowie legacy_id auf
 * Personen- und Zuordnungsebene.
 *
 * Quelltabellen werden ueber $db->table_exists() OHNE TABLE_PREFIX angesprochen
 * (table_exists() ergaenzt TABLE_PREFIX selbst, siehe Dev-Kit conventions.md §4).
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_Migration
{
    /**
     * Mapping der alten Verwandtschaftsgrade: Grundbegriff => [concept_key,
     * Label-Geschlecht (0 maennlich, 1 weiblich)]. Zielkonzepte stammen ausschliesslich
     * aus Relatives_RelationshipTypes::COMMON_DEFAULTS. Zusaetze wie "mütterlicherseits",
     * "väterlicherseits" und Klammern ("(entfernt)", "(Stief-, Adoptiv-, angeheiratet,...)")
     * werden vor dem Nachschlagen entfernt (base_status_label()) -- die Konzepte kennen
     * diese Unterscheidung nicht. Ausnahme "(entfernt)": solche Grade werden zu
     * distant_relative (Geschlecht aus dem Grundbegriff), siehe map_status_label().
     * null = bewusst ohne Verwandtschaftsgrad uebernehmen.
     *
     * Ein genutzter alter Grad, der hier fehlt, bricht die Migration VOR dem ersten
     * Schreibzugriff ab -- es gibt keinen Freitext-Fallback.
     */
    public const STATUS_BASE_MAP = [
        'Mutter'           => ['parent', 1],
        'Vater'            => ['parent', 0],
        'Stiefmutter'      => ['step_parent', 1],
        'Stiefvater'       => ['step_parent', 0],
        'Adoptivmutter'    => ['adoptive_parent', 1],
        'Adoptivvater'     => ['adoptive_parent', 0],
        'Schwester'        => ['sibling', 1],
        'Bruder'           => ['sibling', 0],
        'Halbschwester'    => ['half_sibling', 1],
        'Halbbruder'       => ['half_sibling', 0],
        'Stiefschwester'   => ['step_sibling', 1],
        'Stiefbruder'      => ['step_sibling', 0],
        'Adoptivschwester' => ['adoptive_sibling', 1],
        'Adoptivbruder'    => ['adoptive_sibling', 0],
        'Tochter'          => ['child', 1],
        'Sohn'             => ['child', 0],
        'Adoptivtochter'   => ['adoptive_child', 1],
        'Adoptivsohn'      => ['adoptive_child', 0],
        'Stieftochter'     => ['step_child', 1],
        'Stiefsohn'        => ['step_child', 0],
        'Großmutter'       => ['grandparent', 1],
        'Großvater'        => ['grandparent', 0],
        'Urgroßmutter'     => ['great_grandparent', 1],
        'Urgroßvater'      => ['great_grandparent', 0],
        'Enkelin'          => ['grandchild', 1],
        'Enkel'            => ['grandchild', 0],
        'Tante'            => ['aunt_uncle', 1],
        'Onkel'            => ['aunt_uncle', 0],
        'Nichte'           => ['niece_nephew', 1],
        'Neffe'            => ['niece_nephew', 0],
        'Cousine'          => ['cousin', 1],
        'Cousin'           => ['cousin', 0],
        'Ehefrau'          => ['spouse', 1],
        'Ehemann'          => ['spouse', 0],
        'Ex-Frau'          => ['ex_spouse', 1],
        'Ex-Mann'          => ['ex_spouse', 0],
        'Verlobte'         => ['fiance', 1],
        'Verlobter'        => ['fiance', 0],
        'Schwägerin'       => ['sibling_in_law', 1],
        'Schwager'         => ['sibling_in_law', 0],
        'Schwiegertochter' => ['child_in_law', 1],
        'Schwiegersohn'    => ['child_in_law', 0],
        'NPC'              => null,
    ];

    /** option_name -> Label der neuen relatives_attribute_groups-Zeile. */
    private const ATTRIBUTE_GROUP_MAP = [
        'haarfarbe'  => 'Haarfarbe',
        'augenfarbe' => 'Augenfarbe',
        'ethnik'     => 'Ethnie',
        'blutstatus' => 'Blutstatus',
    ];

    /** Personentabelle => [Zuordnungstabelle, Spalte mit der Personen-id]. */
    private const SOURCES = [
        'relatives' => ['relatives_status', 'relative_id'],
        'wanted'    => ['wanted_status', 'wanted_id'],
    ];

    /** Platzhalter des Altplugins in "herkunft": 0 = keine Angabe, -1 = egal, -2 = Sonstiges. */
    private const ORIGIN_PLACEHOLDERS = ['', '0', '-1', '-2'];

    public static function legacy_tables_available(): array
    {
        global $db;
        $tables = [];
        foreach (['relatives', 'relatives_status', 'wanted', 'wanted_status', 'relatives_options'] as $table) {
            $tables[$table] = $db->table_exists($table);
        }
        return $tables;
    }

    public static function already_migrated(): bool
    {
        global $mybb;
        return isset($mybb->settings['relatives_legacy_migrated']) && (int)$mybb->settings['relatives_legacy_migrated'] > 0;
    }

    /** "Onkel mütterlicherseits (entfernt)" -> "Onkel". */
    public static function base_status_label(string $label): string
    {
        $base = preg_replace('/\s*\(.*\)\s*$/u', '', trim($label));
        $base = preg_replace('/\s+(mütterlicherseits|väterlicherseits)$/iu', '', trim((string)$base));
        return trim((string)$base);
    }

    /**
     * Ordnet einen alten Grad zu.
     *
     * @return array ['mapped' => bool, 'concept' => ?string, 'gender' => ?int]
     */
    public static function map_status_label(string $label): array
    {
        static $index = null;
        if ($index === null) {
            $index = [];
            foreach (self::STATUS_BASE_MAP as $key => $target) {
                $index[mb_strtolower($key)] = $target;
            }
        }

        $key = mb_strtolower(self::base_status_label($label));
        if (!array_key_exists($key, $index)) {
            return ['mapped' => false, 'concept' => null, 'gender' => null];
        }
        $target = $index[$key];
        // "(entfernt)" hiess im Altplugin: irgendwie weitlaeufig verwandt, ohne dass der
        // Weg bekannt ist ("Cousine (entfernt)"). Als echte Cousine uebernommen, stuende sie
        // falsch im Baum -- dafuer gibt es den Sammelbegriff "entfernte*r Verwandte*r".
        if ($target !== null && preg_match('/\(\s*entfernt\s*\)/iu', $label)) {
            $target = ['distant_relative', $target[1]];
        }
        return [
            'mapped'  => true,
            'concept' => $target === null ? null : $target[0],
            'gender'  => $target === null ? null : $target[1],
        ];
    }

    /**
     * Vorschau ohne Schreibzugriff: Zuordnung jedes alten Grades samt Nutzung sowie die
     * Kennzahlen, welche Personen/Zuordnungen uebernommen bzw. uebersprungen wuerden.
     * 'unmapped' enthaelt nur genutzte, nicht zugeordnete Grade (die blockieren run()).
     */
    public static function preview(): array
    {
        global $db;

        $preview = [
            'tables'   => self::legacy_tables_available(),
            'statuses' => [],
            'unmapped' => [],
            'stats'    => self::empty_stats(),
        ];

        $valid_uids = self::load_valid_uids();
        $status_usage = [];
        foreach (self::SOURCES as $table => [$status_table, $fk]) {
            $collected = self::collect_links($table, $status_table, $fk, $valid_uids);
            foreach ($collected['stats'] as $key => $value) {
                $preview['stats'][$key] += $value;
            }
            if ($table === 'relatives') {
                $status_usage = $collected['status_usage'];
            }
        }

        if (!$preview['tables']['relatives_options']) {
            return $preview;
        }

        $known_ids = [];
        $query = $db->simple_select('relatives_options', 'id, option_value', "option_name = 'status'", ['order_by' => 'id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($query)) {
            $legacy_id = (int)$row['id'];
            $known_ids[$legacy_id] = true;
            $label = (string)$row['option_value'];
            $links = $status_usage[$legacy_id] ?? 0;
            $mapping = self::map_status_label($label);

            $target_label = null;
            if ($mapping['concept'] !== null) {
                $type = Relatives_RelationshipTypes::find_by_concept_and_gender($mapping['concept'], (int)$mapping['gender']);
                $target_label = $type !== null
                    ? (string)$type['label']
                    : Relatives_RelationshipTypes::default_label($mapping['concept'], (int)$mapping['gender']);
            }

            $preview['statuses'][] = [
                'legacy_id'    => $legacy_id,
                'label'        => $label,
                'mapped'       => $mapping['mapped'],
                'concept'      => $mapping['concept'],
                'target_label' => $target_label,
                'links'        => $links,
            ];
            if (!$mapping['mapped'] && $links > 0) {
                $preview['unmapped'][] = $label;
            }
        }

        foreach ($status_usage as $status_id => $count) {
            if (!isset($known_ids[$status_id])) {
                $preview['stats']['links_invalid_status'] += $count;
            }
        }

        return $preview;
    }

    /**
     * Fuehrt die komplette Migration aus. $force = true erlaubt einen erneuten Lauf,
     * auch wenn bereits migriert wurde (explizite ACP-Bestaetigung noetig).
     *
     * Rueckgabe: Abschlussbericht-Array (nicht persistiert).
     */
    public static function run(bool $force = false): array
    {
        global $db;

        $report = [
            'ran'                          => false,
            'reason'                       => '',
            'relationship_types_created'   => 0,
            'attribute_groups_created'     => 0,
            'attribute_options_created'    => 0,
            'persons_created_relatives'    => 0,
            'persons_created_wanted'       => 0,
            'owner_links_created'          => 0,
            'attribute_assignments'        => 0,
            'links_without_relationship'   => 0,
            'persons_skipped_no_owner'     => 0,
            'persons_skipped_invalid'      => 0,
            'links_skipped_missing_user'   => 0,
            'links_skipped_missing_person' => 0,
            'links_skipped_duplicate'      => 0,
            'structure_anchored'           => 0,
            'structure_ambiguous'          => 0,
            'structure_unresolved'         => 0,
            'unmapped_statuses'            => [],
            'missing_neutral_concepts'     => [],
        ];

        if (self::already_migrated() && !$force) {
            $report['reason'] = 'already_migrated';
            return $report;
        }

        $available = self::legacy_tables_available();
        $has_source = ($available['relatives'] && $available['relatives_status'])
            || ($available['wanted'] && $available['wanted_status']);
        if (!$has_source) {
            $report['reason'] = 'no_legacy_tables';
            return $report;
        }

        // Vorabpruefung vor dem ersten Schreibzugriff: kein genutzter alter Grad darf
        // ohne Zuordnung bleiben.
        $preview = self::preview();
        if (!empty($preview['unmapped'])) {
            $report['reason'] = 'unmapped_statuses';
            $report['unmapped_statuses'] = $preview['unmapped'];
            return $report;
        }

        // Das Mapping zeigt nur auf Standard-Konzepte -- fehlende Zeilen jetzt anlegen.
        $report['relationship_types_created'] = Relatives_RelationshipTypes::create_common_defaults();

        $type_id_map = $available['relatives_options'] ? self::build_type_id_map() : [];
        $attribute_option_id_map = $available['relatives_options'] ? self::migrate_attribute_options($report) : [];
        $valid_uids = self::load_valid_uids();

        foreach (self::SOURCES as $table => [$status_table, $fk]) {
            self::migrate_source($table, $status_table, $fk, $type_id_map, $attribute_option_id_map, $valid_uids, $report);
        }
        // Imported without the single-value rule: groups where someone got several values
        // become multi-select, so the next form save keeps them.
        Relatives_Attributes::flag_multi_value_groups();

        // Stammbaum-Struktur ableiten (Bezugspersonen), siehe Relatives_StructureInference.
        $structure = Relatives_StructureInference::apply_to_migrated_data();
        $report['structure_anchored'] = $structure['anchored'];
        $report['structure_ambiguous'] = $structure['ambiguous'];
        $report['structure_unresolved'] = $structure['unresolved'];

        $report['missing_neutral_concepts'] = self::find_missing_neutral_concepts();

        $db->update_query('settings', ['value' => TIME_NOW], "name = 'relatives_legacy_migrated'");
        rebuild_settings();

        $report['ran'] = true;
        return $report;
    }

    private static function empty_stats(): array
    {
        return [
            'persons_total'         => 0,
            'persons_with_owner'    => 0,
            'persons_without_owner' => 0,
            'links_total'           => 0,
            'links_valid'           => 0,
            'links_missing_user'    => 0,
            'links_missing_person'  => 0,
            'links_duplicate'       => 0,
            'links_invalid_status'  => 0,
        ];
    }

    /** Alle existierenden Accounts als Set (uid => true). */
    private static function load_valid_uids(): array
    {
        global $db;
        $uids = [];
        $query = $db->simple_select('users', 'uid');
        while ($row = $db->fetch_array($query)) {
            $uids[(int)$row['uid']] = true;
        }
        return $uids;
    }

    /**
     * Liest die Zuordnungen einer Quelle und sortiert aus, was nicht uebernommen werden
     * kann: Links auf geloeschte Accounts, Links auf nicht (mehr) existierende Personen
     * und doppelte (Person, Besitzer*in)-Paare (der aelteste Link gewinnt).
     */
    private static function collect_links(string $table, string $status_table, string $fk, array $valid_uids): array
    {
        global $db;

        $result = [
            'available'       => false,
            'links_by_person' => [],
            'status_usage'    => [],
            'stats'           => self::empty_stats(),
        ];
        if (!$db->table_exists($table) || !$db->table_exists($status_table)) {
            return $result;
        }
        $result['available'] = true;
        $stats = self::empty_stats();

        $person_ids = [];
        $query = $db->simple_select($table, 'id');
        while ($row = $db->fetch_array($query)) {
            $person_ids[(int)$row['id']] = true;
        }
        $stats['persons_total'] = count($person_ids);

        $seen = [];
        $query = $db->simple_select($status_table, '*', '', ['order_by' => 'id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($query)) {
            $stats['links_total']++;
            $person_id = isset($row[$fk]) ? (int)$row[$fk] : 0;
            $owner_uid = isset($row['owner_uid']) ? (int)$row['owner_uid'] : 0;

            if (!isset($person_ids[$person_id])) {
                $stats['links_missing_person']++;
                continue;
            }
            if (!isset($valid_uids[$owner_uid])) {
                $stats['links_missing_user']++;
                continue;
            }
            if (isset($seen[$person_id][$owner_uid])) {
                $stats['links_duplicate']++;
                continue;
            }
            $seen[$person_id][$owner_uid] = true;
            $stats['links_valid']++;
            $result['links_by_person'][$person_id][] = $row;

            if ($table === 'relatives') {
                $status_id = isset($row['status']) ? (int)$row['status'] : 0;
                $result['status_usage'][$status_id] = ($result['status_usage'][$status_id] ?? 0) + 1;
            }
        }

        $stats['persons_with_owner'] = count($result['links_by_person']);
        $stats['persons_without_owner'] = $stats['persons_total'] - $stats['persons_with_owner'];
        $result['stats'] = $stats;
        return $result;
    }

    /** Alte relatives_options-id (Grad) -> neue relationship_type_id. */
    private static function build_type_id_map(): array
    {
        global $db;
        $map = [];
        $query = $db->simple_select('relatives_options', 'id, option_value', "option_name = 'status'");
        while ($row = $db->fetch_array($query)) {
            $mapping = self::map_status_label((string)$row['option_value']);
            if ($mapping['concept'] === null) {
                continue;
            }
            $type = Relatives_RelationshipTypes::find_by_concept_and_gender($mapping['concept'], (int)$mapping['gender']);
            if ($type !== null) {
                $map[(int)$row['id']] = (int)$type['id'];
            }
        }
        return $map;
    }

    /**
     * relatives_options (Attribut-Gruppen) -> relatives_attribute_groups/-options.
     * Idempotent: Gruppen und Optionen werden per Label wiederverwendet.
     *
     * @return array alte relatives_options-id -> neue attribute_option_id
     */
    private static function migrate_attribute_options(array &$report): array
    {
        global $db;

        $group_id_by_name = [];
        foreach (self::ATTRIBUTE_GROUP_MAP as $option_name => $label) {
            $existing = $db->fetch_array($db->simple_select('relatives_attribute_groups', 'id', "label = '" . $db->escape_string($label) . "'", ['limit' => 1]));
            if ($existing) {
                $group_id_by_name[$option_name] = (int)$existing['id'];
            } else {
                $group_id_by_name[$option_name] = Relatives_Attributes::create_group(['label' => $label, 'sort_order' => 0, 'is_active' => 1]);
                $report['attribute_groups_created']++;
            }
        }

        $map = [];
        $query = $db->simple_select('relatives_options', '*', '', ['order_by' => 'id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($query)) {
            $option_name = isset($row['option_name']) ? trim((string)$row['option_name']) : '';
            if (!isset($group_id_by_name[$option_name], $row['option_value'], $row['id'])) {
                continue; // Grade (option_name='status') laufen ueber build_type_id_map()
            }
            $group_id = $group_id_by_name[$option_name];
            $option_value = trim((string)$row['option_value']);

            $existing = $db->fetch_array($db->simple_select(
                'relatives_attribute_options',
                'id',
                "group_id = " . (int)$group_id . " AND label = '" . $db->escape_string($option_value) . "'",
                ['limit' => 1]
            ));
            if ($existing) {
                $map[(int)$row['id']] = (int)$existing['id'];
                continue;
            }
            $map[(int)$row['id']] = Relatives_Attributes::create_option([
                'group_id'   => $group_id,
                'label'      => $option_value,
                'sort_order' => 0,
                'is_active'  => 1,
            ]);
            $report['attribute_options_created']++;
        }

        return $map;
    }

    /**
     * Uebernimmt eine Quelle ('relatives' oder 'wanted'): jede Altperson mit mindestens
     * einer gueltigen Zuordnung wird genau eine neue Person (legacy_id bleibt eindeutig),
     * jede gueltige Zuordnung ein Eintrag in relatives_person_owners.
     * 'beruf' wird bewusst NICHT migriert (Aufgabe der Jobliste-Migration).
     */
    private static function migrate_source(string $table, string $status_table, string $fk, array $type_id_map, array $attribute_option_id_map, array $valid_uids, array &$report): void
    {
        global $db;

        $collected = self::collect_links($table, $status_table, $fk, $valid_uids);
        if (!$collected['available']) {
            return;
        }
        $report['links_skipped_missing_user'] += $collected['stats']['links_missing_user'];
        $report['links_skipped_missing_person'] += $collected['stats']['links_missing_person'];
        $report['links_skipped_duplicate'] += $collected['stats']['links_duplicate'];
        $report['persons_skipped_no_owner'] += $collected['stats']['persons_without_owner'];

        $query = $db->simple_select($table, '*', '', ['order_by' => 'id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($query)) {
            $legacy_id = (int)$row['id'];
            if (empty($collected['links_by_person'][$legacy_id])) {
                continue; // niemand (mehr) als Besitzer*in -- bleibt nur im Backup
            }

            $person_id = self::find_migrated_person($legacy_id, $table);
            if ($person_id === 0) {
                $data = self::person_data_from_legacy($row, $table);
                if ($data === null) {
                    $report['persons_skipped_invalid']++;
                    continue;
                }
                $person_id = Relatives_Database::create_person($data);
                $report['persons_created_' . $table]++;

                $option_ids = [];
                foreach (array_keys(self::ATTRIBUTE_GROUP_MAP) as $column) {
                    // <= 0 sind Platzhalter des Altplugins (0 keine Angabe, -1 egal, -2 Sonstiges)
                    $legacy_option_id = isset($row[$column]) ? (int)$row[$column] : 0;
                    if ($legacy_option_id > 0 && isset($attribute_option_id_map[$legacy_option_id])) {
                        $option_ids[] = $attribute_option_id_map[$legacy_option_id];
                    }
                }
                if ($option_ids) {
                    Relatives_Attributes::set_person_attributes($person_id, $option_ids, false);
                    $report['attribute_assignments'] += count($option_ids);
                }
            }

            foreach ($collected['links_by_person'][$legacy_id] as $link) {
                $owner_data = self::owner_data_from_legacy($link, $table, $type_id_map);
                $relation = [
                    'relationship_type_id'      => $owner_data['relationship_type_id'],
                    'relationship_custom_label' => $owner_data['relationship_custom_label'],
                ];
                unset($owner_data['relationship_type_id'], $owner_data['relationship_custom_label']);
                if (Relatives_Database::add_owner($person_id, (int)$link['owner_uid'], $owner_data) > 0) {
                    // Eigene Kante je Liste -- nicht add_owner()s Primaerkante, die bei einer
                    // Person aus mehreren Listen die Aussage der vorigen Liste ueberschriebe.
                    Relatives_Database::add_list_relation($person_id, (int)$link['owner_uid'], $relation);
                    $report['owner_links_created']++;
                    if ($relation['relationship_type_id'] === null && $relation['relationship_custom_label'] === null) {
                        $report['links_without_relationship']++;
                    }
                }
            }
        }
    }

    private static function find_migrated_person(int $legacy_id, string $source): int
    {
        global $db;
        $row = $db->fetch_array($db->simple_select(
            'relatives_persons',
            'id',
            "legacy_id = " . (int)$legacy_id . " AND legacy_source = '" . $db->escape_string($source) . "'",
            ['limit' => 1]
        ));
        return $row ? (int)$row['id'] : 0;
    }

    /** Stammdaten einer Altperson; null bei unbrauchbarer Zeile (kein Name). */
    private static function person_data_from_legacy(array $row, string $source): ?array
    {
        $name = isset($row['name']) ? trim((string)$row['name']) : '';
        if ($name === '') {
            return null;
        }

        $gender = isset($row['geschlecht']) ? (int)$row['geschlecht'] : -1;
        if (!in_array($gender, [-1, 0, 1], true)) {
            $gender = -1;
        }
        $age = isset($row['age']) ? (int)$row['age'] : 0;
        $origin = isset($row['herkunft']) ? trim((string)$row['herkunft']) : '';
        $avatar = isset($row['avatar']) ? trim((string)$row['avatar']) : '';
        $description = isset($row['gesuchstext']) ? trim((string)$row['gesuchstext']) : '';
        $link = isset($row['link']) ? trim((string)$row['link']) : '';
        if ($link !== '' && function_exists('relatives_sanitize_url')) {
            $link = relatives_sanitize_url($link);
        }

        return [
            'name'               => mb_substr($name, 0, 255),
            'gender'             => $gender,
            'age'                => $age > 0 ? $age : null, // 0 = im Altplugin nicht angegeben
            'origin'             => in_array($origin, self::ORIGIN_PLACEHOLDERS, true) ? null : mb_substr($origin, 0, 255),
            'avatar'             => $avatar !== '' ? mb_substr($avatar, 0, 255) : null,
            'description'        => $description !== '' ? $description : null,
            'is_wanted'          => isset($row['gesucht']) ? (int)(bool)$row['gesucht'] : ($source === 'wanted' ? 1 : 0),
            'wanted_thread_link' => $link !== '' ? mb_substr($link, 0, 255) : null,
            'legacy_id'          => (int)$row['id'],
            'legacy_source'      => $source,
        ];
    }

    /** Besitzer-Felder einer alten Zuordnung. */
    private static function owner_data_from_legacy(array $link, string $source, array $type_id_map): array
    {
        $type_id = null;
        $custom_label = null;
        $status = isset($link['status']) ? trim((string)$link['status']) : '';

        if ($source === 'wanted') {
            // wanted_status.status war schon im Altplugin eine freie Beziehung
            // ("Freunde", "Geschäftsbeziehung") -> "Sonstige Verbindung".
            $custom_label = $status !== '' ? mb_substr($status, 0, 255) : null;
        } elseif (isset($type_id_map[(int)$status])) {
            $type_id = $type_id_map[(int)$status];
        }

        $description = isset($link['beziehung']) ? trim((string)$link['beziehung']) : '';

        return [
            'relationship_type_id'      => $type_id,
            'relationship_custom_label' => $custom_label,
            'description'               => $description !== '' ? $description : null,
            'legacy_id'                 => isset($link['id']) ? (int)$link['id'] : null,
        ];
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
