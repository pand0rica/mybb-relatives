<?php
/**
 * relatives - TreeBuilder
 *
 * Zeigt den Ausschnitt des gemeinsamen Geflechts (v2.2) rund um einen Charakter und
 * berechnet daraus ein Generationen-Layout (x/y-Koordinaten pro Knoten). Wurzel ist der
 * Knoten des Charakters (Person mit bestaetigtem linked_uid, siehe Relatives_Graph);
 * von dort wird mit einem Schritt-Budget durchs Geflecht gegangen. Knoten am Rand, hinter
 * denen es weitergeht, tragen has_more und lassen sich per Klick aufklappen ($expand).
 *
 * Rueckgabeformat (JSON-tauglich):
 * [
 *   'nodes'    => [ 'self:<uid>' => [...], 'person:<id>' => [...], ... ],
 *   'edges'    => [ ['from' => 'self:<uid>', 'to' => 'person:<id>'], ... ],
 *   'partners' => [ ['a' => <key>, 'b' => <key>, 'style' => .., 'secret' => bool], ... ],
 *   'families' => [ ['id' => ..., 'parents' => [<key>, ...], 'children' => [<key>, ...],
 *                    'style' => 'solid'|'dashed'|'dotted'|'dashdot', 'secret' => bool,
 *                    'secret_children' => [<key>, ...] (geheime Abstammung je Kind; 'secret'
 *                    nur, wenn alle Kinder geheim sind),
 *                    'primary' => bool, 'group' => 'blood'|'adoptive'|...], ... ],
 *   'links'    => [ ['from' => <key>, 'to' => <key>, 'kind' => 'other'|'account',
 *                    'style' => .., 'secret' => bool], ... ],
 * ]
 *
 * Eine Person kann in MEHREREN Familien stehen (leibliche Eltern + Adoptiveltern):
 * nur die Familie mit 'primary' => true bestimmt die Position im Layout, die anderen
 * werden vom Frontend als zusaetzliche, andersartige Linie gezeichnet.
 *
 * 'edges' bildet weiterhin die reine Anlege-Struktur ab (wer wurde als Verwandte/r von
 * wem eingetragen) -- das Frontend nutzt sie zum Ein-/Ausklappen von Aesten. Fuer die
 * gezeichneten Linien ist dagegen 'families'/'partners'/'links' massgeblich: daraus
 * ergibt sich die genealogisch korrekte Darstellung (Partnerlinie zwischen den Kaesten,
 * ein gemeinsamer Abgang aus der Paarmitte, ein geteilter Geschwisterbalken).
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_TreeBuilder
{
    /** Zusaetzliche Schritte ab einem per Klick aufgeklappten Randknoten. */
    private const EXPAND_STEPS = 3;

    /** Hoechstzahl immer sichtbarer Blutsverwandter (Schutz vor riesigen Familienverbuenden). */
    private const CORE_LIMIT = 300;

    /** Hoechstzahl Knoten bei "Alle ausklappen". */
    private const ALL_LIMIT = 400;

    /**
     * Kosten je Kantenart. Partner*innen kosten mehr, damit angeheiratete Familien beim
     * Aufklappen frueh enden, statt den Baum ueber mehrere Familienverbuende wachsen zu
     * lassen. Die eigene Partner*in kostet nur einen Schritt.
     */
    private const STEP_COST = ['parent' => 1, 'child' => 1, 'sibling' => 1, 'partner' => 3, 'other' => 2, 'member' => 3];

    /** "Alle ausklappen" hat ALL_LIMIT erreicht -- nicht alles ist zu sehen. */
    private bool $truncated = false;

    private array $nodes = [];
    private array $edges = [];

    /** Rohe "Person ist <Konzept> von Anker"-Aussagen im Ausschnitt, Basis der Familienstruktur. */
    private array $relations = [];

    /** Referenzierte Familien-Kaesten: family_id => Zeile aus relatives_families. */
    private array $families = [];

    /** Klartext-Verbindungen je Knoten (Panel: "Bruder von Delia, Vater von Ethan"). */
    private array $node_relations = [];

    /** Zweitfamilien (Adoptiv-, Stief-, Pflegeeltern) -- positionieren nicht, ruecken aber ihre Eltern heran. */
    private array $secondary_families = [];

    /** Paare innerhalb einer Layout-Einheit (Partner*innen, gemeinsame Eltern): key => [key => true]. */
    private array $unit_pairs = [];

    /** Paare mit gemeinsamen Kindern (Eltern derselben Familie): key => [key => true]. */
    private array $coparent_pairs = [];

    /** Neutrale Platzhalter-Abgaenge auf ein Elternpaar (siehe Relatives_TreePlaceholders). */
    private array $placeholder_attachments = [];

    /** Lose verbundene Knoten, die fuers Layout in den Geschwisterblock ihres Ankers rutschen: Knoten => Anker. */
    private array $floating_anchor = [];

    /** Herkunfts-Etiketten eingeklappter Angeheirateter: 'origin:<family>:<person>' => Personen-Knoten. */
    private array $origin_tags = [];

    private string $root_key = '';
    private int $root_uid = 0;
    private int $viewer_uid = 0;

    /**
     * @param int   $root_uid   Charakter, dessen Baum gezeigt wird
     * @param int   $viewer_uid wer schaut (steuert can_edit)
     * @param array $expand     Personen-ids, deren Umgebung zusaetzlich aufgeklappt wird
     * @param bool  $expand_all alles Verbundene zeigen ("Alle ausklappen", bis ALL_LIMIT)
     */
    public static function build(int $root_uid, int $viewer_uid = 0, array $expand = [], bool $expand_all = false): array
    {
        // Immer mit frischem Geflecht: ein Baum wird oft direkt nach einem Speichern im
        // selben Request gebaut (Formular, Tests).
        Relatives_Kinship::reset();

        $builder = new self();
        $builder->root_uid = $root_uid;
        $builder->viewer_uid = $viewer_uid;
        $builder->collect($expand, $expand_all);
        $builder->place_family_nodes();
        [$structure, $partners] = $builder->layout();
        $builder->attach_node_relations($structure);

        return [
            'root'      => $builder->root_key,
            'nodes'     => array_values($builder->nodes),
            'edges'     => $builder->edges,
            'partners'  => $partners,
            'families'  => $structure['families'],
            'links'     => $structure['links'],
            'truncated' => $builder->truncated,
        ];
    }

    /**
     * Gesuchte Personen (is_wanted=1) im Stammbaum-Ausschnitt eines Charakters -- dieselben
     * Knoten wie in build(), aber ohne das teure Layout. Aus Sicht des Charakters
     * (computed_label, relationship_description aus seiner Liste), naechste Verwandte zuerst.
     *
     * @return array Liste von Knoten im Format von build()['nodes']
     */
    public static function wanted_nodes(int $root_uid): array
    {
        Relatives_Kinship::reset();

        $builder = new self();
        $builder->root_uid = $root_uid;
        $builder->viewer_uid = $root_uid;
        $builder->collect([]);

        $wanted = [];
        foreach ($builder->nodes as $node) {
            if (($node['type'] ?? '') === 'person' && !empty($node['is_wanted'])) {
                $wanted[] = $node;
            }
        }
        return $wanted;
    }

    /**
     * Struktur (inkl. Platzhalter) und Positionen. Ohne DB-Zugriff -- deshalb auch aus
     * tests/cli-tree-placeholders.php aufrufbar.
     *
     * @return array [structure, partners]
     */
    private function layout(): array
    {
        $structure = $this->structure_with_placeholders();

        // Nur die Primaerfamilie positioniert einen Knoten -- sonst zoege eine
        // Adoptivfamilie das Kind aus seiner leiblichen Geschwisterreihe heraus. Angehaengte
        // Halb-/Stiefgeschwister ohne eigene Familie positionieren ueber ihre Zweitfamilie.
        $layout = $structure;
        $layout['families'] = array_values(array_filter($structure['families'], static function ($family) {
            return !empty($family['primary']) || !empty($family['positions']);
        }));
        $this->secondary_families = array_values(array_filter($structure['families'], static function ($family) {
            return empty($family['primary']) && empty($family['positions']) && !empty($family['parents']);
        }));
        $layout['families'] = $this->with_attached_members($layout['families']);
        $layout['families'] = $this->with_floating_neighbours($layout['families'], $structure['links']);
        $this->assign_positions($layout);

        return [$structure, $this->with_coparent_partners($layout)];
    }

    /**
     * Erster Strukturdurchlauf, dann lose Aussagen (Tante, Cousin*e, Schwiegereltern ...)
     * ueber Platzhalter-Knoten aufloesen (Relatives_TreePlaceholders) und die Struktur mit
     * den zusaetzlichen Kanten neu bauen. Die Platzhalter werden nie gespeichert.
     */
    private function structure_with_placeholders(): array
    {
        $structure = $this->build_structure();
        $result = (new Relatives_TreePlaceholders($this->nodes, $this->relations, $structure))->resolve();
        if (empty($result['resolved'])) {
            return $structure;
        }

        foreach ($result['nodes'] as $key => $node) {
            $this->nodes[$key] = $node;
        }
        foreach (array_keys($result['resolved']) as $index) {
            $this->relations[$index]['resolved'] = true;
        }
        foreach ($result['relations'] as $relation) {
            $this->relations[] = $relation;
        }
        foreach ($result['edges'] as $edge) {
            $this->edges[] = $edge;
        }
        $this->placeholder_attachments = $result['attachments'];

        return $this->build_structure();
    }

    /**
     * Nur fuers Layout: wer auf der Paarlinie eines neutralen Platzhalter-Abgangs sitzt
     * ('either'), rueckt in dessen Familie, sofern er noch keine eigene hat. So steht
     * "Grosselternteil von Jolina" ueber Tante und Elternpaar statt irgendwo in der Zeile.
     */
    private function with_attached_members(array $families): array
    {
        // Angehaengte Halb-/Stiefgeschwister stehen im Geschwisterblock der Familie mit
        // denselben Eltern -- als eigene Familie kaemen sie ans Zeilenende und der
        // gemeinsame Balken liefe quer ueber den Baum.
        $primary_by_parents = [];
        foreach ($families as $index => $family) {
            if (!empty($family['primary']) && !empty($family['parents'])) {
                $parents = $family['parents'];
                sort($parents);
                $primary_by_parents[implode('|', $parents)] = $primary_by_parents[implode('|', $parents)] ?? $index;
            }
        }
        foreach ($families as $index => $family) {
            if (empty($family['positions'])) {
                continue;
            }
            $parents = $family['parents'];
            sort($parents);
            $target = $primary_by_parents[implode('|', $parents)] ?? null;
            if ($target !== null) {
                $families[$target]['children'] = array_merge($families[$target]['children'], $family['children']);
                unset($families[$index]);
            }
        }
        $families = array_values($families);

        $placed = [];
        foreach ($families as $family) {
            foreach ($family['children'] as $child) {
                $placed[$child] = true;
            }
        }
        foreach ($families as $index => $family) {
            foreach ($family['either'] ?? [] as $group) {
                foreach ($group as $member) {
                    if (!isset($placed[$member])) {
                        $families[$index]['children'][] = $member;
                        $placed[$member] = true;
                    }
                }
            }
        }
        return $families;
    }

    /**
     * Sammelt den Ausschnitt: welche Knoten sichtbar sind (visible_graph_keys(), plus
     * aufgeklappte Randknoten), kuerzeste Wege ab der Wurzel (Dijkstra mit STEP_COST),
     * Generation entlang dieser Wege, danach Knoten, Aussagen und Randmarkierungen.
     */
    private function collect(array $expand, bool $expand_all = false): void
    {
        $world = Relatives_Kinship::world();
        $persons = $world['persons'];
        $types = $world['types'];

        $user = get_user($this->root_uid);
        $username = $user ? (string)$user['username'] : '';
        $root_person = Relatives_Graph::find_account_person_id($this->root_uid);

        if ($root_person === 0 || !isset($persons[$root_person])) {
            // Charakter ohne eigenen Knoten (noch nichts eingetragen): nur er selbst.
            $this->root_key = 'self:' . $this->root_uid;
            $this->nodes[$this->root_key] = [
                'key'        => $this->root_key,
                'type'       => 'self',
                'uid'        => $this->root_uid,
                'username'   => $username,
                'id'         => 0,
                'person_id'  => 0,
                'can_edit'   => $this->root_uid === $this->viewer_uid || Relatives_Graph::is_staff(),
                'has_more'   => false,
                'generation' => 0,
            ];
            return;
        }
        $this->root_key = 'person:' . $root_person;

        // Nachbarschaft in beide Richtungen: [anderer Knoten, Kanten-Index, Art, delta]
        // mit Generation(anderer) = Generation(dieser) + delta.
        $adjacency = [];
        $keys_of_relation = [];
        foreach ($world['relations'] as $index => $relation) {
            $subject_id = (int)$relation['person_id'];
            if (!isset($persons[$subject_id])) {
                continue;
            }
            $subject = 'person:' . $subject_id;
            if (!empty($relation['related_to_family_id'])) {
                $anchor = 'family:' . (int)$relation['related_to_family_id'];
            } elseif (!empty($relation['related_to_person_id']) && isset($persons[(int)$relation['related_to_person_id']])) {
                $anchor = 'person:' . (int)$relation['related_to_person_id'];
            } else {
                continue;
            }
            if ($anchor === $subject) {
                continue;
            }
            $type = $types[(int)($relation['relationship_type_id'] ?? 0)] ?? null;
            // Freund*innen, Clique & Co. (freie Bezeichnung) gehoeren nicht in den
            // Stammbaum -- sie stehen nur in der Liste.
            if (self::is_non_kin($relation, $type)) {
                continue;
            }
            $concept = $type !== null ? $type['concept'] : null;
            $offset = $type !== null ? $type['offset'] : 0;
            $kind = self::classify_relation($concept, $offset);
            // Reine Familien-Mitgliedschaft (ohne Grad): fuehrt wie eine echte Verbindung
            // durch die Familie, damit Kasten und Mitglieder im Baum erscheinen. Fuer die
            // Generation zaehlt sie wie ein Kind des Kastens; ein kuerzerer echter Weg
            // (z. B. ueber den eigenen Vater) gewinnt wegen der hoeheren Kosten.
            if (self::is_family_membership($relation, $type)) {
                $kind = 'member';
                $offset = 1;
            }

            // "Subjekt ist <Konzept> von Anker": Generation(Subjekt) = Generation(Anker) + offset.
            $adjacency[$subject][] = [$anchor, $index, $kind, -$offset];
            $adjacency[$anchor][] = [$subject, $index, $kind, $offset];
            $keys_of_relation[$index] = [$subject, $anchor, $type];
        }

        $expand_keys = [];
        foreach ($expand as $expand_id) {
            $expand_keys['person:' . (int)$expand_id] = true;
        }

        // Was gezeigt wird: alle Blutsverwandten (+ die eigene Adoptiv-/Stieflinie), dazu
        // ihre Partner*innen, Co-Eltern und Familien-Kaesten. Dahinter endet der Baum am
        // Rand ("..."); aufgeklappte Randknoten bringen ihre Umgebung mit.
        $allowed = null;
        // Kern = Blutsverwandte (+ eigene Adoptivlinie); steuert auch Etiketten und Generationen.
        $core = [];
        $visible = self::visible_graph_keys($world['graph'], 'p:' . $root_person);
        foreach ($visible as $graph_key => $role) {
            if ($role === 'core') {
                $core[self::tree_key($graph_key)] = true;
            }
        }
        if (!$expand_all) {
            $allowed = [];
            foreach (array_keys($visible) as $graph_key) {
                $allowed[self::tree_key($graph_key)] = true;
            }
            foreach (array_keys($expand_keys) as $expand_key) {
                if (isset($allowed[$expand_key])) {
                    $allowed += self::neighbourhood($adjacency, $expand_key, self::EXPAND_STEPS);
                }
            }
        }

        $frontier = [$this->root_key => 0];
        $reached_via = [];
        $settled = [];
        while ($frontier) {
            asort($frontier);
            $key = (string)array_key_first($frontier);
            $distance = $frontier[$key];
            unset($frontier[$key]);
            if (isset($settled[$key])) {
                continue;
            }
            if ($allowed === null && count($settled) >= self::ALL_LIMIT) {
                $this->truncated = true;
                break;
            }
            $settled[$key] = $distance;

            foreach ($adjacency[$key] ?? [] as [$other, $index, $kind, $delta]) {
                if (isset($settled[$other]) || ($allowed !== null && !isset($allowed[$other]))) {
                    continue;
                }
                // Lose Angaben ("Ravena ist Tante von Kjartan") beschreiben keinen Weg
                // durch die Familie. Ueber sie wuerde sonst jede fremde Familienliste in den
                // Baum gezogen -- sie zaehlen nur direkt an der Wurzel oder an einem
                // ausdruecklich aufgeklappten Knoten.
                if ($kind === 'other' && $key !== $this->root_key && !isset($expand_keys[$key])) {
                    continue;
                }
                $cost = ($kind === 'partner' && $key === $this->root_key) ? 1 : (self::STEP_COST[$kind] ?? 2);
                $next = $distance + $cost;
                if (!isset($frontier[$other]) || $frontier[$other] > $next) {
                    $frontier[$other] = $next;
                    $reached_via[$other] = [$key, $delta];
                }
            }
        }
        $generation = self::partners_in_one_row(self::settle_generations($settled, $reached_via, $adjacency), $settled, $adjacency, $core);

        $person_ids = [];
        $family_ids = [];
        foreach (array_keys($settled) as $key) {
            if (strpos($key, 'family:') === 0) {
                $family_ids[] = (int)substr($key, 7);
            } else {
                $person_ids[] = (int)substr($key, 7);
            }
        }

        // Herkunft Angeheirateter, deren Eltern eingeklappt bleiben: der Kasten ihrer
        // Vorfahren steht als Etikett ueber ihnen ("Travers" ueber Rebecca). Jede Person
        // bekommt ihr eigenes Etikett -- Rebecca und Beatrix stammen beide von den Travers.
        $origin_tags = [];
        foreach (array_keys($settled) as $key) {
            if (strpos($key, 'person:') !== 0) {
                continue;
            }
            $has_box = false;
            $has_hidden_parent = false;
            foreach ($adjacency[$key] ?? [] as [$other, , $kind, $delta]) {
                if (strpos($other, 'family:') === 0 && isset($settled[$other])) {
                    $has_box = true;
                }
                if ($delta < 0 && $kind !== 'other' && !isset($settled[$other])) {
                    $has_hidden_parent = true;
                }
            }
            if ($has_box || !$has_hidden_parent) {
                continue;
            }
            foreach (Relatives_Graph::origin_families_from($world['graph'], 'p:' . substr($key, 7)) as $family_id) {
                if (!isset($settled['family:' . $family_id])) {
                    $origin_tags['origin:' . $family_id . ':' . substr($key, 7)] = $key;
                    $family_ids[] = $family_id;
                }
            }
        }
        $family_ids = array_values(array_unique($family_ids));
        $this->families = Relatives_Families::get_bulk($family_ids);

        // Verwandtschaft IMMER aus Sicht des gezeigten Charakters, nicht der schauenden Person.
        $described = [];
        foreach ($person_ids as $person_id) {
            $described[$person_id] = $persons[$person_id];
        }
        $kinship = Relatives_Kinship::compute_from($described, $world['graph'], 'p:' . $root_person);

        $owner_counts = Relatives_Database::count_owners_bulk($person_ids);
        $details = Relatives_Database::person_details($person_ids);
        $attribute_ids = Relatives_Attributes::get_option_ids_bulk($person_ids);
        $viewer_list = [];
        foreach (Relatives_Database::get_persons_by_owner($this->viewer_uid) as $row) {
            $viewer_list[(int)$row['id']] = $row;
        }
        $is_staff = Relatives_Graph::is_staff();
        // Gleiche Familie wie der eigene Charakter: dessen Mitglieder sind mitbearbeitbar
        // (Relatives_Graph::can_edit_person()).
        $viewer_person = Relatives_Graph::find_account_person_id($this->viewer_uid);

        $primary = [];
        $relation_count = [];
        foreach ($world['relations'] as $relation) {
            $subject_id = (int)$relation['person_id'];
            $relation_count[$subject_id] = ($relation_count[$subject_id] ?? 0) + 1;
            if (!empty($relation['is_primary'])) {
                $primary[$subject_id] = $relation;
            }
        }

        foreach ($person_ids as $person_id) {
            $key = 'person:' . $person_id;
            $person = $persons[$person_id];
            $is_root = $key === $this->root_key;
            $is_account = (int)$person['link_status'] === 2 && !empty($person['linked_uid']);

            if ($is_staff) {
                $can_edit = true;
            } elseif ($is_account) {
                $can_edit = (int)$person['linked_uid'] === $this->viewer_uid;
            } else {
                $can_edit = isset($viewer_list[$person_id])
                    || Relatives_Graph::shares_family($person_id, $viewer_person);
            }

            $has_more = false;
            foreach ($adjacency[$key] ?? [] as [$other]) {
                if (!isset($settled[$other])) {
                    $has_more = true;
                    break;
                }
            }

            $primary_relation = $primary[$person_id] ?? null;
            $this->nodes[$key] = [
                'key'                       => $key,
                'type'                      => $is_root ? 'self' : 'person',
                'id'                        => $person_id,
                'person_id'                 => $person_id,
                'uid'                       => $is_root ? $this->root_uid : null,
                'username'                  => $is_root ? $username : null,
                'owner_count'               => $owner_counts[$person_id] ?? 0,
                'name'                      => (string)$person['name'],
                'gender'                    => (int)$person['gender'],
                'relationship_type_id'      => $primary_relation !== null && $primary_relation['relationship_type_id'] !== null ? (int)$primary_relation['relationship_type_id'] : null,
                'relationship_custom_label' => $primary_relation['relationship_custom_label'] ?? null,
                'related_to_person_id'      => $primary_relation !== null && $primary_relation['related_to_person_id'] !== null ? (int)$primary_relation['related_to_person_id'] : null,
                'computed_label'            => $is_root ? null : Relatives_Kinship::display_label(
                    $kinship[$person_id]['label'] ?? null,
                    $kinship[$person_id]['via_person_id'] ?? null
                ),
                'computed_category'         => $kinship[$person_id]['category'] ?? null,
                'age'                       => Relatives_Database::computed_age($person),
                'birth_year'                => isset($person['birth_year']) && $person['birth_year'] !== null ? (int)$person['birth_year'] : null,
                'age_label'                 => Relatives_Database::age_label($person),
                'origin'                    => $person['origin'],
                'job'                       => $details[$person_id]['job'] ?? '',
                'description'               => $person['description'],
                'relationship_description'  => isset($viewer_list[$person_id]) ? $viewer_list[$person_id]['relationship_description'] : null,
                'attribute_option_ids'      => $attribute_ids[$person_id] ?? [],
                'is_wanted'                 => (int)$person['is_wanted'],
                'wanted_thread_link'        => $person['wanted_thread_link'],
                'is_deceased'               => (int)($person['is_deceased'] ?? 0),
                'death_note'                => $person['death_note'] ?? null,
                // Das Baum-Panel bearbeitet nur eine Verbindung; bei mehreren
                // verweist es aufs volle Formular (sonst gingen die anderen verloren).
                'relation_count'            => $relation_count[$person_id] ?? 0,
                'linked_uid'                => $person['linked_uid'] !== null ? (int)$person['linked_uid'] : null,
                'link_status'               => (int)$person['link_status'],
                'in_viewer_list'            => isset($viewer_list[$person_id]),
                'can_edit'                  => $can_edit,
                'has_more'                  => $has_more,
                'generation'                => $generation[$key] ?? 0,
                'core'                      => $is_root || isset($core[$key]),
            ];
        }

        // Anlege-Struktur fuers Ein-/Ausklappen: der Weg, ueber den ein Knoten erreicht wurde.
        foreach (array_keys($settled) as $key) {
            if (isset($reached_via[$key]) && $key !== $this->root_key) {
                $this->edges[] = ['from' => $reached_via[$key][0], 'to' => $key];
            }
        }

        // Aussagen, deren beide Enden im Ausschnitt liegen.
        foreach ($keys_of_relation as $index => [$subject, $anchor, $type]) {
            if (!isset($settled[$subject], $settled[$anchor])) {
                continue;
            }
            $relation = $world['relations'][$index];
            $this->relations[] = [
                'id'      => (int)($relation['id'] ?? 0),
                'node'    => $subject,
                'anchor'  => $anchor,
                'concept' => $type !== null ? $type['concept'] : null,
                'offset'  => $type !== null ? $type['offset'] : 0,
                'label'   => $type !== null ? $type['label'] : (self::is_family_membership($relation, $type)
                    ? self::family_member_label()
                    : (string)($relation['relationship_custom_label'] ?? '')),
                'style'   => $type !== null ? Relatives_RelationshipTypes::sanitize_line_style((string)$type['line_style']) : 'solid',
                'secret'  => !empty($relation['is_secret']),
                'primary' => !empty($relation['is_primary']),
                'membership' => self::is_family_membership($relation, $type),
            ];
        }

        // Herkunfts-Etiketten haengen beim Einklappen an ihrer Person (place_family_nodes()
        // legt die Knoten an).
        foreach ($origin_tags as $tag_key => $key) {
            if (isset($this->families[(int)explode(':', $tag_key)[1]])) {
                $this->origin_tags[$tag_key] = $key;
                $this->edges[] = ['from' => $key, 'to' => $tag_key];
            }
        }
    }

    /**
     * Was der Baum ohne Aufklappen zeigt, als Schluessel des Verwandtschaftsgraphen
     * ('p:<id>', 'f:<id>'):
     * - Kern: alle Blutsverwandten der Wurzel (Relatives_Kinship::blood_degrees()) und ihre
     *   eigene Adoptiv-, Stief- und Pflegelinie (diese Eltern samt deren Blutsverwandten,
     *   nicht-leibliche Geschwister). Hoechstens CORE_LIMIT, die naechsten zuerst.
     * - Anhang: Partner*innen und Co-Eltern der Kernpersonen sowie die Familien-Kaesten,
     *   an denen Kern- oder Anhangpersonen direkt haengen. Die Herkunftsfamilien
     *   Angeheirateter und Adoptivfamilien entfernter Verwandter bleiben eingeklappt.
     * Reine Funktion ohne DB-Zugriff (tests/cli-tree-layout.php).
     *
     * @return array graph key => 'core'|'attached'
     */
    public static function visible_graph_keys(array $graph, string $root): array
    {
        $closeness = self::blood_keys($graph, $root);
        $add = static function (string $key, int $value) use (&$closeness): void {
            if (!isset($closeness[$key]) || $closeness[$key] > $value) {
                $closeness[$key] = $value;
            }
        };
        foreach ($graph['parents'][$root] ?? [] as $parent => $edge) {
            if (!empty($edge['blood'])) {
                continue;
            }
            foreach (self::blood_keys($graph, (string)$parent) as $key => $value) {
                $add((string)$key, 1 + $value);
            }
        }
        foreach ($graph['siblings'][$root] ?? [] as $sibling => $concept) {
            if (!Relatives_Kinship::is_blood_concept($concept)) {
                $add((string)$sibling, 1);
            }
        }
        if (count($closeness) > self::CORE_LIMIT) {
            asort($closeness);
            $closeness = array_slice($closeness, 0, self::CORE_LIMIT, true);
        }

        $visible = [];
        foreach (array_keys($closeness) as $key) {
            $visible[(string)$key] = 'core';
        }
        foreach (array_keys($closeness) as $key) {
            if (strpos((string)$key, 'p:') !== 0) {
                continue;
            }
            foreach (array_keys($graph['partners'][$key] ?? []) as $partner) {
                $visible[(string)$partner] = $visible[(string)$partner] ?? 'attached';
            }
            foreach ($graph['parents'][$key] ?? [] as $parent => $edge) {
                if (!empty($edge['blood'])) {
                    $visible[(string)$parent] = $visible[(string)$parent] ?? 'attached';
                }
            }
        }
        foreach ($graph['edges'] as [$subject, $anchor]) {
            if (strpos((string)$anchor, 'f:') === 0 && isset($visible[(string)$subject])) {
                $visible[(string)$anchor] = $visible[(string)$anchor] ?? 'attached';
            }
        }
        return $visible;
    }

    /** Direkt an der Wurzel angegebene Vorfahr*innen ohne Zwischenperson. */
    private const LINEAL_UP_CONCEPTS = ['grandparent', 'great_grandparent', 'distant_ancestor'];

    /** Angeheiratete Grade -- keine Blutsverwandtschaft, auch wenn sie direkt an der Wurzel stehen. */
    private const IN_LAW_CONCEPTS = ['sibling_in_law', 'parent_in_law', 'child_in_law'];

    /**
     * Blutsverwandte im engen Sinn: Vorfahr*innen der Wurzel (ueber leibliche Eltern, auch
     * die bei vollen Geschwistern eingetragenen) und alle ihre Nachkommen samt deren
     * Geschwistern. Anders als Relatives_Kinship::blood_degrees() fuehrt kein Weg von
     * Nachkommen wieder nach oben -- Rebecca ist Mutter von Megans Cousin Jaron, aber
     * nicht mit Megan verwandt. Familien-Kaesten als Elternteil zaehlen wie Vorfahr*innen.
     *
     * @return array graph key => Naehe (Anzahl Schritte, fuer CORE_LIMIT)
     */
    private static function blood_keys(array $graph, string $root): array
    {
        $is_full_sibling = static function ($concept): bool {
            return $concept === null || $concept === '' || $concept === 'sibling';
        };

        // Nach oben: Vorfahr*innen; volle Geschwister teilen ihre Eltern.
        $ancestors = [$root => 0];
        $collateral = [];
        foreach (Relatives_Kinship::direct_statements($graph, $root)['direct'] as $key => $concept) {
            if (in_array($concept, self::LINEAL_UP_CONCEPTS, true)) {
                $ancestors[(string)$key] = 2;
            } elseif (Relatives_Kinship::is_blood_concept($concept) && !in_array($concept, self::IN_LAW_CONCEPTS, true)
                && in_array($concept, Relatives_Kinship::CONCEPTS_LOOSE, true)) {
                $collateral[(string)$key] = 2;
            }
        }
        $queue = array_keys($ancestors);
        while ($queue) {
            $key = (string)array_shift($queue);
            $level = $ancestors[$key];
            $sources = [$key];
            foreach ($graph['siblings'][$key] ?? [] as $sibling => $concept) {
                if ($is_full_sibling($concept)) {
                    $sources[] = (string)$sibling;
                }
            }
            foreach ($sources as $source) {
                foreach ($graph['parents'][$source] ?? [] as $parent => $edge) {
                    if (empty($edge['blood']) || !empty($edge['inferred']) || isset($ancestors[(string)$parent])) {
                        continue;
                    }
                    $ancestors[(string)$parent] = $level + 1;
                    $queue[] = (string)$parent;
                }
            }
        }

        // Nach unten: Nachkommen aller Vorfahr*innen, Geschwister unterwegs eingeschlossen.
        $blood = $ancestors;
        $queue = array_keys($ancestors);
        foreach ($collateral as $key => $level) {
            if (!isset($blood[$key])) {
                $blood[$key] = $level;
                $queue[] = $key;
            }
        }
        while ($queue) {
            $key = (string)array_shift($queue);
            $level = $blood[$key];
            $next = [];
            foreach ($graph['children'][$key] ?? [] as $child => $edge) {
                if (!empty($edge['blood'])) {
                    $next[] = (string)$child;
                }
            }
            foreach ($graph['siblings'][$key] ?? [] as $sibling => $concept) {
                if (Relatives_Kinship::is_blood_concept($concept)) {
                    $next[] = (string)$sibling;
                }
            }
            foreach ($next as $other) {
                if (!isset($blood[$other])) {
                    $blood[$other] = $level + 1;
                    $queue[] = $other;
                }
            }
        }
        return $blood;
    }

    /** 'p:12' -> 'person:12', 'f:3' -> 'family:3' */
    private static function tree_key(string $graph_key): string
    {
        return (strpos($graph_key, 'f:') === 0 ? 'family:' : 'person:') . substr($graph_key, 2);
    }

    /**
     * Knoten im Umkreis eines aufgeklappten Randknotens (Dijkstra mit STEP_COST bis
     * $budget; lose Angaben nur direkt an ihm).
     *
     * @return array key => true
     */
    private static function neighbourhood(array $adjacency, string $start, int $budget): array
    {
        $frontier = [$start => 0];
        $settled = [];
        while ($frontier) {
            asort($frontier);
            $key = (string)array_key_first($frontier);
            $distance = $frontier[$key];
            unset($frontier[$key]);
            if (isset($settled[$key])) {
                continue;
            }
            $settled[$key] = true;
            foreach ($adjacency[$key] ?? [] as [$other, , $kind]) {
                if (isset($settled[$other]) || ($kind === 'other' && $key !== $start)) {
                    continue;
                }
                $next = $distance + (self::STEP_COST[$kind] ?? 2);
                if ($next <= $budget && (!isset($frontier[$other]) || $frontier[$other] > $next)) {
                    $frontier[$other] = $next;
                }
            }
        }
        // Keine halben Paare: Partner*innen der aufgeklappten Personen kommen mit.
        foreach (array_keys($settled) as $key) {
            foreach ($adjacency[$key] ?? [] as [$other, , $kind]) {
                if ($kind === 'partner') {
                    $settled[$other] = true;
                }
            }
        }
        return $settled;
    }

    /**
     * Generation along the shortest paths, with one correction: a family box only says
     * "belongs to this family", not where in the line. A person reached through a box who
     * also has a direct statement to someone already in the tree ("entfernter Vorfahr von
     * Tadgh") takes the generation from that statement -- otherwise Aidyn would stand
     * beside every other member of the box. The box itself is placed above its highest
     * member later (place_family_nodes()), so it follows.
     *
     * @param array $settled     key => distance, in settle order
     * @param array $reached_via key => [previous key, delta]
     * @param array $adjacency   key => [[other, relation index, kind, delta], ...]
     * @return array key => generation
     */
    private static function settle_generations(array $settled, array $reached_via, array $adjacency): array
    {
        $order = array_keys($settled);

        // Generation relative to the path start, used to measure positions inside a branch.
        $base = [];
        foreach ($order as $key) {
            $base[$key] = isset($reached_via[$key]) ? $base[$reached_via[$key][0]] + $reached_via[$key][1] : 0;
        }

        // Every person reached directly through a box starts a branch (the person and all
        // below them). A statement from anywhere in that branch to a person outside of it
        // anchors the whole branch -- "Martha ist entfernte Vorfahrin von Caiomhe" places
        // William two levels above Martha, too. The anchor closest to the root wins.
        $anchor_of = [];
        foreach ($order as $start) {
            $via = $reached_via[$start] ?? null;
            if ($via === null || strpos($via[0], 'family:') !== 0 || strpos($start, 'person:') !== 0) {
                continue;
            }
            foreach ($order as $member) {
                if ($member !== $start && !self::reached_through($member, $start, $reached_via)) {
                    continue;
                }
                foreach ($adjacency[$member] ?? [] as [$other, , $kind, $delta]) {
                    if ($kind === 'member' || strpos($other, 'person:') !== 0 || !isset($settled[$other])
                        || $other === $start || self::reached_through($other, $start, $reached_via)) {
                        continue;
                    }
                    if (!isset($anchor_of[$start]) || $settled[$other] < $settled[$anchor_of[$start][0]]) {
                        // Generation(other) = Generation(member) + delta; the start sits
                        // ($base[member] - $base[start]) above the member.
                        $anchor_of[$start] = [$other, $delta + $base[$member] - $base[$start]];
                    }
                }
            }
        }

        // An anchor may be settled after the branch it anchors, so repeat until stable.
        $generation = [];
        for ($round = 0; $round < 8; $round++) {
            $previous = $generation;
            $generation = [];
            foreach ($order as $key) {
                $anchor = $anchor_of[$key] ?? null;
                $anchor_generation = $anchor !== null ? ($generation[$anchor[0]] ?? $previous[$anchor[0]] ?? null) : null;
                if ($anchor_generation !== null) {
                    $generation[$key] = $anchor_generation - $anchor[1];
                } elseif (isset($reached_via[$key])) {
                    $generation[$key] = $generation[$reached_via[$key][0]] + $reached_via[$key][1];
                } else {
                    $generation[$key] = 0;
                }
            }
            if ($generation === $previous) {
                break;
            }
        }
        return $generation;
    }

    /**
     * Partner*innen stehen in einer Reihe. Widersprechen sich die Wege (Aurora ist ueber
     * ihren Bruder Orion eine Generation hoeher eingeordnet als ihr Mann Lucius), uebernimmt
     * die angeheiratete Person die Reihe der blutsverwandten -- bei zwei Gleichrangigen die
     * weiter von der Wurzel entfernte die der naeheren.
     *
     * @param array $generation key => generation
     * @param array $settled    key => distance, in settle order
     * @param array $core       key => true (Blutsverwandte)
     */
    private static function partners_in_one_row(array $generation, array $settled, array $adjacency, array $core): array
    {
        foreach (array_keys($settled) as $key) {
            foreach ($adjacency[$key] ?? [] as [$other, , $kind]) {
                if ($kind !== 'partner' || !isset($settled[$other], $generation[$other], $generation[$key])
                    || $generation[$other] === $generation[$key]) {
                    continue;
                }
                $key_rank = [isset($core[$key]) ? 0 : 1, $settled[$key]];
                $other_rank = [isset($core[$other]) ? 0 : 1, $settled[$other]];
                if ($key_rank <= $other_rank) {
                    $generation[$other] = $generation[$key];
                } else {
                    $generation[$key] = $generation[$other];
                }
            }
        }
        return $generation;
    }

    /** Liegt $ancestor auf dem Weg, ueber den $key erreicht wurde? */
    private static function reached_through(string $key, string $ancestor, array $reached_via): bool
    {
        for ($steps = 0; isset($reached_via[$key]) && $steps < 100; $steps++) {
            $key = $reached_via[$key][0];
            if ($key === $ancestor) {
                return true;
            }
        }
        return false;
    }

    /**
     * Legt fuer jeden referenzierten Familien-Kasten einen Knoten an. Er steht genau
     * eine Generation ueber seinem hoechsten Kind und wirkt im Layout wie ein
     * Elternteil ohne Namen -- deshalb muessen die Personen-Generationen bereits
     * feststehen, wenn das hier laeuft.
     */
    private function place_family_nodes(): void
    {
        $children_generations = [];
        foreach ($this->relations as $relation) {
            if (strpos($relation['anchor'], 'family:') !== 0 || !isset($this->nodes[$relation['node']])) {
                continue;
            }
            $generation = (int)$this->nodes[$relation['node']]['generation'];
            $anchor = $relation['anchor'];
            if (!isset($children_generations[$anchor]) || $generation < $children_generations[$anchor]) {
                $children_generations[$anchor] = $generation;
            }
        }

        $member_lists = ($children_generations || $this->origin_tags) ? Relatives_Graph::family_member_lists() : [];
        $persons = Relatives_Kinship::world()['persons'];

        foreach ($this->origin_tags as $tag_key => $person_key) {
            $family_id = (int)explode(':', $tag_key)[1];
            $family = $this->families[$family_id];
            $this->nodes[$tag_key] = [
                'key'         => $tag_key,
                'type'        => 'family',
                'id'          => $family_id,
                'name'        => (string)$family['name'],
                'color'       => Relatives_Families::sanitize_color((string)$family['color']),
                'description' => $family['description'],
                'generation'  => (int)$this->nodes[$person_key]['generation'],
                'members'     => $this->family_member_entries($member_lists[$family_id] ?? [], $persons),
                'tag'         => true,
            ];
        }

        foreach ($children_generations as $key => $generation) {
            $family_id = (int)substr($key, strlen('family:'));
            if (!isset($this->families[$family_id]) || isset($this->nodes[$key])) {
                continue;
            }
            $family = $this->families[$family_id];
            $this->nodes[$key] = [
                'key'         => $key,
                'type'        => 'family',
                'id'          => $family_id,
                'name'        => (string)$family['name'],
                'color'       => Relatives_Families::sanitize_color((string)$family['color']),
                'description' => $family['description'],
                'generation'  => $generation - 1,
                // Alle Mitglieder, nicht nur die direkt am Kasten haengenden -- das Panel
                // listet sie; wer nicht im Ausschnitt steht, erscheint ohne Sprunglink.
                'members'     => $this->family_member_entries($member_lists[$family_id] ?? [], $persons),
            ];
        }
    }

    /**
     * Haengt an jeden Knoten die Klartextliste seiner Verbindungen ("Bruder von Delia,
     * Jonathan, Ravena · Ehemann von Ava · Vater von Ethan").
     *
     * Eingetragen wird immer nur eine Richtung, deshalb entsteht zu jeder Aussage auch
     * die Gegenrichtung. Zusaetzlich werden Verbindungen ABGELEITET, die niemand
     * ausdruecklich eingetragen hat: wer dieselben Eltern (oder denselben
     * Familien-Kasten, dieselbe Geschwistergruppe) hat, ist Geschwisterteil -- so ist ein
     * neu angelegter "Bruder von Oliver" sofort auch als Bruder von Delia und Matthew
     * erkennbar. Abgeleitete Eintraege tragen 'derived' => true.
     */
    private function attach_node_relations(array $structure): void
    {
        $explicit = [];
        foreach ($this->relations as $relation) {
            $node = $relation['node'];
            $anchor = $relation['anchor'];
            // Synthetische Platzhalter-Kanten sind keine Aussagen -- im Panel steht die
            // urspruengliche ("Tante von Jolina").
            if (!isset($this->nodes[$node], $this->nodes[$anchor]) || $node === $anchor || !empty($relation['synthetic'])) {
                continue;
            }
            $explicit[self::pair_key($node, $anchor)] = true;

            $concept = $relation['concept'];
            $kind = self::classify_relation($concept, (int)$relation['offset']);
            $this->add_node_relation($node, $anchor, $kind, $relation, $relation['label']);

            $inverse = Relatives_Kinship::inverse_concept($concept);
            if ($inverse === null) {
                continue;
            }
            $inverse_label = $this->concept_label($inverse, $anchor);
            if ($inverse_label !== null) {
                $this->add_node_relation($anchor, $node, self::inverse_kind($kind), $relation, $inverse_label);
            }
        }

        $derived_meta = ['style' => 'solid', 'secret' => false];
        foreach ($structure['families'] as $family) {
            $children = array_values(array_filter($family['children'], function ($key) {
                return isset($this->nodes[$key]) && !in_array($this->nodes[$key]['type'] ?? '', ['family', 'placeholder'], true);
            }));

            // Ueber einen Platzhalter verbundene Personen sind nicht sicher Geschwister: drei
            // Tanten unter "Grosselternteil von Jolina" koennen von beiden Seiten stammen.
            $via_placeholder = false;
            foreach (empty($family['parents']) ? $family['children'] : $family['parents'] as $member) {
                if (($this->nodes[$member]['type'] ?? '') === 'placeholder') {
                    $via_placeholder = true;
                    break;
                }
            }

            // Geschwister ueber gemeinsame Eltern / Kasten / Geschwistergruppe.
            if (($family['group'] ?? 'blood') === 'blood' && !$via_placeholder) {
                for ($i = 0, $count = count($children); $i < $count; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $a = $children[$i];
                        $b = $children[$j];
                        if (isset($explicit[self::pair_key($a, $b)])) {
                            continue;
                        }
                        $explicit[self::pair_key($a, $b)] = true;
                        $label_a = $this->concept_label('sibling', $a);
                        $label_b = $this->concept_label('sibling', $b);
                        if ($label_a !== null) {
                            $this->add_node_relation($a, $b, 'sibling', $derived_meta, $label_a, true);
                        }
                        if ($label_b !== null) {
                            $this->add_node_relation($b, $a, 'sibling', $derived_meta, $label_b, true);
                        }
                    }
                }
            }

            // Elternteil ueber die Partner*in eines eingetragenen Elternteils. In einer
            // Adoptiv-/Stief-/Pflegefamilie heisst die abgeleitete Verbindung entsprechend.
            // Angehaengte Halb-/Stiefgeschwister haengen am ganzen Elternpaar ("eine*r von
            // beiden") -- daraus folgt keine Elternschaft zu jedem Einzelnen.
            if (!empty($family['attach'])) {
                continue;
            }
            $group_prefix = ($family['group'] ?? 'blood') === 'blood' ? '' : $family['group'] . '_';
            foreach ($family['parents'] as $parent) {
                if (!isset($this->nodes[$parent]) || in_array($this->nodes[$parent]['type'] ?? '', ['family', 'placeholder'], true)) {
                    continue;
                }
                foreach ($children as $child) {
                    if (isset($explicit[self::pair_key($parent, $child)])) {
                        continue;
                    }
                    $explicit[self::pair_key($parent, $child)] = true;
                    $parent_label = $this->concept_label($group_prefix . 'parent', $parent);
                    $child_label = $this->concept_label($group_prefix . 'child', $child);
                    if ($parent_label !== null) {
                        $this->add_node_relation($parent, $child, 'parent', $derived_meta, $parent_label, true);
                    }
                    if ($child_label !== null) {
                        $this->add_node_relation($child, $parent, 'child', $derived_meta, $child_label, true);
                    }
                }
            }
        }
    }

    private function add_node_relation(string $key, string $other, string $kind, array $relation, string $label, bool $derived = false): void
    {
        if ($label === '') {
            return;
        }
        // Gespeicherte Kanten darf trennen, wer eine der beiden Personen bearbeiten darf
        // (Relatives_Graph::can_edit_relation()) -- abgeleitete gibt es nicht als Kante.
        $relation_id = $derived ? 0 : (int)($relation['id'] ?? 0);
        $editable = static function (?array $node): bool {
            return $node !== null && !empty($node['can_edit']) && in_array($node['type'] ?? '', ['person', 'self'], true);
        };
        $this->node_relations[$key][] = [
            'kind'        => $kind,
            'label'       => $label,
            'other_key'   => $other,
            'other_name'  => $this->node_label($other),
            'style'       => $relation['style'] ?? 'solid',
            'secret'      => !empty($relation['secret']),
            'derived'     => $derived,
            'relation_id' => $relation_id,
            'can_edit'    => $relation_id > 0 && ($editable($this->nodes[$key] ?? null) || $editable($this->nodes[$other] ?? null)),
        ];

        if (isset($this->nodes[$key])) {
            $this->nodes[$key]['relations'] = $this->node_relations[$key];
        }
    }

    /** Strukturklasse in der Gegenrichtung. */
    private static function inverse_kind(string $kind): string
    {
        if ($kind === 'parent') {
            return 'child';
        }
        if ($kind === 'child') {
            return 'parent';
        }
        return $kind;
    }

    /** Label eines Konzepts im Geschlecht des Zielknotens. */
    private function concept_label(string $concept, string $key): ?string
    {
        $gender = Relatives_RelationshipTypes::LABEL_NEUTRAL;
        if (isset($this->nodes[$key]['gender'])) {
            $gender = Relatives_RelationshipTypes::map_person_gender_to_label_gender((int)$this->nodes[$key]['gender']);
        }
        $row = Relatives_RelationshipTypes::get_by_concept_and_gender($concept, $gender);
        if ($row === null && $gender !== Relatives_RelationshipTypes::LABEL_NEUTRAL) {
            $row = Relatives_RelationshipTypes::get_by_concept_and_gender($concept, Relatives_RelationshipTypes::LABEL_NEUTRAL);
        }
        return $row !== null ? (string)$row['label'] : null;
    }

    /** Anzeigename eines Knotens (Person, Account oder Familien-Kasten). */
    private function node_label(string $key): string
    {
        $node = $this->nodes[$key] ?? null;
        if ($node === null) {
            return '';
        }
        if (($node['type'] ?? '') === 'self') {
            return (string)($node['username'] ?? '');
        }
        return (string)($node['name'] ?? '');
    }

    // --- Familienstruktur ------------------------------------------------------------
    //
    // Aus den rohen "Person P ist <Konzept> von Anker A"-Relationen wird eine echte
    // Stammbaum-Struktur abgeleitet, damit das Frontend Linien nach genealogischer
    // Grammatik zeichnen kann (Partnerlinie, gemeinsamer Abgang aus der Paarmitte,
    // Geschwisterbalken) statt nur "Anker -> Kind"-Striche.

    /**
     * Die Zuordnung Konzept -> Strukturklasse (Partner/Eltern/Kind/Geschwister/lose)
     * liegt in Relatives_Kinship, weil die Verwandtschaftsrechnung exakt dieselbe
     * Einteilung braucht -- zwei Kopien wuerden unweigerlich auseinanderlaufen.
     */
    /** @return array [['key' => 'person:<id>', 'name' => ...], ...] nach Name sortiert */
    private function family_member_entries(array $person_ids, array $persons): array
    {
        $entries = [];
        foreach ($person_ids as $person_id) {
            if (!isset($persons[$person_id])) {
                continue;
            }
            $entries[] = ['key' => 'person:' . $person_id, 'name' => (string)$persons[$person_id]['name']];
        }
        usort($entries, static function ($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });
        return $entries;
    }

    /** Kante zu einem Familien-Kasten ohne Grad und ohne freie Bezeichnung. */
    private static function is_family_membership(array $relation, ?array $type): bool
    {
        return !empty($relation['related_to_family_id']) && $type === null
            && trim((string)($relation['relationship_custom_label'] ?? '')) === '';
    }

    /**
     * Verbindung ohne Verwandtschaft: freie Bezeichnung ohne Grad (keine reine
     * Familien-Mitgliedschaft) oder ein Grad mit dem Konzept "other".
     */
    public static function is_non_kin(array $relation, ?array $type): bool
    {
        if ($type === null) {
            return trim((string)($relation['relationship_custom_label'] ?? '')) !== '';
        }
        return ($type['concept'] ?? '') === 'other';
    }

    private static function family_member_label(): string
    {
        global $lang;
        return isset($lang->relatives_family_member) ? (string)$lang->relatives_family_member : 'Mitglied';
    }

    private static function classify_relation(?string $concept, int $offset): string
    {
        return Relatives_Kinship::classify_relation($concept, $offset);
    }

    /**
     * Gemeinsame Eltern eines Kindes werden auch ohne ausdrueckliche Partner-Relation als
     * Paar verbunden -- Altdaten kennen nur "Mutter" und "Vater" je Kind, nicht "Ehefrau
     * von". Laeuft nach dem Layout: verbunden werden jeweils die nebeneinanderstehenden
     * Elternteile derselben Generation (das Layout haelt sie als Einheit zusammen).
     */
    private function with_coparent_partners(array $structure): array
    {
        $partners = $structure['partners'];
        $seen = [];
        foreach ($partners as $pair) {
            $seen[self::pair_key($pair['a'], $pair['b'])] = true;
        }

        // Zweitfamilien zaehlen mit: leibliche Eltern eines Adoptivkinds sind sonst kein Paar.
        $nodes = $this->nodes;
        foreach (array_merge($structure['families'], $this->secondary_families) as $family) {
            $parents = array_values(array_filter($family['parents'], static function ($key) use ($nodes) {
                return isset($nodes[$key]);
            }));
            if (count($parents) < 2) {
                continue;
            }
            usort($parents, static function ($a, $b) use ($nodes) {
                return $nodes[$a]['x'] <=> $nodes[$b]['x'];
            });
            for ($i = 1, $count = count($parents); $i < $count; $i++) {
                $a = $parents[$i - 1];
                $b = $parents[$i];
                $key = self::pair_key($a, $b);
                if ($nodes[$a]['generation'] !== $nodes[$b]['generation'] || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $partners[] = ['a' => $a, 'b' => $b, 'secret' => !empty($family['secret'])];
            }
        }

        return $partners;
    }

    private static function pair_key(string $a, string $b): string
    {
        return $a < $b ? $a . '|' . $b : $b . '|' . $a;
    }

    /** Union-Find-Wurzel mit Pfadverkuerzung. */
    private static function uf_find(array &$parent, string $key): string
    {
        while (isset($parent[$key]) && $parent[$key] !== $key) {
            $parent[$key] = $parent[$parent[$key]] ?? $parent[$key];
            $key = $parent[$key];
        }
        return $key;
    }

    private static function uf_union(array &$parent, string $a, string $b): void
    {
        $root_a = self::uf_find($parent, $a);
        $root_b = self::uf_find($parent, $b);
        if ($root_a !== $root_b) {
            $parent[$root_b] = $root_a;
        }
    }

    /**
     * Leitet aus $this->relations Partner-Paare, Eltern-Mengen und Familien ab.
     *
     * @return array Schluessel: partners, families, links
     */
    private function build_structure(): array
    {
        $partner_pairs = [];
        $sibling_pairs = [];
        $adoptive_sibling_pairs = [];
        $parents_of = [];
        $links = [];

        // Meta je Eltern-Kind-Kante (Linienart, Geheimnis, Primaerkante) -- der Schluessel
        // ist "Kind|Elternteil", damit sich Gruppierung und Meta spaeter wiederfinden.
        $edge_meta = [];

        foreach ($this->relations as $relation) {
            $node = $relation['node'];
            $anchor = $relation['anchor'];
            // 'resolved': ueber Platzhalter aufgeloest -- keine lose Linie mehr.
            if (!isset($this->nodes[$node]) || !isset($this->nodes[$anchor]) || $node === $anchor || !empty($relation['resolved'])) {
                continue;
            }
            $meta = [
                'style'   => $relation['style'] ?? 'solid',
                'secret'  => !empty($relation['secret']),
                'primary' => !empty($relation['primary']),
                'group'   => $relation['group'] ?? self::lineage_group($relation['concept']),
                // Synthetische Platzhalter-Kanten: angehaengte Zweitfamilie (Halb-/Stief-
                // geschwister) bzw. Partner*in, die nicht zum Elternteil der Kinder wird.
                'attach'      => !empty($relation['attach']),
                'no_coparent' => !empty($relation['no_coparent']),
            ];

            switch (self::classify_relation($relation['concept'], (int)$relation['offset'])) {
                case 'partner':
                    $partner_pairs[] = [$anchor, $node, $meta];
                    break;
                case 'parent':
                    $parents_of[$anchor][$meta['group']][$node] = true;
                    $edge_meta[$anchor . '|' . $node] = $meta;
                    break;
                case 'child':
                    $parents_of[$node][$meta['group']][$anchor] = true;
                    $edge_meta[$node . '|' . $anchor] = $meta;
                    break;
                case 'sibling':
                    // Nur volle Geschwister teilen sich die Eltern. Halb-, Stief- und
                    // Adoptivgeschwister haben (teils) andere Eltern -- sie in dieselbe
                    // Familie zu ziehen, verschmolz frueher ganze Elternpaare.
                    if ($relation['concept'] === 'sibling' || $relation['concept'] === null) {
                        $sibling_pairs[] = [$anchor, $node, $meta];
                    } else {
                        if ($relation['concept'] === 'adoptive_sibling') {
                            $adoptive_sibling_pairs[] = [$anchor, $node, $meta];
                        }
                        $links[] = [
                            'from'   => $anchor,
                            'to'     => $node,
                            'kind'   => 'other',
                            'style'  => $meta['style'],
                            'secret' => $meta['secret'],
                        ];
                    }
                    break;
                default:
                    $links[] = [
                        'from'   => $anchor,
                        'to'     => $node,
                        'kind'   => 'other',
                        'style'  => $meta['style'],
                        'secret' => $meta['secret'],
                        'concept' => $relation['concept'],
                        'membership' => !empty($relation['membership']),
                    ];
                    break;
            }
        }

        $partner_of = [];
        foreach ($partner_pairs as $pair) {
            if (!empty($pair[2]['no_coparent'])) {
                continue;
            }
            $partner_of[$pair[0]][$pair[1]] = true;
            $partner_of[$pair[1]][$pair[0]] = true;
        }

        // Partner eines Elternteils gelten ebenfalls als Elternteil ("Mamas Ehemann"
        // ist dein Vater -- dieselbe Annahme trifft bereits die COMPOSE-Tabelle in
        // Relatives_RelationshipTypes). Mehrfach, damit auch Ketten greifen. Die
        // Ergaenzung bleibt innerhalb derselben Abstammungsgruppe: der Ehemann der
        // Adoptivmutter ist Adoptivvater, nicht ploetzlich leiblicher Vater.
        for ($pass = 0; $pass < 3; $pass++) {
            foreach ($parents_of as $child_key => $groups) {
                foreach ($groups as $group => $direct_parents) {
                    foreach (array_keys($direct_parents) as $parent_key) {
                        if (!empty($edge_meta[$child_key . '|' . $parent_key]['attach'])) {
                            continue;
                        }
                        foreach (array_keys($partner_of[$parent_key] ?? []) as $partner_key) {
                            // Nur ergaenzen, solange das Kind noch keine zwei Eltern in dieser
                            // Gruppe hat: Louisa hat Delia UND Morgan -- Delias Ehemann Duncan
                            // ist deshalb nicht ihr dritter Elternteil.
                            if ($partner_key !== $child_key && !isset($parents_of[$child_key][$group][$partner_key])
                                && count($parents_of[$child_key][$group]) < 2) {
                                $parents_of[$child_key][$group][$partner_key] = true;
                                if (!isset($edge_meta[$child_key . '|' . $partner_key])) {
                                    $edge_meta[$child_key . '|' . $partner_key] = $edge_meta[$child_key . '|' . $parent_key]
                                        ?? ['style' => 'solid', 'secret' => false, 'primary' => false, 'group' => $group];
                                }
                            }
                        }
                    }
                }
            }
        }

        // Sicherheitsnetz: ein Elternteil muss echt oberhalb des Kindes stehen. Faengt
        // widerspruechliche Nutzereingaben (Zyklen, falsch gesetzte Bezugspersonen) ab.
        foreach ($parents_of as $child_key => $groups) {
            foreach ($groups as $group => $parent_keys) {
                foreach (array_keys($parent_keys) as $parent_key) {
                    if (!isset($this->nodes[$parent_key])
                        || $this->nodes[$parent_key]['generation'] >= $this->nodes[$child_key]['generation']) {
                        unset($parents_of[$child_key][$group][$parent_key]);
                    }
                }
                if (empty($parents_of[$child_key][$group])) {
                    unset($parents_of[$child_key][$group]);
                }
            }
            if (empty($parents_of[$child_key])) {
                unset($parents_of[$child_key]);
            }
        }

        // Adoptivgeschwister ohne eigene Eltern im Baum gehoeren zur Familie des anderen:
        // Louisa hat Adam und Judith als Adoptiveltern, Zacharias ist nur als "Adoptivbruder
        // von Louisa" eingetragen -- er ist dann leibliches Kind von Adam und Judith. Hat
        // umgekehrt nur das Geschwisterteil leibliche Eltern, wurde die elternlose Person
        // von ihnen adoptiert. Ohne diese Ableitung hingen Adoptivgeschwister als lose
        // Striche am Adoptivkind und die Adoptiveltern stuenden kinderlos am Zeilenende.
        foreach ($adoptive_sibling_pairs as [$a, $b, $meta]) {
            foreach ([[$a, $b], [$b, $a]] as [$known, $bare]) {
                if (!empty($parents_of[$bare])) {
                    continue;
                }
                if (!empty($parents_of[$known]['adoptive'])) {
                    $group = 'blood';
                    $source = $parents_of[$known]['adoptive'];
                } elseif (!empty($parents_of[$known]['blood'])) {
                    $group = 'adoptive';
                    $source = $parents_of[$known]['blood'];
                } else {
                    continue;
                }
                foreach (array_keys($source) as $parent_key) {
                    if ($this->nodes[$parent_key]['generation'] >= $this->nodes[$bare]['generation']) {
                        continue 2;
                    }
                }
                $parents_of[$bare][$group] = $source;
                foreach (array_keys($source) as $parent_key) {
                    $edge_meta[$bare . '|' . $parent_key] = [
                        'style'   => $group === 'blood' ? 'solid' : $meta['style'],
                        'secret'  => false,
                        'primary' => false,
                        'group'   => $group,
                    ];
                }
                break;
            }
        }

        // Geschwister teilen sich dieselben Eltern: Komponenten bilden, Eltern-Mengen
        // innerhalb einer Komponente vereinigen.
        $sibling_uf = [];
        foreach (array_keys($this->nodes) as $key) {
            $sibling_uf[$key] = $key;
        }
        foreach ($sibling_pairs as $pair) {
            if ($this->nodes[$pair[0]]['generation'] === $this->nodes[$pair[1]]['generation']) {
                self::uf_union($sibling_uf, $pair[0], $pair[1]);
            }
        }
        $sibling_groups = [];
        foreach (array_keys($this->nodes) as $key) {
            $sibling_groups[self::uf_find($sibling_uf, $key)][] = $key;
        }

        $virtual_family_of = [];
        foreach ($sibling_groups as $root => $members) {
            if (count($members) < 2) {
                continue;
            }
            // Im eigenen Stammbaum sind die Eltern der Wurzel massgeblich: steht sie in der
            // Geschwistergruppe, gelten ihre Eltern fuer alle. Widersprechende Angaben aus
            // anderen Listen ("Damien ist Sohn von Delia") ziehen sonst einen Bruder aus der
            // eigenen Familie heraus.
            if (in_array($this->root_key, $members, true) && !empty($parents_of[$this->root_key]['blood'])) {
                foreach ($members as $member) {
                    $parents_of[$member]['blood'] = $parents_of[$this->root_key]['blood'];
                }
                continue;
            }
            // Geschwister teilen sich ihre leiblichen Eltern. Andere Gruppen
            // (Adoptiv-, Stief-, Pflegeeltern) bleiben bewusst individuell -- nicht
            // jedes Geschwisterkind wurde von denselben Menschen adoptiert.
            // Die bekannten Eltern werden an Geschwister OHNE eigene Eltern weitergegeben.
            // Haben mehrere Geschwister unterschiedliche Eltern eingetragen (widerspruechliche
            // Listen im gemeinsamen Geflecht), bleiben ihre Eltern getrennt -- vereinigt
            // entstanden frueher Familien mit vier Elternteilen.
            $shared = [];
            foreach ($members as $member) {
                $own = $parents_of[$member]['blood'] ?? [];
                if (count($own) > count($shared)) {
                    $shared = $own;
                }
            }
            if (empty($shared)) {
                $has_any_parents = false;
                foreach ($members as $member) {
                    if (!empty($parents_of[$member])) {
                        $has_any_parents = true;
                        break;
                    }
                }
                if (!$has_any_parents) {
                    // Geschwister ohne bekannte Eltern: eigener Geschwisterbalken ohne
                    // Elternabgang (so zeichnet es auch eine klassische Ahnentafel).
                    foreach ($members as $member) {
                        $virtual_family_of[$member] = 'siblings:' . $root;
                    }
                }
                continue;
            }
            foreach ($members as $member) {
                if (empty($parents_of[$member]['blood'])) {
                    $parents_of[$member]['blood'] = $shared;
                }
            }
        }

        // Familien gruppieren: gleiche Eltern-Menge = eine Familie = ein
        // Geschwisterbalken. Ein Kind kann in mehreren Familien stehen (leibliche +
        // Adoptiveltern); genau eine davon ist seine Primaerfamilie und bestimmt das
        // Layout.
        $families = [];
        foreach (array_keys($this->nodes) as $key) {
            $groups = $parents_of[$key] ?? [];

            if (empty($groups)) {
                if (!isset($virtual_family_of[$key])) {
                    continue;
                }
                $family_id = $virtual_family_of[$key];
                if (!isset($families[$family_id])) {
                    $families[$family_id] = [
                        'id' => $family_id, 'parents' => [], 'children' => [],
                        'style' => 'solid', 'secret' => false, 'primary' => true, 'group' => 'blood',
                    ];
                }
                $families[$family_id]['children'][] = $key;
                continue;
            }

            $primary_group = self::primary_group($key, $groups, $edge_meta);
            foreach ($groups as $group => $parent_map) {
                $parent_keys = array_keys($parent_map);
                sort($parent_keys);
                $family_id = 'family:' . $group . ':' . implode('|', $parent_keys);

                $style = 'solid';
                $secret = false;
                $attach = false;
                foreach ($parent_keys as $parent_key) {
                    $meta = $edge_meta[$key . '|' . $parent_key] ?? null;
                    if ($meta === null) {
                        continue;
                    }
                    if ($meta['style'] !== 'solid') {
                        $style = $meta['style'];
                    }
                    if ($meta['secret']) {
                        $secret = true;
                    }
                    if (!empty($meta['attach'])) {
                        $attach = true;
                    }
                }

                if (!isset($families[$family_id])) {
                    $families[$family_id] = [
                        'id'       => $family_id,
                        'parents'  => $parent_keys,
                        'children' => [],
                        'style'    => $style,
                        'secret'   => $secret,
                        'secret_children' => [],
                        'primary'  => false,
                        'group'    => $group,
                        'attach'   => $attach,
                    ];
                }
                $families[$family_id]['children'][] = $key;
                // Geheim ist die Abstammung je Kind: Louisa ist heimliche Tochter von Delia
                // und Morgan, der Charakter in derselben Familie ganz offen. Die Familie gilt
                // nur als geheim, wenn es alle Kinder sind; sonst nur deren Stichleitung.
                if ($secret) {
                    $families[$family_id]['secret_children'][] = $key;
                } else {
                    $families[$family_id]['secret'] = false;
                }
                if ($group === $primary_group) {
                    // Angehaengte Halb-/Stiefgeschwister ohne eigene Familie: die Familie
                    // positioniert sie, gezeichnet wird sie aber als Zweitfamilie am Balken.
                    if ($attach) {
                        $families[$family_id]['positions'] = true;
                    } else {
                        $families[$family_id]['primary'] = true;
                    }
                }
            }
        }

        // Neutrale Platzhalter ("Grosselternteil von Jolina"): der Abgang endet auf der
        // Paarlinie eines Elternpaars -- eine*r von beiden ist Kind dieser Familie.
        foreach ($this->placeholder_attachments as $attachment) {
            $group = array_values(array_filter($attachment['group'], function ($member) {
                return isset($this->nodes[$member]);
            }));
            if (count($group) < 2) {
                continue;
            }
            $target = null;
            if ($attachment['parent'] !== null) {
                if (!isset($this->nodes[$attachment['parent']])) {
                    continue;
                }
                $target = 'family:blood:' . $attachment['parent'];
                if (!isset($families[$target])) {
                    $families[$target] = [
                        'id' => $target, 'parents' => [$attachment['parent']], 'children' => [],
                        'style' => 'solid', 'secret' => !empty($attachment['secret']), 'primary' => true, 'group' => 'blood',
                    ];
                }
            } else {
                foreach ($families as $family_id => $family) {
                    if (!empty($family['primary']) && $family['group'] === 'blood' && !empty($family['parents'])
                        && in_array($attachment['child'], $family['children'], true)) {
                        $target = $family_id;
                        break;
                    }
                }
                if ($target === null) {
                    continue;
                }
            }
            $child_generation = (int)$this->nodes[$families[$target]['parents'][0]]['generation'] + 1;
            foreach ($group as $member) {
                if ((int)$this->nodes[$member]['generation'] !== $child_generation) {
                    continue 2;
                }
            }
            if (!in_array($group, $families[$target]['either'] ?? [], true)) {
                $families[$target]['either'][] = $group;
            }
        }

        $partners = [];
        $seen_partner_pairs = [];
        foreach ($partner_pairs as $pair) {
            $pair_key = $pair[0] < $pair[1] ? $pair[0] . '|' . $pair[1] : $pair[1] . '|' . $pair[0];
            if (isset($seen_partner_pairs[$pair_key])) {
                continue;
            }
            $seen_partner_pairs[$pair_key] = true;
            $meta = $pair[2] ?? ['style' => 'solid', 'secret' => false];
            $partners[] = [
                'a'      => $pair[0],
                'b'      => $pair[1],
                'style'  => $meta['style'],
                'secret' => !empty($meta['secret']),
            ];
        }

        return [
            'partners' => $partners,
            'families' => array_values($families),
            'links'    => $this->needed_links($links, $families, $partners),
        ];
    }

    /**
     * Nur fuers Layout: Knoten ohne eigene Familie, die lose mit jemandem derselben
     * Generation verbunden sind (Adoptivgeschwister, Cousine ohne Eltern im Baum), rutschen
     * in dessen Geschwisterblock. So stehen sie direkt daneben, statt zwischen fremde
     * Familien geschoben zu werden und diese auseinanderzudruecken (Siri stand frueher 13
     * Kastenbreiten von ihren Eltern entfernt). Gezeichnet wird trotzdem nur die lose
     * Verbindung -- die Familien fuers Zeichnen bleiben unveraendert.
     */
    private function with_floating_neighbours(array $families, array $links): array
    {
        $family_index = [];
        foreach ($families as $index => $family) {
            foreach ($family['children'] as $child) {
                $family_index[$child] = $family_index[$child] ?? $index;
            }
        }

        foreach ($links as $link) {
            foreach ([[$link['from'], $link['to']], [$link['to'], $link['from']]] as [$anchor, $floating]) {
                if (!isset($family_index[$anchor]) || isset($family_index[$floating])
                    || !isset($this->nodes[$anchor], $this->nodes[$floating])
                    || (int)$this->nodes[$anchor]['generation'] !== (int)$this->nodes[$floating]['generation']) {
                    continue;
                }
                $index = $family_index[$anchor];
                $families[$index]['children'][] = $floating;
                $family_index[$floating] = $index;
                $this->floating_anchor[$floating] = $anchor;
            }
        }
        return $families;
    }

    /**
     * Lose Verbindungen (Tante, Cousine, Adoptivgeschwister, freie Bezeichnung) werden nur
     * dort gezeichnet, wo sie als einzige zeigen, wohin ein Knoten gehoert -- und dann nur
     * eine je Knoten, bevorzugt die zur Wurzel. Steht ein Knoten bereits in einer Familie
     * oder einem Paar, ist die Verbindung ueber Hervorhebung und Panel sichtbar; als Linie
     * liefe sie nur quer ueber den ganzen Baum. Ausnahme: verbindet die lose Aussage zwei
     * sonst getrennte Teile des Baums, wird sie trotzdem gezeichnet (reine Familien-
     * Mitgliedschaften nur, wenn sonst nichts die Teile verbindet).
     */
    private function needed_links(array $links, array $families, array $partners): array
    {
        $placed = [$this->root_key => true];
        foreach ($families as $family) {
            foreach (array_merge($family['parents'], $family['children']) as $key) {
                $placed[$key] = true;
            }
        }
        foreach ($partners as $pair) {
            $placed[$pair['a']] = true;
            $placed[$pair['b']] = true;
        }

        $kept = [];
        $linked = [];
        foreach ([true, false] as $root_links_first) {
            foreach ($links as $link) {
                $touches_root = $link['from'] === $this->root_key || $link['to'] === $this->root_key;
                if ($touches_root !== $root_links_first) {
                    continue;
                }
                $floating = !isset($placed[$link['to']]) ? $link['to'] : (!isset($placed[$link['from']]) ? $link['from'] : null);
                if ($floating === null || isset($linked[$floating])) {
                    continue;
                }
                $linked[$floating] = true;
                $kept[] = $link;
            }
        }

        // A loose statement between two nodes that both stand in families can still be the
        // only thing tying one part of the tree to the rest (a distant ancestor with their
        // own family box above them) -- otherwise the island floats in the tree without any
        // visible connection.
        $component = [];
        foreach ($families as $family) {
            $members = array_merge($family['parents'], $family['children']);
            foreach ($members as $key) {
                self::uf_union($component, $members[0], $key);
            }
        }
        foreach ($partners as $pair) {
            self::uf_union($component, $pair['a'], $pair['b']);
        }
        foreach ($kept as $link) {
            self::uf_union($component, $link['from'], $link['to']);
        }
        // Distant ancestors/descendants are the line itself: each one between two separate
        // parts is drawn (Martha -> Caiomhe AND Aidyn -> Tadgh, even if both tie the same
        // island to the tree).
        $parts = $component;
        foreach ($links as $link) {
            if (!in_array($link['concept'] ?? null, ['distant_ancestor', 'distant_descendant'], true)
                || in_array($link, $kept, true)
                || self::uf_find($parts, $link['from']) === self::uf_find($parts, $link['to'])) {
                continue;
            }
            self::uf_union($component, $link['from'], $link['to']);
            $kept[] = $link;
        }
        // Other statements: one line per island, a bare family membership only if nothing
        // else connects it, root links first.
        foreach ([[false, true], [false, false], [true, true], [true, false]] as [$membership, $root_links_first]) {
            foreach ($links as $link) {
                $touches_root = $link['from'] === $this->root_key || $link['to'] === $this->root_key;
                if ($touches_root !== $root_links_first || !empty($link['membership']) !== $membership
                    || self::uf_find($component, $link['from']) === self::uf_find($component, $link['to'])) {
                    continue;
                }
                self::uf_union($component, $link['from'], $link['to']);
                $kept[] = $link;
            }
        }
        return $kept;
    }

    /**
     * Abstammungsgruppe einer Eltern-Kind-Kante. Leibliche und angenommene Elternschaft
     * duerfen sich nicht zu einer Familie vermischen -- sonst haette ein adoptiertes
     * Kind vier Eltern in einem Kasten statt zweier getrennter Elternpaare.
     */
    private static function lineage_group(?string $concept): string
    {
        if ($concept === null || $concept === '') {
            return 'blood';
        }
        foreach (['adoptive', 'step', 'foster'] as $prefix) {
            if (strpos($concept, $prefix . '_') === 0) {
                return $prefix;
            }
        }
        return 'blood';
    }

    /**
     * Welche Abstammungsgruppe positioniert das Kind? Vorrang hat die Gruppe, in der
     * die als "Haupt" markierte Kante liegt (is_primary), sonst die leibliche, sonst
     * die erste vorhandene.
     */
    private static function primary_group(string $child_key, array $groups, array $edge_meta): string
    {
        foreach ($groups as $group => $parent_map) {
            foreach (array_keys($parent_map) as $parent_key) {
                if (!empty($edge_meta[$child_key . '|' . $parent_key]['primary'])) {
                    return $group;
                }
            }
        }
        if (isset($groups['blood'])) {
            return 'blood';
        }
        return (string)array_key_first($groups);
    }

    // --- Layout ------------------------------------------------------------------------

    /** Groesster Abstand (in Knotenbreiten) zwischen zwei Kaesten, wenn dazwischen in keiner Generation etwas steht. */
    private const MAX_EMPTY_COLUMN = 1.6;

    /** How far an origin label (tag box) sits above its person's centre, in rows: on the top edge of the box. */
    private const TAG_RISE = 0.21;

    /** Horizontal distance between two origin labels above the same person. */
    private const TAG_SPREAD = 0.55;

    /**
     * Entfernt senkrechte Leerstreifen, in denen in KEINER Generation ein Kasten steht.
     * Weil jeder Streifen in allen Generationen gleich breit entfernt wird, bleiben Eltern
     * und Kinder zueinander ausgerichtet -- nur der leere Raum dazwischen verschwindet.
     */
    private function close_empty_columns(): void
    {
        $xs = [];
        foreach ($this->nodes as $key => $node) {
            if (isset($node['x'])) {
                $xs[$key] = (float)$node['x'];
            }
        }
        foreach (self::closed_columns($xs) as $key => $x) {
            $this->nodes[$key]['x'] = $x;
        }
    }

    /** Rechenkern von close_empty_columns(): Knoten => x, ohne die Leerstreifen. */
    private static function closed_columns(array $xs): array
    {
        if (count($xs) < 2) {
            return $xs;
        }
        $sorted = array_values($xs);
        sort($sorted);

        $cuts = [];
        for ($i = 1, $count = count($sorted); $i < $count; $i++) {
            $gap = $sorted[$i] - $sorted[$i - 1];
            if ($gap > self::MAX_EMPTY_COLUMN) {
                $cuts[] = [$sorted[$i], $gap - self::MAX_EMPTY_COLUMN];
            }
        }
        if (!$cuts) {
            return $xs;
        }

        foreach ($xs as $key => $x) {
            $delta = 0.0;
            foreach ($cuts as [$threshold, $amount]) {
                if ($x >= $threshold - 0.0001) {
                    $delta += $amount;
                }
            }
            $xs[$key] = $x - $delta;
        }
        return $xs;
    }

    /**
     * Vergibt x/y-Koordinaten (y = Generation, x in Knotenbreiten) ueber
     * Relatives_TreeLayout: Paare stehen als Einheit nebeneinander, Geschwister nach
     * Geburtsjahr, Eltern zentriert ueber ihren Kindern; die Reihen nahe der Wurzel
     * haben Vorrang. Herkunfts-Etiketten (siehe tag_boxes()) stehen danach klein ueber
     * ihrer Person.
     */
    private function assign_positions(array $structure): void
    {
        $tags = $this->tag_boxes($structure) + $this->origin_tags;
        foreach ($tags as $box => $person) {
            $this->nodes[$box]['tag'] = true;
        }
        $families = array_values(array_filter($structure['families'], static function ($family) use ($tags) {
            foreach ($family['parents'] as $parent) {
                if (isset($tags[$parent])) {
                    return false;
                }
            }
            return true;
        }));
        $links = array_values(array_filter($structure['links'] ?? [], static function ($link) use ($tags) {
            return !isset($tags[$link['from']]) && !isset($tags[$link['to']]);
        }));

        $units = $this->build_units(['families' => $families, 'partners' => $structure['partners']], $tags);
        // Year of birth for the sibling order; where only the age is kept, estimated from
        // the in-play year.
        $inplay_year = class_exists('Relatives_Database') ? Relatives_Database::inplay_year() : null;
        $layout_nodes = [];
        foreach ($this->nodes as $key => $node) {
            if (isset($tags[$key])) {
                continue;
            }
            $birth = isset($node['birth_year']) ? (int)$node['birth_year'] : null;
            if ($birth === null && $inplay_year !== null && isset($node['age']) && is_numeric($node['age'])) {
                $birth = $inplay_year - (int)$node['age'];
            }
            $layout_nodes[$key] = ['generation' => (int)$node['generation'], 'birth_year' => $birth];
        }

        $layout = new Relatives_TreeLayout($this->root_key, $layout_nodes, $units, $families, $this->secondary_families, $links, $this->floating_anchor);
        $xs = $layout->run();
        foreach ($units as $unit_id => $unit) {
            // run() orients units (members may be reversed) -- read the final order back.
            $unit = $layout->unit($unit_id);
            $count = count($unit['members']);
            foreach ($unit['members'] as $offset => $key) {
                $this->nodes[$key]['x'] = ($xs[$unit_id] ?? 0.0) + ($offset - ($count - 1) / 2);
                $this->nodes[$key]['y'] = (int)$this->nodes[$key]['generation'];
            }
        }

        $this->close_empty_columns();

        $root_key = $this->root_key;
        $shift = isset($this->nodes[$root_key]['x']) ? -(float)$this->nodes[$root_key]['x'] : 0.0;
        foreach ($this->nodes as $key => $node) {
            if (isset($node['x'])) {
                $this->nodes[$key]['x'] = $node['x'] + $shift;
            }
        }

        $per_person = [];
        foreach ($tags as $box => $person) {
            $per_person[$person][] = $box;
        }
        foreach ($per_person as $person => $boxes) {
            foreach ($boxes as $index => $box) {
                $this->nodes[$box]['x'] = (float)($this->nodes[$person]['x'] ?? 0.0) + ($index - (count($boxes) - 1) / 2) * self::TAG_SPREAD;
                $this->nodes[$box]['y'] = (int)($this->nodes[$person]['generation'] ?? 0) - self::TAG_RISE;
            }
        }
    }

    /**
     * Familien-Kaesten, die nur fuer eine einzige Person stehen (der Kasten ist allein
     * Elternteil genau einer Person oder nur ueber eine Linie mit ihr verbunden), werden
     * zum Herkunfts-Etikett: klein direkt ueber der Person statt als eigener Kasten eine
     * Reihe hoeher ("Travers" ueber Rebecca, "Slughorn" ueber Ava).
     *
     * @return array box key => person key
     */
    private function tag_boxes(array $structure): array
    {
        $persons_of = [];
        $blocked = [];
        foreach ($structure['families'] as $family) {
            foreach ($family['children'] as $child) {
                $blocked[$child] = true;
            }
            foreach ($family['parents'] as $parent) {
                if (($this->nodes[$parent]['type'] ?? '') !== 'family') {
                    continue;
                }
                if (count($family['parents']) > 1 || !empty($family['either'])) {
                    $blocked[$parent] = true;
                }
                foreach ($family['children'] as $child) {
                    $persons_of[$parent][$child] = true;
                }
            }
        }
        foreach ($structure['links'] ?? [] as $link) {
            foreach ([[$link['from'], $link['to']], [$link['to'], $link['from']]] as [$box, $other]) {
                if (($this->nodes[$box]['type'] ?? '') === 'family') {
                    $persons_of[$box][$other] = true;
                }
            }
        }

        $tags = [];
        foreach ($persons_of as $box => $persons) {
            if (isset($blocked[$box])) {
                continue;
            }
            $people = array_values(array_filter(array_keys($persons), function ($person) {
                return isset($this->nodes[$person]) && in_array($this->nodes[$person]['type'] ?? '', ['person', 'self'], true);
            }));
            if (count($people) !== count($persons) || !$people) {
                continue;
            }
            // Mehrere Personen: nur wenn alle angeheiratet sind (die Solomons Orion und Aurora
            // stehen bei ihren Ehepartner*innen, ihr Kasten waere sonst weit weg und seine
            // Linien liefen quer durch den Baum). Jede bekommt ihr eigenes Etikett.
            if (count($people) > 1) {
                foreach ($people as $person) {
                    if (!isset($this->nodes[$person]['core']) || !empty($this->nodes[$person]['core'])) {
                        continue 2;
                    }
                }
            }
            foreach ($people as $index => $person) {
                if ($index === 0) {
                    $tags[$box] = $person;
                    continue;
                }
                $clone = 'origin:' . ($this->nodes[$box]['id'] ?? substr($box, 7)) . ':' . substr($person, strpos($person, ':') + 1);
                $this->nodes[$clone] = ['key' => $clone, 'generation' => $this->nodes[$person]['generation']] + $this->nodes[$box];
                $this->edges[] = ['from' => $person, 'to' => $clone];
                $tags[$clone] = $person;
            }
        }
        return $tags;
    }

    /**
     * Fasst Partner (und gemeinsame Eltern einer Familie) derselben Generation zu einer
     * Einheit zusammen, die im Layout nie auseinandergerissen wird.
     *
     * @return array unit_id => ['members' => [...], 'generation' => int]
     */
    private function build_units(array $structure, array $skip = []): array
    {
        $unit_uf = [];
        foreach (array_keys($this->nodes) as $key) {
            if (!isset($skip[$key])) {
                $unit_uf[$key] = $key;
            }
        }
        $this->unit_pairs = [];
        $this->coparent_pairs = [];
        foreach ($structure['partners'] as $pair) {
            if (isset($unit_uf[$pair['a']], $unit_uf[$pair['b']])
                && $this->nodes[$pair['a']]['generation'] === $this->nodes[$pair['b']]['generation']) {
                self::uf_union($unit_uf, $pair['a'], $pair['b']);
                $this->unit_pairs[$pair['a']][$pair['b']] = true;
                $this->unit_pairs[$pair['b']][$pair['a']] = true;
            }
        }
        // Auch die Eltern einer Zweitfamilie stehen als Paar zusammen: Louisas leibliche
        // Eltern bleiben erkennbar, auch wenn sie bei Adoptiveltern aufwaechst.
        foreach (array_merge($structure['families'], $this->secondary_families) as $family) {
            $previous = null;
            foreach ($family['parents'] as $parent_key) {
                if (!isset($unit_uf[$parent_key])) {
                    continue;
                }
                if ($previous !== null
                    && $this->nodes[$previous]['generation'] === $this->nodes[$parent_key]['generation']) {
                    self::uf_union($unit_uf, $previous, $parent_key);
                    $this->unit_pairs[$previous][$parent_key] = true;
                    $this->unit_pairs[$parent_key][$previous] = true;
                    $this->coparent_pairs[$previous][$parent_key] = true;
                    $this->coparent_pairs[$parent_key][$previous] = true;
                }
                $previous = $parent_key;
            }
        }

        $units = [];
        foreach (array_keys($unit_uf) as $key) {
            $unit_id = self::uf_find($unit_uf, $key);
            if (!isset($units[$unit_id])) {
                $units[$unit_id] = [
                    'members'    => [],
                    'generation' => (int)$this->nodes[$key]['generation'],
                ];
            }
            $units[$unit_id]['members'][] = $key;
        }
        foreach ($units as $unit_id => $unit) {
            $units[$unit_id]['members'] = $this->chain_order($unit['members']);
        }
        return $units;
    }

    /**
     * Bilden die Paare einer Einheit eine Kette (Cathalain - Caiomhe - Simon - Milena),
     * stehen die Mitglieder in genau dieser Kette -- sonst lag die Partnerlinie ueber fremden
     * Kaesten und die Abgaenge der beiden Paare kreuzten sich. Von den zwei Richtungen gilt
     * die, die naeher an der uebergebenen Wunschreihenfolge liegt. Jemand mit drei oder
     * mehr Partner*innen: siehe star_order(). Kreis: Reihenfolge bleibt.
     */
    private function chain_order(array $members): array
    {
        if (count($members) < 3) {
            return $members;
        }
        $in_unit = array_flip($members);
        $neighbours = [];
        foreach ($members as $member) {
            $neighbours[$member] = array_values(array_filter(array_keys($this->unit_pairs[$member] ?? []), function ($other) use ($in_unit) {
                return isset($in_unit[$other]);
            }));
        }
        foreach ($neighbours as $list) {
            if (count($list) > 2) {
                return $this->star_order($members, $neighbours);
            }
        }

        $ends = array_values(array_filter($members, function ($member) use ($neighbours) {
            return count($neighbours[$member]) === 1;
        }));
        if (count($ends) !== 2) {
            return $members;
        }
        $chain = [$ends[0]];
        $previous = null;
        $current = $ends[0];
        while (count($chain) < count($members)) {
            $next = null;
            foreach ($neighbours[$current] as $candidate) {
                if ($candidate !== $previous) {
                    $next = $candidate;
                    break;
                }
            }
            if ($next === null || in_array($next, $chain, true)) {
                return $members; // nicht zusammenhaengend
            }
            $chain[] = $next;
            $previous = $current;
            $current = $next;
        }

        $wish = array_flip($members);
        $displacement = function (array $order) use ($wish): int {
            $sum = 0;
            foreach ($order as $index => $member) {
                $sum += abs($index - $wish[$member]);
            }
            return $sum;
        };
        $reversed = array_reverse($chain);
        return $displacement($reversed) < $displacement($chain) ? $reversed : $chain;
    }

    /**
     * Eine Person mit drei oder mehr Partner*innen (Nathaniel: Drusilla, Beatrix, Mama
     * Griffin) steht in der Mitte. Wer mit ihr Kinder hat, steht direkt daneben -- sonst
     * laeuft der Abgang zu den Kindern aus einer Paarmitte ueber einem fremden Kasten.
     * Die uebrigen rutschen nach aussen. Andere Formen als dieser Stern: Reihenfolge bleibt.
     */
    private function star_order(array $members, array $neighbours): array
    {
        $hubs = array_values(array_filter($members, static function ($member) use ($neighbours) {
            return count($neighbours[$member]) > 2;
        }));
        if (count($hubs) !== 1) {
            return $members;
        }
        $hub = $hubs[0];
        $partners = [];
        foreach ($members as $member) {
            if ($member === $hub) {
                continue;
            }
            if ($neighbours[$member] !== [$hub]) {
                return $members;
            }
            $partners[] = $member;
        }

        // Wunschreihenfolge bleibt Tie-Break; wer links vom Hub gewuenscht war, bleibt links.
        $wish = array_flip($members);
        usort($partners, function ($a, $b) use ($hub, $wish) {
            return [isset($this->coparent_pairs[$hub][$a]) ? 0 : 1, $wish[$a]]
                <=> [isset($this->coparent_pairs[$hub][$b]) ? 0 : 1, $wish[$b]];
        });
        $inner = array_slice($partners, 0, 2);
        usort($inner, static function ($a, $b) use ($wish) {
            return $wish[$a] <=> $wish[$b];
        });
        $left = [$inner[0]];
        $right = isset($inner[1]) ? [$inner[1]] : [];
        foreach (array_slice($partners, 2) as $index => $partner) {
            if ($index % 2 === 0) {
                array_unshift($left, $partner);
            } else {
                $right[] = $partner;
            }
        }
        return array_merge($left, [$hub], $right);
    }
}
