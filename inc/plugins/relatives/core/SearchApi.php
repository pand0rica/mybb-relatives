<?php
/**
 * relatives - SearchApi
 *
 * Implementiert relatives_search_persons() -- die einzige erlaubte Zugriffsart auf
 * relatives_*-Daten fuer Fremdplugins (siehe PROJECT_CONTEXT.md "Oeffentliche
 * Adapter-API" und Dev-Kit conventions.md §7).
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_SearchApi
{
    /**
     * Filter-Keys (alle optional): gender, relationship_type_id, attribute_option_ids
     * (array), is_wanted (bool, Default true), age_min, age_max, query (Text Name/
     * Description). Rueckgabe: sanitisierte, aber NICHT html-escapte Zeilen -- Escaping
     * macht der Konsument beim Rendern.
     */
    public static function search(array $filters): array
    {
        global $db;

        $where = ["1=1"];

        if (isset($filters['gender']) && $filters['gender'] !== '' && $filters['gender'] !== null) {
            $where[] = "gender = " . (int)$filters['gender'];
        }

        if (isset($filters['relationship_type_id']) && (int)$filters['relationship_type_id'] > 0) {
            $where[] = "relationship_type_id = " . (int)$filters['relationship_type_id'];
        }

        $is_wanted = array_key_exists('is_wanted', $filters) ? (bool)$filters['is_wanted'] : true;
        $where[] = "is_wanted = " . ($is_wanted ? 1 : 0);

        if (isset($filters['age_min']) && $filters['age_min'] !== '' && $filters['age_min'] !== null) {
            $where[] = "age >= " . (int)$filters['age_min'];
        }
        if (isset($filters['age_max']) && $filters['age_max'] !== '' && $filters['age_max'] !== null) {
            $where[] = "age <= " . (int)$filters['age_max'];
        }

        if (isset($filters['query']) && trim((string)$filters['query']) !== '') {
            // Wildcard-Zeichen escapen, BEVOR escape_string() greift (kein Verlass auf
            // db-spezifische escape_string_like()-Hilfsmethoden -- nicht in jeder
            // MyBB-1.8.x-DB-Abstraktion garantiert vorhanden).
            $raw = trim((string)$filters['query']);
            $raw = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $raw);
            $needle = $db->escape_string($raw);
            $where[] = "(name LIKE '%{$needle}%' OR description LIKE '%{$needle}%')";
        }

        $person_ids_filter = null;
        if (!empty($filters['attribute_option_ids']) && is_array($filters['attribute_option_ids'])) {
            $option_ids = array_filter(array_map('intval', $filters['attribute_option_ids']), static function ($v) {
                return $v > 0;
            });
            if (!empty($option_ids)) {
                $person_ids_filter = self::person_ids_with_options($option_ids);
                if (empty($person_ids_filter)) {
                    return []; // kein Match moeglich
                }
                $where[] = "id IN (" . implode(',', $person_ids_filter) . ")";
            }
        }

        $rows = [];
        $query = $db->simple_select(
            'relatives_persons',
            'id, owner_uid, name, gender, relationship_type_id, relationship_custom_label, age, origin, description, is_wanted, wanted_thread_link',
            implode(' AND ', $where),
            ['order_by' => 'name', 'order_dir' => 'ASC']
        );
        while ($row = $db->fetch_array($query)) {
            $rows[] = [
                'id'                        => (int)$row['id'],
                'owner_uid'                 => (int)$row['owner_uid'],
                'name'                      => (string)$row['name'],
                'gender'                    => (int)$row['gender'],
                'relationship_type_id'      => $row['relationship_type_id'] !== null ? (int)$row['relationship_type_id'] : null,
                'relationship_custom_label' => $row['relationship_custom_label'],
                'age'                       => $row['age'] !== null ? (int)$row['age'] : null,
                'origin'                    => $row['origin'],
                'description'               => $row['description'],
                'is_wanted'                 => (bool)$row['is_wanted'],
                'wanted_thread_link'        => $row['wanted_thread_link'],
            ];
        }
        return $rows;
    }

    private static function person_ids_with_options(array $option_ids): array
    {
        global $db;
        $ids = [];
        $query = $db->simple_select(
            'relatives_person_attributes',
            'DISTINCT person_id',
            'option_id IN (' . implode(',', array_map('intval', $option_ids)) . ')'
        );
        while ($row = $db->fetch_array($query)) {
            $ids[] = (int)$row['person_id'];
        }
        return $ids;
    }
}
