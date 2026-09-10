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
        $persons_by_id = [];
        foreach ($persons as $person) {
            $persons_by_id[(int)$person['id']] = $person;
        }

        $type_cache = [];
        $get_type = function (?int $type_id) use (&$type_cache): ?array {
            if (empty($type_id)) {
                return null;
            }
            if (!array_key_exists($type_id, $type_cache)) {
                $type_cache[$type_id] = Relatives_RelationshipTypes::get_by_id($type_id);
            }
            return $type_cache[$type_id];
        };

        // Generationen aufloesen: eine Person ohne related_to_person_id (oder deren
        // Bezugsperson nicht (mehr) existiert) haengt direkt an self, sonst an der
        // Generation der Bezugsperson. Iterativ (statt rekursiv), weil
        // get_persons_by_owner() alphabetisch sortiert -- die Bezugsperson kann in der
        // Liste erst SPAETER als die referenzierende Zeile kommen. Mit Sicherheitsnetz
        // gegen Zyklen/verwaiste Referenzen (faellt dann auf self-relativ zurueck).
        $person_generations = [];
        $unresolved = array_keys($persons_by_id);
        for ($pass = 0; $pass < count($unresolved) + 1 && !empty($unresolved); $pass++) {
            $still_unresolved = [];
            foreach ($unresolved as $person_id) {
                $related_id = !empty($persons_by_id[$person_id]['related_to_person_id'])
                    ? (int)$persons_by_id[$person_id]['related_to_person_id'] : null;
                $offset = 0;
                $type = $get_type(!empty($persons_by_id[$person_id]['relationship_type_id']) ? (int)$persons_by_id[$person_id]['relationship_type_id'] : null);
                if ($type !== null) {
                    $offset = (int)$type['generation_offset'];
                }

                if ($related_id === null || !isset($persons_by_id[$related_id])) {
                    $person_generations[$person_id] = $generation + $offset;
                } elseif (isset($person_generations[$related_id])) {
                    $person_generations[$person_id] = $person_generations[$related_id] + $offset;
                } else {
                    $still_unresolved[] = $person_id; // Bezugsperson noch nicht aufgeloest, naechster Durchlauf
                }
            }
            $unresolved = $still_unresolved;
        }
        foreach ($unresolved as $person_id) { // Zyklus-Sicherheitsnetz
            $offset = 0;
            $type = $get_type(!empty($persons_by_id[$person_id]['relationship_type_id']) ? (int)$persons_by_id[$person_id]['relationship_type_id'] : null);
            if ($type !== null) {
                $offset = (int)$type['generation_offset'];
            }
            $person_generations[$person_id] = $generation + $offset;
        }

        // Knoten anlegen (inkl. komponiertem Anzeige-Label aus deiner Sicht, siehe
        // Relatives_RelationshipTypes::compose_relationship()).
        foreach ($persons as $person) {
            $person_id = (int)$person['id'];
            $person_key = 'person:' . $person_id;
            $person_generation = $person_generations[$person_id];

            $related_id = !empty($person['related_to_person_id']) ? (int)$person['related_to_person_id'] : null;
            $own_type = $get_type(!empty($person['relationship_type_id']) ? (int)$person['relationship_type_id'] : null);

            $display_label = null;
            if ($related_id !== null && isset($persons_by_id[$related_id])) {
                $anchor_type = $get_type(!empty($persons_by_id[$related_id]['relationship_type_id']) ? (int)$persons_by_id[$related_id]['relationship_type_id'] : null);
                $composed = Relatives_RelationshipTypes::compose_relationship($anchor_type, $own_type, (int)$person['gender']);
                if ($composed !== null) {
                    $display_label = (string)$composed['label'];
                } else {
                    $raw_label = $own_type !== null ? (string)$own_type['label'] : (string)$person['relationship_custom_label'];
                    $display_label = $raw_label . ' von ' . (string)$persons_by_id[$related_id]['name'];
                }
            }

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
                    'display_relationship_label' => $display_label,
                    'age'                       => $person['age'] !== null ? (int)$person['age'] : null,
                    'origin'                    => $person['origin'],
                    'description'               => $person['description'],
                    'is_wanted'                 => (int)$person['is_wanted'],
                    'wanted_thread_link'        => $person['wanted_thread_link'],
                    'linked_uid'                => $person['linked_uid'] !== null ? (int)$person['linked_uid'] : null,
                    'link_status'               => (int)$person['link_status'],
                    'related_to_person_id'      => $related_id,
                    'generation'                => $person_generation,
                ];
            }
        }

        // Zweiter Durchlauf: Kanten setzen + in verknuepfte Fremd-Baeume weitertraversieren.
        foreach ($persons as $person) {
            $person_id = (int)$person['id'];
            $person_key = 'person:' . $person_id;

            // Verbindung "Geschwister der Mutter" etc.: die Kante kommt von der
            // angeklickten Person statt von self, wenn related_to_person_id auf eine
            // bekannte Person desselben Besitzers zeigt (sonst -- z.B. Ziel geloescht --
            // Fallback auf self als Quelle).
            $related_id = !empty($person['related_to_person_id']) ? (int)$person['related_to_person_id'] : null;
            $related_key = $related_id !== null ? 'person:' . $related_id : null;
            $edge_from = ($related_key !== null && isset($this->nodes[$related_key])) ? $related_key : $self_key;
            $this->edges[] = ['from' => $edge_from, 'to' => $person_key];

            // Nur bestaetigte Verknuepfungen erlauben Weiter-Traversal in den fremden Baum.
            if ((int)$person['link_status'] === 2 && !empty($person['linked_uid'])) {
                $linked_uid = (int)$person['linked_uid'];
                $person_generation = $person_generations[$person_id];
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
            $keys = $this->order_generation_keys($keys);
            $count = count($keys);
            foreach ($keys as $index => $key) {
                $this->nodes[$key]['x'] = $index - (($count - 1) / 2);
                $this->nodes[$key]['y'] = $generation;
            }
        }
    }

    /**
     * Ordnet die Knoten einer Generation so an, dass ueber "Verwandte/n von X
     * hinzufuegen" angelegte Personen direkt neben X einsortiert werden (statt rein
     * alphabetisch/nach id verstreut zu sein) -- macht z.B. aus "Mama, Onkel, Papa"
     * die zusammenhaengende Gruppe "Mama, Papa" (Mamas Mann direkt daneben), "Onkel"
     * bleibt separat. Rekursiv, damit auch Ketten (Verwandte einer Verwandten einer
     * Verwandten, alle in derselben Generation) zusammenbleiben. Faellt bei Zyklen/
     * verwaisten Referenzen sicher auf "hinten anhaengen" zurueck statt Knoten zu
     * verlieren.
     */
    private function order_generation_keys(array $keys): array
    {
        $key_set = array_flip($keys);
        $children_of = [];
        $anchor_keys = [];

        foreach ($keys as $key) {
            $node = $this->nodes[$key];
            $related_key = !empty($node['related_to_person_id']) ? 'person:' . $node['related_to_person_id'] : null;
            if ($related_key !== null && isset($key_set[$related_key])) {
                $children_of[$related_key][] = $key;
            } else {
                $anchor_keys[] = $key;
            }
        }
        sort($anchor_keys);
        foreach ($children_of as &$list) {
            sort($list);
        }
        unset($list);

        $ordered = [];
        $placed = [];
        $place = function (string $key) use (&$place, &$ordered, &$placed, &$children_of): void {
            if (isset($placed[$key])) {
                return;
            }
            $placed[$key] = true;
            $ordered[] = $key;
            foreach ($children_of[$key] ?? [] as $child) {
                $place($child);
            }
        };

        foreach ($anchor_keys as $anchor) {
            $place($anchor);
        }
        // Sicherheitsnetz: alles, was durch einen Zyklus oder eine verwaiste
        // related_to_person_id-Referenz noch nicht platziert wurde, hinten anhaengen.
        foreach ($keys as $key) {
            $place($key);
        }

        return $ordered;
    }
}
