<?php
/**
 * relatives - Loeschantraege fuer NPCs
 *
 * Eine nicht bespielte Person gehoert dem gemeinsamen Geflecht. Wer sie loescht, stellt
 * deshalb einen Antrag: Alle anderen, die sie in ihrer Liste haben, muessen zustimmen.
 * - Niemand sonst hat sie in der Liste -> sofort komplett loeschen (samt Verbindungen).
 * - Alle stimmen zu -> komplett loeschen, die antragstellende Person bekommt Bescheid.
 * - Eine Person lehnt ab -> Antrag platzt, der NPC bleibt; die antragstellende Person
 *   wird aus ihrer eigenen Liste ausgetragen und bekommt Bescheid.
 * Gespielte Charaktere werden nie so geloescht -- "Loeschen" traegt sie nur aus der
 * eigenen Liste aus (wie bisher).
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_DeleteRequests
{
    public const STATUS_PENDING = 0;
    public const STATUS_COMPLETED = 1;
    public const STATUS_DECLINED = 2;
    /** Abgebrochen, weil die Person inzwischen ein gespielter Charakter ist. */
    public const STATUS_CANCELLED = 3;

    public const VOTE_OPEN = 0;
    public const VOTE_CONFIRMED = 1;
    public const VOTE_DECLINED = 2;

    /** Ergebnisse von request() */
    public const RESULT_REMOVED = 'removed';
    public const RESULT_DELETED = 'deleted';
    public const RESULT_REQUESTED = 'requested';
    public const RESULT_PENDING_EXISTS = 'pending_exists';
    public const RESULT_FAILED = 'failed';

    /** Ergebnisse von vote() */
    public const VOTE_RESULT_CONFIRMED = 'confirmed';
    public const VOTE_RESULT_COMPLETED = 'completed';
    public const VOTE_RESULT_DECLINED = 'declined';
    public const VOTE_RESULT_FAILED = 'failed';

    /** Eine Person ist ein NPC, solange kein Account sie bestaetigt spielt. */
    public static function is_npc(array $person): bool
    {
        return !((int)$person['link_status'] === 2 && !empty($person['linked_uid']));
    }

    public static function get(int $id): ?array
    {
        global $db;
        if ($id <= 0) {
            return null;
        }
        $query = $db->simple_select('relatives_delete_requests', '*', "id = " . (int)$id, ['limit' => 1]);
        $row = $db->fetch_array($query);
        return $row ?: null;
    }

    public static function get_pending_for_person(int $person_id): ?array
    {
        global $db;
        if ($person_id <= 0 || !$db->table_exists('relatives_delete_requests')) {
            return null;
        }
        $query = $db->simple_select(
            'relatives_delete_requests',
            '*',
            "person_id = " . (int)$person_id . " AND status = " . self::STATUS_PENDING,
            ['limit' => 1]
        );
        $row = $db->fetch_array($query);
        return $row ?: null;
    }

    /**
     * Offene Antraege, die $uid selbst gestellt hat, je Person mit Stand der Zustimmungen
     * (fuer die Markierung in "Meine Verwandten").
     *
     * @return array person_id => ['id' => int, 'confirmed' => int, 'total' => int]
     */
    public static function get_pending_by_requester(int $uid): array
    {
        global $db;
        if ($uid <= 0 || !$db->table_exists('relatives_delete_requests')) {
            return [];
        }
        $result = [];
        $query = $db->query("SELECT r.id, r.person_id,
                SUM(CASE WHEN v.vote = " . self::VOTE_CONFIRMED . " THEN 1 ELSE 0 END) AS confirmed,
                COUNT(v.id) AS total
            FROM " . TABLE_PREFIX . "relatives_delete_requests r
            LEFT JOIN " . TABLE_PREFIX . "relatives_delete_votes v ON (v.request_id = r.id)
            WHERE r.requested_by = " . (int)$uid . " AND r.status = " . self::STATUS_PENDING . "
            GROUP BY r.id, r.person_id");
        while ($row = $db->fetch_array($query)) {
            $result[(int)$row['person_id']] = [
                'id'        => (int)$row['id'],
                'confirmed' => (int)$row['confirmed'],
                'total'     => (int)$row['total'],
            ];
        }
        return $result;
    }

    /**
     * Antraege, auf die $uid noch antworten muss.
     *
     * @return array Zeilen mit id, person_id, person_name, requested_by, created_at
     */
    public static function get_open_for_voter(int $uid): array
    {
        global $db;
        if ($uid <= 0 || !$db->table_exists('relatives_delete_requests')) {
            return [];
        }
        $rows = [];
        $query = $db->query("SELECT r.id, r.person_id, r.person_name, r.requested_by, r.created_at
            FROM " . TABLE_PREFIX . "relatives_delete_votes v
            INNER JOIN " . TABLE_PREFIX . "relatives_delete_requests r ON (r.id = v.request_id)
            WHERE v.uid = " . (int)$uid . " AND v.vote = " . self::VOTE_OPEN . " AND r.status = " . self::STATUS_PENDING . "
            ORDER BY r.created_at ASC");
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function count_open_for_voter(int $uid): int
    {
        global $db;
        if ($uid <= 0 || !$db->table_exists('relatives_delete_requests')) {
            return 0;
        }
        $query = $db->query("SELECT COUNT(*) AS cnt
            FROM " . TABLE_PREFIX . "relatives_delete_votes v
            INNER JOIN " . TABLE_PREFIX . "relatives_delete_requests r ON (r.id = v.request_id)
            WHERE v.uid = " . (int)$uid . " AND v.vote = " . self::VOTE_OPEN . " AND r.status = " . self::STATUS_PENDING);
        return (int)$db->fetch_field($query, 'cnt');
    }

    /**
     * Alle offenen Antraege (ACP) mit Stand der Zustimmungen.
     *
     * @return array Zeilen mit id, person_id, person_name, requested_by, created_at, confirmed, open
     */
    public static function get_all_pending(): array
    {
        global $db;
        if (!$db->table_exists('relatives_delete_requests')) {
            return [];
        }
        $rows = [];
        $query = $db->query("SELECT r.id, r.person_id, r.person_name, r.requested_by, r.created_at,
                SUM(CASE WHEN v.vote = " . self::VOTE_CONFIRMED . " THEN 1 ELSE 0 END) AS confirmed,
                SUM(CASE WHEN v.vote = " . self::VOTE_OPEN . " THEN 1 ELSE 0 END) AS open
            FROM " . TABLE_PREFIX . "relatives_delete_requests r
            LEFT JOIN " . TABLE_PREFIX . "relatives_delete_votes v ON (v.request_id = r.id)
            WHERE r.status = " . self::STATUS_PENDING . "
            GROUP BY r.id, r.person_id, r.person_name, r.requested_by, r.created_at
            ORDER BY r.created_at ASC");
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** uids, die bei einem Antrag noch nicht geantwortet haben. */
    public static function get_open_voter_uids(int $request_id): array
    {
        global $db;
        $uids = [];
        $query = $db->simple_select(
            'relatives_delete_votes',
            'uid',
            "request_id = " . (int)$request_id . " AND vote = " . self::VOTE_OPEN
        );
        while ($row = $db->fetch_array($query)) {
            $uids[] = (int)$row['uid'];
        }
        return $uids;
    }

    /**
     * "Loeschen" aus Sicht von $requester_uid (muss Besitzer*in sein -- prueft der Aufrufer).
     *
     * @return array ['result' => RESULT_*, 'waiting' => int Anzahl noch ausstehender Zustimmungen]
     */
    public static function request(int $person_id, int $requester_uid): array
    {
        global $db;

        $person = Relatives_Database::get_person($person_id);
        if ($person === null || $requester_uid <= 0) {
            return ['result' => self::RESULT_FAILED, 'waiting' => 0];
        }

        // Gespielte Charaktere gehoeren ihrem Account: nur aus der eigenen Liste austragen.
        if (!self::is_npc($person)) {
            Relatives_Database::remove_owner($person_id, $requester_uid);
            Relatives_Kinship::recompute_all();
            return ['result' => self::RESULT_REMOVED, 'waiting' => 0];
        }

        if (self::get_pending_for_person($person_id) !== null) {
            return ['result' => self::RESULT_PENDING_EXISTS, 'waiting' => 0];
        }

        $others = array_values(array_diff(
            array_unique(Relatives_Database::get_owner_uids($person_id)),
            [$requester_uid]
        ));

        // Niemand sonst zu fragen: sofort komplett weg, samt Verbindungen.
        if (!$others) {
            Relatives_Database::delete_person($person_id);
            Relatives_Kinship::recompute_all();
            return ['result' => self::RESULT_DELETED, 'waiting' => 0];
        }

        $request_id = (int)$db->insert_query('relatives_delete_requests', [
            'person_id'    => (int)$person_id,
            // Name bleibt fuer die Abschluss-Benachrichtigung erhalten, auch wenn die
            // Person dann schon geloescht ist.
            'person_name'  => $db->escape_string(mb_substr((string)$person['name'], 0, 255)),
            'requested_by' => (int)$requester_uid,
            'status'       => self::STATUS_PENDING,
            'created_at'   => TIME_NOW,
            'resolved_at'  => 0,
        ]);
        if ($request_id <= 0) {
            return ['result' => self::RESULT_FAILED, 'waiting' => 0];
        }

        self::add_votes($request_id, $others);
        Relatives_Alerts::send_delete_request($others, $request_id, (string)$person['name']);

        return ['result' => self::RESULT_REQUESTED, 'waiting' => count($others)];
    }

    /**
     * Antwort von $uid auf einen Antrag.
     *
     * @return string VOTE_RESULT_*
     */
    public static function vote(int $request_id, int $uid, bool $confirm): string
    {
        global $db;

        $request = self::get($request_id);
        if ($request === null || (int)$request['status'] !== self::STATUS_PENDING || $uid <= 0) {
            return self::VOTE_RESULT_FAILED;
        }
        $query = $db->simple_select(
            'relatives_delete_votes',
            'id, vote',
            "request_id = " . (int)$request_id . " AND uid = " . (int)$uid,
            ['limit' => 1]
        );
        $vote = $db->fetch_array($query);
        if (!$vote || (int)$vote['vote'] !== self::VOTE_OPEN) {
            return self::VOTE_RESULT_FAILED;
        }

        $db->update_query('relatives_delete_votes', [
            'vote'        => $confirm ? self::VOTE_CONFIRMED : self::VOTE_DECLINED,
            'answered_at' => TIME_NOW,
        ], "id = " . (int)$vote['id']);

        if (!$confirm) {
            self::decline($request, $uid);
            return self::VOTE_RESULT_DECLINED;
        }

        return self::try_finalize($request) ? self::VOTE_RESULT_COMPLETED : self::VOTE_RESULT_CONFIRMED;
    }

    /**
     * Die antragstellende Person zieht ihren offenen Antrag zurueck: die Person bleibt
     * unveraendert in der Liste, offene Antworten entfallen.
     */
    public static function withdraw(int $request_id, int $uid): bool
    {
        global $db;
        $request = self::get($request_id);
        if (
            $request === null
            || $uid <= 0
            || (int)$request['requested_by'] !== $uid
            || (int)$request['status'] !== self::STATUS_PENDING
        ) {
            return false;
        }
        $open_uids = self::get_open_voter_uids($request_id);
        self::set_status($request_id, self::STATUS_CANCELLED);
        $db->delete_query('relatives_delete_votes', "request_id = " . (int)$request_id . " AND vote = " . self::VOTE_OPEN);
        Relatives_Alerts::send_delete_withdrawn($open_uids, $request_id, (string)$request['person_name']);
        return true;
    }

    /**
     * Team-Entscheidung im ACP: sofort loeschen bzw. verwerfen, ohne auf offene
     * Antworten zu warten.
     */
    public static function force(int $request_id, bool $delete): bool
    {
        $request = self::get($request_id);
        if ($request === null || (int)$request['status'] !== self::STATUS_PENDING) {
            return false;
        }
        if ($delete) {
            self::complete($request);
        } else {
            self::decline($request, 0);
        }
        return true;
    }

    /**
     * Ein geloeschter Account: seine offenen Antraege platzen, seine offenen Antworten
     * entfallen (und koennen damit einen Antrag abschliessen).
     */
    public static function handle_user_delete(int $uid): void
    {
        global $db;
        if ($uid <= 0 || !$db->table_exists('relatives_delete_requests')) {
            return;
        }

        $db->update_query('relatives_delete_requests', [
            'status'      => self::STATUS_CANCELLED,
            'resolved_at' => TIME_NOW,
        ], "requested_by = " . (int)$uid . " AND status = " . self::STATUS_PENDING);

        $request_ids = [];
        $query = $db->simple_select('relatives_delete_votes', 'request_id', "uid = " . (int)$uid);
        while ($row = $db->fetch_array($query)) {
            $request_ids[] = (int)$row['request_id'];
        }
        $db->delete_query('relatives_delete_votes', "uid = " . (int)$uid);

        foreach (array_unique($request_ids) as $request_id) {
            $request = self::get($request_id);
            if ($request !== null && (int)$request['status'] === self::STATUS_PENDING) {
                self::try_finalize($request);
            }
        }
    }

    /** Aufraeumen, wenn eine Person auf anderem Weg komplett geloescht wurde. */
    public static function purge_for_person(int $person_id): void
    {
        global $db;
        if ($person_id <= 0 || !$db->table_exists('relatives_delete_requests')) {
            return;
        }
        $db->update_query('relatives_delete_requests', [
            'status'      => self::STATUS_CANCELLED,
            'resolved_at' => TIME_NOW,
        ], "person_id = " . (int)$person_id . " AND status = " . self::STATUS_PENDING);
    }

    // ------------------------------------------------------------------

    private static function add_votes(int $request_id, array $uids): void
    {
        global $db;
        foreach ($uids as $uid) {
            $db->insert_query('relatives_delete_votes', [
                'request_id'  => (int)$request_id,
                'uid'         => (int)$uid,
                'vote'        => self::VOTE_OPEN,
                'answered_at' => 0,
            ]);
        }
    }

    /**
     * Schliesst den Antrag ab, sobald niemand mehr offen ist. Wer inzwischen neu
     * hinzugekommen ist (Person uebernommen), wird vorher noch gefragt; wer die Person
     * nicht mehr in der Liste hat, zaehlt nicht mehr.
     *
     * @return bool true, wenn die Person dabei geloescht wurde
     */
    private static function try_finalize(array $request): bool
    {
        global $db;

        $request_id = (int)$request['id'];
        $person_id = (int)$request['person_id'];
        $person = Relatives_Database::get_person($person_id);

        if ($person === null) {
            self::set_status($request_id, self::STATUS_COMPLETED);
            Relatives_Alerts::send_delete_completed((int)$request['requested_by'], $request_id, (string)$request['person_name']);
            return true;
        }
        if (!self::is_npc($person)) {
            self::set_status($request_id, self::STATUS_CANCELLED);
            Relatives_Alerts::send_delete_declined((int)$request['requested_by'], $request_id, (string)$request['person_name'], 'played');
            return false;
        }

        $owners = array_unique(Relatives_Database::get_owner_uids($person_id));
        $requester = (int)$request['requested_by'];

        $voters = [];
        $query = $db->simple_select('relatives_delete_votes', 'uid, vote', "request_id = " . $request_id);
        while ($row = $db->fetch_array($query)) {
            $voters[(int)$row['uid']] = (int)$row['vote'];
        }

        // Offene Stimmen von Accounts, die die Person nicht mehr in der Liste haben, entfallen.
        $gone = array_diff(array_keys($voters), $owners);
        foreach ($gone as $uid) {
            if ($voters[$uid] === self::VOTE_OPEN) {
                $db->delete_query('relatives_delete_votes', "request_id = " . $request_id . " AND uid = " . (int)$uid);
                unset($voters[$uid]);
            }
        }

        $newcomers = array_values(array_diff($owners, array_keys($voters), [$requester]));
        if ($newcomers) {
            self::add_votes($request_id, $newcomers);
            Relatives_Alerts::send_delete_request($newcomers, $request_id, (string)$request['person_name'], $requester);
            return false;
        }

        if (in_array(self::VOTE_OPEN, $voters, true)) {
            return false;
        }

        self::complete($request);
        return true;
    }

    private static function complete(array $request): void
    {
        $person_id = (int)$request['person_id'];
        if (Relatives_Database::get_person($person_id) !== null) {
            Relatives_Database::delete_person($person_id);
            Relatives_Kinship::recompute_all();
        }
        self::set_status((int)$request['id'], self::STATUS_COMPLETED);
        Relatives_Alerts::send_delete_completed((int)$request['requested_by'], (int)$request['id'], (string)$request['person_name']);
    }

    /** Antrag platzt: NPC bleibt, die antragstellende Person wird ausgetragen. */
    private static function decline(array $request, int $declined_by): void
    {
        $request_id = (int)$request['id'];
        $requester = (int)$request['requested_by'];

        self::set_status($request_id, self::STATUS_DECLINED);
        Relatives_Database::remove_owner((int)$request['person_id'], $requester);
        Relatives_Kinship::recompute_all();
        Relatives_Alerts::send_delete_declined($requester, $request_id, (string)$request['person_name'], $declined_by > 0 ? 'user' : 'team');
    }

    private static function set_status(int $request_id, int $status): void
    {
        global $db;
        $db->update_query('relatives_delete_requests', [
            'status'      => (int)$status,
            'resolved_at' => TIME_NOW,
        ], "id = " . (int)$request_id);
    }
}
