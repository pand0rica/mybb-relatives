<?php
/**
 * relatives - SearchApi
 *
 * Implementiert relatives_search_persons() und relatives_count_persons() -- die einzige
 * erlaubte Zugriffsart auf relatives_*-Daten fuer Fremdplugins (siehe PROJECT_CONTEXT.md
 * "Oeffentliche Adapter-API" und Dev-Kit conventions.md §7).
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_SearchApi
{
    /**
     * Filter-Keys (alle optional): gender, relationship_type_id, attribute_option_ids
     * (array), is_wanted (bool, Default true), age_min, age_max, query (Text Name/
     * Description), owner_uids (int[], Person gehoert mindestens einem dieser Accounts),
     * ids (int[]), dazu limit, offset und order ("name" = Standard, "age", "newest",
     * "updated"). Jede Zeile enthaelt auch created_at/updated_at (Unix-Zeit).
     * Rueckgabe: eine Zeile je Person, sanitisiert, aber NICHT html-escaped
     * -- Escaping macht der Konsument beim Rendern. Eine Person kann mehreren
     * Charakteren gehoeren; deren Zuordnungen stehen in 'owners' (inkl.
     * relationship_description = Beschreibung der Zuordnung und closeness = Naehe der
     * Verwandtschaft, kleiner = naeher, siehe Relatives_Kinship::closeness()). relationship_type_id
     * trifft, sobald mindestens eine Zuordnung diesen Grad hat.
     */
    public static function search(array $filters): array
    {
        global $db;

        $where = self::build_where($filters);
        if ($where === null) {
            return [];
        }

        $order = isset($filters['order']) ? (string)$filters['order'] : 'name';
        if ($order === 'newest') {
            $options = ['order_by' => 'created_at DESC, id', 'order_dir' => 'DESC'];
        } elseif ($order === 'updated') {
            $options = ['order_by' => 'updated_at DESC, id', 'order_dir' => 'DESC'];
        } elseif ($order === 'age') {
            $age_sql = self::age_sql();
            $options = ['order_by' => $age_sql . ' IS NULL, ' . $age_sql . ', name', 'order_dir' => 'ASC'];
        } else {
            $options = ['order_by' => 'name', 'order_dir' => 'ASC'];
        }
        if (isset($filters['limit']) && (int)$filters['limit'] > 0) {
            $options['limit'] = (int)$filters['limit'];
            $options['limit_start'] = isset($filters['offset']) ? max(0, (int)$filters['offset']) : 0;
        }

        $rows = [];
        $query = $db->simple_select(
            'relatives_persons',
            'id, name, gender, age, birth_year, origin, avatar, description, is_wanted, wanted_thread_link, created_at, updated_at',
            $where,
            $options
        );
        while ($row = $db->fetch_array($query)) {
            $rows[(int)$row['id']] = [
                'id'                 => (int)$row['id'],
                'name'               => (string)$row['name'],
                'gender'             => (int)$row['gender'],
                'age'                => Relatives_Database::computed_age($row),
                'birth_year'         => $row['birth_year'] !== null ? (int)$row['birth_year'] : null,
                'origin'             => $row['origin'],
                'avatar'             => $row['avatar'],
                'description'        => $row['description'],
                'is_wanted'          => (bool)$row['is_wanted'],
                'wanted_thread_link' => $row['wanted_thread_link'],
                'created_at'         => (int)$row['created_at'],
                'updated_at'         => (int)$row['updated_at'],
                'owners'             => [],
            ];
        }

        foreach (Relatives_Database::get_owners_bulk(array_keys($rows)) as $person_id => $owners) {
            if (isset($rows[$person_id])) {
                foreach ($owners as $i => $owner) {
                    $owners[$i]['closeness'] = Relatives_Kinship::closeness($owner['computed_concept_key'], $owner['computed_category']);
                }
                $rows[$person_id]['owners'] = $owners;
            }
        }

        return array_values($rows);
    }

    /**
     * Anzahl der Treffer fuer dieselben Filter wie search() (limit/offset/order werden
     * ignoriert) -- fuer Pagination in Fremdplugins.
     */
    public static function count(array $filters): int
    {
        global $db;

        $where = self::build_where($filters);
        if ($where === null) {
            return 0;
        }
        return (int)$db->fetch_field($db->simple_select('relatives_persons', 'COUNT(*) AS c', $where), 'c');
    }

    /** Alter = Inplay-Jahr - Geburtsjahr, sonst das alte, fest eingetragene Alter. */
    private static function age_sql(): string
    {
        $inplay_year = Relatives_Database::inplay_year();
        return $inplay_year !== null
            ? "(CASE WHEN birth_year IS NOT NULL THEN " . (int)$inplay_year . " - birth_year ELSE age END)"
            : "age";
    }

    /**
     * @return string|null WHERE-Bedingung, null wenn kein Treffer moeglich ist
     */
    private static function build_where(array $filters): ?string
    {
        global $db;

        $where = ["1=1"];

        if (isset($filters['gender']) && $filters['gender'] !== '' && $filters['gender'] !== null) {
            $where[] = "gender = " . (int)$filters['gender'];
        }

        if (isset($filters['relationship_type_id']) && (int)$filters['relationship_type_id'] > 0) {
            // Grade stehen seit v2.1 an den Verbindungen, nicht an der Zuordnung.
            $where[] = "id IN (SELECT person_id FROM " . TABLE_PREFIX . "relatives_relations"
                . " WHERE relationship_type_id = " . (int)$filters['relationship_type_id'] . ")";
        }

        $is_wanted = array_key_exists('is_wanted', $filters) ? (bool)$filters['is_wanted'] : true;
        $where[] = "is_wanted = " . ($is_wanted ? 1 : 0);

        $age_sql = self::age_sql();
        if (isset($filters['age_min']) && $filters['age_min'] !== '' && $filters['age_min'] !== null) {
            $where[] = $age_sql . " >= " . (int)$filters['age_min'];
        }
        if (isset($filters['age_max']) && $filters['age_max'] !== '' && $filters['age_max'] !== null) {
            $where[] = $age_sql . " <= " . (int)$filters['age_max'];
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

        // ids / owner_uids: gesetzt, aber leer = bewusst "nichts" (kein Treffer).
        if (array_key_exists('ids', $filters)) {
            $ids = self::positive_ints($filters['ids']);
            if (empty($ids)) {
                return null;
            }
            $where[] = "id IN (" . implode(',', $ids) . ")";
        }

        if (array_key_exists('owner_uids', $filters)) {
            $uids = self::positive_ints($filters['owner_uids']);
            if (empty($uids)) {
                return null;
            }
            $where[] = "id IN (SELECT person_id FROM " . TABLE_PREFIX . "relatives_person_owners"
                . " WHERE owner_uid IN (" . implode(',', $uids) . "))";
        }

        if (!empty($filters['attribute_option_ids']) && is_array($filters['attribute_option_ids'])) {
            $option_ids = self::positive_ints($filters['attribute_option_ids']);
            if (!empty($option_ids)) {
                $person_ids_filter = self::person_ids_with_options($option_ids);
                if (empty($person_ids_filter)) {
                    return null; // kein Match moeglich
                }
                $where[] = "id IN (" . implode(',', $person_ids_filter) . ")";
            }
        }

        return implode(' AND ', $where);
    }

    /** @return int[] */
    private static function positive_ints($value): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array)$value), static function ($v) {
            return $v > 0;
        })));
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
