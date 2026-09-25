<?php
/**
 * relatives - RelationshipTypes
 *
 * CRUD fuer relatives_relationship_types (ACP-verwaltete, genderspezifische
 * Verwandtschaftsgrade) + die Filterfunktion, die die Grundlage der clientseitigen
 * genderabhaengigen Dropdown-Filterung im Formular/Klick-Add-Interface ist.
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_RelationshipTypes
{
    /** Gender-Konstanten fuer relatives_relationship_types.gender (Label-Geschlecht). */
    public const LABEL_MALE = 0;
    public const LABEL_FEMALE = 1;
    public const LABEL_NEUTRAL = 2;

    /**
     * Fest kuratiertes Set der gaengigsten Verwandtschaftskonzepte, je Konzept mit
     * allen drei Gender-Varianten (maennlich/weiblich/neutral) und generation_offset
     * gemaess PROJECT_CONTEXT.md ("Interaktiver Stammbaum": -2 Großeltern, -1 Eltern/
     * Onkel-Tante, 0 Geschwister/Ehepartner/Cousin, +1 Kinder/Nichte-Neffe, +2 Enkel).
     * Bewusst kein automatischer Insert in install() (siehe "What NOT to change") --
     * nur ueber den expliziten ACP-Button in create_common_defaults().
     * Format je Zeile: concept_key, generation_offset, [maennlich, weiblich, neutral],
     * line_style (Linienart im Stammbaum, siehe LINE_STYLES).
     *
     * Die "distant_*"-Konzepte sind keine Auswahl fuers Formular, sondern die
     * Sammelbegriffe, auf die Relatives_Kinship alles abbildet, was weiter weg ist als
     * Urgrosseltern bzw. Grossonkel/Grosscousin*e. Sie brauchen trotzdem Zeilen hier,
     * weil ihre Bezeichnungen -- wie alle anderen -- im ACP editierbar sein sollen.
     */
    private const COMMON_DEFAULTS = [
        ['parent',            -1, ['Vater', 'Mutter', 'Elternteil'], 'solid'],
        ['sibling',            0, ['Bruder', 'Schwester', 'Geschwisterteil'], 'solid'],
        ['child',              1, ['Sohn', 'Tochter', 'Kind'], 'solid'],
        ['grandparent',       -2, ['Großvater', 'Großmutter', 'Großelternteil'], 'solid'],
        ['grandchild',         2, ['Enkel', 'Enkelin', 'Enkelkind'], 'solid'],
        ['aunt_uncle',        -1, ['Onkel', 'Tante', 'Tante oder Onkel'], 'solid'],
        ['niece_nephew',       1, ['Neffe', 'Nichte', 'Nichte oder Neffe'], 'solid'],
        ['cousin',             0, ['Cousin', 'Cousine', 'Cousin oder Cousine'], 'solid'],
        ['spouse',             0, ['Ehemann', 'Ehefrau', 'Ehepartner*in'], 'solid'],
        ['ex_spouse',          0, ['Ex-Mann', 'Ex-Frau', 'Ex-Partner*in'], 'dashed'],
        ['partner',            0, ['Partner', 'Partnerin', 'Partner*in'], 'solid'],
        ['fiance',             0, ['Verlobter', 'Verlobte', 'Verlobte*r'], 'dotted'],
        ['step_parent',       -1, ['Stiefvater', 'Stiefmutter', 'Stiefelternteil'], 'dotted'],
        ['step_sibling',       0, ['Stiefbruder', 'Stiefschwester', 'Stiefgeschwisterteil'], 'dotted'],
        ['step_child',         1, ['Stiefsohn', 'Stieftochter', 'Stiefkind'], 'dotted'],
        ['adoptive_parent',   -1, ['Adoptivvater', 'Adoptivmutter', 'Adoptivelternteil'], 'dashed'],
        ['adoptive_child',     1, ['Adoptivsohn', 'Adoptivtochter', 'Adoptivkind'], 'dashed'],
        ['adoptive_sibling',   0, ['Adoptivbruder', 'Adoptivschwester', 'Adoptivgeschwisterteil'], 'dashed'],
        ['foster_parent',     -1, ['Pflegevater', 'Pflegemutter', 'Pflegeelternteil'], 'dashed'],
        ['foster_child',       1, ['Pflegesohn', 'Pflegetochter', 'Pflegekind'], 'dashed'],
        ['half_sibling',       0, ['Halbbruder', 'Halbschwester', 'Halbgeschwisterteil'], 'dashdot'],
        ['sibling_in_law',     0, ['Schwager', 'Schwägerin', 'Schwager*in'], 'dotted'],
        ['parent_in_law',     -1, ['Schwiegervater', 'Schwiegermutter', 'Schwiegerelternteil'], 'dotted'],
        ['child_in_law',       1, ['Schwiegersohn', 'Schwiegertochter', 'Schwiegerkind'], 'dotted'],
        ['great_grandparent', -3, ['Urgroßvater', 'Urgroßmutter', 'Urgroßelternteil'], 'solid'],
        ['great_grandchild',   3, ['Urenkel', 'Urenkelin', 'Urenkelkind'], 'solid'],
        ['great_aunt_uncle',  -2, ['Großonkel', 'Großtante', 'Großtante oder Großonkel'], 'solid'],
        ['great_niece_nephew', 2, ['Großneffe', 'Großnichte', 'Großnichte oder Großneffe'], 'solid'],
        ['second_cousin',      0, ['Großcousin', 'Großcousine', 'Großcousin*e'], 'solid'],
        ['distant_ancestor',  -4, ['entfernter Vorfahr', 'entfernte Vorfahrin', 'entfernte*r Vorfahr*in'], 'dotted'],
        ['distant_descendant', 4, ['entfernter Nachfahr', 'entfernte Nachfahrin', 'entfernte*r Nachfahr*in'], 'dotted'],
        ['distant_relative',   0, ['entfernter Verwandter', 'entfernte Verwandte', 'entfernte*r Verwandte*r'], 'dotted'],
    ];

    /**
     * Zulaessige Linienarten. Sie bestimmen, wie eine Verbindung im Stammbaum
     * gezeichnet wird -- deshalb steckt "getrennt/geschieden" im Grad (ex_spouse)
     * und nicht in einem zweiten Feld: wer den Grad umstellt, aendert die Linie mit.
     */
    public const LINE_STYLES = ['solid', 'dashed', 'dotted', 'dashdot'];

    public static function sanitize_line_style(string $style): string
    {
        return in_array($style, self::LINE_STYLES, true) ? $style : 'solid';
    }

    /**
     * Verwandtschafts-Komposition: "R" (Verhaeltnis der neuen Person ZUR
     * Bezugsperson, z.B. 'spouse' = Ehemann von Mama) + "A" (Verhaeltnis der
     * Bezugsperson ZU DIR, z.B. 'parent' = Mama ist deine Mutter) ergibt "C"
     * (Verhaeltnis der neuen Person ZU DIR, z.B. 'parent' = er ist dein Vater).
     * Format: COMPOSE[A][R] = C. Nur gebraeuchliche Kombinationen, bei denen die
     * naheliegende Lesart gilt ("Bruder der Tante" = Onkel, "Mamas Kind" = Geschwister
     * -- du selbst stehst ja bereits als eigener Knoten im Baum). Unbekannte
     * Kombinationen (z.B. Groß-Tante/-Onkel -- dafuer gibt es kein eigenes Konzept)
     * werden NICHT geraten, sondern zeigen stattdessen "<gewaehlter Typ> von
     * <Bezugsperson>" (siehe TreeBuilder).
     * Ketten (Tante -> deren Bruder -> dessen Kind) werden im TreeBuilder schrittweise
     * ueber compose_concept() aufgeloest, A ist dort also bereits das komponierte
     * Verhaeltnis der Bezugsperson zu dir.
     */
    private const COMPOSE = [
        'parent' => [
            'spouse' => 'parent', 'ex_spouse' => 'parent', 'partner' => 'parent', 'fiance' => 'parent',
            'sibling' => 'aunt_uncle', 'step_sibling' => 'aunt_uncle',
            'half_sibling' => 'aunt_uncle', 'adoptive_sibling' => 'aunt_uncle',
            'parent' => 'grandparent', 'step_parent' => 'grandparent', 'adoptive_parent' => 'grandparent',
            'child' => 'sibling',
        ],
        'sibling' => [
            'child' => 'niece_nephew',
            'sibling' => 'sibling',
            'parent' => 'parent',
            'spouse' => 'sibling_in_law',
        ],
        'half_sibling' => [
            'child' => 'niece_nephew',
            'spouse' => 'sibling_in_law',
        ],
        'adoptive_sibling' => [
            'child' => 'niece_nephew',
            'spouse' => 'sibling_in_law',
        ],
        'aunt_uncle' => [
            'child' => 'cousin',
            'spouse' => 'aunt_uncle', 'partner' => 'aunt_uncle',
            'sibling' => 'aunt_uncle',
            'parent' => 'grandparent',
        ],
        'cousin' => [
            'sibling' => 'cousin',
            'parent' => 'aunt_uncle',
        ],
        'child' => [
            'child' => 'grandchild',
            'sibling' => 'child',
            'spouse' => 'child_in_law',
        ],
        'niece_nephew' => [
            'sibling' => 'niece_nephew',
        ],
        'grandchild' => [
            'sibling' => 'grandchild',
            'child' => 'great_grandchild',
        ],
        'grandparent' => [
            'spouse' => 'grandparent', 'partner' => 'grandparent',
            'parent' => 'great_grandparent',
        ],
        'spouse' => [
            'child' => 'step_child',
            'sibling' => 'sibling_in_law',
            'parent' => 'parent_in_law',
        ],
        'partner' => [
            'child' => 'step_child',
        ],
        'fiance' => [
            'child' => 'step_child',
        ],
    ];

    /**
     * Standard-Bezeichnung eines Konzepts aus COMMON_DEFAULTS (fuer die
     * Migrations-Vorschau, bevor die Zeilen tatsaechlich angelegt sind).
     */
    public static function default_label(string $concept_key, int $gender): ?string
    {
        foreach (self::COMMON_DEFAULTS as [$default_key, $offset, $labels, $line_style]) {
            if ($default_key === $concept_key) {
                return $labels[$gender] ?? null;
            }
        }
        return null;
    }

    /**
     * Wie get_by_concept_and_gender(), aber auch inaktive Zeilen (Migration: ein im
     * ACP deaktivierter Grad soll trotzdem zugeordnet werden statt verloren zu gehen).
     */
    public static function find_by_concept_and_gender(string $concept_key, int $gender): ?array
    {
        global $db;
        $row = $db->fetch_array($db->simple_select(
            'relatives_relationship_types',
            '*',
            "concept_key = '" . $db->escape_string($concept_key) . "' AND gender = " . (int)$gender,
            ['order_by' => 'is_active DESC, id', 'order_dir' => 'ASC', 'limit' => 1]
        ));
        return $row ?: null;
    }

    /**
     * Findet die aktive Zeile fuer ein Konzept+Geschlecht (z.B. fuer die
     * Komposition oben: "welche relatives_relationship_types-Zeile ist
     * concept_key='parent', gender=maennlich?").
     */
    public static function get_by_concept_and_gender(string $concept_key, int $gender): ?array
    {
        global $db;
        $row = $db->fetch_array($db->simple_select(
            'relatives_relationship_types',
            '*',
            "concept_key = '" . $db->escape_string($concept_key) . "' AND gender = " . (int)$gender . " AND is_active = 1",
            ['limit' => 1]
        ));
        return $row ?: null;
    }

    /**
     * Komponiert "Verhaeltnis der Bezugsperson zu dir" (A) + "Verhaeltnis der neuen
     * Person zur Bezugsperson" (R) zu "Verhaeltnis der neuen Person zu dir" (siehe
     * COMPOSE-Tabelle). Gibt null zurueck, wenn die Kombination unbekannt ist -- der
     * Aufrufer faellt dann auf einen Klartext-Fallback zurueck statt zu raten.
     */
    public static function compose_concept(string $anchor_concept, string $relation_concept): ?string
    {
        return self::COMPOSE[$anchor_concept][$relation_concept] ?? null;
    }

    /**
     * Anzeige-Label je Person aus Sicht ihres Besitzers. Fuer Personen mit Bezugsperson
     * wird die komplette Bezugskette komponiert ("Bruder von Tante", Tante = "Schwester
     * von Mama" -> "Onkel"); unbekannte Kombinationen werden nicht geraten, sondern als
     * "<Grad> von <Bezugsperson>" ausgegeben. Personen ohne Bezugsperson bekommen null
     * (der Aufrufer nimmt dann den Grad bzw. die freie Bezeichnung selbst).
     *
     * @param array $persons_by_id Zeilen aus Relatives_Database::get_persons_by_owner(), nach id
     * @return array person_id => ?string
     */
    public static function display_labels(array $persons_by_id): array
    {
        $type_cache = [];
        $get_type = function (?int $type_id) use (&$type_cache): ?array {
            if (empty($type_id)) {
                return null;
            }
            if (!array_key_exists($type_id, $type_cache)) {
                $type_cache[$type_id] = self::get_by_id($type_id);
            }
            return $type_cache[$type_id];
        };

        // Verhaeltnis ZU DIR ueber die komplette Bezugskette; null = nicht eindeutig
        // aufloesbar (Freitext, unbekannte Kombination, Zyklus).
        $resolved = [];
        $resolving = [];
        $resolve = function (int $person_id) use (&$resolve, &$resolved, &$resolving, $persons_by_id, $get_type): ?string {
            if (array_key_exists($person_id, $resolved)) {
                return $resolved[$person_id];
            }
            if (isset($resolving[$person_id])) {
                return null;
            }
            $resolving[$person_id] = true;

            $person = $persons_by_id[$person_id];
            $type = $get_type(!empty($person['relationship_type_id']) ? (int)$person['relationship_type_id'] : null);
            $concept = $type !== null ? (string)$type['concept_key'] : null;

            $related_id = !empty($person['related_to_person_id']) ? (int)$person['related_to_person_id'] : null;
            if ($concept !== null && $related_id !== null && isset($persons_by_id[$related_id])) {
                $anchor_concept = $resolve($related_id);
                $concept = $anchor_concept !== null ? self::compose_concept($anchor_concept, $concept) : null;
            }

            unset($resolving[$person_id]);
            $resolved[$person_id] = $concept;
            return $concept;
        };

        $label_cache = [];
        $labels = [];
        foreach ($persons_by_id as $person_id => $person) {
            $person_id = (int)$person_id;
            $related_id = !empty($person['related_to_person_id']) ? (int)$person['related_to_person_id'] : null;
            if ($related_id === null || !isset($persons_by_id[$related_id])) {
                $labels[$person_id] = null;
                continue;
            }

            $concept = $resolve($person_id);
            if ($concept !== null) {
                $label_gender = self::map_person_gender_to_label_gender((int)$person['gender']);
                $cache_key = $concept . '|' . $label_gender;
                if (!array_key_exists($cache_key, $label_cache)) {
                    $label_cache[$cache_key] = self::get_by_concept_and_gender($concept, $label_gender);
                }
                if ($label_cache[$cache_key] !== null) {
                    $labels[$person_id] = (string)$label_cache[$cache_key]['label'];
                    continue;
                }
            }

            $own_type = $get_type(!empty($person['relationship_type_id']) ? (int)$person['relationship_type_id'] : null);
            $raw_label = $own_type !== null ? (string)$own_type['label'] : (string)$person['relationship_custom_label'];
            $labels[$person_id] = $raw_label . ' von ' . (string)$persons_by_id[$related_id]['name'];
        }

        return $labels;
    }

    public static function get_all(bool $active_only = true): array
    {
        global $db;
        $rows = [];
        $where = $active_only ? "is_active = 1" : "1=1";
        $query = $db->simple_select(
            'relatives_relationship_types',
            '*',
            $where,
            ['order_by' => 'concept_key, sort_order, id', 'order_dir' => 'ASC']
        );
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function get_by_id(int $id): ?array
    {
        global $db;
        if ($id <= 0) {
            return null;
        }
        $row = $db->fetch_array($db->simple_select(
            'relatives_relationship_types',
            '*',
            "id = " . (int)$id,
            ['limit' => 1]
        ));
        return $row ?: null;
    }

    /**
     * Ordnet den Gender-Wert einer Person (-1/0/1/2) auf den passenden
     * relationship_types.gender-Wert ab (siehe PROJECT_CONTEXT "Formular-Logik").
     */
    public static function map_person_gender_to_label_gender(int $person_gender): int
    {
        if ($person_gender === 0) {
            return self::LABEL_MALE;
        }
        if ($person_gender === 1) {
            return self::LABEL_FEMALE;
        }
        // -1 (egal) und 2 (divers) -> neutrale Labels
        return self::LABEL_NEUTRAL;
    }

    /**
     * Liefert alle aktiven Relationship-Types, deren Label-Geschlecht zum uebergebenen
     * Personen-Geschlecht passt (serverseitige Variante des clientseitigen Filters,
     * u.a. fuer die Validierung beim Speichern und fuer CLI-Selbsttests).
     */
    public static function get_for_person_gender(int $person_gender): array
    {
        global $db;
        $label_gender = self::map_person_gender_to_label_gender($person_gender);
        $rows = [];
        $query = $db->simple_select(
            'relatives_relationship_types',
            '*',
            "is_active = 1 AND gender = " . (int)$label_gender,
            ['order_by' => 'concept_key, sort_order, id', 'order_dir' => 'ASC']
        );
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Serverseitig mitgeliefertes JSON-Payload aller aktiven Zeilen (Grundlage der
     * clientseitigen Dropdown-Filterung im Formular / Klick-Add-Interface).
     */
    public static function get_active_json_payload(): array
    {
        $rows = self::get_all(true);
        $payload = [];
        foreach ($rows as $row) {
            $payload[] = [
                'id'                => (int)$row['id'],
                'concept_key'       => (string)$row['concept_key'],
                'gender'            => (int)$row['gender'],
                'label'             => (string)$row['label'],
                'generation_offset' => (int)$row['generation_offset'],
                'line_style'        => isset($row['line_style']) ? (string)$row['line_style'] : 'solid',
            ];
        }
        return $payload;
    }

    /**
     * Gruppiert alle Typen (aktiv + inaktiv) nach concept_key, mit m/w/neutral
     * nebeneinander -- Grundlage der ACP-Uebersicht ("fehlende neutrale Variante"
     * visuell markieren).
     */
    public static function get_grouped_by_concept(): array
    {
        $rows = self::get_all(false);
        $grouped = [];
        foreach ($rows as $row) {
            $key = (string)$row['concept_key'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    self::LABEL_MALE    => null,
                    self::LABEL_FEMALE  => null,
                    self::LABEL_NEUTRAL => null,
                ];
            }
            $grouped[$key][(int)$row['gender']] = $row;
        }
        ksort($grouped);
        return $grouped;
    }

    public static function create(array $data): int
    {
        global $db;
        $insert = [
            'concept_key'       => $db->escape_string((string)($data['concept_key'] ?? '')),
            'gender'            => (int)($data['gender'] ?? self::LABEL_NEUTRAL),
            'label'             => $db->escape_string((string)($data['label'] ?? '')),
            'generation_offset' => (int)($data['generation_offset'] ?? 0),
            'line_style'        => $db->escape_string(self::sanitize_line_style((string)($data['line_style'] ?? 'solid'))),
            'sort_order'        => (int)($data['sort_order'] ?? 0),
            'is_active'         => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
        ];
        return (int)$db->insert_query('relatives_relationship_types', $insert);
    }

    public static function update(int $id, array $data): bool
    {
        global $db;
        if ($id <= 0) {
            return false;
        }
        $update = [];
        if (isset($data['concept_key'])) {
            $update['concept_key'] = $db->escape_string((string)$data['concept_key']);
        }
        if (isset($data['gender'])) {
            $update['gender'] = (int)$data['gender'];
        }
        if (isset($data['label'])) {
            $update['label'] = $db->escape_string((string)$data['label']);
        }
        if (isset($data['generation_offset'])) {
            $update['generation_offset'] = (int)$data['generation_offset'];
        }
        if (isset($data['line_style'])) {
            $update['line_style'] = $db->escape_string(self::sanitize_line_style((string)$data['line_style']));
        }
        if (isset($data['sort_order'])) {
            $update['sort_order'] = (int)$data['sort_order'];
        }
        if (isset($data['is_active'])) {
            $update['is_active'] = (int)(bool)$data['is_active'];
        }
        if (empty($update)) {
            return false;
        }
        $db->update_query('relatives_relationship_types', $update, "id = " . (int)$id, 1);
        return true;
    }

    /**
     * Legt das kuratierte Standard-Set (COMMON_DEFAULTS) an -- Knopfdruck-Funktion fuer
     * den ACP-Tab "Verwandtschaftsgrade". Idempotent wie der Migrations-Import: eine
     * bereits vorhandene concept_key+gender-Kombination wird uebersprungen, nie
     * dupliziert oder ueberschrieben (bestehende Labels/Sort-Order/Aktiv-Status bleiben
     * unangetastet, auch bei mehrfachem Klick oder nach manuellen Anpassungen).
     * Die Legacy-Migration ruft das selbst auf, weil ihr Mapping (siehe
     * Migration::STATUS_BASE_MAP) ausschliesslich auf diese Konzepte zeigt.
     *
     * @return int Anzahl tatsaechlich neu angelegter Zeilen.
     */
    public static function create_common_defaults(): int
    {
        global $db;

        $created = 0;
        $sort_order = 0;
        foreach (self::COMMON_DEFAULTS as [$concept_key, $generation_offset, $labels, $line_style]) {
            foreach ([self::LABEL_MALE, self::LABEL_FEMALE, self::LABEL_NEUTRAL] as $gender) {
                $existing = $db->fetch_array($db->simple_select(
                    'relatives_relationship_types',
                    'id',
                    "concept_key = '" . $db->escape_string($concept_key) . "' AND gender = " . (int)$gender,
                    ['limit' => 1]
                ));
                if ($existing) {
                    continue;
                }
                self::create([
                    'concept_key'       => $concept_key,
                    'gender'            => $gender,
                    'label'             => $labels[$gender],
                    'generation_offset' => $generation_offset,
                    'line_style'        => $line_style,
                    'sort_order'        => $sort_order,
                    'is_active'         => 1,
                ]);
                $created++;
            }
            $sort_order++;
        }

        return $created;
    }

    public static function delete(int $id): bool
    {
        global $db;
        if ($id <= 0) {
            return false;
        }
        // Verbindungen, die diesen Typ referenzieren, verlieren den Grad (nicht die Verbindung).
        // Seit v2.1 steht der Grad in relatives_relations (und der Konfliktliste), nicht mehr
        // auf der Zuordnung. Raw NULL statt update_query() mit PHP-null: MyBBs quote_val()
        // kennt kein NULL und wuerde stattdessen 0 schreiben.
        foreach (['relatives_relations', 'relatives_relation_conflicts'] as $table) {
            if ($db->table_exists($table)) {
                $db->write_query(
                    "UPDATE " . TABLE_PREFIX . $table . " SET relationship_type_id = NULL"
                    . " WHERE relationship_type_id = " . (int)$id
                );
            }
        }
        Relatives_Kinship::reset();
        $db->delete_query('relatives_relationship_types', "id = " . (int)$id, 1);
        return true;
    }
}
