<?php
/**
 * relatives - Families
 *
 * CRUD fuer relatives_families (Familien-Kaesten wie "Familie Flemming"). Ein Kasten
 * haengt im Stammbaum an der Stelle der Eltern und fasst eine Geschwistergruppe
 * zusammen, ohne dass einzelne Vorfahren angelegt werden muessen.
 *
 * Familien sind -- wie Personen -- forumsweit geteilt: derselbe Kasten kann in
 * beliebig vielen Stammbaeumen haengen. Deshalb laeuft das Anlegen ueber
 * find_by_name(), analog zu Relatives_Database::find_person_by_name().
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_Families
{
    /** Fallback-Farbe, identisch zum DB-Default. */
    public const DEFAULT_COLOR = '#7d8894';

    public static function get_all(): array
    {
        global $db;
        $rows = [];
        $query = $db->simple_select('relatives_families', '*', '', ['order_by' => 'name', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($query)) {
            $rows[(int)$row['id']] = $row;
        }
        return $rows;
    }

    public static function get_by_id(int $id): ?array
    {
        global $db;
        if ($id <= 0) {
            return null;
        }
        $row = $db->fetch_array($db->simple_select('relatives_families', '*', "id = " . (int)$id, ['limit' => 1]));
        return $row ?: null;
    }

    /**
     * Nur die Kaesten, die im uebergebenen Baum tatsaechlich referenziert werden.
     *
     * @param array $family_ids Liste von ids (Duplikate egal)
     * @return array id => Zeile
     */
    public static function get_bulk(array $family_ids): array
    {
        global $db;
        $ids = [];
        foreach ($family_ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if (!$ids) {
            return [];
        }
        $rows = [];
        $query = $db->simple_select('relatives_families', '*', "id IN (" . implode(',', $ids) . ")");
        while ($row = $db->fetch_array($query)) {
            $rows[(int)$row['id']] = $row;
        }
        return $rows;
    }

    /**
     * Exakter Namenstreffer, Gross-/Kleinschreibung und aeussere Leerzeichen egal.
     * Der Vergleich passiert bewusst in PHP und nicht per SQL: die Kollation
     * utf8_unicode_ci wuerde auch Akzente gleichsetzen ("Flemming" = "Flémming"),
     * was hier zwei verschiedene Familien waeren (gleiche Begruendung wie in
     * Relatives_Database::find_person_by_name()). Aeltester Eintrag gewinnt.
     */
    public static function find_by_name(string $name): ?array
    {
        global $db;

        $needle = self::normalize_name($name);
        if ($needle === '') {
            return null;
        }

        $query = $db->simple_select(
            'relatives_families',
            '*',
            "name = '" . $db->escape_string(Relatives_Text::encode_4byte(trim($name))) . "'",
            ['order_by' => 'id', 'order_dir' => 'ASC']
        );
        while ($row = $db->fetch_array($query)) {
            if (self::normalize_name((string)$row['name']) === $needle) {
                return $row;
            }
        }
        return null;
    }

    private static function normalize_name(string $name): string
    {
        return mb_strtolower(Relatives_Text::encode_4byte(trim($name)));
    }

    /**
     * Anzahl Personen je Familien-Kasten (ueber alle Baeume, Person nur einmal
     * gezaehlt) -- fuer die Uebersichtsseite.
     *
     * @return array family_id => int
     */
    public static function get_member_counts(): array
    {
        global $db;
        $counts = [];
        $query = $db->write_query(
            "SELECT related_to_family_id AS fid, COUNT(DISTINCT person_id) AS total"
            . " FROM " . TABLE_PREFIX . "relatives_relations"
            . " WHERE related_to_family_id IS NOT NULL AND related_to_family_id > 0"
            . " GROUP BY related_to_family_id"
        );
        while ($row = $db->fetch_array($query)) {
            $counts[(int)$row['fid']] = (int)$row['total'];
        }
        return $counts;
    }

    public static function create(array $data): int
    {
        global $db;

        $now = TIME_NOW;
        $insert = [
            'name'        => $db->escape_string(Relatives_Text::encode_4byte(trim((string)($data['name'] ?? '')), 100)),
            'color'       => $db->escape_string(self::sanitize_color((string)($data['color'] ?? ''))),
            'description' => $db->escape_string(Relatives_Text::encode_4byte((string)($data['description'] ?? ''))),
            'welcomes_new_members' => array_key_exists('welcomes_new_members', $data) && empty($data['welcomes_new_members']) ? 0 : 1,
            'created_by'  => (int)($data['created_by'] ?? 0),
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
        return (int)$db->insert_query('relatives_families', $insert);
    }

    public static function update(int $id, array $data): bool
    {
        global $db;
        if ($id <= 0) {
            return false;
        }

        $update = [];
        if (isset($data['name'])) {
            $update['name'] = $db->escape_string(Relatives_Text::encode_4byte(trim((string)$data['name']), 100));
        }
        if (isset($data['color'])) {
            $update['color'] = $db->escape_string(self::sanitize_color((string)$data['color']));
        }
        if (isset($data['description'])) {
            $update['description'] = $db->escape_string(Relatives_Text::encode_4byte((string)$data['description']));
        }
        if (isset($data['welcomes_new_members'])) {
            $update['welcomes_new_members'] = empty($data['welcomes_new_members']) ? 0 : 1;
        }
        if (!$update) {
            return false;
        }
        $update['updated_at'] = TIME_NOW;

        $db->update_query('relatives_families', $update, "id = " . (int)$id, 1);
        return true;
    }

    /**
     * Loescht den Kasten und alle Kanten, die auf ihn zeigen. Die Personen selbst
     * bleiben erhalten -- sie haengen danach nur ohne Eltern-Ersatz im Baum.
     */
    public static function delete(int $id): bool
    {
        global $db;
        if ($id <= 0) {
            return false;
        }

        $db->delete_query('relatives_relations', "related_to_family_id = " . (int)$id);
        Relatives_Attributes::delete_family_attributes($id);
        $db->delete_query('relatives_families', "id = " . (int)$id, 1);
        return true;
    }

    /**
     * Freut sich die Familie ueber neue Mitglieder? Standard ja; fehlt die Spalte noch
     * (Plugin nach dem Update nicht reaktiviert), gilt die Familie ebenfalls als offen.
     * Eine geschlossene Familie nimmt keine weiteren gespielten Charaktere auf -- nur
     * NPCs, die bereits in ihrem Stammbaum stehen, koennen noch bespielt werden
     * (siehe Relatives_Graph::closed_family_violations()).
     */
    public static function welcomes_new_members(array $family): bool
    {
        return !isset($family['welcomes_new_members']) || (int)$family['welcomes_new_members'] !== 0;
    }

    /**
     * Akzeptiert nur #rrggbb (auch in Kurzform #rgb). Alles andere faellt auf die
     * Standardfarbe zurueck, damit der Wert gefahrlos in ein style-Attribut bzw.
     * ins Tree-JSON kann.
     */
    public static function sanitize_color(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#([0-9a-f]{3})$/i', $color, $short)) {
            $c = $short[1];
            return mb_strtolower('#' . $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2]);
        }
        if (preg_match('/^#[0-9a-f]{6}$/i', $color)) {
            return mb_strtolower($color);
        }
        return self::DEFAULT_COLOR;
    }
}
