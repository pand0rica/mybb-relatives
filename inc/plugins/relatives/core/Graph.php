<?php
/**
 * relatives - Graph
 *
 * Das gemeinsame Stammbaum-Geflecht (v2.2). Verbindungen gehoeren niemandem mehr:
 * jede Aussage "X ist <Grad> von Y" steht genau einmal in relatives_relations und gilt
 * in allen Stammbaeumen. Jeder gespielte Charakter ist selbst ein Knoten im Geflecht
 * (eine Person mit bestaetigtem linked_uid), "relativ zu dir" ist damit eine ganz
 * normale Kante zu diesem Knoten.
 *
 * Diese Klasse kapselt:
 * - den Knoten eines Accounts (account_person_id()),
 * - die Bearbeitungsrechte (can_edit_person()/can_edit_relation()),
 * - Duplikat-/Widerspruchspruefung zwischen zwei Knoten (canonical_key()),
 * - die einmalige Zusammenfuehrung der frueheren Einzel-Listen (preview()/merge()).
 *
 * relatives_relations.owner_uid ist nach der Zusammenfuehrung 0 und wird nicht mehr
 * gelesen; wer eine Kante eingetragen hat, steht in created_by.
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_Graph
{
    /** Hat das Team die Einzel-Listen bereits zusammengefuehrt? */
    public static function is_merged(): bool
    {
        global $mybb;
        return !empty($mybb->settings['relatives_graph_merged']);
    }

    // ------------------------------------------------------------------
    // Knoten gespielter Charaktere
    // ------------------------------------------------------------------

    /** Person, die einen Account im Geflecht vertritt (bestaetigt verknuepft), sonst 0. */
    public static function find_account_person_id(int $uid): int
    {
        global $db;
        if ($uid <= 0) {
            return 0;
        }
        $row = $db->fetch_array($db->simple_select(
            'relatives_persons',
            'id',
            "linked_uid = " . (int)$uid . " AND link_status = 2",
            ['order_by' => 'id', 'order_dir' => 'ASC', 'limit' => 1]
        ));
        return $row ? (int)$row['id'] : 0;
    }

    /**
     * Knoten eines Accounts, bei Bedarf angelegt: zuerst eine bereits bestaetigt
     * verknuepfte Person, dann eine gleichnamige, noch unverknuepfte Person (wird
     * verknuepft -- "jeder Charakter hat nur einen Eintrag"), sonst eine neue Person.
     */
    public static function account_person_id(int $uid): int
    {
        $existing = self::find_account_person_id($uid);
        if ($existing > 0 || $uid <= 0) {
            return $existing;
        }

        $user = get_user($uid);
        if (!$user || !isset($user['username'])) {
            return 0;
        }

        $same_name = Relatives_Database::find_person_by_name((string)$user['username']);
        if ($same_name !== null && (int)$same_name['link_status'] !== 2) {
            self::link_person_to_account((int)$same_name['id'], $uid);
            return (int)$same_name['id'];
        }

        return Relatives_Database::create_person([
            'name'              => (string)$user['username'],
            'gender'            => -1,
            'linked_uid'        => $uid,
            'link_status'       => 2,
            'link_confirmed_at' => TIME_NOW,
        ]);
    }

    private static function link_person_to_account(int $person_id, int $uid): void
    {
        Relatives_Database::update_person($person_id, [
            'linked_uid'        => $uid,
            'link_status'       => 2,
            'link_requested_by' => null,
            'link_confirmed_at' => TIME_NOW,
            'is_wanted'         => 0,
        ]);
    }

    // ------------------------------------------------------------------
    // Rechte
    // ------------------------------------------------------------------

    /** Team (Moderation/Administration) darf alles im Geflecht bearbeiten. */
    public static function is_staff(): bool
    {
        global $mybb;
        return !empty($mybb->usergroup['canmodcp']) || !empty($mybb->usergroup['cancp']);
    }

    /**
     * Darf $uid die Person bearbeiten?
     * - gespielter Charakter: nur der eigene Account (und das Team),
     * - nicht bespielte Person: alle, die mit ihr verbunden sind (sie in ihrer Liste
     *   haben), und das Team.
     */
    public static function can_edit_person(int $person_id, int $uid, ?array $person = null): bool
    {
        if ($person_id <= 0 || $uid <= 0) {
            return false;
        }
        if (self::is_staff()) {
            return true;
        }
        $person = $person ?? Relatives_Database::get_person($person_id);
        if ($person === null) {
            return false;
        }
        if ((int)$person['link_status'] === 2 && !empty($person['linked_uid'])) {
            return (int)$person['linked_uid'] === $uid;
        }
        return Relatives_Database::is_owner($person_id, $uid)
            || self::shares_family($person_id, self::find_account_person_id($uid));
    }

    /** @var array|null person_id => [family_id => true], einmal je Request */
    private static $family_members = null;

    /**
     * Familien-Zugehoerigkeit aus dem Verwandtschaftsgraphen: Zu einem Familien-Kasten
     * gehoeren die Personen, die direkt an ihm haengen, deren Geschwister, alle
     * Nachkommen davon und die Partner*innen all dieser Personen. Von Partner*innen aus
     * wird nicht weitergesucht -- sonst zoege jede Heirat die ganze andere Familie mit.
     *
     * @return array person_id => [family_id => true]
     */
    public static function family_memberships(): array
    {
        if (self::$family_members !== null) {
            return self::$family_members;
        }
        self::$family_members = self::memberships_from(Relatives_Kinship::world()['graph']);
        return self::$family_members;
    }

    /**
     * Familien-Zugehoerigkeit fuer einen beliebigen Graphen (Relatives_Kinship::structure_from()).
     * Reine Funktion -- closed_family_violations() rechnet damit einen noch nicht
     * gespeicherten Stand durch.
     *
     * @return array person_id => [family_id => true]
     */
    public static function memberships_from(array $graph): array
    {
        $members = [];

        // Startpunkte je Kasten: jede Kante dorthin -- als Kind ("als Eltern anzeigen")
        // genauso wie eine reine Mitgliedschaft ohne Grad.
        $direct = [];
        foreach ($graph['edges'] as [$subject, $anchor]) {
            if (strpos((string)$anchor, 'f:') === 0) {
                $direct[(int)substr((string)$anchor, 2)][] = (string)$subject;
            }
        }

        foreach ($direct as $family_id => $queue) {
            $seen = [];
            while ($queue) {
                $key = (string)array_shift($queue);
                if (isset($seen[$key]) || strpos($key, 'p:') !== 0) {
                    continue;
                }
                $seen[$key] = true;
                foreach (array_keys($graph['children'][$key] ?? []) as $next) {
                    $queue[] = $next;
                }
                foreach (array_keys($graph['siblings'][$key] ?? []) as $next) {
                    $queue[] = $next;
                }
            }
            // Partner*innen gehoeren dazu, werden aber nicht weiter aufgeloest.
            foreach (array_keys($seen) as $key) {
                foreach (array_keys($graph['partners'][$key] ?? []) as $partner) {
                    if (strpos((string)$partner, 'p:') === 0) {
                        $seen[(string)$partner] = true;
                    }
                }
            }
            foreach (array_keys($seen) as $key) {
                $members[(int)substr($key, 2)][$family_id] = true;
            }
        }

        return $members;
    }

    /**
     * Welche gespielten Charaktere wuerden durch die neuen Kanten einer Familie
     * beitreten, die sich nicht ueber neue Mitglieder freut? Gerechnet wird mit der
     * vollen Mitgliedschaft (family_memberships()): ein Charakter tritt nicht nur ueber
     * eine Kante zum Kasten bei, sondern ebenso als Kind, Geschwister oder Partner*in
     * eines Mitglieds -- auch dann, wenn die Kante an einer neuen NPC haengt ("Delia ist
     * meine Mutter" macht den eigenen Charakter zum Mitglied von Delias Familie).
     *
     * Erlaubt bleibt, eine NPC zu bespielen, die bereits im Stammbaum steht: sie ist
     * schon Mitglied und wird durch die Verknuepfung (LinkManager, account_person_id())
     * nur zum gespielten Charakter -- dabei entstehen keine neuen Kanten.
     *
     * @param int   $person_id     Person, deren Kanten gespeichert werden (0 = wird neu angelegt)
     * @param array $new_relations Kanten wie fuer Relatives_Database::replace_relations()
     * @param bool  $replace       true = ersetzen die bisherigen Kanten der Person, false = kommen dazu
     * @return array Liste von ['family' => Zeile, 'person' => Zeile], leer = alles erlaubt
     */
    public static function closed_family_violations(int $person_id, array $new_relations, bool $replace): array
    {
        $closed = [];
        foreach (Relatives_Families::get_all() as $family_id => $family) {
            if (!Relatives_Families::welcomes_new_members($family)) {
                $closed[(int)$family_id] = $family;
            }
        }
        if (!$closed || !$new_relations) {
            return [];
        }

        // Frisch lesen: relatives_collect_relations() legt den Charakter-Knoten bei Bedarf
        // erst in diesem Request an (account_person_id()), ein zuvor gecachter Graph
        // kennt ihn dann noch nicht.
        Relatives_Kinship::reset();
        $world = Relatives_Kinship::world();
        $persons = $world['persons'];
        // Eine neu anzulegende Person hat noch keine id; -1 kollidiert mit keiner echten
        // und ist nie ein gespielter Charakter.
        $subject_id = $person_id > 0 ? $person_id : -1;
        if (!isset($persons[$subject_id])) {
            $persons[$subject_id] = ['id' => $subject_id, 'link_status' => 0, 'linked_uid' => null];
        }

        $relations = [];
        foreach ($world['relations'] as $relation) {
            if ($replace && (int)$relation['person_id'] === $subject_id) {
                continue;
            }
            $relations[] = $relation;
        }
        foreach ($new_relations as $relation) {
            $relation['person_id'] = $subject_id;
            $relations[] = $relation;
        }

        $before = self::family_memberships();
        $after = self::memberships_from(Relatives_Kinship::structure_from($persons, $relations, $world['types']));

        $violations = [];
        foreach ($after as $member_id => $families) {
            $member = $persons[$member_id] ?? null;
            if ($member === null || (int)$member['link_status'] !== 2 || empty($member['linked_uid'])) {
                continue; // nur gespielte Charaktere zaehlen als neue Mitglieder
            }
            foreach (array_keys($families) as $family_id) {
                if (isset($closed[$family_id]) && !isset($before[$member_id][$family_id])) {
                    $violations[] = ['family' => $closed[$family_id], 'person' => $member];
                }
            }
        }
        return $violations;
    }

    /**
     * Alle Mitglieder je Familien-Kasten (Definition siehe family_memberships()).
     *
     * @return array family_id => [person_id, ...]
     */
    public static function family_member_lists(): array
    {
        $lists = [];
        foreach (self::family_memberships() as $person_id => $families) {
            foreach (array_keys($families) as $family_id) {
                $lists[$family_id][] = (int)$person_id;
            }
        }
        return $lists;
    }

    /** Gehoeren beide Personen mindestens einem gemeinsamen Familien-Kasten an? */
    public static function shares_family(int $person_id, int $other_person_id): bool
    {
        if ($person_id <= 0 || $other_person_id <= 0) {
            return false;
        }
        $members = self::family_memberships();
        if (!isset($members[$person_id], $members[$other_person_id])) {
            return false;
        }
        return (bool)array_intersect_key($members[$person_id], $members[$other_person_id]);
    }

    /** Nach Schreibzugriffen im selben Request (Tests, Zusammenfuehren). */
    public static function reset_family_memberships(): void
    {
        self::$family_members = null;
    }

    /** Eine Verbindung darf aendern, wer eine ihrer beiden Personen bearbeiten darf. */
    public static function can_edit_relation(array $relation, int $uid): bool
    {
        if (self::can_edit_person((int)$relation['person_id'], $uid)) {
            return true;
        }
        $other = (int)($relation['related_to_person_id'] ?? 0);
        return $other > 0 && self::can_edit_person($other, $uid);
    }

    // ------------------------------------------------------------------
    // Duplikate & Widersprueche
    // ------------------------------------------------------------------

    /**
     * Vergleichsschluessel einer Kante, unabhaengig davon, von welcher Seite sie
     * eingetragen wurde: "Delia ist Mutter von Louisa" und "Louisa ist Tochter von
     * Delia" ergeben dasselbe Paar mit demselben Konzept. Geschlecht spielt keine Rolle
     * (Bruder/Schwester sind beide 'sibling').
     *
     * @return array ['pair' => string, 'concept' => string] oder null ohne Gegenstelle
     */
    public static function canonical_key(int $person_id, ?int $related_person_id, ?int $related_family_id, ?string $concept, ?string $custom_label): ?array
    {
        $statement = $concept !== null && $concept !== ''
            ? $concept
            : 'custom:' . mb_strtolower(trim((string)$custom_label));

        if ($related_family_id !== null && $related_family_id > 0) {
            return ['pair' => 'p' . $person_id . '|f' . $related_family_id, 'concept' => $statement];
        }
        if ($related_person_id === null || $related_person_id <= 0 || $related_person_id === $person_id) {
            return null;
        }

        if ($person_id < $related_person_id) {
            return ['pair' => 'p' . $person_id . '|p' . $related_person_id, 'concept' => $statement];
        }
        // Von der anderen Seite aus formulieren, damit beide Richtungen vergleichbar sind.
        if (strpos($statement, 'custom:') !== 0) {
            $statement = Relatives_Kinship::inverse_concept($statement) ?? $statement;
        }
        return ['pair' => 'p' . $related_person_id . '|p' . $person_id, 'concept' => $statement];
    }

    /**
     * Gibt es zwischen den beiden Knoten schon eine Aussage? Liefert die bestehende
     * Kante mit 'same' => true (Duplikat) oder false (Widerspruch), sonst null.
     * $ignore_person_id blendet die Kanten der gerade bearbeiteten Person aus, die
     * ohnehin ersetzt werden.
     */
    public static function find_existing_statement(int $person_id, ?int $related_person_id, ?int $related_family_id, ?string $concept, ?string $custom_label, int $ignore_person_id = 0): ?array
    {
        global $db;

        $key = self::canonical_key($person_id, $related_person_id, $related_family_id, $concept, $custom_label);
        if ($key === null) {
            return null;
        }

        if ($related_family_id !== null && $related_family_id > 0) {
            $where = "person_id = " . (int)$person_id . " AND related_to_family_id = " . (int)$related_family_id;
        } else {
            $a = (int)$person_id;
            $b = (int)$related_person_id;
            $where = "((person_id = {$a} AND related_to_person_id = {$b}) OR (person_id = {$b} AND related_to_person_id = {$a}))";
        }
        if ($ignore_person_id > 0) {
            $where .= " AND person_id != " . (int)$ignore_person_id;
        }

        $types = Relatives_Kinship::type_map();
        $query = $db->simple_select('relatives_relations', '*', $where, ['order_by' => 'id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($query)) {
            $row_concept = !empty($row['relationship_type_id']) ? ($types[(int)$row['relationship_type_id']]['concept'] ?? null) : null;
            $row_key = self::canonical_key(
                (int)$row['person_id'],
                $row['related_to_person_id'] !== null ? (int)$row['related_to_person_id'] : null,
                $row['related_to_family_id'] !== null ? (int)$row['related_to_family_id'] : null,
                $row_concept,
                $row['relationship_custom_label']
            );
            if ($row_key === null) {
                continue;
            }
            $row['same'] = $row_key['concept'] === $key['concept'];
            return $row;
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Zusammenfuehrung der frueheren Einzel-Listen
    // ------------------------------------------------------------------

    /** Username (normalisiert) => uid. */
    private static function username_map(): array
    {
        global $db;
        $map = [];
        $query = $db->simple_select('users', 'uid, username');
        while ($row = $db->fetch_array($query)) {
            $map[mb_strtolower(trim((string)$row['username']))] = (int)$row['uid'];
        }
        return $map;
    }

    /** Normalisierter Name => [Personen-Zeilen, aelteste zuerst]. */
    private static function name_groups(): array
    {
        global $db;
        $groups = [];
        $query = $db->simple_select('relatives_persons', 'id, name, linked_uid, link_status', '', ['order_by' => 'id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($query)) {
            $groups[mb_strtolower(trim((string)$row['name']))][] = $row;
        }
        return $groups;
    }

    /**
     * Was wuerde merge() tun? Ohne Schreibzugriff -- Grundlage der ACP-Vorschau.
     *
     * @return array ['account_links' => [[person_id, name, uid]], 'duplicate_names' =>
     *               [[name, ids]], 'new_account_nodes' => [usernames], 'identical' => int,
     *               'conflicts' => [[text]]]
     */
    public static function preview(): array
    {
        global $db;

        $users = self::username_map();
        $groups = self::name_groups();
        $result = ['account_links' => [], 'duplicate_names' => [], 'new_account_nodes' => [], 'identical' => 0, 'conflicts' => []];

        $account_person = [];
        $query = $db->simple_select('relatives_persons', 'id, linked_uid', 'link_status = 2 AND linked_uid IS NOT NULL', ['order_by' => 'id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($query)) {
            $account_person[(int)$row['linked_uid']] = $account_person[(int)$row['linked_uid']] ?? (int)$row['id'];
        }

        foreach ($groups as $name => $rows) {
            if (count($rows) > 1) {
                $result['duplicate_names'][] = ['name' => (string)$rows[0]['name'], 'ids' => array_map('intval', array_column($rows, 'id'))];
            }
            if (isset($users[$name]) && !isset($account_person[$users[$name]])) {
                $account_person[$users[$name]] = (int)$rows[0]['id'];
                $result['account_links'][] = ['person_id' => (int)$rows[0]['id'], 'name' => (string)$rows[0]['name'], 'uid' => $users[$name]];
            }
        }

        $query = $db->simple_select('relatives_person_owners', 'DISTINCT owner_uid');
        while ($row = $db->fetch_array($query)) {
            $uid = (int)$row['owner_uid'];
            if (!isset($account_person[$uid])) {
                $user = get_user($uid);
                $result['new_account_nodes'][] = $user ? (string)$user['username'] : '#' . $uid;
            }
        }

        // Personen, die per Namensgleichheit zusammenfallen, auf die aelteste abbilden.
        $survivor = [];
        foreach ($groups as $rows) {
            foreach ($rows as $row) {
                $survivor[(int)$row['id']] = (int)$rows[0]['id'];
            }
        }

        $types = Relatives_Kinship::type_map();
        $seen = [];
        $query = $db->simple_select('relatives_relations', '*', '', ['order_by' => 'related_to_person_id IS NULL DESC, id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($query)) {
            $a = $survivor[(int)$row['person_id']] ?? (int)$row['person_id'];
            $family = $row['related_to_family_id'] !== null ? (int)$row['related_to_family_id'] : null;
            $b = null;
            if ($family === null) {
                $b = $row['related_to_person_id'] !== null
                    ? ($survivor[(int)$row['related_to_person_id']] ?? (int)$row['related_to_person_id'])
                    : ($account_person[(int)$row['owner_uid']] ?? -(int)$row['owner_uid']);
            }
            $concept = !empty($row['relationship_type_id']) ? ($types[(int)$row['relationship_type_id']]['concept'] ?? null) : null;
            $key = $b !== null && $b < 0
                ? ['pair' => 'p' . $a . '|u' . (-$b), 'concept' => (string)$concept]
                : self::canonical_key($a, $b, $family, $concept, $row['relationship_custom_label']);
            if ($key === null) {
                continue;
            }
            if (!isset($seen[$key['pair']])) {
                $seen[$key['pair']] = $key['concept'];
            } elseif ($seen[$key['pair']] === $key['concept']) {
                $result['identical']++;
            } else {
                $result['conflicts'][] = $key['pair'] . ': ' . $seen[$key['pair']] . ' / ' . $key['concept'];
            }
        }

        return $result;
    }

    /**
     * Fuehrt die frueheren Einzel-Listen zu einem gemeinsamen Geflecht zusammen.
     * Idempotent: ein zweiter Lauf findet nichts mehr zu tun.
     *
     * Reihenfolge ist wichtig -- erst muessen die Knoten feststehen (Accounts,
     * Namensdubletten), dann werden die Kanten auf diese Knoten umgeschrieben.
     */
    public static function merge(): array
    {
        global $db;

        $report = ['account_links' => 0, 'persons_merged' => 0, 'account_nodes_created' => 0, 'relations_kept' => 0, 'identical_removed' => 0, 'conflicts' => 0, 'self_removed' => 0];

        // 1. Personen mit dem Namen eines Accounts werden dessen Knoten.
        $users = self::username_map();
        foreach (self::name_groups() as $name => $rows) {
            if (!isset($users[$name]) || self::find_account_person_id($users[$name]) > 0) {
                continue;
            }
            self::link_person_to_account((int)$rows[0]['id'], $users[$name]);
            $report['account_links']++;
        }

        // 2. Gleichnamige Personen zu einer zusammenfassen ("nur ein Eintrag je Charakter").
        foreach (self::name_groups() as $rows) {
            for ($i = 1, $count = count($rows); $i < $count; $i++) {
                self::merge_person((int)$rows[$i]['id'], (int)$rows[0]['id']);
                $report['persons_merged']++;
            }
        }

        // 3. Jeder Charakter mit einer Liste bekommt seinen eigenen Knoten.
        $owner_uids = [];
        foreach (['relatives_person_owners', 'relatives_relations'] as $table) {
            $query = $db->simple_select($table, 'DISTINCT owner_uid', 'owner_uid > 0');
            while ($row = $db->fetch_array($query)) {
                $owner_uids[(int)$row['owner_uid']] = true;
            }
        }
        foreach (array_keys($owner_uids) as $uid) {
            if (self::find_account_person_id($uid) === 0 && get_user($uid)) {
                self::account_person_id($uid);
                $report['account_nodes_created']++;
            }
        }

        // 4. Kanten globalisieren. Aussagen, die direkt am eigenen Charakter hingen,
        //    kommen zuerst: die spielende Person kennt ihre eigene Familie am besten.
        $types = Relatives_Kinship::type_map();
        $seen = [];
        $rows = [];
        $query = $db->simple_select('relatives_relations', '*', '', ['order_by' => 'related_to_person_id IS NULL DESC, id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $person_id = (int)$row['person_id'];
            $family_id = $row['related_to_family_id'] !== null ? (int)$row['related_to_family_id'] : null;
            $anchor_id = null;
            if ($family_id === null) {
                $anchor_id = $row['related_to_person_id'] !== null
                    ? (int)$row['related_to_person_id']
                    : self::find_account_person_id((int)$row['owner_uid']);
            }

            if ($family_id === null && ($anchor_id === null || $anchor_id <= 0 || $anchor_id === $person_id)) {
                // Selbstbezug (z.B. die eigene Person in der eigenen Liste) oder Account
                // ohne Knoten -- ohne Gegenstelle ist die Aussage bedeutungslos.
                $db->delete_query('relatives_relations', "id = {$id}", 1);
                $report['self_removed']++;
                continue;
            }

            $concept = !empty($row['relationship_type_id']) ? ($types[(int)$row['relationship_type_id']]['concept'] ?? null) : null;
            $key = self::canonical_key($person_id, $anchor_id, $family_id, $concept, $row['relationship_custom_label']);

            if (!isset($seen[$key['pair']])) {
                $seen[$key['pair']] = ['concept' => $key['concept'], 'id' => $id];
                $update = ['owner_uid' => 0];
                if ((int)$row['created_by'] === 0 && (int)$row['owner_uid'] > 0) {
                    $update['created_by'] = (int)$row['owner_uid'];
                }
                if ($anchor_id !== null) {
                    $update['related_to_person_id'] = $anchor_id;
                }
                $db->update_query('relatives_relations', $update, "id = {$id}", 1);
                $report['relations_kept']++;
                continue;
            }

            if ($seen[$key['pair']]['concept'] !== $key['concept']) {
                $conflict_row = $row;
                $conflict_row['related_to_person_id'] = $anchor_id;
                self::store_conflict($conflict_row, (int)$seen[$key['pair']]['id'], (int)$row['owner_uid']);
                $report['conflicts']++;
            } else {
                $report['identical_removed']++;
            }
            $db->delete_query('relatives_relations', "id = {$id}", 1);
        }

        // 5. Genau eine Hauptverbindung je Person -- jetzt forumsweit statt je Liste.
        $query = $db->simple_select('relatives_relations', 'DISTINCT person_id');
        $person_ids = [];
        while ($row = $db->fetch_array($query)) {
            $person_ids[] = (int)$row['person_id'];
        }
        foreach ($person_ids as $person_id) {
            Relatives_Database::normalize_primary($person_id);
        }

        $db->update_query('settings', ['value' => TIME_NOW], "name = 'relatives_graph_merged'");
        rebuild_settings();

        Relatives_Kinship::recompute_all();

        return $report;
    }

    /**
     * Was wuerde merge_person() mit jeder Verbindung von $from tun? Ohne Schreibzugriff.
     *
     * - keep:      wird auf $into umgehaengt
     * - duplicate: dieselbe Aussage steht schon bei $into -> verworfen
     * - conflict:  widerspricht einer Aussage von $into (anderer Grad zwischen denselben
     *              Personen, oder ein dritter leiblicher Elternteil) -> Konfliktliste
     * - self:      wuerde auf $into selbst zeigen -> verworfen
     *
     * Aussagen von $into selbst haben Vorrang: bei einem gespielten Charakter sind das die
     * Angaben der spielenden Person ueber die eigene Familie.
     */
    public static function plan_merge_person(int $from, int $into): array
    {
        $plan = ['keep' => [], 'duplicate' => [], 'conflict' => [], 'self' => []];
        if ($from <= 0 || $into <= 0 || $from === $into) {
            return $plan;
        }

        $types = Relatives_Kinship::type_map();
        $concept_of = static function (array $row) use ($types): ?string {
            return !empty($row['relationship_type_id']) ? ($types[(int)$row['relationship_type_id']]['concept'] ?? null) : null;
        };

        $existing = [];
        $parent_edges = [];
        foreach (Relatives_Database::get_relations_touching($into) as $row) {
            $key = self::row_key($row, $concept_of($row));
            if ($key !== null) {
                $existing[$key['pair']] = ['concept' => $key['concept'], 'id' => (int)$row['id']];
            }
            if (self::is_blood_parent_edge_of($row, $into, $concept_of($row))) {
                $parent_edges[] = (int)$row['id'];
            }
        }

        foreach (Relatives_Database::get_relations_touching($from) as $row) {
            $moved = $row;
            if ((int)$moved['person_id'] === $from) {
                $moved['person_id'] = $into;
            }
            if ((int)($moved['related_to_person_id'] ?? 0) === $from) {
                $moved['related_to_person_id'] = $into;
            }
            if ((int)$moved['person_id'] === (int)($moved['related_to_person_id'] ?? 0)) {
                $plan['self'][] = $row;
                continue;
            }

            $concept = $concept_of($moved);
            $key = self::row_key($moved, $concept);
            if ($key !== null && isset($existing[$key['pair']])) {
                if ($existing[$key['pair']]['concept'] === $key['concept']) {
                    $plan['duplicate'][] = $row;
                } else {
                    $plan['conflict'][] = ['row' => $moved, 'original' => $row, 'kept_relation_id' => $existing[$key['pair']]['id'], 'reason' => 'contradiction'];
                }
                continue;
            }
            if (count($parent_edges) >= 2 && self::is_blood_parent_edge_of($moved, $into, $concept)) {
                $plan['conflict'][] = ['row' => $moved, 'original' => $row, 'kept_relation_id' => $parent_edges[0], 'reason' => 'third_parent'];
                continue;
            }

            $plan['keep'][] = ['row' => $moved, 'original' => $row];
            if ($key !== null) {
                $existing[$key['pair']] = ['concept' => $key['concept'], 'id' => (int)$row['id']];
            }
            if (self::is_blood_parent_edge_of($moved, $into, $concept)) {
                $parent_edges[] = (int)$row['id'];
            }
        }

        return $plan;
    }

    private static function row_key(array $row, ?string $concept): ?array
    {
        return self::canonical_key(
            (int)$row['person_id'],
            $row['related_to_person_id'] !== null ? (int)$row['related_to_person_id'] : null,
            $row['related_to_family_id'] !== null ? (int)$row['related_to_family_id'] : null,
            $concept,
            $row['relationship_custom_label']
        );
    }

    /** Ist die Kante eine leibliche Elternschaft, in der $person das Kind ist? */
    private static function is_blood_parent_edge_of(array $row, int $person, ?string $concept): bool
    {
        if ($concept === null || !Relatives_Kinship::is_blood_concept($concept)) {
            return false;
        }
        $class = Relatives_Kinship::classify_relation($concept, 0);
        if ($class === 'child') {
            return (int)$row['person_id'] === $person && !empty($row['related_to_person_id']);
        }
        if ($class === 'parent') {
            return (int)($row['related_to_person_id'] ?? 0) === $person;
        }
        return false;
    }

    /** Legt eine verworfene Aussage in der Konfliktliste ab (ACP: uebernehmen/verwerfen). */
    private static function store_conflict(array $row, int $kept_relation_id, int $source_uid): void
    {
        global $db;

        $conflict = [
            'person_id'        => (int)$row['person_id'],
            'is_secret'        => (int)($row['is_secret'] ?? 0),
            'source_uid'       => $source_uid,
            'kept_relation_id' => $kept_relation_id,
            'created_at'       => TIME_NOW,
        ];
        if (!empty($row['related_to_person_id'])) {
            $conflict['related_to_person_id'] = (int)$row['related_to_person_id'];
        }
        if (!empty($row['related_to_family_id'])) {
            $conflict['related_to_family_id'] = (int)$row['related_to_family_id'];
        }
        if (!empty($row['relationship_type_id'])) {
            $conflict['relationship_type_id'] = (int)$row['relationship_type_id'];
        }
        if (isset($row['relationship_custom_label']) && $row['relationship_custom_label'] !== null) {
            $conflict['relationship_custom_label'] = $db->escape_string((string)$row['relationship_custom_label']);
        }
        $db->insert_query('relatives_relation_conflicts', $conflict);
    }

    /**
     * Fuehrt Person $from in $into ueber (siehe plan_merge_person()): Zuordnungen,
     * passende Verbindungen, Attribute, leere Stammdaten und die Alt-ID wandern mit;
     * doppelte und widerspruechliche Verbindungen werden nicht stillschweigend
     * uebernommen. $from wird danach geloescht.
     *
     * @return array Zaehler: relations_moved, duplicates_removed, conflicts, self_removed, owners_moved
     */
    public static function merge_person(int $from, int $into): array
    {
        global $db;
        $report = ['relations_moved' => 0, 'duplicates_removed' => 0, 'conflicts' => 0, 'self_removed' => 0, 'owners_moved' => 0];
        if ($from <= 0 || $into <= 0 || $from === $into) {
            return $report;
        }
        $source = Relatives_Database::get_person($from);
        $target = Relatives_Database::get_person($into);
        if ($source === null || $target === null) {
            return $report;
        }

        $plan = self::plan_merge_person($from, $into);

        // Zuordnungen: doppelte (gleiche*r Besitzer*in) verwerfen, Rest umhaengen.
        $query = $db->simple_select('relatives_person_owners', 'id, owner_uid, description', "person_id = {$from}");
        while ($row = $db->fetch_array($query)) {
            if (Relatives_Database::is_owner($into, (int)$row['owner_uid'])) {
                $db->delete_query('relatives_person_owners', "id = " . (int)$row['id'], 1);
            } else {
                $db->update_query('relatives_person_owners', ['person_id' => $into], "id = " . (int)$row['id'], 1);
                $report['owners_moved']++;
            }
        }

        $touched_subjects = [$into => true];
        foreach ($plan['keep'] as $item) {
            $update = [];
            if ((int)$item['original']['person_id'] === $from) {
                $update['person_id'] = $into;
            }
            if ((int)($item['original']['related_to_person_id'] ?? 0) === $from) {
                $update['related_to_person_id'] = $into;
            }
            if ($update) {
                $db->update_query('relatives_relations', $update, "id = " . (int)$item['original']['id'], 1);
            }
            $touched_subjects[(int)$item['row']['person_id']] = true;
            $report['relations_moved']++;
        }
        foreach ($plan['conflict'] as $item) {
            self::store_conflict($item['row'], (int)$item['kept_relation_id'], (int)($item['original']['created_by'] ?? 0));
            $db->delete_query('relatives_relations', "id = " . (int)$item['original']['id'], 1);
            $report['conflicts']++;
        }
        foreach ($plan['duplicate'] as $row) {
            $db->delete_query('relatives_relations', "id = " . (int)$row['id'], 1);
            $report['duplicates_removed']++;
        }
        foreach ($plan['self'] as $row) {
            $db->delete_query('relatives_relations', "id = " . (int)$row['id'], 1);
            $report['self_removed']++;
        }
        if ($db->table_exists('relatives_relation_conflicts')) {
            $db->update_query('relatives_relation_conflicts', ['person_id' => $into], "person_id = {$from}");
            $db->update_query('relatives_relation_conflicts', ['related_to_person_id' => $into], "related_to_person_id = {$from}");
        }

        if (empty(Relatives_Attributes::get_person_attributes($into))) {
            $db->update_query('relatives_person_attributes', ['person_id' => $into], "person_id = {$from}");
        }
        Relatives_Attributes::delete_person_attributes($from);

        $fill = [];
        foreach (['age', 'origin', 'avatar', 'description', 'wanted_thread_link', 'death_note'] as $field) {
            if (($target[$field] === null || $target[$field] === '') && $source[$field] !== null && $source[$field] !== '') {
                $fill[$field] = $source[$field];
            }
        }
        if ((int)$target['gender'] === -1 && (int)$source['gender'] !== -1) {
            $fill['gender'] = (int)$source['gender'];
        }
        if ((int)$target['link_status'] !== 2 && (int)$source['link_status'] === 2) {
            $fill['linked_uid'] = (int)$source['linked_uid'];
            $fill['link_status'] = 2;
            $fill['link_confirmed_at'] = (int)$source['link_confirmed_at'];
        }
        // Die Alt-ID darf nicht verloren gehen: die Jobliste-Migration loest ueber sie auf.
        if (empty($target['legacy_id']) && !empty($source['legacy_id'])) {
            $fill['legacy_id'] = (int)$source['legacy_id'];
            $fill['legacy_source'] = (string)$source['legacy_source'];
        }
        if ($fill) {
            Relatives_Database::update_person($into, $fill);
        }

        $db->delete_query('relatives_persons', "id = {$from}", 1);

        foreach (array_keys($touched_subjects) as $person_id) {
            Relatives_Database::normalize_primary((int)$person_id, 0);
        }
        Relatives_Kinship::reset();

        return $report;
    }

    // ------------------------------------------------------------------
    // Konflikte (ACP)
    // ------------------------------------------------------------------

    public static function get_conflicts(): array
    {
        global $db;
        $rows = [];
        $query = $db->simple_select('relatives_relation_conflicts', '*', '', ['order_by' => 'id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Verworfene Aussage statt der behaltenen uebernehmen. */
    public static function accept_conflict(int $conflict_id): bool
    {
        global $db;
        $conflict = $db->fetch_array($db->simple_select('relatives_relation_conflicts', '*', "id = " . (int)$conflict_id, ['limit' => 1]));
        if (!$conflict) {
            return false;
        }
        $db->delete_query('relatives_relations', "id = " . (int)$conflict['kept_relation_id'], 1);
        Relatives_Database::add_relation([
            'person_id'                 => (int)$conflict['person_id'],
            'related_to_person_id'      => $conflict['related_to_person_id'],
            'related_to_family_id'      => $conflict['related_to_family_id'],
            'relationship_type_id'      => $conflict['relationship_type_id'],
            'relationship_custom_label' => $conflict['relationship_custom_label'],
            'is_secret'                 => (int)$conflict['is_secret'],
            'created_by'                => (int)$conflict['source_uid'],
        ]);
        $db->delete_query('relatives_relation_conflicts', "id = " . (int)$conflict_id, 1);
        Relatives_Kinship::recompute_for_person((int)$conflict['person_id']);
        return true;
    }

    public static function discard_conflict(int $conflict_id): bool
    {
        global $db;
        $db->delete_query('relatives_relation_conflicts', "id = " . (int)$conflict_id, 1);
        return true;
    }

    // ------------------------------------------------------------------
    // Geratene Zuordnungen der Alt-Migration zuruecknehmen
    // ------------------------------------------------------------------

    /**
     * Macht migrierte Verbindungen, die die Struktur-Ableitung damals nur aus
     * Eindeutigkeit geraten hat ("Christopher ist Sohn von Delia", weil in Jolinas Liste
     * nur Delia als Tante stand), wieder zur urspruenglichen Aussage ("Christopher ist
     * Cousin von Jolina"). Im gemeinsamen Geflecht gelten solche Vermutungen sonst fuer
     * alle Stammbaeume.
     *
     * Angefasst wird nur, was noch genau so aussieht wie damals geraten und von der Liste
     * stammt, die geraten hat -- spaeter von Hand geaenderte oder unabhaengig bestaetigte
     * Verbindungen bleiben. Geratene Aussagen, die bereits in der Konfliktliste liegen,
     * werden dort entfernt.
     *
     * @param bool $dry_run true = nur auflisten
     * @return array Liste von ['guess' => ..., 'edge' => Kante oder Konflikt, 'source' => 'relation'|'conflict', 'node' => int]
     */
    public static function revert_legacy_guesses(bool $dry_run = true, ?int $owner_uid = null): array
    {
        global $db;

        $types = Relatives_Kinship::type_map();
        $items = [];
        foreach (Relatives_StructureInference::replay_legacy_guesses() as $guess) {
            // Optional nur die Rateergebnisse aus der Liste eines bestimmten Charakters.
            if ($owner_uid !== null && $guess['owner_uid'] !== $owner_uid) {
                continue;
            }
            $node = self::find_account_person_id($guess['owner_uid']);

            $edge = null;
            foreach (Relatives_Database::get_relations_for_person($guess['person_id']) as $row) {
                $concept = !empty($row['relationship_type_id']) ? ($types[(int)$row['relationship_type_id']]['concept'] ?? null) : null;
                if ((int)$row['related_to_person_id'] === $guess['anchor_id'] && $concept === $guess['relation']
                    && (int)$row['created_by'] === $guess['owner_uid']) {
                    $edge = $row;
                    break;
                }
            }
            $source = 'relation';
            if ($edge === null && $db->table_exists('relatives_relation_conflicts')) {
                $row = $db->fetch_array($db->simple_select(
                    'relatives_relation_conflicts',
                    '*',
                    "person_id = " . (int)$guess['person_id'] . " AND related_to_person_id = " . (int)$guess['anchor_id']
                    . " AND source_uid = " . (int)$guess['owner_uid'],
                    ['limit' => 1]
                ));
                if ($row) {
                    $edge = $row;
                    $source = 'conflict';
                }
            }
            if ($edge === null) {
                continue; // schon korrigiert oder nie so uebernommen
            }

            $items[] = ['guess' => $guess, 'edge' => $edge, 'source' => $source, 'node' => $node];
            if ($dry_run) {
                continue;
            }

            $table = $source === 'conflict' ? 'relatives_relation_conflicts' : 'relatives_relations';
            $db->delete_query($table, "id = " . (int)$edge['id'], 1);

            // Urspruengliche Aussage relativ zum Charakter wiederherstellen.
            if ($node > 0 && $node !== $guess['person_id']) {
                $type = Relatives_RelationshipTypes::find_by_concept_and_gender($guess['legacy_concept'], $guess['legacy_gender']);
                if ($type !== null && self::find_existing_statement($guess['person_id'], $node, null, $guess['legacy_concept'], null) === null) {
                    Relatives_Database::add_relation([
                        'person_id'            => $guess['person_id'],
                        'related_to_person_id' => $node,
                        'relationship_type_id' => (int)$type['id'],
                        'created_by'           => $guess['owner_uid'],
                    ]);
                }
            }
            Relatives_Database::normalize_primary($guess['person_id'], 0);
        }

        if (!$dry_run && $items) {
            Relatives_Kinship::recompute_all();
        }
        return $items;
    }
}
