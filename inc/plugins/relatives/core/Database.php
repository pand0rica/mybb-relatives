<?php
/**
 * relatives - Database
 *
 * Repository fuer relatives_persons: CRUD, Owner-Checks, Limit-Check,
 * Herkunfts-Logik (freetext/profilefield).
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_Database
{
    /**
     * Laedt eine einzelne Person anhand ihrer id.
     */
    public static function get_person(int $id): ?array
    {
        global $db;
        if ($id <= 0) {
            return null;
        }
        $row = $db->fetch_array($db->simple_select(
            'relatives_persons',
            '*',
            "id = " . (int)$id,
            ['limit' => 1]
        ));
        return $row ?: null;
    }

    /**
     * Alle Personen eines Besitzers (eigene Personen), sortiert nach Name.
     */
    public static function get_persons_by_owner(int $owner_uid): array
    {
        global $db;
        $rows = [];
        $query = $db->simple_select(
            'relatives_persons',
            '*',
            "owner_uid = " . (int)$owner_uid,
            ['order_by' => 'name', 'order_dir' => 'ASC']
        );
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Alle Personen-Zeilen, die einen bestimmten Account als linked_uid referenzieren.
     */
    public static function get_persons_by_linked_uid(int $linked_uid): array
    {
        global $db;
        $rows = [];
        $query = $db->simple_select('relatives_persons', '*', "linked_uid = " . (int)$linked_uid);
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function count_persons_by_owner(int $owner_uid): int
    {
        global $db;
        $row = $db->fetch_array($db->simple_select(
            'relatives_persons',
            'COUNT(*) AS cnt',
            "owner_uid = " . (int)$owner_uid
        ));
        return $row ? (int)$row['cnt'] : 0;
    }

    /**
     * Owner-Check: gehoert die Person diesem Account? Grundlage jedes Update/Delete
     * (fixt den Alt-IDOR-Bug).
     */
    public static function is_owner(int $person_id, int $uid): bool
    {
        $person = self::get_person($person_id);
        return $person !== null && (int)$person['owner_uid'] === $uid;
    }

    /**
     * Legt eine neue Person an. $data enthaelt bereits validierte/sanitisierte Werte
     * (Validierung liegt beim Aufrufer / relatives.php).
     */
    public static function create_person(array $data): int
    {
        global $db;
        $now = TIME_NOW;
        $insert = self::prepare_row($data);
        $insert['created_at'] = $now;
        $insert['updated_at'] = $now;
        // MyBB's insert_query() quotes PHP null as the string '', which a nullable
        // int/varchar column stores as 0/'' instead of SQL NULL. Omit null-valued
        // keys entirely so the column's own DEFAULT NULL applies instead.
        $insert = array_filter($insert, static function ($value) {
            return $value !== null;
        });
        return (int)$db->insert_query('relatives_persons', $insert);
    }

    public static function update_person(int $id, array $data): bool
    {
        global $db;
        if ($id <= 0) {
            return false;
        }
        $update = self::prepare_row($data);
        $update['updated_at'] = TIME_NOW;

        // Same quoting problem as create_person(), but omitting the key isn't an
        // option here since an explicit NULL (e.g. clearing linked_uid on decline)
        // must actually change the column. Split out null-valued fields and set
        // them via a raw NULL assignment; the non-null fields still go through the
        // normal escaped/quoted update_query().
        $nullFields = [];
        foreach ($update as $field => $value) {
            if ($value === null) {
                $nullFields[] = $field;
                unset($update[$field]);
            }
        }

        if ($update) {
            $db->update_query('relatives_persons', $update, "id = " . (int)$id, 1);
        }
        if ($nullFields) {
            $set = [];
            foreach ($nullFields as $field) {
                $set[] = "`{$field}` = NULL";
            }
            $db->write_query(
                "UPDATE " . TABLE_PREFIX . "relatives_persons SET " . implode(', ', $set)
                . " WHERE id = " . (int)$id . " LIMIT 1"
            );
        }
        return true;
    }

    public static function delete_person(int $id): bool
    {
        global $db;
        if ($id <= 0) {
            return false;
        }
        $db->delete_query('relatives_persons', "id = " . (int)$id, 1);
        Relatives_Attributes::delete_person_attributes($id);
        return true;
    }

    /**
     * Sanitisiert/escaped ein rohes Daten-Array fuer INSERT/UPDATE. Nur bekannte Spalten
     * werden uebernommen (Whitelist), alles wird typisiert/escaped.
     */
    private static function prepare_row(array $data): array
    {
        global $db;
        $row = [];

        if (isset($data['owner_uid'])) {
            $row['owner_uid'] = (int)$data['owner_uid'];
        }
        if (isset($data['name'])) {
            $row['name'] = $db->escape_string((string)$data['name']);
        }
        if (isset($data['gender'])) {
            $row['gender'] = (int)$data['gender'];
        }
        if (array_key_exists('relationship_type_id', $data)) {
            $row['relationship_type_id'] = $data['relationship_type_id'] === null
                ? null
                : (int)$data['relationship_type_id'];
        }
        if (array_key_exists('relationship_custom_label', $data)) {
            $row['relationship_custom_label'] = $data['relationship_custom_label'] === null
                ? null
                : $db->escape_string((string)$data['relationship_custom_label']);
        }
        if (array_key_exists('age', $data)) {
            $row['age'] = $data['age'] === null || $data['age'] === '' ? null : (int)$data['age'];
        }
        if (array_key_exists('origin', $data)) {
            $row['origin'] = $data['origin'] === null ? null : $db->escape_string((string)$data['origin']);
        }
        if (array_key_exists('avatar', $data)) {
            $row['avatar'] = $data['avatar'] === null ? null : $db->escape_string((string)$data['avatar']);
        }
        if (array_key_exists('description', $data)) {
            $row['description'] = $data['description'] === null ? null : $db->escape_string((string)$data['description']);
        }
        if (isset($data['is_wanted'])) {
            $row['is_wanted'] = (int)(bool)$data['is_wanted'];
        }
        if (array_key_exists('wanted_thread_link', $data)) {
            $row['wanted_thread_link'] = $data['wanted_thread_link'] === null
                ? null
                : $db->escape_string((string)$data['wanted_thread_link']);
        }
        if (array_key_exists('linked_uid', $data)) {
            $row['linked_uid'] = $data['linked_uid'] === null ? null : (int)$data['linked_uid'];
        }
        if (isset($data['link_status'])) {
            $row['link_status'] = (int)$data['link_status'];
        }
        if (array_key_exists('link_requested_by', $data)) {
            $row['link_requested_by'] = $data['link_requested_by'] === null ? null : (int)$data['link_requested_by'];
        }
        if (array_key_exists('link_confirmed_at', $data)) {
            $row['link_confirmed_at'] = $data['link_confirmed_at'] === null ? null : (int)$data['link_confirmed_at'];
        }
        if (array_key_exists('legacy_id', $data)) {
            $row['legacy_id'] = $data['legacy_id'] === null ? null : (int)$data['legacy_id'];
        }
        if (array_key_exists('legacy_source', $data)) {
            $row['legacy_source'] = $data['legacy_source'] === null
                ? null
                : $db->escape_string((string)$data['legacy_source']);
        }

        return $row;
    }

    /**
     * Herkunfts-Optionsliste fuer das Formular, abhaengig von relatives_origin_mode.
     * Bei 'profilefield' werden die Optionen aus profilefields.options (MyBB-Format:
     * eine Option pro Zeile) gelesen. Bei fehlender/ungueltiger fid wird ein leeres
     * Array zurueckgegeben (Formular faellt dann auf Freitext zurueck).
     */
    public static function get_origin_options(): array
    {
        global $mybb, $db;

        if (!isset($mybb->settings['relatives_origin_mode']) || $mybb->settings['relatives_origin_mode'] !== 'profilefield') {
            return [];
        }

        $fid = isset($mybb->settings['relatives_origin_profilefield_id'])
            ? (int)$mybb->settings['relatives_origin_profilefield_id']
            : 0;
        if ($fid <= 0) {
            return [];
        }

        $field = $db->fetch_array($db->simple_select('profilefields', '*', "fid = " . (int)$fid, ['limit' => 1]));
        if (!$field || !isset($field['type']) || !isset($field['options'])) {
            return [];
        }

        $options = explode("\n", (string)$field['options']);
        $options = array_map('trim', $options);
        $options = array_filter($options, static function ($value) {
            return $value !== '';
        });

        return array_values($options);
    }

    /**
     * Existiert die konfigurierte Herkunfts-fid ueberhaupt in profilefields?
     * Wird von der ACP beim Speichern der Settings validiert.
     */
    public static function origin_profilefield_exists(int $fid): bool
    {
        global $db;
        if ($fid <= 0) {
            return false;
        }
        $row = $db->fetch_array($db->simple_select('profilefields', 'fid', "fid = " . (int)$fid, ['limit' => 1]));
        return $row !== false;
    }
}
