<?php
/**
 * relatives - Database
 *
 * Repository fuer relatives_persons (Personen-Stammdaten) und relatives_person_owners
 * (Zuordnung Person <-> Besitzer*in, n:m): CRUD, Owner-Checks, Limit-Check,
 * Namenssuche fuer das Zusammenfuehren gleichnamiger Personen, Herkunfts-Logik
 * (freetext/profilefield).
 *
 * Aufteilung der Felder:
 * - relatives_persons: name, gender, age, origin, avatar, description, is_wanted,
 *   wanted_thread_link, is_deceased, death_note, Konto-Verknuepfung, legacy_*
 *   -- gilt fuer alle Besitzer*innen.
 * - relatives_person_owners: description (= eigener Beziehungstext) und der Cache der
 *   berechneten Verwandtschaft zu dir (computed_*) -- gilt pro Besitzer*in, genau
 *   einmal je Person.
 * - relatives_relations: das gemeinsame Geflecht (v2.2) -- jede Aussage "X ist <Grad>
 *   von Y" genau einmal, gueltig in allen Stammbaeumen, beliebig viele je Person.
 *   Genau eine davon ist je Person is_primary = 1; sie ist es, die alle Altaufrufer
 *   ueber die unveraenderten Aliasse relationship_type_id / relationship_custom_label /
 *   related_to_person_id zu sehen bekommen (siehe owner_select_sql()).
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_Database
{
    /**
     * Laedt die Stammdaten einer Person (ohne Besitzer-Felder).
     */
    /**
     * Zusatzangaben anderer Plugins je Person (Provider-Hook relatives_collect_person_details),
     * derzeit der Beruf aus der Jobliste. Listener setzen $args['details'][person_id]['job']
     * als reinen Text; Relatives escapet beim Ausgeben. Unbekannte Personen und andere Keys
     * werden verworfen. $args['linked_uids'] (person_id => uid) nennt die bestaetigt
     * verknuepften Accounts gespielter Charaktere -- deren Beruf haengt am Account.
     *
     * @return array person_id => ['job' => string]
     */
    public static function person_details(array $person_ids): array
    {
        global $plugins, $db;

        $person_ids = array_values(array_unique(array_filter(array_map('intval', $person_ids), static function ($id) {
            return $id > 0;
        })));
        if (!$person_ids || !is_object($plugins)) {
            return [];
        }
        $linked_uids = [];
        $query = $db->simple_select(
            'relatives_persons',
            'id, linked_uid',
            'link_status = 2 AND linked_uid > 0 AND id IN (' . implode(',', $person_ids) . ')'
        );
        while ($row = $db->fetch_array($query)) {
            $linked_uids[(int)$row['id']] = (int)$row['linked_uid'];
        }
        $args = ['person_ids' => $person_ids, 'linked_uids' => $linked_uids, 'details' => []];
        $plugins->run_hooks('relatives_collect_person_details', $args);

        $details = [];
        $known = array_flip($person_ids);
        foreach ((array)($args['details'] ?? []) as $person_id => $values) {
            $person_id = (int)$person_id;
            if (!isset($known[$person_id]) || !is_array($values)) {
                continue;
            }
            $job = isset($values['job']) && is_scalar($values['job']) ? trim((string)$values['job']) : '';
            if ($job !== '') {
                $details[$person_id] = ['job' => mb_substr($job, 0, 255)];
            }
        }
        return $details;
    }

    /** Aktuelles Inplay-Jahr aus den Einstellungen, null = nicht gesetzt (Feld "Alter"). */
    public static function inplay_year(): ?int
    {
        global $mybb;
        $value = trim((string)($mybb->settings['relatives_inplay_year'] ?? ''));
        if ($value === '' || !preg_match('/^-?\d{1,5}$/', $value)) {
            return null;
        }
        return (int)$value;
    }

    /**
     * Todesjahr aus dem Freitext-Feld death_note ("1999", "12.03.1999", "März 1999"):
     * die laengste Ziffernfolge, bei Gleichstand die letzte. null = nicht verstorben
     * oder kein Jahr erkennbar.
     */
    public static function death_year(array $person): ?int
    {
        if (empty($person['is_deceased']) || !isset($person['death_note'])) {
            return null;
        }
        if (!preg_match_all('/\d{1,5}/', (string)$person['death_note'], $matches)) {
            return null;
        }
        $year = null;
        foreach ($matches[0] as $digits) {
            if ($year === null || strlen($digits) >= strlen($year)) {
                $year = $digits;
            }
        }
        return (int)$year;
    }

    /**
     * Alter einer Person: aus dem Geburtsjahr, wenn es und das Inplay-Jahr bekannt sind,
     * sonst das alte, fest eingetragene Alter. Bei Verstorbenen mit erkennbarem Todesjahr
     * zaehlt das Todesjahr statt des Inplay-Jahres (Alter beim Tod).
     */
    public static function computed_age(array $person): ?int
    {
        $inplay_year = self::inplay_year();
        if ($inplay_year !== null && isset($person['birth_year']) && $person['birth_year'] !== null && $person['birth_year'] !== '') {
            $birth_year = (int)$person['birth_year'];
            $death_year = self::death_year($person);
            $reference_year = ($death_year !== null && $death_year >= $birth_year) ? $death_year : $inplay_year;
            return max(0, $reference_year - $birth_year);
        }
        if (isset($person['age']) && $person['age'] !== null && $person['age'] !== '') {
            return (int)$person['age'];
        }
        return null;
    }

    /** Anzeige "34 (geb. 1957)" bzw. "34", '' ohne Angabe. Roh -- beim Ausgeben escapen. */
    public static function age_label(array $person): string
    {
        global $lang;
        $age = self::computed_age($person);
        if ($age === null) {
            return '';
        }
        if (self::inplay_year() !== null && isset($person['birth_year']) && $person['birth_year'] !== null && $person['birth_year'] !== '') {
            return $lang->sprintf($lang->relatives_age_born, $age, (int)$person['birth_year']);
        }
        return (string)$age;
    }

    /**
     * Rechnet fest eingetragene Alter einmalig in Geburtsjahre um (ACP-Knopf): fuer alle
     * Personen mit Alter und ohne Geburtsjahr. Gibt die Anzahl umgerechneter Personen zurueck.
     */
    public static function convert_ages_to_birth_years(int $inplay_year): int
    {
        global $db;
        $db->write_query("UPDATE `" . TABLE_PREFIX . "relatives_persons`
            SET birth_year = " . (int)$inplay_year . " - age, age = NULL
            WHERE age IS NOT NULL AND birth_year IS NULL");
        return (int)$db->affected_rows();
    }

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
     * Stammdaten + Besitzer-Felder einer Person aus Sicht eines Accounts. null, wenn
     * der Account die Person nicht in seiner Liste hat.
     */
    public static function get_person_for_owner(int $person_id, int $owner_uid): ?array
    {
        global $db;
        if ($person_id <= 0 || $owner_uid <= 0) {
            return null;
        }
        $query = $db->query(self::owner_select_sql()
            . " WHERE p.id = " . (int)$person_id . " AND o.owner_uid = " . (int)$owner_uid
            . " LIMIT 1");
        $row = $db->fetch_array($query);
        return $row ?: null;
    }

    /**
     * Alle Personen eines Accounts, jeweils mit dessen Besitzer-Feldern
     * (relationship_type_id, relationship_custom_label, related_to_person_id,
     * relationship_description), sortiert nach Name.
     */
    public static function get_persons_by_owner(int $owner_uid): array
    {
        global $db;
        $rows = [];
        $query = $db->query(self::owner_select_sql()
            . " WHERE o.owner_uid = " . (int)$owner_uid
            . " ORDER BY p.name ASC, p.id ASC");
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Der LEFT JOIN auf die Primaer-Kante haelt die Feldnamen stabil, die es vor der
     * Einfuehrung von relatives_relations direkt auf relatives_person_owners gab.
     * Dadurch laufen TreeBuilder, SearchApi, Liste, Profil und die Legacy-Migration
     * unveraendert weiter und sehen die "Haupt"-Beziehung einer Person; alle weiteren
     * Kanten holt man sich gezielt ueber get_relations()/get_relations_bulk().
     * normalize_primary() garantiert hoechstens eine is_primary-Zeile je Person,
     * sonst wuerde der JOIN Zeilen vervielfachen.
     */
    private static function owner_select_sql(): string
    {
        return "SELECT p.*, o.id AS owner_link_id, o.owner_uid, o.description AS relationship_description,"
            . " o.computed_concept_key, o.computed_label, o.computed_up, o.computed_down, o.computed_category, o.computed_via_person_id,"
            . " r.id AS primary_relation_id, r.relationship_type_id, r.relationship_custom_label,"
            . " r.related_to_person_id, r.related_to_family_id, r.is_secret AS relation_is_secret"
            . " FROM " . TABLE_PREFIX . "relatives_persons p"
            . " INNER JOIN " . TABLE_PREFIX . "relatives_person_owners o ON o.person_id = p.id"
            . " LEFT JOIN " . TABLE_PREFIX . "relatives_relations r"
            . " ON r.person_id = p.id AND r.is_primary = 1";
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

    /**
     * Sucht eine bestehende Person mit exakt diesem Namen (Gross-/Kleinschreibung egal,
     * aeussere Leerzeichen egal). Bei mehreren Treffern gewinnt die aelteste Person.
     * Die Kollation der Spalte ist akzentunempfindlich ("Lena" = "Léna") -- deshalb wird
     * in PHP zusaetzlich exakt verglichen, damit nicht versehentlich zusammengefuehrt wird.
     */
    public static function find_person_by_name(string $name): ?array
    {
        global $db;
        $name = Relatives_Text::encode_4byte(trim($name));
        if ($name === '') {
            return null;
        }
        $needle = mb_strtolower($name);
        $query = $db->simple_select(
            'relatives_persons',
            '*',
            "name = '" . $db->escape_string($name) . "'",
            ['order_by' => 'id', 'order_dir' => 'ASC']
        );
        while ($row = $db->fetch_array($query)) {
            if (mb_strtolower(trim((string)$row['name'])) === $needle) {
                return $row;
            }
        }
        return null;
    }

    /** Anzahl Personen in der Liste eines Accounts (Grundlage des Personen-Limits). */
    public static function count_persons_by_owner(int $owner_uid): int
    {
        global $db;
        $row = $db->fetch_array($db->simple_select(
            'relatives_person_owners',
            'COUNT(*) AS cnt',
            "owner_uid = " . (int)$owner_uid
        ));
        return $row ? (int)$row['cnt'] : 0;
    }

    /** Anzahl Besitzer*innen einer Person. */
    public static function count_owners(int $person_id): int
    {
        global $db;
        $row = $db->fetch_array($db->simple_select(
            'relatives_person_owners',
            'COUNT(*) AS cnt',
            "person_id = " . (int)$person_id
        ));
        return $row ? (int)$row['cnt'] : 0;
    }

    /**
     * Anzahl Besitzer*innen fuer mehrere Personen auf einmal (Stammbaum).
     *
     * @return array person_id => Anzahl
     */
    public static function count_owners_bulk(array $person_ids): array
    {
        global $db;
        $ids = array_values(array_filter(array_map('intval', $person_ids), static function ($id) {
            return $id > 0;
        }));
        if (empty($ids)) {
            return [];
        }
        $counts = [];
        $query = $db->simple_select(
            'relatives_person_owners',
            'person_id, COUNT(*) AS cnt',
            'person_id IN (' . implode(',', $ids) . ') GROUP BY person_id'
        );
        while ($row = $db->fetch_array($query)) {
            $counts[(int)$row['person_id']] = (int)$row['cnt'];
        }
        return $counts;
    }

    /**
     * Besitzer-Zuordnungen mehrerer Personen (Such-API).
     *
     * @return array person_id => [ ['owner_uid' => .., 'relationship_type_id' => .., 'relationship_custom_label' => ..], ... ]
     */
    public static function get_owners_bulk(array $person_ids): array
    {
        global $db;
        $ids = array_values(array_filter(array_map('intval', $person_ids), static function ($id) {
            return $id > 0;
        }));
        if (empty($ids)) {
            return [];
        }
        $owners = [];
        $query = $db->write_query(
            "SELECT o.person_id, o.owner_uid, o.computed_concept_key, o.computed_label, o.computed_category, o.computed_via_person_id,"
            . " r.relationship_type_id, r.relationship_custom_label"
            . " FROM " . TABLE_PREFIX . "relatives_person_owners o"
            . " LEFT JOIN " . TABLE_PREFIX . "relatives_relations r"
            . " ON r.person_id = o.person_id AND r.is_primary = 1"
            . " WHERE o.person_id IN (" . implode(',', $ids) . ")"
            . " ORDER BY o.id ASC"
        );
        while ($row = $db->fetch_array($query)) {
            $owners[(int)$row['person_id']][] = [
                'owner_uid'                 => (int)$row['owner_uid'],
                'relationship_type_id'      => $row['relationship_type_id'] !== null ? (int)$row['relationship_type_id'] : null,
                'relationship_custom_label' => $row['relationship_custom_label'],
                'computed_concept_key'      => $row['computed_concept_key'],
                'computed_label'            => Relatives_Kinship::display_label($row['computed_label'], $row['computed_via_person_id']),
                'computed_category'         => $row['computed_category'],
            ];
        }
        return $owners;
    }

    /** Alle Besitzer*innen-uids einer Person (Cache-Neuberechnung nach jedem Schreibvorgang). */
    public static function get_owner_uids(int $person_id): array
    {
        global $db;
        if ($person_id <= 0) {
            return [];
        }
        $uids = [];
        $query = $db->simple_select('relatives_person_owners', 'owner_uid', "person_id = " . (int)$person_id);
        while ($row = $db->fetch_array($query)) {
            $uids[] = (int)$row['owner_uid'];
        }
        return $uids;
    }

    // ------------------------------------------------------------------
    // relatives_relations (Kanten des gemeinsamen Geflechts)
    // ------------------------------------------------------------------

    /** Alle Kanten, die von einer Person ausgehen (sie ist Subjekt der Aussage). */
    public static function get_relations_for_person(int $person_id): array
    {
        global $db;
        if ($person_id <= 0) {
            return [];
        }
        $rows = [];
        $query = $db->simple_select(
            'relatives_relations',
            '*',
            "person_id = " . (int)$person_id,
            ['order_by' => 'is_primary DESC, sort_order, id', 'order_dir' => 'ASC']
        );
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Alle Kanten, an denen eine Person beteiligt ist -- egal auf welcher Seite. */
    public static function get_relations_touching(int $person_id): array
    {
        global $db;
        if ($person_id <= 0) {
            return [];
        }
        $rows = [];
        $query = $db->simple_select(
            'relatives_relations',
            '*',
            "person_id = " . (int)$person_id . " OR related_to_person_id = " . (int)$person_id,
            ['order_by' => 'id', 'order_dir' => 'ASC']
        );
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Das komplette Geflecht in einer Query -- Baum und Verwandtschaftsrechnung brauchen es ganz. */
    public static function get_all_relations(): array
    {
        global $db;
        $rows = [];
        $query = $db->simple_select(
            'relatives_relations',
            '*',
            '',
            ['order_by' => 'is_primary DESC, sort_order, id', 'order_dir' => 'ASC']
        );
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Das komplette Geflecht, nach Subjekt gruppiert.
     *
     * @return array person_id => [Zeilen]
     */
    public static function get_relations_bulk(): array
    {
        $grouped = [];
        foreach (self::get_all_relations() as $row) {
            $grouped[(int)$row['person_id']][] = $row;
        }
        return $grouped;
    }

    public static function get_relation(int $id): ?array
    {
        global $db;
        if ($id <= 0) {
            return null;
        }
        $row = $db->fetch_array($db->simple_select('relatives_relations', '*', "id = " . (int)$id, ['limit' => 1]));
        return $row ?: null;
    }

    public static function get_primary_relation(int $person_id): ?array
    {
        global $db;
        if ($person_id <= 0) {
            return null;
        }
        $row = $db->fetch_array($db->simple_select(
            'relatives_relations',
            '*',
            "person_id = " . (int)$person_id . " AND is_primary = 1",
            ['order_by' => 'id', 'order_dir' => 'ASC', 'limit' => 1]
        ));
        return $row ?: null;
    }

    public static function add_relation(array $data): int
    {
        global $db;
        $person_id = (int)($data['person_id'] ?? 0);
        if ($person_id <= 0) {
            return 0;
        }

        $now = TIME_NOW;
        $insert = self::prepare_relation_row($data);
        $insert['person_id'] = $person_id;
        $insert['owner_uid'] = 0; // Kanten gehoeren niemandem (v2.2)
        $insert['created_at'] = $now;
        $insert['updated_at'] = $now;

        $id = (int)$db->insert_query('relatives_relations', self::without_nulls($insert));
        self::normalize_primary($person_id, !empty($insert['is_primary']) ? $id : 0);
        return $id;
    }

    /**
     * Listen-Kante der Alt-Migration: "Person ist <Grad> von <Charakter owner_uid>" -- die
     * Form vor dem gemeinsamen Geflecht (owner_uid gesetzt, ohne Bezugsperson = relativ
     * zum Charakter). Im Altplugin stand dieselbe Person oft in mehreren Listen (Caiomhe
     * ist bei Kayley Mutter, bei Ciaran Tante); jede Liste braucht daher ihre eigene
     * Kante. add_owner() schreibt dagegen in die EINE Primaerkante der Person und wuerde
     * die Aussage der vorigen Liste ueberschreiben. Relatives_Graph::merge() macht aus
     * den Listen-Kanten das Geflecht (Duplikate zusammenlegen, Widersprueche in die
     * Konfliktliste, eine Primaerkante je Person).
     */
    public static function add_list_relation(int $person_id, int $owner_uid, array $data): int
    {
        global $db;
        if ($person_id <= 0 || $owner_uid <= 0) {
            return 0;
        }
        $now = TIME_NOW;
        $insert = self::prepare_relation_row($data);
        $insert['person_id'] = $person_id;
        $insert['owner_uid'] = $owner_uid;
        $insert['is_primary'] = 1;
        $insert['created_by'] = $owner_uid;
        $insert['created_at'] = $now;
        $insert['updated_at'] = $now;
        return (int)$db->insert_query('relatives_relations', self::without_nulls($insert));
    }

    /** Aendert die Listen-Kante eines Charakters (Struktur-Ableitung der Alt-Migration). */
    public static function update_list_relation(int $person_id, int $owner_uid, array $data): bool
    {
        if ($person_id <= 0 || $owner_uid <= 0) {
            return false;
        }
        $update = self::prepare_relation_row($data);
        unset($update['created_by']);
        if (!$update) {
            return false;
        }
        $update['updated_at'] = TIME_NOW;
        self::update_row(
            'relatives_relations',
            $update,
            "person_id = " . (int)$person_id . " AND owner_uid = " . (int)$owner_uid
        );
        return true;
    }

    public static function update_relation(int $id, array $data): bool
    {
        $existing = self::get_relation($id);
        if ($existing === null) {
            return false;
        }

        $update = self::prepare_relation_row($data);
        unset($update['created_by']); // wer eine Kante angelegt hat, bleibt erhalten
        if (!$update) {
            return false;
        }
        $update['updated_at'] = TIME_NOW;
        self::update_row('relatives_relations', $update, "id = " . (int)$id);

        self::normalize_primary((int)$existing['person_id'], !empty($update['is_primary']) ? $id : 0);
        return true;
    }

    /** Loescht eine Kante. Die Rechtepruefung liegt beim Aufrufer (Relatives_Graph::can_edit_relation()). */
    public static function delete_relation(int $id): bool
    {
        global $db;
        $existing = self::get_relation($id);
        if ($existing === null) {
            return false;
        }
        $db->delete_query('relatives_relations', "id = " . (int)$id, 1);
        self::normalize_primary((int)$existing['person_id'], 0);
        return true;
    }

    /**
     * Ersetzt alle Kanten, die von einer Person ausgehen, durch den uebergebenen Satz
     * (Formular-Speichern: das Formular schickt immer den vollstaendigen Stand).
     * Kanten ANDERER Personen, die auf sie zeigen ("Ethan ist Sohn von Oliver" beim
     * Bearbeiten von Oliver), bleiben unberuehrt.
     *
     * @param array $relations Liste von ['relationship_type_id','relationship_custom_label',
     *                         'related_to_person_id','related_to_family_id','is_secret','is_primary']
     */
    public static function replace_relations(int $person_id, array $relations, int $created_by = 0): void
    {
        global $db;
        if ($person_id <= 0) {
            return;
        }

        // Unveraenderte Kanten (gleicher Anker + Grad) behalten ihre Zeile: id und
        // created_by bleiben so stabil. Offene Aenderungsanfragen (replaces_relation_id)
        // verweisen auf diese id -- wuerde jede Speicherung alles neu anlegen, zeigte
        // die Anfrage beim Bestaetigen ins Leere.
        $previous = [];
        foreach (self::get_relations_for_person($person_id) as $old) {
            $previous[self::relation_signature($old)][] = $old;
        }

        $rows = [];
        foreach ($relations as $relation) {
            $signature = self::relation_signature($relation);
            $old = !empty($previous[$signature]) ? array_shift($previous[$signature]) : null;
            $rows[] = [$relation, $old];
        }

        // Was im neuen Stand nicht mehr vorkommt, faellt weg.
        foreach ($previous as $leftovers) {
            foreach ($leftovers as $old) {
                $db->delete_query('relatives_relations', "id = " . (int)$old['id'], 1);
            }
        }

        $sort = 0;
        $preferred = 0;
        foreach ($rows as [$relation, $old]) {
            // Fehlende Felder wie bei einer neu angelegten Zeile behandeln (DB-Defaults).
            $relation += [
                'relationship_type_id'      => null,
                'relationship_custom_label' => null,
                'related_to_person_id'      => null,
                'related_to_family_id'      => null,
                'is_secret'                 => 0,
                'is_primary'                => 0,
            ];
            $relation['sort_order'] = $sort++;
            if ($old !== null) {
                $id = (int)$old['id'];
                $update = self::prepare_relation_row($relation);
                unset($update['created_by']); // wer eine Kante angelegt hat, bleibt erhalten
                $update['updated_at'] = TIME_NOW;
                self::update_row('relatives_relations', $update, "id = " . $id);
            } else {
                $relation['person_id'] = $person_id;
                $relation['created_by'] = $created_by;
                $id = self::add_relation($relation);
            }
            if (!empty($relation['is_primary'])) {
                $preferred = $id;
            }
        }
        self::normalize_primary($person_id, $preferred);
    }

    /** Vergleichsschluessel einer Kante fuer replace_relations() (Anker + Grad). */
    private static function relation_signature(array $relation): string
    {
        return (int)($relation['related_to_person_id'] ?? 0) . '|'
            . (int)($relation['related_to_family_id'] ?? 0) . '|'
            . (int)($relation['relationship_type_id'] ?? 0) . '|'
            . mb_strtolower(trim((string)($relation['relationship_custom_label'] ?? '')));
    }

    /**
     * Stellt sicher, dass es je Person genau eine is_primary-Kante gibt:
     * owner_select_sql() joint darauf, mehrere Treffer wuerden Zeilen vervielfachen und
     * keiner wuerde die "Haupt"-Beziehung eindeutig machen.
     * $preferred_id > 0 erzwingt eine bestimmte Kante, sonst gewinnt die bisherige
     * Hauptkante bzw. die zuerst eingetragene.
     */
    public static function normalize_primary(int $person_id, int $preferred_id = 0): void
    {
        global $db;
        if ($person_id <= 0) {
            return;
        }

        $ids = [];
        $query = $db->simple_select(
            'relatives_relations',
            'id, is_primary',
            "person_id = " . (int)$person_id,
            ['order_by' => 'sort_order, id', 'order_dir' => 'ASC']
        );
        while ($row = $db->fetch_array($query)) {
            $ids[(int)$row['id']] = (int)$row['is_primary'];
        }
        if (!$ids) {
            return;
        }

        $winner = 0;
        if ($preferred_id > 0 && isset($ids[$preferred_id])) {
            $winner = $preferred_id;
        } else {
            foreach ($ids as $id => $is_primary) {
                if ($is_primary) {
                    $winner = $id;
                    break;
                }
            }
        }
        if ($winner === 0) {
            $winner = (int)array_key_first($ids);
        }

        $db->update_query(
            'relatives_relations',
            ['is_primary' => 0],
            "person_id = " . (int)$person_id . " AND id != " . (int)$winner
        );
        $db->update_query('relatives_relations', ['is_primary' => 1], "id = " . (int)$winner, 1);
    }

    /**
     * Schreibt die Beziehungsfelder eines Altaufrufers (add_owner()/update_owner(),
     * v.a. Legacy-Migration und Struktur-Ableitung) in die Primaerkante. Eine Kante
     * ohne Bezugsperson bedeutet dort "relativ zu mir" -- im gemeinsamen Geflecht zeigt
     * sie deshalb auf den Knoten des Accounts.
     */
    private static function write_primary_relation(int $person_id, int $owner_uid, array $data): void
    {
        $keys = ['relationship_type_id', 'relationship_custom_label', 'related_to_person_id', 'related_to_family_id', 'is_secret'];
        $touched = false;
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $touched = true;
                break;
            }
        }
        if (!$touched) {
            return;
        }

        if (empty($data['related_to_person_id']) && empty($data['related_to_family_id'])) {
            $account_person = Relatives_Graph::account_person_id($owner_uid);
            if ($account_person <= 0 || $account_person === $person_id) {
                return;
            }
            $data['related_to_person_id'] = $account_person;
        }

        $existing = self::get_primary_relation($person_id);
        if ($existing !== null) {
            self::update_relation((int)$existing['id'], $data);
            return;
        }

        $data['person_id'] = $person_id;
        $data['is_primary'] = 1;
        $data['created_by'] = $owner_uid;
        self::add_relation($data);
    }

    /**
     * Owner-Check: hat dieser Account die Person in seiner Liste? Grundlage jedes
     * Update/Delete (fixt den Alt-IDOR-Bug). Jede*r Besitzer*in darf die Stammdaten
     * bearbeiten.
     */
    public static function is_owner(int $person_id, int $uid): bool
    {
        global $db;
        if ($person_id <= 0 || $uid <= 0) {
            return false;
        }
        $row = $db->fetch_array($db->simple_select(
            'relatives_person_owners',
            'id',
            "person_id = " . (int)$person_id . " AND owner_uid = " . (int)$uid,
            ['limit' => 1]
        ));
        return (bool)$row;
    }

    /**
     * Darf $related_id Bezugsperson von $person_id sein (0 = neue Person)? Im
     * gemeinsamen Geflecht zaehlt nur noch: die Person existiert und ist nicht die
     * Person selbst. Wer dort etwas eintragen darf, regelt
     * Relatives_Graph::can_edit_relation(); Kreise sind in einem Geflecht normal
     * (Geschwister zeigen aufeinander) und werden im Layout abgefangen.
     */
    public static function is_valid_related_to(int $related_id, int $person_id, int $owner_uid = 0): bool
    {
        if ($related_id <= 0 || $related_id === $person_id) {
            return false;
        }
        return self::get_person($related_id) !== null;
    }

    /**
     * Fuehrt irgendeine Bezugskette ab $start (einschliesslich) ueber $needle?
     * Seit der Einfuehrung von relatives_relations kann eine Person mehrere
     * Bezugspersonen haben -- die Pruefung ist deshalb eine Breitensuche ueber alle
     * Kanten statt eines Laufs entlang einer einzelnen Kette. $relations_by_person ist
     * optional; fehlt es, wird wie frueher nur die Primaerkante betrachtet (die steckt
     * bereits als related_to_person_id in $persons_by_id).
     */
    public static function related_chain_contains(array $persons_by_id, int $start, int $needle, ?array $relations_by_person = null): bool
    {
        $queue = [$start];
        $seen = [];
        while ($queue) {
            $current = (int)array_shift($queue);
            if ($current <= 0 || isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            if ($current === $needle) {
                return true;
            }
            if (!isset($persons_by_id[$current])) {
                continue;
            }

            if ($relations_by_person === null) {
                $queue[] = (int)($persons_by_id[$current]['related_to_person_id'] ?? 0);
                continue;
            }
            foreach ($relations_by_person[$current] ?? [] as $relation) {
                $queue[] = (int)($relation['related_to_person_id'] ?? 0);
            }
        }
        return false;
    }

    /**
     * Legt eine neue Person an (nur Stammdaten -- Besitzer*in danach per add_owner()).
     * $data enthaelt bereits validierte/sanitisierte Werte (Validierung liegt beim
     * Aufrufer / relatives.php).
     */
    public static function create_person(array $data): int
    {
        global $db;
        $now = TIME_NOW;
        $insert = self::prepare_person_row($data);
        $insert['created_at'] = $now;
        $insert['updated_at'] = $now;
        return (int)$db->insert_query('relatives_persons', self::without_nulls($insert));
    }

    public static function update_person(int $id, array $data): bool
    {
        if ($id <= 0) {
            return false;
        }
        $update = self::prepare_person_row($data);
        $update['updated_at'] = TIME_NOW;
        self::update_row('relatives_persons', $update, "id = " . (int)$id);
        return true;
    }

    /**
     * Fuegt einer Person eine*n Besitzer*in hinzu. Gibt 0 zurueck, wenn der Account die
     * Person bereits in seiner Liste hat (UNIQUE person_id + owner_uid).
     */
    public static function add_owner(int $person_id, int $owner_uid, array $data = []): int
    {
        global $db;
        if ($person_id <= 0 || $owner_uid <= 0 || self::is_owner($person_id, $owner_uid)) {
            return 0;
        }
        $now = TIME_NOW;
        $insert = self::prepare_owner_row($data);
        $insert['person_id'] = $person_id;
        $insert['owner_uid'] = $owner_uid;
        $insert['created_at'] = $now;
        $insert['updated_at'] = $now;
        $id = (int)$db->insert_query('relatives_person_owners', self::without_nulls($insert));

        // Altaufrufer uebergeben den Grad direkt hier -- er landet in der Primaerkante.
        self::write_primary_relation($person_id, $owner_uid, $data);
        return $id;
    }

    /**
     * Aktualisiert die Besitzer-Felder eines Accounts fuer eine Person. Beziehungs-
     * Keys (relationship_type_id, related_to_person_id, ...) werden weiterhin
     * akzeptiert und auf die Primaerkante umgeleitet -- so bleiben Legacy-Migration
     * und Struktur-Ableitung unveraendert nutzbar.
     */
    public static function update_owner(int $person_id, int $owner_uid, array $data): bool
    {
        if ($person_id <= 0 || $owner_uid <= 0) {
            return false;
        }
        $update = self::prepare_owner_row($data);
        if ($update) {
            $update['updated_at'] = TIME_NOW;
            self::update_row(
                'relatives_person_owners',
                $update,
                "person_id = " . (int)$person_id . " AND owner_uid = " . (int)$owner_uid
            );
        }
        self::write_primary_relation($person_id, $owner_uid, $data);
        return true;
    }

    /**
     * Schreibt den Verwandtschafts-Cache einer Person in einem Baum
     * (siehe Relatives_Kinship). Bewusst eine eigene, schmale Methode: sie laeuft
     * beim Neuberechnen ueber alle Personen und soll keine Beziehungs-Seiteneffekte
     * ausloesen.
     */
    public static function update_computed(int $person_id, int $owner_uid, array $computed): bool
    {
        if ($person_id <= 0 || $owner_uid <= 0) {
            return false;
        }
        $update = self::prepare_owner_row([
            'computed_concept_key' => $computed['concept_key'] ?? null,
            'computed_label'       => $computed['label'] ?? null,
            'computed_up'          => $computed['up'] ?? null,
            'computed_down'        => $computed['down'] ?? null,
            'computed_category'    => $computed['category'] ?? null,
            'computed_via_person_id' => $computed['via_person_id'] ?? null,
            'computed_at'          => TIME_NOW,
        ]);
        self::update_row(
            'relatives_person_owners',
            $update,
            "person_id = " . (int)$person_id . " AND owner_uid = " . (int)$owner_uid
        );
        return true;
    }

    /**
     * Entfernt eine Person aus der Liste eines Accounts. Ihre Verbindungen bleiben im
     * gemeinsamen Geflecht erhalten. Die Person selbst wird nur geloescht, wenn danach
     * niemand mehr mit ihr verbunden ist, sie kein gespielter Charakter ist und sie
     * keine Verbindungen mehr hat -- sonst wuerde ein Loch in fremde Stammbaeume gerissen.
     *
     * @return bool true, wenn die Person dabei komplett geloescht wurde.
     */
    public static function remove_owner(int $person_id, int $owner_uid): bool
    {
        global $db;
        if ($person_id <= 0 || $owner_uid <= 0) {
            return false;
        }
        $db->delete_query(
            'relatives_person_owners',
            "person_id = " . (int)$person_id . " AND owner_uid = " . (int)$owner_uid
        );

        $person = self::get_person($person_id);
        if ($person !== null
            && self::count_owners($person_id) === 0
            && (int)$person['link_status'] !== 2
            && !self::get_relations_touching($person_id)) {
            self::delete_person($person_id);
            return true;
        }
        return false;
    }

    /** Loescht eine Person komplett -- inklusive aller Zuordnungen und Verbindungen. */
    public static function delete_person(int $id): bool
    {
        global $db;
        if ($id <= 0) {
            return false;
        }
        $affected = [];
        foreach (self::get_relations_touching($id) as $relation) {
            $affected[(int)$relation['person_id']] = true;
        }
        $db->delete_query(
            'relatives_relations',
            "person_id = " . (int)$id . " OR related_to_person_id = " . (int)$id
        );
        if ($db->table_exists('relatives_relation_conflicts')) {
            $db->delete_query(
                'relatives_relation_conflicts',
                "person_id = " . (int)$id . " OR related_to_person_id = " . (int)$id
            );
        }
        $db->delete_query('relatives_person_owners', "person_id = " . (int)$id);
        $db->delete_query('relatives_persons', "id = " . (int)$id, 1);
        // Ein offener Loeschantrag fuer sie ist damit gegenstandslos.
        if (class_exists('Relatives_DeleteRequests')) {
            Relatives_DeleteRequests::purge_for_person($id);
        }
        if (class_exists('Relatives_RelationRequests')) {
            Relatives_RelationRequests::purge_for_person($id);
        }
        Relatives_Attributes::delete_person_attributes($id);

        // Wer eine Kante zu ihr als Hauptverbindung hatte, braucht eine neue.
        foreach (array_keys($affected) as $person_id) {
            if ($person_id !== $id) {
                self::normalize_primary($person_id, 0);
            }
        }
        return true;
    }

    /**
     * MyBB's insert_query() quotes PHP null as the string '', which a nullable
     * int/varchar column stores as 0/'' instead of SQL NULL. Omit null-valued keys
     * entirely so the column's own DEFAULT NULL applies instead.
     */
    private static function without_nulls(array $row): array
    {
        return array_filter($row, static function ($value) {
            return $value !== null;
        });
    }

    /**
     * Same quoting problem as without_nulls(), but omitting the key isn't an option for
     * updates since an explicit NULL (e.g. clearing linked_uid on decline) must actually
     * change the column. Null-valued fields are set via a raw NULL assignment; the
     * non-null fields still go through the normal escaped/quoted update_query().
     */
    private static function update_row(string $table, array $update, string $where): void
    {
        global $db;

        $null_fields = [];
        foreach ($update as $field => $value) {
            if ($value === null) {
                $null_fields[] = $field;
                unset($update[$field]);
            }
        }

        if ($update) {
            $db->update_query($table, $update, $where);
        }
        if ($null_fields) {
            $set = [];
            foreach ($null_fields as $field) {
                $set[] = "`{$field}` = NULL";
            }
            $db->write_query("UPDATE " . TABLE_PREFIX . $table . " SET " . implode(', ', $set) . " WHERE " . $where);
        }
    }

    /**
     * Sanitisiert/escaped die Stammdaten fuer INSERT/UPDATE. Nur bekannte Spalten
     * werden uebernommen (Whitelist) -- und nur Keys, die im Array ueberhaupt vorkommen.
     */
    private static function prepare_person_row(array $data): array
    {
        global $db;
        $row = [];

        if (isset($data['name'])) {
            $row['name'] = $db->escape_string(Relatives_Text::encode_4byte((string)$data['name'], 255));
        }
        if (isset($data['gender'])) {
            $row['gender'] = (int)$data['gender'];
        }
        if (array_key_exists('age', $data)) {
            $row['age'] = $data['age'] === null || $data['age'] === '' ? null : (int)$data['age'];
        }
        if (array_key_exists('birth_year', $data)) {
            $row['birth_year'] = $data['birth_year'] === null || $data['birth_year'] === '' ? null : (int)$data['birth_year'];
        }
        foreach (['origin', 'avatar', 'description', 'wanted_thread_link', 'death_note', 'legacy_source'] as $field) {
            if (array_key_exists($field, $data)) {
                $row[$field] = $data[$field] === null ? null : $db->escape_string(Relatives_Text::encode_4byte((string)$data[$field]));
            }
        }
        if (isset($data['is_wanted'])) {
            $row['is_wanted'] = (int)(bool)$data['is_wanted'];
        }
        if (array_key_exists('is_deceased', $data)) {
            $row['is_deceased'] = (int)(bool)$data['is_deceased'];
        }
        if (isset($data['link_status'])) {
            $row['link_status'] = (int)$data['link_status'];
        }
        foreach (['linked_uid', 'link_requested_by', 'link_confirmed_at', 'legacy_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $row[$field] = $data[$field] === null ? null : (int)$data[$field];
            }
        }

        return $row;
    }

    /**
     * Wie prepare_person_row(), fuer die Besitzer-Felder. Die Beziehungsfelder sind
     * hier bewusst NICHT mehr dabei -- sie liegen in relatives_relations und werden
     * von write_primary_relation() verarbeitet.
     */
    private static function prepare_owner_row(array $data): array
    {
        global $db;
        $row = [];

        foreach (['legacy_id', 'computed_up', 'computed_down', 'computed_via_person_id', 'computed_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $row[$field] = $data[$field] === null ? null : (int)$data[$field];
            }
        }
        foreach (['description', 'computed_concept_key', 'computed_label', 'computed_category'] as $field) {
            if (array_key_exists($field, $data)) {
                $row[$field] = $data[$field] === null ? null : $db->escape_string(Relatives_Text::encode_4byte((string)$data[$field]));
            }
        }

        return $row;
    }

    /** Sanitisiert/escaped eine Kante fuer INSERT/UPDATE (Whitelist wie oben). */
    private static function prepare_relation_row(array $data): array
    {
        global $db;
        $row = [];

        foreach (['relationship_type_id', 'related_to_person_id', 'related_to_family_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                // 0 und '' bedeuten im Formular "keine Angabe" -- als echtes NULL
                // speichern (MyBB wuerde sonst 0 schreiben).
                $row[$field] = ($value === null || $value === '' || (int)$value <= 0) ? null : (int)$value;
            }
        }
        if (array_key_exists('relationship_custom_label', $data)) {
            $row['relationship_custom_label'] = $data['relationship_custom_label'] === null
                ? null
                : $db->escape_string((string)$data['relationship_custom_label']);
        }
        foreach (['is_secret', 'is_primary'] as $field) {
            if (array_key_exists($field, $data)) {
                $row[$field] = (int)(bool)$data[$field];
            }
        }
        foreach (['sort_order', 'created_by'] as $field) {
            if (array_key_exists($field, $data)) {
                $row[$field] = (int)$data[$field];
            }
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
