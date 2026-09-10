<?php
/**
 * relatives - TreeBuilder
 *
 * Baut aus relatives_persons (ausgehend von einem Wurzel-Account) einen Graphen und
 * berechnet daraus ein Generationen-Layout (x/y-Koordinaten pro Knoten). Bestaetigte
 * linked_uid-Kanten (link_status = 2) erlauben Traversal in fremde Baeume.
 *
 * Rueckgabeformat (JSON-tauglich):
 * [
 *   'nodes' => [ 'self:<uid>' => [...], 'person:<id>' => [...], ... ],
 *   'edges' => [ ['from' => 'self:<uid>', 'to' => 'person:<id>'], ... ],
 * ]
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_TreeBuilder
{
    /** Schutz gegen Endlosschleifen bei zirkulaeren Konto-Verknuepfungen. */
    private const MAX_DEPTH = 6;

    private array $nodes = [];
    private array $edges = [];
    private array $visited_uids = [];

    public static function build(int $root_uid): array
    {
        $builder = new self();
        $builder->traverse_uid($root_uid, 0, 0);
        $builder->assign_positions();
        return [
            'nodes' => array_values($builder->nodes),
            'edges' => $builder->edges,
        ];
    }

    private function traverse_uid(int $uid, int $generation, int $depth): void
    {
        global $db;

        if ($uid <= 0 || $depth > self::MAX_DEPTH || isset($this->visited_uids[$uid])) {
            return;
        }
        $this->visited_uids[$uid] = true;

        $self_key = 'self:' . $uid;
        if (!isset($this->nodes[$self_key])) {
            $user = $db->fetch_array($db->simple_select('users', 'uid, username', "uid = " . (int)$uid, ['limit' => 1]));
            $this->nodes[$self_key] = [
                'key'        => $self_key,
                'type'       => 'self',
                'uid'        => $uid,
                'username'   => $user ? (string)$user['username'] : '',
                'generation' => $generation,
            ];
        }

        $persons = Relatives_Database::get_persons_by_owner($uid);
        foreach ($persons as $person) {
            $person_id = (int)$person['id'];
            $person_key = 'person:' . $person_id;

            $offset = 0;
            if (!empty($person['relationship_type_id'])) {
                $type = Relatives_RelationshipTypes::get_by_id((int)$person['relationship_type_id']);
                if ($type !== null) {
                    $offset = (int)$type['generation_offset'];
                }
            }
            $person_generation = $generation + $offset;

            if (!isset($this->nodes[$person_key])) {
                $this->nodes[$person_key] = [
                    'key'                       => $person_key,
                    'type'                      => 'person',
                    'id'                        => $person_id,
                    'owner_uid'                 => $uid,
                    'name'                      => (string)$person['name'],
                    'gender'                    => (int)$person['gender'],
                    'relationship_type_id'      => $person['relationship_type_id'] !== null ? (int)$person['relationship_type_id'] : null,
                    'relationship_custom_label' => $person['relationship_custom_label'],
                    'age'                       => $person['age'] !== null ? (int)$person['age'] : null,
                    'origin'                    => $person['origin'],
                    'description'               => $person['description'],
                    'is_wanted'                 => (int)$person['is_wanted'],
                    'wanted_thread_link'        => $person['wanted_thread_link'],
                    'linked_uid'                => $person['linked_uid'] !== null ? (int)$person['linked_uid'] : null,
                    'link_status'               => (int)$person['link_status'],
                    'generation'                => $person_generation,
                ];
                $this->edges[] = ['from' => $self_key, 'to' => $person_key];
            }

            // Nur bestaetigte Verknuepfungen erlauben Weiter-Traversal in den fremden Baum.
            if ((int)$person['link_status'] === 2 && !empty($person['linked_uid'])) {
                $linked_uid = (int)$person['linked_uid'];
                if (!isset($this->visited_uids[$linked_uid])) {
                    $this->traverse_uid($linked_uid, $person_generation, $depth + 1);
                }
                // Kante immer setzen, auch wenn der Ziel-Account bereits ueber einen
                // anderen Pfad besucht wurde (sonst fehlt die Verbindung im Graph).
                $linked_self_key = 'self:' . $linked_uid;
                if (isset($this->nodes[$linked_self_key])) {
                    $this->edges[] = ['from' => $person_key, 'to' => $linked_self_key];
                }
            }
        }
    }

    /**
     * Vergibt x-Positionen pro Generation (gleichmaessig verteilt, sortiert nach Namen
     * innerhalb der Generation) -- reines Server-Layout, das Frontend-JS uebernimmt nur
     * noch Pan/Zoom/Ein-Ausklappen.
     */
    private function assign_positions(): void
    {
        $by_generation = [];
        foreach ($this->nodes as $key => $node) {
            $by_generation[$node['generation']][] = $key;
        }
        ksort($by_generation);

        foreach ($by_generation as $generation => $keys) {
            sort($keys); // stabile, deterministische Reihenfolge
            $count = count($keys);
            foreach ($keys as $index => $key) {
                $this->nodes[$key]['x'] = $index - (($count - 1) / 2);
                $this->nodes[$key]['y'] = $generation;
            }
        }
    }
}
