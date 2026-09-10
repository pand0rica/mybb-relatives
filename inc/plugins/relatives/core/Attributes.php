<?php
/**
 * relatives - Attributes
 *
 * CRUD fuer relatives_attribute_groups/_options + die Zuordnungslogik in
 * relatives_person_attributes (n:m Person <-> Attributwert).
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_Attributes
{
    // --- Gruppen -----------------------------------------------------------------

    public static function get_groups(bool $active_only = true): array
    {
        global $db;
        $rows = [];
        $where = $active_only ? "is_active = 1" : "1=1";
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
        return (int)$db->insert_query('relatives_attribute_groups', [
            'label'      => $db->escape_string((string)($data['label'] ?? '')),
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
        $db->delete_query('relatives_attribute_groups', "id = " . (int)$id, 1);
        return true;
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
    public static function get_options_grouped(bool $active_only = true): array
    {
        $grouped = [];
        foreach (self::get_groups($active_only) as $group) {
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
        $db->delete_query('relatives_attribute_options', "id = " . (int)$id, 1);
        return true;
    }

    // --- Zuordnung Person <-> Attributwert ------------------------------------------

    /**
     * Ersetzt die komplette Attribut-Zuordnung einer Person durch die uebergebenen
     * option_ids (loescht alte Zuordnungen, fuegt neue ein). Ungueltige/fremde
     * option_ids werden stillschweigend uebersprungen.
     */
    public static function set_person_attributes(int $person_id, array $option_ids): void
    {
        global $db;
        if ($person_id <= 0) {
            return;
        }
        self::delete_person_attributes($person_id);

        foreach ($option_ids as $option_id) {
            $option_id = (int)$option_id;
            if ($option_id <= 0) {
                continue;
            }
            $option = self::get_option($option_id);
            if ($option === null) {
                continue; // fremde/geloeschte option_id: verwerfen statt Fehler
            }
            $db->insert_query('relatives_person_attributes', [
                'person_id' => $person_id,
                'group_id'  => (int)$option['group_id'],
                'option_id' => $option_id,
            ]);
        }
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
}
