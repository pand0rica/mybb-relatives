<?php
/**
 * relatives - Verbindungsanfragen zu gespielten Charakteren
 *
 * Wer eine Verbindung zu einem fremden gespielten Charakter eintraegt oder aendert
 * ("Norah ist Grosscousine von Lenja"), speichert sie nicht sofort: Die Spieler*in des
 * Charakters bekommt eine Anfrage (MyAlert + Box unter "Meine Verwandten") und
 * bestaetigt oder lehnt ab. Erst nach der Bestaetigung steht die Kante in
 * relatives_relations -- Kinship, Baum und Familien sehen offene Anfragen nie.
 *
 * - Bestaetigt: Kante wird angelegt bzw. die ersetzte Kante geaendert, und die
 *   Gegenperson steht danach auch in der Liste der bestaetigenden Person.
 * - Abgelehnt / abgebrochen: es bleibt alles, wie es war.
 * Trennen geht dagegen immer sofort, von beiden Seiten (relatives.php action=relation_delete).
 * Accounts derselben Spieler*in (Account-Switcher, users.as_uid) gelten als eine Person.
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_RelationRequests
{
    public const STATUS_PENDING = 0;
    public const STATUS_ACCEPTED = 1;
    public const STATUS_DECLINED = 2;
    public const STATUS_CANCELLED = 3;

    public const RESULT_ACCEPTED = 'accepted';
    public const RESULT_CONFLICT = 'conflict';
    public const RESULT_FAILED = 'failed';

    /** @var array|null uid => Haupt-uid (Account-Switcher), einmal je Request */
    private static $masters = null;

    /** @var bool|null Gibt es users.as_uid (Account-Switcher installiert)? Einmal je Request. */
    private static $has_as_uid = null;

    // ------------------------------------------------------------------
    // Wer muss bestaetigen?
    // ------------------------------------------------------------------

    /** Haupt-Account einer uid (Account-Switcher: users.as_uid), sonst die uid selbst. */
    public static function master_uid(int $uid): int
    {
        global $db;
        if ($uid <= 0) {
            return 0;
        }
        if (self::$masters === null) {
            self::$masters = [];
        }
        if (self::$has_as_uid === null) {
            self::$has_as_uid = (bool)$db->field_exists('as_uid', 'users');
        }
        if (!isset(self::$masters[$uid])) {
            $master = $uid;
            if (self::$has_as_uid) {
                $row = $db->fetch_array($db->simple_select('users', 'as_uid', 'uid = ' . (int)$uid, ['limit' => 1]));
                if ($row && (int)$row['as_uid'] > 0) {
                    $master = (int)$row['as_uid'];
                }
            }
            self::$masters[$uid] = $master;
        }
        return self::$masters[$uid];
    }

    /** Gehoeren beide Accounts derselben Spieler*in? */
    public static function same_player(int $a, int $b): bool
    {
        return $a > 0 && $b > 0 && ($a === $b || self::master_uid($a) === self::master_uid($b));
    }

    /**
     * Account, der eine Kante zwischen den beiden Personen bestaetigen muss, 0 = keiner.
     * Das ist die Spieler*in eines beteiligten gespielten Charakters, der nicht zu $uid
     * gehoert (die angefragte Seite wird zuerst geprueft).
     */
    public static function confirming_uid(int $person_id, ?int $related_person_id, int $uid): int
    {
        foreach ([(int)$related_person_id, $person_id] as $candidate) {
            if ($candidate <= 0) {
                continue;
            }
            $person = Relatives_Database::get_person($candidate);
            if ($person === null || (int)$person['link_status'] !== 2 || empty($person['linked_uid'])) {
                continue;
            }
            $linked_uid = (int)$person['linked_uid'];
            if (!self::same_player($linked_uid, $uid)) {
                return $linked_uid;
            }
        }
        return 0;
    }

    /**
     * Teilt die Kanten eines Formulars in sofort zu speichernde und anzufragende.
     * Unveraenderte Kanten bleiben sofort; eine geaenderte Kante zu einem fremden
     * Charakter bleibt in ihrer alten Form stehen, bis die Aenderung bestaetigt ist.
     *
     * @param int   $person_id  Person, deren Kanten gespeichert werden (0 = neu)
     * @param array $relations  Kanten wie fuer Relatives_Database::replace_relations()
     * @return array [array $immediate, array $pending] -- $pending je Eintrag
     *               ['relation' => .., 'target_uid' => int, 'replaces' => int]
     */
    public static function split(int $person_id, array $relations, int $uid): array
    {
        $existing = $person_id > 0 ? Relatives_Database::get_relations_for_person($person_id) : [];
        $by_signature = [];
        $by_anchor = [];
        foreach ($existing as $row) {
            $by_signature[self::signature($row)] = $row;
            $by_anchor[self::anchor_key($row)][] = $row;
        }

        $immediate = [];
        $pending = [];
        $kept_ids = [];
        foreach ($relations as $relation) {
            $anchor_person = !empty($relation['related_to_person_id']) ? (int)$relation['related_to_person_id'] : null;
            $target = self::confirming_uid($person_id, $anchor_person, $uid);
            if ($target === 0 || isset($by_signature[self::signature($relation)])) {
                $immediate[] = $relation;
                continue;
            }

            // Aenderung einer bestehenden Kante zu demselben Anker: die alte bleibt bis zur
            // Bestaetigung stehen (sonst waere die Verbindung bis dahin ganz weg).
            $replaces = 0;
            foreach ($by_anchor[self::anchor_key($relation)] ?? [] as $old) {
                if (!isset($kept_ids[(int)$old['id']])) {
                    $replaces = (int)$old['id'];
                    $kept_ids[$replaces] = true;
                    $immediate[] = self::relation_from_row($old);
                    break;
                }
            }
            $pending[] = ['relation' => $relation, 'target_uid' => $target, 'replaces' => $replaces];
        }
        return [$immediate, $pending];
    }

    // ------------------------------------------------------------------
    // Lesen
    // ------------------------------------------------------------------

    public static function get(int $id): ?array
    {
        global $db;
        if ($id <= 0 || !$db->table_exists('relatives_relation_requests')) {
            return null;
        }
        $row = $db->fetch_array($db->simple_select('relatives_relation_requests', '*', 'id = ' . (int)$id, ['limit' => 1]));
        return $row ?: null;
    }

    /** Offene Anfragen, die $uid (oder ein Account derselben Spieler*in) bestaetigen soll. */
    public static function get_open_for_target(int $uid): array
    {
        return self::query_open('target_uid', $uid);
    }

    /** Offene Anfragen, die $uid gestellt hat. */
    public static function get_open_by_requester(int $uid): array
    {
        return self::query_open('requested_by', $uid);
    }

    public static function count_open_for_target(int $uid): int
    {
        global $db;
        if ($uid <= 0 || !$db->table_exists('relatives_relation_requests')) {
            return 0;
        }
        return (int)$db->fetch_field($db->simple_select(
            'relatives_relation_requests',
            'COUNT(*) AS total',
            'target_uid = ' . (int)$uid . ' AND status = ' . self::STATUS_PENDING
        ), 'total');
    }

    /** Offene Anfragen fuer Kanten einer Person (Formular-Hinweis). */
    public static function get_open_for_person(int $person_id): array
    {
        global $db;
        if ($person_id <= 0 || !$db->table_exists('relatives_relation_requests')) {
            return [];
        }
        $rows = [];
        $query = $db->simple_select(
            'relatives_relation_requests',
            '*',
            '(person_id = ' . (int)$person_id . ' OR related_to_person_id = ' . (int)$person_id . ') AND status = ' . self::STATUS_PENDING,
            ['order_by' => 'id', 'order_dir' => 'ASC']
        );
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Klartext der angefragten Aussage, roh ("Norah Bell ist Grosscousine von Lenja
     * Iversen"). Beim Ausgeben escapen.
     */
    public static function describe(array $request): string
    {
        global $lang;

        $subject = Relatives_Database::get_person((int)$request['person_id']);
        $subject_name = $subject !== null ? (string)$subject['name'] : '?';
        if (!empty($request['related_to_family_id'])) {
            $family = Relatives_Families::get_by_id((int)$request['related_to_family_id']);
            return $lang->sprintf($lang->relatives_relation_statement_family, $subject_name, $family !== null ? (string)$family['name'] : '?');
        }
        $anchor = Relatives_Database::get_person((int)$request['related_to_person_id']);
        $type = !empty($request['relationship_type_id']) ? Relatives_RelationshipTypes::get_by_id((int)$request['relationship_type_id']) : null;
        $degree = $type !== null ? (string)$type['label'] : (string)($request['relationship_custom_label'] ?? '');
        return $lang->sprintf($lang->relatives_relation_statement, $subject_name, $degree, $anchor !== null ? (string)$anchor['name'] : '?');
    }

    // ------------------------------------------------------------------
    // Schreiben
    // ------------------------------------------------------------------

    /**
     * Legt eine Anfrage an und benachrichtigt die Spieler*in. Eine gleiche offene Anfrage
     * wird nicht verdoppelt. Gibt die id zurueck (0 = nichts angelegt).
     */
    public static function create(int $person_id, array $relation, int $target_uid, int $requested_by, int $replaces = 0): int
    {
        global $db;
        if ($person_id <= 0 || $target_uid <= 0) {
            return 0;
        }

        $row = [
            'person_id'                 => $person_id,
            'related_to_person_id'      => !empty($relation['related_to_person_id']) ? (int)$relation['related_to_person_id'] : 0,
            'related_to_family_id'      => !empty($relation['related_to_family_id']) ? (int)$relation['related_to_family_id'] : 0,
            'relationship_type_id'      => !empty($relation['relationship_type_id']) ? (int)$relation['relationship_type_id'] : 0,
            'relationship_custom_label' => (string)($relation['relationship_custom_label'] ?? ''),
            'is_secret'                 => !empty($relation['is_secret']) ? 1 : 0,
            'replaces_relation_id'      => max(0, $replaces),
            'requested_by'              => $requested_by,
            'target_uid'                => $target_uid,
        ];

        foreach (self::get_open_for_person($person_id) as $open) {
            if ((int)$open['person_id'] === $person_id
                && (int)$open['related_to_person_id'] === $row['related_to_person_id']
                && (int)$open['related_to_family_id'] === $row['related_to_family_id']) {
                // Neuer Stand ersetzt eine noch offene Anfrage fuer dieselbe Verbindung.
                $db->update_query('relatives_relation_requests', [
                    'relationship_type_id'      => $row['relationship_type_id'],
                    'relationship_custom_label' => $db->escape_string($row['relationship_custom_label']),
                    'is_secret'                 => $row['is_secret'],
                    'requested_by'              => $requested_by,
                    'created_at'                => TIME_NOW,
                ], 'id = ' . (int)$open['id']);
                return (int)$open['id'];
            }
        }

        $description = self::describe($row);
        $row['relationship_custom_label'] = $db->escape_string($row['relationship_custom_label']);
        $row['status'] = self::STATUS_PENDING;
        $row['created_at'] = TIME_NOW;
        $row['resolved_at'] = 0;
        $id = (int)$db->insert_query('relatives_relation_requests', $row);
        if ($id > 0) {
            Relatives_Alerts::send_relation_request($target_uid, $id, $description, $requested_by);
        }
        return $id;
    }

    /**
     * Bestaetigt eine Anfrage. Nur die angefragte Spieler*in.
     *
     * @return string RESULT_ACCEPTED | RESULT_CONFLICT (inzwischen widerspricht eine andere
     *                Aussage, Anfrage wird abgebrochen) | RESULT_FAILED
     */
    public static function accept(int $id, int $uid): string
    {
        $request = self::get($id);
        if ($request === null || (int)$request['status'] !== self::STATUS_PENDING
            || !self::same_player((int)$request['target_uid'], $uid)) {
            return self::RESULT_FAILED;
        }
        $person_id = (int)$request['person_id'];
        $anchor_id = (int)$request['related_to_person_id'];
        $family_id = (int)$request['related_to_family_id'];
        if (Relatives_Database::get_person($person_id) === null
            || ($anchor_id > 0 && Relatives_Database::get_person($anchor_id) === null)) {
            self::set_status($id, self::STATUS_CANCELLED);
            return self::RESULT_FAILED;
        }

        $relation = [
            'relationship_type_id'      => (int)$request['relationship_type_id'] > 0 ? (int)$request['relationship_type_id'] : null,
            'relationship_custom_label' => (string)$request['relationship_custom_label'] !== '' ? (string)$request['relationship_custom_label'] : null,
            'related_to_person_id'      => $anchor_id > 0 ? $anchor_id : null,
            'related_to_family_id'      => $family_id > 0 ? $family_id : null,
            'is_secret'                 => (int)$request['is_secret'],
        ];

        $replaces = (int)$request['replaces_relation_id'];
        $old = $replaces > 0 ? Relatives_Database::get_relation($replaces) : null;
        if ($old !== null && (int)$old['person_id'] === $person_id) {
            Relatives_Database::update_relation($replaces, $relation);
        } else {
            $type = $relation['relationship_type_id'] !== null ? Relatives_RelationshipTypes::get_by_id($relation['relationship_type_id']) : null;
            $existing = Relatives_Graph::find_existing_statement(
                $person_id,
                $relation['related_to_person_id'],
                $relation['related_to_family_id'],
                $type !== null ? (string)$type['concept_key'] : null,
                $relation['relationship_custom_label']
            );
            if ($existing !== null && empty($existing['same'])) {
                self::set_status($id, self::STATUS_CANCELLED);
                return self::RESULT_CONFLICT;
            }
            if ($existing === null) {
                $relation['person_id'] = $person_id;
                $relation['created_by'] = (int)$request['requested_by'];
                $relation['is_primary'] = Relatives_Database::get_primary_relation($person_id) === null ? 1 : 0;
                Relatives_Database::add_relation($relation);
            }
        }

        // Die Gegenperson steht danach auch in der Liste der bestaetigenden Person.
        $own_person = Relatives_Graph::find_account_person_id((int)$request['target_uid']);
        foreach ([$person_id, $anchor_id] as $other) {
            if ($other > 0 && $other !== $own_person && !Relatives_Database::is_owner($other, (int)$request['target_uid'])) {
                Relatives_Database::add_owner($other, (int)$request['target_uid']);
            }
        }

        self::set_status($id, self::STATUS_ACCEPTED);
        Relatives_Graph::reset_family_memberships();
        Relatives_Kinship::recompute_for_person($person_id);
        if ($anchor_id > 0) {
            Relatives_Kinship::recompute_for_person($anchor_id);
        }
        Relatives_Kinship::recompute_owner((int)$request['target_uid']);
        Relatives_Alerts::send_relation_answer((int)$request['requested_by'], $id, self::describe($request), true);
        return self::RESULT_ACCEPTED;
    }

    /** Lehnt eine Anfrage ab (nur die angefragte Spieler*in). */
    public static function decline(int $id, int $uid): bool
    {
        $request = self::get($id);
        if ($request === null || (int)$request['status'] !== self::STATUS_PENDING
            || !self::same_player((int)$request['target_uid'], $uid)) {
            return false;
        }
        self::set_status($id, self::STATUS_DECLINED);
        Relatives_Alerts::send_relation_answer((int)$request['requested_by'], $id, self::describe($request), false);
        return true;
    }

    /** Zieht eine eigene Anfrage zurueck. */
    public static function cancel(int $id, int $uid): bool
    {
        $request = self::get($id);
        if ($request === null || (int)$request['status'] !== self::STATUS_PENDING
            || !self::same_player((int)$request['requested_by'], $uid)) {
            return false;
        }
        self::set_status($id, self::STATUS_CANCELLED);
        return true;
    }

    /** Eine Person wurde komplett geloescht: ihre offenen Anfragen sind gegenstandslos. */
    public static function purge_for_person(int $person_id): void
    {
        global $db;
        if ($person_id <= 0 || !$db->table_exists('relatives_relation_requests')) {
            return;
        }
        $db->update_query('relatives_relation_requests', [
            'status'      => self::STATUS_CANCELLED,
            'resolved_at' => TIME_NOW,
        ], '(person_id = ' . (int)$person_id . ' OR related_to_person_id = ' . (int)$person_id . ') AND status = ' . self::STATUS_PENDING);
    }

    /** Ein Account wurde geloescht: Anfragen von ihm und an ihn entfallen. */
    public static function handle_user_delete(int $uid): void
    {
        global $db;
        if ($uid <= 0 || !$db->table_exists('relatives_relation_requests')) {
            return;
        }
        $db->update_query('relatives_relation_requests', [
            'status'      => self::STATUS_CANCELLED,
            'resolved_at' => TIME_NOW,
        ], '(requested_by = ' . (int)$uid . ' OR target_uid = ' . (int)$uid . ') AND status = ' . self::STATUS_PENDING);
    }

    // ------------------------------------------------------------------

    private static function query_open(string $column, int $uid): array
    {
        global $db;
        if ($uid <= 0 || !$db->table_exists('relatives_relation_requests')) {
            return [];
        }
        $rows = [];
        $query = $db->simple_select(
            'relatives_relation_requests',
            '*',
            $column . ' = ' . (int)$uid . ' AND status = ' . self::STATUS_PENDING,
            ['order_by' => 'created_at', 'order_dir' => 'ASC']
        );
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    private static function set_status(int $id, int $status): void
    {
        global $db;
        $db->update_query('relatives_relation_requests', [
            'status'      => $status,
            'resolved_at' => TIME_NOW,
        ], 'id = ' . (int)$id);
    }

    /** Anker einer Kante: Person oder Familie. */
    private static function anchor_key(array $relation): string
    {
        if (!empty($relation['related_to_family_id'])) {
            return 'f' . (int)$relation['related_to_family_id'];
        }
        return 'p' . (int)($relation['related_to_person_id'] ?? 0);
    }

    /** Anker + Grad + freie Bezeichnung -- "unveraendert"? */
    private static function signature(array $relation): string
    {
        return self::anchor_key($relation) . '|' . (int)($relation['relationship_type_id'] ?? 0) . '|'
            . mb_strtolower(trim((string)($relation['relationship_custom_label'] ?? '')));
    }

    /** Gespeicherte Kante in das Format von replace_relations(). */
    private static function relation_from_row(array $row): array
    {
        return [
            'relationship_type_id'      => $row['relationship_type_id'] !== null ? (int)$row['relationship_type_id'] : null,
            'relationship_custom_label' => $row['relationship_custom_label'],
            'related_to_person_id'      => $row['related_to_person_id'] !== null ? (int)$row['related_to_person_id'] : null,
            'related_to_family_id'      => $row['related_to_family_id'] !== null ? (int)$row['related_to_family_id'] : null,
            'is_secret'                 => (int)$row['is_secret'],
            'is_primary'                => (int)$row['is_primary'],
        ];
    }
}
