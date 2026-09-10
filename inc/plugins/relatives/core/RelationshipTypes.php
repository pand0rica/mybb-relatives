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
     * Format je Zeile: concept_key, generation_offset, [maennlich, weiblich, neutral].
     */
    private const COMMON_DEFAULTS = [
        ['parent',          -1, ['Vater', 'Mutter', 'Elternteil']],
        ['sibling',          0, ['Bruder', 'Schwester', 'Geschwisterteil']],
        ['child',            1, ['Sohn', 'Tochter', 'Kind']],
        ['grandparent',     -2, ['Großvater', 'Großmutter', 'Großelternteil']],
        ['grandchild',       2, ['Enkel', 'Enkelin', 'Enkelkind']],
        ['aunt_uncle',      -1, ['Onkel', 'Tante', 'Tante oder Onkel']],
        ['niece_nephew',     1, ['Neffe', 'Nichte', 'Nichte oder Neffe']],
        ['cousin',           0, ['Cousin', 'Cousine', 'Cousin oder Cousine']],
        ['spouse',           0, ['Ehemann', 'Ehefrau', 'Ehepartner:in']],
        ['ex_spouse',        0, ['Ex-Mann', 'Ex-Frau', 'Ex-Partner:in']],
        ['partner',          0, ['Partner', 'Partnerin', 'Partner:in']],
        ['step_parent',     -1, ['Stiefvater', 'Stiefmutter', 'Stiefelternteil']],
        ['step_sibling',     0, ['Stiefbruder', 'Stiefschwester', 'Stiefgeschwisterteil']],
        ['step_child',       1, ['Stiefsohn', 'Stieftochter', 'Stiefkind']],
        ['adoptive_parent', -1, ['Adoptivvater', 'Adoptivmutter', 'Adoptivelternteil']],
        ['adoptive_child',   1, ['Adoptivsohn', 'Adoptivtochter', 'Adoptivkind']],
    ];

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
     * Laeuft daher auch gut als Luecken-Fueller nach einer Legacy-Migration, die nur
     * maennliche/weibliche Varianten kennt (siehe Migration::RELATIONSHIP_MAP).
     *
     * @return int Anzahl tatsaechlich neu angelegter Zeilen.
     */
    public static function create_common_defaults(): int
    {
        global $db;

        $created = 0;
        $sort_order = 0;
        foreach (self::COMMON_DEFAULTS as [$concept_key, $generation_offset, $labels]) {
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
        // Personen, die diesen Typ referenzieren, verlieren die Zuordnung (nicht die Person).
        $db->update_query('relatives_persons', ['relationship_type_id' => null], "relationship_type_id = " . (int)$id);
        $db->delete_query('relatives_relationship_types', "id = " . (int)$id, 1);
        return true;
    }
}
