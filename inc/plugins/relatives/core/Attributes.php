<?php
/**
 * relatives - Attributes
 *
 * CRUD fuer relatives_attribute_groups/_options + die Zuordnungslogik in
 * relatives_person_attributes (n:m Person <-> Attributwert) und
 * relatives_family_attributes (je Familie und Gruppe ein Wert).
 *
 * Eine Gruppe gilt fuer Personen, fuer Familien oder fuer beide (applies_to). Alles, was
 * fuer Personen gilt, ist immer Vorauswahl; nur reine Familien-Gruppen koennen ein
 * Freitextfeld sein (field_type), dann bleiben ihre Optionen ungenutzt.
 * is_multiple gilt nur fuer Personen: 0 = genau ein Wert (Dropdown, z. B. Haarfarbe),
 * 1 = mehrere Werte (Checkboxen). Familien haben je Gruppe immer genau einen Wert.
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_Attributes
{
    public const APPLIES_PERSON = 'person';
    public const APPLIES_FAMILY = 'family';
    public const APPLIES_BOTH = 'both';
    public const TYPE_SELECT = 'select';
    public const TYPE_TEXT = 'text';

    /** Maximale Laenge eines Freitextwerts (Spaltenbreite). */
    public const TEXT_MAX_LENGTH = 255;

    // --- Gruppen -----------------------------------------------------------------

    /**
     * @param string|null $applies_to APPLIES_PERSON / APPLIES_FAMILY -- liefert jeweils auch die
     *                                Gruppen mit APPLIES_BOTH; null = alle (ACP)
     */
    public static function get_groups(bool $active_only = true, ?string $applies_to = self::APPLIES_PERSON): array
    {
        global $db;
        $rows = [];
        $conditions = [];
        if ($active_only) {
            $conditions[] = "is_active = 1";
        }
        if ($applies_to !== null) {
            if (self::has_scope_columns()) {
                $scope = $applies_to === self::APPLIES_FAMILY ? self::APPLIES_FAMILY : self::APPLIES_PERSON;
                $conditions[] = "applies_to IN ('" . $scope . "', '" . self::APPLIES_BOTH . "')";
            } elseif ($applies_to === self::APPLIES_FAMILY) {
                return []; // vor dem Reaktivieren gibt es nur Personen-Gruppen
            }
        }
        $where = $conditions ? implode(' AND ', $conditions) : "1=1";
        $query = $db->simple_select('relatives_attribute_groups', '*', $where,
            ['order_by' => 'sort_order, id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function get_group(int $id): ?array
    {
        global $db;
        if ($id <= 0) {
            return null;
        }
        $row = $db->fetch_array($db->simple_select('relatives_attribute_groups', '*', "id = " . (int)$id, ['limit' => 1]));
        return $row ?: null;
    }

    public static function create_group(array $data): int
    {
        global $db;
        $applies_to = self::sanitize_applies_to((string)($data['applies_to'] ?? self::APPLIES_PERSON));
        return (int)$db->insert_query('relatives_attribute_groups', [
            'label'      => $db->escape_string((string)($data['label'] ?? '')),
            'applies_to' => $db->escape_string($applies_to),
            'field_type' => $db->escape_string(self::sanitize_field_type((string)($data['field_type'] ?? self::TYPE_SELECT), $applies_to)),
            'is_multiple' => !empty($data['is_multiple']) ? 1 : 0,
            'sort_order' => (int)($data['sort_order'] ?? 0),
            'is_active'  => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
        ]);
    }

    public static function update_group(int $id, array $data): bool
    {
        global $db;
        if ($id <= 0) {
            return false;
        }
        $update = [];
        if (isset($data['label'])) {
            $update['label'] = $db->escape_string((string)$data['label']);
        }
        if (isset($data['applies_to']) || isset($data['field_type'])) {
            $current = self::get_group($id) ?? [];
            $applies_to = self::sanitize_applies_to((string)($data['applies_to'] ?? ($current['applies_to'] ?? self::APPLIES_PERSON)));
            $update['applies_to'] = $db->escape_string($applies_to);
            $update['field_type'] = $db->escape_string(self::sanitize_field_type(
                (string)($data['field_type'] ?? ($current['field_type'] ?? self::TYPE_SELECT)),
                $applies_to
            ));
        }
        if (isset($data['is_multiple'])) {
            $update['is_multiple'] = (int)(bool)$data['is_multiple'];
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
        $db->update_query('relatives_attribute_groups', $update, "id = " . (int)$id, 1);
        return true;
    }

    public static function delete_group(int $id): bool
    {
        global $db;
        if ($id <= 0) {
            return false;
        }
        $option_ids = [];
        $query = $db->simple_select('relatives_attribute_options', 'id', "group_id = " . (int)$id);
        while ($row = $db->fetch_array($query)) {
            $option_ids[] = (int)$row['id'];
        }
        if (!empty($option_ids)) {
            $db->delete_query('relatives_person_attributes', "group_id = " . (int)$id);
            $db->delete_query('relatives_attribute_options', "group_id = " . (int)$id);
        }
        if ($db->table_exists('relatives_family_attributes')) {
            $db->delete_query('relatives_family_attributes', "group_id = " . (int)$id);
        }
        $db->delete_query('relatives_attribute_groups', "id = " . (int)$id, 1);
        return true;
    }

    /** @var bool|null */
    private static $has_scope_columns = null;

    /** Gibt es applies_to/field_type schon (Plugin nach dem Update reaktiviert)? */
    private static function has_scope_columns(): bool
    {
        global $db;
        if (self::$has_scope_columns === null) {
            self::$has_scope_columns = $db->field_exists('applies_to', 'relatives_attribute_groups');
        }
        return self::$has_scope_columns;
    }

    public static function sanitize_applies_to(string $applies_to): string
    {
        return in_array($applies_to, [self::APPLIES_FAMILY, self::APPLIES_BOTH], true) ? $applies_to : self::APPLIES_PERSON;
    }

    /**
     * Freitext gibt es nur bei reinen Familien-Gruppen: Personen-Attribute (Formular,
     * Stammbaum-Panel, Suche) kennen nur Auswahlwerte.
     */
    public static function sanitize_field_type(string $field_type, string $applies_to): string
    {
        return ($field_type === self::TYPE_TEXT && $applies_to === self::APPLIES_FAMILY) ? self::TYPE_TEXT : self::TYPE_SELECT;
    }

    /** May a person hold several values of this group? */
    public static function is_multiple_group(array $group): bool
    {
        return !empty($group['is_multiple']);
    }

    /**
     * Setzt is_multiple bei allen Gruppen, in denen mindestens eine Person schon mehrere
     * Werte hat (Upgrade auf die neue Spalte, Legacy-Import). Nur hochsetzen, nie
     * zuruecksetzen -- "genau ein Wert" entscheidet das Team im ACP.
     */
    public static function flag_multi_value_groups(): void
    {
        global $db;
        if (!$db->field_exists('is_multiple', 'relatives_attribute_groups')) {
            return;
        }
        // Derived table: MySQL refuses a direct subquery here otherwise.
        $db->write_query("UPDATE " . TABLE_PREFIX . "relatives_attribute_groups
            SET is_multiple = 1
            WHERE id IN (
                SELECT group_id FROM (
                    SELECT group_id FROM " . TABLE_PREFIX . "relatives_person_attributes
                    GROUP BY person_id, group_id
                    HAVING COUNT(*) > 1
                ) AS multi
            )");
    }

    public static function is_text_group(array $group): bool
    {
        return ($group['field_type'] ?? self::TYPE_SELECT) === self::TYPE_TEXT
            && ($group['applies_to'] ?? self::APPLIES_PERSON) === self::APPLIES_FAMILY;
    }

    // --- Optionen ------------------------------------------------------------------

    public static function get_options(int $group_id, bool $active_only = true): array
    {
        global $db;
        $rows = [];
        $where = "group_id = " . (int)$group_id;
        if ($active_only) {
            $where .= " AND is_active = 1";
        }
        $query = $db->simple_select('relatives_attribute_options', '*', $where,
            ['order_by' => 'sort_order, id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Alle Gruppen inkl. ihrer Optionen, verschachtelt -- fuer Formular-Payload / ACP.
     */
    public static function get_options_grouped(bool $active_only = true, string $applies_to = self::APPLIES_PERSON): array
    {
        $grouped = [];
        foreach (self::get_groups($active_only, $applies_to) as $group) {
            $grouped[] = [
                'group'   => $group,
                'options' => self::get_options((int)$group['id'], $active_only),
            ];
        }
        return $grouped;
    }

    public static function get_option(int $id): ?array
    {
        global $db;
        if ($id <= 0) {
            return null;
        }
        $row = $db->fetch_array($db->simple_select('relatives_attribute_options', '*', "id = " . (int)$id, ['limit' => 1]));
        return $row ?: null;
    }

    public static function create_option(array $data): int
    {
        global $db;
        return (int)$db->insert_query('relatives_attribute_options', [
            'group_id'   => (int)($data['group_id'] ?? 0),
            'label'      => $db->escape_string((string)($data['label'] ?? '')),
            'sort_order' => (int)($data['sort_order'] ?? 0),
            'is_active'  => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
        ]);
    }

    public static function update_option(int $id, array $data): bool
    {
        global $db;
        if ($id <= 0) {
            return false;
        }
        $update = [];
        if (isset($data['label'])) {
            $update['label'] = $db->escape_string((string)$data['label']);
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
        $db->update_query('relatives_attribute_options', $update, "id = " . (int)$id, 1);
        return true;
    }

    public static function delete_option(int $id): bool
    {
        global $db;
        if ($id <= 0) {
            return false;
        }
        $db->delete_query('relatives_person_attributes', "option_id = " . (int)$id);
        if ($db->table_exists('relatives_family_attributes')) {
            $db->delete_query('relatives_family_attributes', "option_id = " . (int)$id);
        }
        $db->delete_query('relatives_attribute_options', "id = " . (int)$id, 1);
        return true;
    }

    // --- Zuordnung Person <-> Attributwert ------------------------------------------

    /**
     * Ersetzt die komplette Attribut-Zuordnung einer Person durch die uebergebenen
     * option_ids (loescht alte Zuordnungen, fuegt neue ein). Ungueltige/fremde
     * option_ids werden stillschweigend uebersprungen.
     *
     * $enforce_single: in Gruppen ohne is_multiple bleibt nur der erste Wert -- das
     * Formular schickt dort nur einen, ein manipulierter Request koennte mehr senden.
     * Der Legacy-Import schaltet das ab und markiert die Gruppen danach als mehrfach.
     */
    public static function set_person_attributes(int $person_id, array $option_ids, bool $enforce_single = true): void
    {
        global $db;
        if ($person_id <= 0) {
            return;
        }
        self::delete_person_attributes($person_id);

        $groups = [];
        $filled = [];
        foreach ($option_ids as $option_id) {
            $option_id = (int)$option_id;
            if ($option_id <= 0) {
                continue;
            }
            $option = self::get_option($option_id);
            if ($option === null) {
                continue; // fremde/geloeschte option_id: verwerfen statt Fehler
            }
            $group_id = (int)$option['group_id'];
            if ($enforce_single) {
                if (!array_key_exists($group_id, $groups)) {
                    $groups[$group_id] = self::get_group($group_id);
                }
                if ($groups[$group_id] !== null && !self::is_multiple_group($groups[$group_id])) {
                    if (isset($filled[$group_id])) {
                        continue;
                    }
                    $filled[$group_id] = true;
                }
            }
            $db->insert_query('relatives_person_attributes', [
                'person_id' => $person_id,
                'group_id'  => (int)$option['group_id'],
                'option_id' => $option_id,
            ]);
        }
    }

    /**
     * Gewaehlte option_ids mehrerer Personen auf einmal (Stammbaum-Payload).
     *
     * @return array person_id => [option_id, ...]
     */
    public static function get_option_ids_bulk(array $person_ids): array
    {
        global $db;
        $ids = array_values(array_filter(array_map('intval', $person_ids), static function ($id) {
            return $id > 0;
        }));
        if (empty($ids)) {
            return [];
        }
        $result = [];
        $query = $db->simple_select(
            'relatives_person_attributes',
            'person_id, option_id',
            'person_id IN (' . implode(',', $ids) . ')'
        );
        while ($row = $db->fetch_array($query)) {
            $result[(int)$row['person_id']][] = (int)$row['option_id'];
        }
        return $result;
    }

    public static function get_person_attributes(int $person_id): array
    {
        global $db;
        $rows = [];
        if ($person_id <= 0) {
            return $rows;
        }
        $query = $db->query("
            SELECT pa.id, pa.person_id, pa.group_id, pa.option_id,
                   ao.label AS option_label, ag.label AS group_label
            FROM " . TABLE_PREFIX . "relatives_person_attributes pa
            LEFT JOIN " . TABLE_PREFIX . "relatives_attribute_options ao ON ao.id = pa.option_id
            LEFT JOIN " . TABLE_PREFIX . "relatives_attribute_groups ag ON ag.id = pa.group_id
            WHERE pa.person_id = " . (int)$person_id . "
            ORDER BY ag.sort_order ASC, ao.sort_order ASC
        ");
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function delete_person_attributes(int $person_id): void
    {
        global $db;
        if ($person_id <= 0) {
            return;
        }
        $db->delete_query('relatives_person_attributes', "person_id = " . (int)$person_id);
    }

    // --- Zuordnung Familie <-> Attributwert -----------------------------------------

    /**
     * Gespeicherte Werte einer Familie fuers Formular.
     *
     * @return array group_id => ['option_id' => int, 'value' => string]
     */
    public static function get_family_values(int $family_id): array
    {
        global $db;
        $values = [];
        if ($family_id <= 0 || !$db->table_exists('relatives_family_attributes')) {
            return $values;
        }
        $query = $db->simple_select('relatives_family_attributes', 'group_id, option_id, value', "family_id = " . (int)$family_id);
        while ($row = $db->fetch_array($query)) {
            $values[(int)$row['group_id']] = ['option_id' => (int)$row['option_id'], 'value' => (string)$row['value']];
        }
        return $values;
    }

    /**
     * Ersetzt die Attributwerte einer Familie. $input ist group_id => Rohwert aus dem
     * Formular: bei Vorauswahl eine option_id, bei Freitext der Text. Leere Werte,
     * inaktive/fremde Gruppen und Optionen einer anderen Gruppe werden verworfen.
     */
    public static function set_family_attributes(int $family_id, array $input): void
    {
        global $db;
        if ($family_id <= 0 || !$db->table_exists('relatives_family_attributes')) {
            return;
        }
        $db->delete_query('relatives_family_attributes', "family_id = " . (int)$family_id);

        foreach (self::get_groups(true, self::APPLIES_FAMILY) as $group) {
            $group_id = (int)$group['id'];
            if (!isset($input[$group_id]) || is_array($input[$group_id])) {
                continue;
            }
            $raw = trim((string)$input[$group_id]);
            if ($raw === '') {
                continue;
            }

            $row = ['family_id' => $family_id, 'group_id' => $group_id, 'option_id' => 0, 'value' => ''];
            if (self::is_text_group($group)) {
                $row['value'] = $db->escape_string(mb_substr($raw, 0, self::TEXT_MAX_LENGTH));
            } else {
                $option = self::get_option((int)$raw);
                if ($option === null || (int)$option['group_id'] !== $group_id || (int)$option['is_active'] !== 1) {
                    continue;
                }
                $row['option_id'] = (int)$option['id'];
            }
            $db->insert_query('relatives_family_attributes', $row);
        }
    }

    /**
     * Anzeigefertige Werte mehrerer Familien (Uebersicht), in ACP-Reihenfolge und nur
     * aus aktiven Gruppen/Optionen.
     *
     * @return array family_id => [['label' => Gruppenname, 'value' => Wert], ...] (unescaped)
     */
    public static function get_family_attributes_bulk(array $family_ids): array
    {
        global $db;
        $ids = array_values(array_filter(array_map('intval', $family_ids), static function ($id) {
            return $id > 0;
        }));
        if (!$ids || !$db->table_exists('relatives_family_attributes')) {
            return [];
        }
        $result = [];
        $query = $db->query("
            SELECT fa.family_id, fa.value, ag.label AS group_label, ag.field_type, ao.label AS option_label
            FROM " . TABLE_PREFIX . "relatives_family_attributes fa
            INNER JOIN " . TABLE_PREFIX . "relatives_attribute_groups ag
                ON ag.id = fa.group_id AND ag.is_active = 1
                AND ag.applies_to IN ('" . self::APPLIES_FAMILY . "', '" . self::APPLIES_BOTH . "')
            LEFT JOIN " . TABLE_PREFIX . "relatives_attribute_options ao
                ON ao.id = fa.option_id AND ao.is_active = 1
            WHERE fa.family_id IN (" . implode(',', $ids) . ")
            ORDER BY ag.sort_order ASC, ag.id ASC
        ");
        while ($row = $db->fetch_array($query)) {
            $value = $row['field_type'] === self::TYPE_TEXT ? (string)$row['value'] : (string)($row['option_label'] ?? '');
            if ($value === '') {
                continue; // Gruppe umgestellt oder Option deaktiviert -- nichts anzeigen
            }
            $result[(int)$row['family_id']][] = ['label' => (string)$row['group_label'], 'value' => $value];
        }
        return $result;
    }

    /**
     * Gewaehlte Optionen aller Familien (Filter der Uebersicht). Freitextwerte fehlen hier.
     *
     * @return array family_id => [group_id => option_id]
     */
    public static function get_family_option_ids_bulk(array $family_ids): array
    {
        global $db;
        $ids = array_values(array_filter(array_map('intval', $family_ids), static function ($id) {
            return $id > 0;
        }));
        if (!$ids || !$db->table_exists('relatives_family_attributes')) {
            return [];
        }
        $result = [];
        $query = $db->simple_select(
            'relatives_family_attributes',
            'family_id, group_id, option_id',
            'option_id > 0 AND family_id IN (' . implode(',', $ids) . ')'
        );
        while ($row = $db->fetch_array($query)) {
            $result[(int)$row['family_id']][(int)$row['group_id']] = (int)$row['option_id'];
        }
        return $result;
    }

    public static function delete_family_attributes(int $family_id): void
    {
        global $db;
        if ($family_id <= 0 || !$db->table_exists('relatives_family_attributes')) {
            return;
        }
        $db->delete_query('relatives_family_attributes', "family_id = " . (int)$family_id);
    }
}
