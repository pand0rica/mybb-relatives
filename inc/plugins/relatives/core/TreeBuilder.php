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
    /** Verbindungsschritte, die der Baum ab dem Charakter standardmaessig zeigt. */
    private const MAX_STEPS = 6;

    /** Zusaetzliche Schritte ab einem per Klick aufgeklappten Randknoten. */
    private const EXPAND_STEPS = 3;

    /**
     * Kosten je Kantenart. Partner*innen kosten mehr, damit angeheiratete Familien frueh
     * als aufklappbarer Rand enden, statt den Baum ueber mehrere Familienverbuende
     * wachsen zu lassen. Die eigene Partner*in kostet nur einen Schritt.
     */
    private const STEP_COST = ['parent' => 1, 'child' => 1, 'sibling' => 1, 'partner' => 3, 'other' => 2, 'member' => 3];

    private array $nodes = [];
    private array $edges = [];

    /** Rohe "Person ist <Konzept> von Anker"-Aussagen im Ausschnitt, Basis der Familienstruktur. */
    private array $relations = [];

    /** Referenzierte Familien-Kaesten: family_id => Zeile aus relatives_families. */
    private array $families = [];

    /** Klartext-Verbindungen je Knoten (Panel: "Bruder von Delia, Vater von Ethan"). */
    private array $node_relations = [];

    /** Gezeichnete lose Verbindungen -- das Layout stellt Knoten ohne Familie daneben. */
    private array $layout_links = [];

    /** Zweitfamilien (Adoptiv-, Stief-, Pflegeeltern) -- positionieren nicht, ruecken aber ihre Eltern heran. */
    private array $secondary_families = [];

    /** Kind -> Eltern seiner Primaerfamilie (nur fuers Layout). */
    private array $primary_parents_of = [];

    /** Paare innerhalb einer Layout-Einheit (Partner*innen, gemeinsame Eltern): key => [key => true]. */
    private array $unit_pairs = [];

    /** Neutrale Platzhalter-Abgaenge auf ein Elternpaar (siehe Relatives_TreePlaceholders). */
    private array $placeholder_attachments = [];

    /** Lose verbundene Knoten, die fuers Layout in den Geschwisterblock ihres Ankers rutschen: Knoten => Anker. */
    private array $floating_anchor = [];

    /** Struktur, wie das Frontend sie zeichnet (families inkl. Zweitfamilien, partners, links) -- Grundlage der Kreuzungszaehlung. */
    private array $drawn = [];

    /**
     * Hoechstzahl probeweise durchgerechneter Reihenfolgen je Baum (Schritt 2 der
     * Kreuzungssuche). Bewusst eine Anzahl statt einer Zeit: sonst saehe derselbe Baum je
     * nach Serverlast bei jedem Aufruf anders aus.
     */
    private const CROSSING_BUDGET = 80;

    /** Wie weit (in Plaetzen) eine Einheit bei der Kreuzungssuche hoechstens wandert. */
    private const CROSSING_WINDOW = 4;

    private string $root_key = '';
    private int $root_uid = 0;
    private int $viewer_uid = 0;

    /**
     * @param int   $root_uid   Charakter, dessen Baum gezeigt wird
     * @param int   $viewer_uid wer schaut (steuert can_edit)
     * @param array $expand     Personen-ids, deren Umgebung zusaetzlich aufgeklappt wird
     */
    public static function build(int $root_uid, int $viewer_uid = 0, array $expand = []): array
    {
        // Immer mit frischem Geflecht: ein Baum wird oft direkt nach einem Speichern im
        // selben Request gebaut (Formular, Tests).
        Relatives_Kinship::reset();

        $builder = new self();
        $builder->root_uid = $root_uid;
        $builder->viewer_uid = $viewer_uid;
        $builder->collect($expand);
        $builder->place_family_nodes();
        [$structure, $partners] = $builder->layout();
        $builder->attach_node_relations($structure);

        return [
            'root'     => $builder->root_key,
            'nodes'    => array_values($builder->nodes),
            'edges'    => $builder->edges,
            'partners' => $partners,
            'families' => $structure['families'],
            'links'    => $structure['links'],
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
        $this->drawn = $structure;
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
     * Sammelt den Ausschnitt: kuerzeste Wege ab der Wurzel (Dijkstra mit STEP_COST),
     * Generation entlang dieser Wege, danach Knoten, Aussagen und Randmarkierungen.
     */
    private function collect(array $expand): void
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

        $frontier = [$this->root_key => 0];
        $reached_via = [];
        $settled = [];
        $generation = [];
        while ($frontier) {
            asort($frontier);
            $key = (string)array_key_first($frontier);
            $distance = $frontier[$key];
            unset($frontier[$key]);
            if (isset($settled[$key])) {
                continue;
            }
            // Aufgeklappte Randknoten bekommen frisches Budget.
            if (isset($expand_keys[$key])) {
                $distance = min($distance, self::MAX_STEPS - self::EXPAND_STEPS);
            }
            $settled[$key] = $distance;
            $generation[$key] = isset($reached_via[$key])
                ? $generation[$reached_via[$key][0]] + $reached_via[$key][1]
                : 0;

            foreach ($adjacency[$key] ?? [] as [$other, $index, $kind, $delta]) {
                if (isset($settled[$other])) {
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
                if ($next > self::MAX_STEPS) {
                    continue;
                }
                if (!isset($frontier[$other]) || $frontier[$other] > $next) {
                    $frontier[$other] = $next;
                    $reached_via[$other] = [$key, $delta];
                }
            }
        }

        $person_ids = [];
        $family_ids = [];
        foreach (array_keys($settled) as $key) {
            if (strpos($key, 'family:') === 0) {
                $family_ids[] = (int)substr($key, 7);
            } else {
                $person_ids[] = (int)substr($key, 7);
            }
        }
        $this->families = Relatives_Families::get_bulk($family_ids);

        // Verwandtschaft IMMER aus Sicht des gezeigten Charakters, nicht der schauenden Person.
        $described = [];
        foreach ($person_ids as $person_id) {
            $described[$person_id] = $persons[$person_id];
        }
        $kinship = Relatives_Kinship::compute_from($described, $world['graph'], 'p:' . $root_person);

        $owner_counts = Relatives_Database::count_owners_bulk($person_ids);
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
                'avatar'                    => $person['avatar'],
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
            ];
        }
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

        $member_lists = $children_generations ? Relatives_Graph::family_member_lists() : [];
        $persons = Relatives_Kinship::world()['persons'];

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

        $nodes = $this->nodes;
        foreach ($structure['families'] as $family) {
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
                $partners[] = ['a' => $a, 'b' => $b];
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
     * liefe sie nur quer ueber den ganzen Baum.
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

    /** Zusaetzlicher Abstand zwischen zwei Einheiten derselben Familie (Geschwister). */
    private const SIBLING_GAP = 0.14;

    /** Zusaetzlicher Abstand zwischen Einheiten verschiedener Familien. */
    private const UNIT_GAP = 0.6;

    /** Groesster Abstand (in Knotenbreiten) zwischen zwei Kaesten, wenn dazwischen in keiner Generation etwas steht. */
    private const MAX_EMPTY_COLUMN = 1.6;

    /**
     * Entfernt senkrechte Leerstreifen, in denen in KEINER Generation ein Kasten steht.
     * Die Nachzentrierung kann verschraenkte Familien weit auseinanderschieben; weil jeder
     * Streifen in allen Generationen gleich breit entfernt wird, bleiben Eltern und Kinder
     * zueinander ausgerichtet -- nur der leere Raum dazwischen verschwindet.
     */
    private function close_empty_columns(): void
    {
        $xs = [];
        foreach ($this->nodes as $key => $node) {
            $xs[$key] = (float)$node['x'];
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
     * Vergibt x/y-Koordinaten (y = Generation, x in Knotenbreiten). Anders als frueher
     * werden Knoten nicht mehr gleichmaessig ueber die Generation verteilt, sondern:
     * Partner stehen direkt nebeneinander (eine "Einheit"), und Eltern werden ueber
     * ihrem Kinderblock zentriert -- die Voraussetzung dafuer, dass die Verbindungen
     * wie in einem echten Stammbaum aussehen.
     */
    private function assign_positions(array $structure): void
    {
        $this->layout_links = $structure['links'] ?? [];
        $this->primary_parents_of = [];
        foreach ($structure['families'] as $family) {
            if (empty($family['parents'])) {
                continue;
            }
            foreach ($family['children'] as $child_key) {
                $this->primary_parents_of[$child_key] = $this->primary_parents_of[$child_key] ?? $family['parents'];
            }
        }
        $units = $this->build_units($structure);
        $order = $this->order_units($units, $structure);
        $this->reduce_crossings($units, $order, $structure);
        $this->apply_unit_positions($units);
    }

    /**
     * Sucht eine Reihenfolge mit moeglichst wenigen Linienkreuzungen und hinterlaesst die
     * fertig zentrierten Einheiten in $units.
     *
     * 1. Einheiten ohne eigene Eltern im Baum (Familien-Kaesten, Stammeltern) haben beim
     *    zeilenweisen Sortieren von oben nach unten keinen Anhaltspunkt und landen am
     *    Zeilenende -- auch wenn ihr Kind weit links steht (Kasten "Nott" ganz rechts,
     *    Tochter Sophie ganz links). Fuer sie wird jeder Platz ihrer Zeile durchgerechnet.
     * 2. Danach werden nur die Einheiten, die an einer verbliebenen Kreuzung beteiligt sind,
     *    versuchsweise verschoben oder gespiegelt (Patchwork-Familien mit Stief- und
     *    Halbgeschwistern auf beiden Seiten). Eine Aenderung bleibt nur, wenn sie Kreuzungen
     *    einspart; die Suche endet nach CROSSING_BUDGET probeweise durchgerechneten Reihenfolgen.
     */
    private function reduce_crossings(array &$units, array $order, array $structure): void
    {
        $budget = self::CROSSING_BUDGET;
        $best = $this->evaluate_order($units, $order, $structure);

        // 1. Elternlose Eltern.
        $unit_of = self::unit_lookup($units);
        $rootless = [];
        foreach ($structure['families'] as $family) {
            foreach ($family['parents'] as $parent_key) {
                $unit_id = $unit_of[$parent_key] ?? null;
                if ($unit_id !== null && empty($units[$unit_id]['families'])) {
                    $rootless[$unit_id] = true;
                }
            }
        }
        $rootless = array_keys($rootless);
        // Von unten nach oben: tiefere Kaesten stehen fest, bevor ihre Vorfahren suchen.
        usort($rootless, function ($a, $b) use ($units) {
            return $units[$b]['generation'] <=> $units[$a]['generation'];
        });
        foreach ($rootless as $unit_id) {
            foreach ($this->slot_variants($best['order'], $unit_id, $units[$unit_id]['generation']) as $trial_order) {
                $trial = $this->evaluate_order($units, $trial_order, $structure);
                if ($trial['crossings'] < $best['crossings']
                    || ($trial['crossings'] === $best['crossings'] && $trial['length'] < $best['length'] - 0.5)) {
                    $best = $trial;
                }
            }
        }

        // 2. Verbliebene Kreuzungen: beteiligte Einheiten verschieben oder spiegeln.
        while ($best['crossings'] > 0 && $budget > 0) {
            $improved = false;
            $involved = array_keys($best['involved']);
            usort($involved, function ($a, $b) use ($units) {
                return $units[$a]['generation'] <=> $units[$b]['generation'];
            });
            foreach ($involved as $unit_id) {
                $candidates = [];
                if (count($units[$unit_id]['members']) > 1) {
                    $mirrored = $best['units'];
                    $mirrored[$unit_id]['members'] = array_reverse($mirrored[$unit_id]['members']);
                    $candidates[] = [$mirrored, $best['order']];
                }
                $generation = $units[$unit_id]['generation'];
                $has_lower = max(array_keys($best['order'])) > $generation;
                foreach ($this->slot_variants($best['order'], $unit_id, $generation, self::CROSSING_WINDOW) as $trial_order) {
                    $candidates[] = [$best['units'], $trial_order];
                    if ($has_lower) {
                        // Mit Kaskade: die Generationen darunter ordnen sich neu nach den
                        // verschobenen Eltern (sonst blieb deren Kinderreihe am alten Platz,
                        // und jeder einzelne Schritt sah zunaechst schlechter aus).
                        $candidates[] = [$best['units'], $trial_order, $generation];
                    }
                }
                foreach ($candidates as $candidate) {
                    [$trial_units, $trial_order] = $candidate;
                    if ($budget-- <= 0) {
                        break 3;
                    }
                    if (isset($candidate[2])) {
                        $fixed = array_filter($trial_order, function ($generation) use ($candidate) {
                            return $generation <= $candidate[2];
                        }, ARRAY_FILTER_USE_KEY);
                        $trial_order = $this->order_units($trial_units, $structure, $fixed);
                    }
                    $trial = $this->evaluate_order($trial_units, $trial_order, $structure);
                    if ($trial['crossings'] < $best['crossings']) {
                        $best = $trial;
                        $improved = true;
                        continue 3; // neu bewerten, welche Einheiten noch beteiligt sind
                    }
                }
            }
            if (!$improved) {
                break;
            }
        }

        $units = $this->straighten_single_children($best['units'], $best['order'], $best['crossings']);
    }

    /**
     * Einzelkinder genau unter ihren Elternstiel: steht ein einziges Kind nur ein paar Pixel
     * neben der Paarmitte, zeichnet das Frontend runter-quer-runter statt einer geraden Linie
     * -- ein Knick, der wie ein Zeichenfehler aussieht (Sophie unter dem Kasten "Nott").
     * Versucht wird, den Elternteil oder das Kind um genau diesen Versatz zu schieben (allein
     * oder mit den Nachbarn auf dieser Seite); uebernommen wird das nur, wenn die Mindestabstaende der Zeile halten, keine Kreuzung
     * hinzukommt und danach insgesamt weniger Knicke uebrig sind.
     */
    private function straighten_single_children(array $units, array $order, int $crossings): array
    {
        $position = [];
        foreach ($order as $sequence) {
            foreach ($sequence as $index => $unit_id) {
                $position[$unit_id] = $index;
            }
        }

        for ($pass = 0; $pass < 3; $pass++) {
            $jogs = $this->single_child_jogs($units);
            $improved = false;
            foreach ($jogs as [$parent_unit, $child_unit, $dx]) {
                // Erst die Einheit allein, dann samt allen Nachbarn auf der Seite, in die sie
                // rueckt (die Nachbarn weichen mit aus, ihre Abstaende bleiben gleich).
                $moves = [];
                foreach ([[$parent_unit, $dx], [$child_unit, -$dx]] as [$unit_id, $shift]) {
                    $sequence = $order[$units[$unit_id]['generation']];
                    $index = $position[$unit_id];
                    if (self::can_shift($units, $sequence, $index, $shift)) {
                        $moves[] = [[$unit_id], $shift];
                    }
                    $pushed = $shift > 0 ? array_slice($sequence, $index) : array_slice($sequence, 0, $index + 1);
                    if (count($pushed) > 1) {
                        $moves[] = [$pushed, $shift];
                    }
                }
                foreach ($moves as [$unit_ids, $shift]) {
                    $trial = $units;
                    foreach ($unit_ids as $unit_id) {
                        $trial[$unit_id]['x'] += $shift;
                    }
                    if (count($this->single_child_jogs($trial)) >= count($jogs)
                        || $this->count_crossings($trial)['crossings'] > $crossings) {
                        continue;
                    }
                    $units = $trial;
                    $improved = true;
                    continue 3; // Knicke neu ermitteln
                }
            }
            if (!$improved) {
                break;
            }
        }
        return $units;
    }

    /**
     * Einzelkinder, die nicht genau unter dem Stiel ihrer Eltern stehen: [Eltern-Einheit,
     * Kind-Einheit, Versatz in Knotenbreiten]. Nur Familien, deren Eltern in einer Einheit
     * stehen und die in der Zeile darunter genau ein Kind haben (auch ueber Zweitfamilien
     * mit denselben Eltern -- die haengen im Frontend am selben Balken).
     */
    private function single_child_jogs(array $units): array
    {
        $unit_of = self::unit_lookup($units);
        $offset = self::member_offsets($units);
        $x_of = function (string $key) use ($units, $unit_of, $offset): float {
            return $units[$unit_of[$key]]['x'] + $offset[$key];
        };

        $groups = [];
        foreach ($this->drawn['families'] ?? [] as $family) {
            if (empty($family['parents']) || !empty($family['either'])) {
                continue;
            }
            $parent_unit = $unit_of[$family['parents'][0]] ?? null;
            if ($parent_unit === null) {
                continue;
            }
            foreach ($family['parents'] as $parent_key) {
                if (($unit_of[$parent_key] ?? null) !== $parent_unit) {
                    continue 2;
                }
            }
            $parents = $family['parents'];
            sort($parents);
            $group_key = implode('|', $parents);
            $groups[$group_key]['parents'] = $family['parents'];
            $groups[$group_key]['unit'] = $parent_unit;
            foreach ($family['children'] as $child_key) {
                $child_unit = $unit_of[$child_key] ?? null;
                if ($child_unit !== null && $units[$child_unit]['generation'] === $units[$parent_unit]['generation'] + 1) {
                    $groups[$group_key]['children'][$child_key] = $child_unit;
                }
            }
        }

        $jogs = [];
        foreach ($groups as $group) {
            if (count($group['children'] ?? []) !== 1) {
                continue;
            }
            $xs = array_map($x_of, $group['parents']);
            $stem = (min($xs) + max($xs)) / 2;
            $child_key = (string)array_key_first($group['children']);
            $dx = $x_of($child_key) - $stem;
            if (abs($dx) > 0.001) {
                $jogs[] = [$group['unit'], $group['children'][$child_key], $dx];
            }
        }
        return $jogs;
    }

    /** Laesst sich $unit_id um $shift verschieben, ohne den Mindestabstand zu den Nachbarn zu unterschreiten? */
    private static function can_shift(array $units, array $sequence, int $index, float $shift): bool
    {
        $x = $units[$sequence[$index]]['x'] + $shift;
        if ($index > 0) {
            $left = $units[$sequence[$index - 1]];
            if ($x - $left['x'] < self::min_separation($left, $units[$sequence[$index]]) - 0.0001) {
                return false;
            }
        }
        if ($index < count($sequence) - 1) {
            $right = $units[$sequence[$index + 1]];
            if ($right['x'] - $x < self::min_separation($units[$sequence[$index]], $right) - 0.0001) {
                return false;
            }
        }
        return true;
    }

    /**
     * Reihenfolgen, in denen $unit_id an einer anderen Stelle seiner Generation steht --
     * naechstgelegene Plaetze zuerst, mit $window hoechstens so viele Plaetze entfernt.
     */
    private function slot_variants(array $order, string $unit_id, int $generation, ?int $window = null): array
    {
        $sequence = $order[$generation] ?? [];
        $current = array_search($unit_id, $sequence, true);
        if ($current === false || count($sequence) < 2) {
            return [];
        }
        $current = (int)$current;
        $rest = $sequence;
        array_splice($rest, $current, 1);
        $slots = range(0, count($rest));
        usort($slots, function ($a, $b) use ($current) {
            return [abs($a - $current), $a] <=> [abs($b - $current), $b];
        });
        $variants = [];
        foreach ($slots as $slot) {
            if ($slot === $current) {
                continue;
            }
            if ($window !== null && abs($slot - $current) > $window) {
                break;
            }
            $trial = $rest;
            array_splice($trial, $slot, 0, [$unit_id]);
            $variant = $order;
            $variant[$generation] = $trial;
            $variants[] = $variant;
        }
        return $variants;
    }

    /**
     * Reiht jede Generation in der gegebenen Reihenfolge auf, laesst die Zentrierung laufen
     * und bewertet das Ergebnis.
     *
     * @return array ['units', 'order', 'crossings', 'length', 'involved' => [unit_id => true]]
     */
    private function evaluate_order(array $units, array $order, array $structure): array
    {
        foreach ($order as $sequence) {
            $x = 0.0;
            foreach ($sequence as $position => $unit_id) {
                if ($position > 0) {
                    $x += self::min_separation($units[$sequence[$position - 1]], $units[$unit_id]);
                }
                $units[$unit_id]['x'] = $x;
            }
        }
        $this->relax_units($units, $order, $structure);
        return ['units' => $units, 'order' => $order] + $this->count_crossings($units);
    }

    // Masse der Zeichnung in relatives-tree.js (Pixel). Nur fuer die Bewertung -- bei
    // Aenderungen dort hier nachziehen, sonst zaehlt die Suche an der Zeichnung vorbei.
    private const DRAW_H_SPACING = 188;
    private const DRAW_V_SPACING = 140;
    private const DRAW_HALF_W = 85;
    private const DRAW_HALF_H = 29;
    private const DRAW_BUS_OFFSET = 32;
    private const DRAW_BUS_LANE_STEP = 12;
    private const DRAW_BUS_LANE_GAP = 24;
    private const DRAW_LOOSE_LANE = 26;
    private const DRAW_SAME_ROW_LANE = 14;
    private const DRAW_STRAIGHT_TOLERANCE = 32;

    /** Groesste Balkengruppe, deren Stapelreihenfolge exakt optimiert wird (wie LANE_ORDER_LIMIT im JS). */
    private const LANE_ORDER_LIMIT = 8;

    /**
     * Zaehlt Kreuzungen so, wie buildConnectorPaths() in relatives-tree.js zeichnet:
     * Partnerlinien, Familienbalken (Zweitfamilie mit denselben Eltern haengt am Balken der
     * Primaerfamilie, ueberlappende Balken einer Zeile bekommen eigene Hoehenstufen) und
     * lose Verbindungen. Eine Kreuzung ist ein senkrechtes Stueck, das ein waagerechtes
     * Stueck einer ANDEREN Linie im Inneren schneidet.
     *
     * @return array ['crossings' => int, 'length' => float (Balkenlaenge in Knotenbreiten),
     *                'involved' => [unit_id => true]]
     */
    private function count_crossings(array $units): array
    {
        $unit_of = self::unit_lookup($units);
        // Gezeichnet wird nach close_empty_columns() -- gleiche Positionen wie im Frontend.
        $offset = self::member_offsets($units);
        $xs = [];
        foreach ($unit_of as $key => $unit_id) {
            $xs[$key] = $units[$unit_id]['x'] + $offset[$key];
        }
        $xs = self::closed_columns($xs);
        $point = function (string $key) use ($units, $unit_of, $xs): ?array {
            $unit_id = $unit_of[$key] ?? null;
            if ($unit_id === null) {
                return null;
            }
            return [
                $xs[$key] * self::DRAW_H_SPACING,
                $units[$unit_id]['generation'] * self::DRAW_V_SPACING,
            ];
        };

        $horizontal = []; // [left, right, y, owner]
        $vertical = [];   // [x, y1, y2, owner]
        $owners = [];     // owner => beteiligte Knoten

        // Partnerlinien: eingetragene Paare und nebeneinanderstehende Eltern einer Familie.
        $pairs = [];
        foreach ($this->drawn['partners'] ?? [] as $pair) {
            $pairs[self::pair_key($pair['a'], $pair['b'])] = [$pair['a'], $pair['b']];
        }
        foreach ($this->drawn['families'] ?? [] as $family) {
            $parents = array_values(array_filter($family['parents'], function ($key) use ($point) {
                return $point($key) !== null;
            }));
            usort($parents, function ($a, $b) use ($point) {
                return $point($a)[0] <=> $point($b)[0];
            });
            for ($i = 1, $count = count($parents); $i < $count; $i++) {
                $pairs[self::pair_key($parents[$i - 1], $parents[$i])] = [$parents[$i - 1], $parents[$i]];
            }
        }
        $elbows = []; // Paare ueber zwei Generationen: rechtwinklig wie lose Verbindungen
        foreach ($pairs as $owner => [$a, $b]) {
            $pa = $point($a);
            $pb = $point($b);
            if ($pa === null || $pb === null) {
                continue;
            }
            if ($pa[1] !== $pb[1]) {
                $elbows['p' . $owner] = [$a, $b];
                continue;
            }
            $left = min($pa[0], $pb[0]) + self::DRAW_HALF_W;
            $right = max($pa[0], $pb[0]) - self::DRAW_HALF_W;
            if ($right - $left > 1) {
                $horizontal[] = [$left, $right, $pa[1], 'p' . $owner];
                $owners['p' . $owner] = [$a, $b];
            }
        }

        // Familienbalken: Primaerfamilien zuerst, damit Zweitfamilien sich anhaengen koennen.
        $families = array_merge(
            array_filter($this->drawn['families'] ?? [], static function ($family) {
                return ($family['primary'] ?? true) !== false;
            }),
            array_filter($this->drawn['families'] ?? [], static function ($family) {
                return ($family['primary'] ?? true) === false;
            })
        );
        $buses = [];
        $bus_by_parents = [];
        foreach ($families as $family_index => $family) {
            $parents = array_values(array_filter($family['parents'], function ($key) use ($point) {
                return $point($key) !== null;
            }));
            $rows = [];
            foreach ($family['children'] as $child_key) {
                $p = $point($child_key);
                if ($p !== null) {
                    $rows[(string)$p[1]][] = ['x' => $p[0], 'bottom' => $p[1] - self::DRAW_HALF_H, 'key' => $child_key];
                }
            }
            foreach ($family['either'] ?? [] as $group) {
                $points = array_map($point, $group);
                if (in_array(null, $points, true)) {
                    continue;
                }
                $xs = array_column($points, 0);
                $rows[(string)$points[0][1]][] = ['x' => (min($xs) + max($xs)) / 2, 'bottom' => $points[0][1], 'key' => $group[0]];
            }
            foreach ($rows as $row_key => $stubs) {
                $child_y = (float)$row_key;
                $child_top = $child_y - self::DRAW_HALF_H;
                $origin_x = null;
                $origin_y = null;
                if ($parents) {
                    $parent_points = array_map($point, $parents);
                    $xs = array_column($parent_points, 0);
                    $origin_x = (min($xs) + max($xs)) / 2;
                    $origin_y = max(array_column($parent_points, 1)) + (count($parents) === 1 ? self::DRAW_HALF_H : 0);
                }
                if ($origin_y !== null && $origin_y >= $child_top - 4) {
                    $origin_x = $origin_y = null;
                }
                if ($origin_x === null && count($stubs) < 2) {
                    continue;
                }
                $keys = array_merge($parents, array_column($stubs, 'key'));
                $parents_key = null;
                if ($origin_x !== null) {
                    $sorted = $parents;
                    sort($sorted);
                    $parents_key = implode('|', $sorted) . '@' . $row_key;
                }
                if ($parents_key !== null && isset($bus_by_parents[$parents_key]) && ($family['primary'] ?? true) === false) {
                    $shared = $bus_by_parents[$parents_key];
                    foreach ($stubs as $stub) {
                        $buses[$shared]['stubs'][] = $stub;
                        $buses[$shared]['left'] = min($buses[$shared]['left'], $stub['x']);
                        $buses[$shared]['right'] = max($buses[$shared]['right'], $stub['x']);
                    }
                    $owners['b' . $shared] = array_merge($owners['b' . $shared], $keys);
                    continue;
                }
                $xs = array_column($stubs, 'x');
                $left = min($xs);
                $right = max($xs);
                if ($origin_x !== null) {
                    $left = min($left, $origin_x);
                    $right = max($right, $origin_x);
                }
                $index = count($buses);
                $buses[] = [
                    'row' => $child_y, 'top' => $child_top, 'stubs' => $stubs,
                    'origin_x' => $origin_x, 'origin_y' => $origin_y,
                    'left' => $left, 'right' => $right, 'lane' => 0,
                ];
                $owners['b' . $index] = $keys;
                if ($parents_key !== null && !isset($bus_by_parents[$parents_key])) {
                    $bus_by_parents[$parents_key] = $index;
                }
            }
        }

        $straight = static function (array $bus): bool {
            return $bus['origin_x'] !== null && count($bus['stubs']) === 1
                && abs($bus['stubs'][0]['x'] - $bus['origin_x']) <= self::DRAW_STRAIGHT_TOLERANCE;
        };
        $lane_rows = [];
        foreach ($buses as $index => $bus) {
            if (!$straight($bus)) {
                $lane_rows[(string)$bus['row']][] = $index;
            }
        }
        // Wie assignBusLanes(): Gruppen sich ueberschneidender Balken; in der Stapelreihenfolge
        // (stack_order()) liegt jeder Balken ueber allen frueheren, die er beruehrt.
        foreach ($lane_rows as $indexes) {
            usort($indexes, function ($a, $b) use ($buses) {
                return [$buses[$a]['right'] - $buses[$a]['left'], $buses[$a]['left']]
                    <=> [$buses[$b]['right'] - $buses[$b]['left'], $buses[$b]['left']];
            });
            $groups = [];
            foreach ($indexes as $index) {
                $joined = null;
                foreach ($groups as $group_index => $group) {
                    $touches = false;
                    foreach ($group as $other) {
                        if (self::buses_overlap($buses[$other], $buses[$index])) {
                            $touches = true;
                            break;
                        }
                    }
                    if (!$touches) {
                        continue;
                    }
                    if ($joined === null) {
                        $joined = $group_index;
                    } else {
                        $groups[$joined] = array_merge($groups[$joined], $group);
                        unset($groups[$group_index]);
                    }
                }
                if ($joined === null) {
                    $groups[] = [$index];
                } else {
                    $groups[$joined][] = $index;
                }
            }
            $rank = array_flip($indexes);
            foreach ($groups as $group) {
                usort($group, function ($a, $b) use ($rank) {
                    return $rank[$a] <=> $rank[$b];
                });
                $placed = [];
                foreach (self::stack_order($buses, $group) as $index) {
                    $lane = 0;
                    foreach ($placed as $other) {
                        if (self::buses_overlap($buses[$other], $buses[$index])) {
                            $lane = max($lane, $buses[$other]['lane'] + 1);
                        }
                    }
                    $buses[$index]['lane'] = $lane;
                    $placed[] = $index;
                }
            }
        }

        $length = 0.0;
        foreach ($buses as $index => $bus) {
            $owner = 'b' . $index;
            if ($straight($bus)) {
                $vertical[] = [$bus['origin_x'], $bus['origin_y'], $bus['stubs'][0]['bottom'], $owner];
                continue;
            }
            $lane_y = $bus['top'] - self::DRAW_BUS_OFFSET - $bus['lane'] * self::DRAW_BUS_LANE_STEP;
            $bus_y = $bus['origin_y'] === null ? $lane_y : max($bus['origin_y'] + 8, $lane_y);
            if ($bus['origin_x'] !== null) {
                $vertical[] = [$bus['origin_x'], $bus['origin_y'], $bus_y, $owner];
            }
            $left = $bus['left'];
            $right = $bus['right'];
            foreach ($bus['stubs'] as $stub) {
                $left = min($left, $stub['x']);
                $right = max($right, $stub['x']);
                $vertical[] = [$stub['x'], $bus_y, $stub['bottom'], $owner];
            }
            if ($right - $left > 1) {
                $horizontal[] = [$left, $right, $bus_y, $owner];
            }
            $length += ($right - $left) / self::DRAW_H_SPACING;
        }

        // Lose Verbindungen und Paare ueber zwei Generationen (rechtwinklig, siehe elbowPath()).
        $loose = $elbows;
        foreach ($this->drawn['links'] ?? [] as $index => $link) {
            $loose['l' . $index] = [$link['from'], $link['to']];
        }
        foreach ($loose as $owner => [$from, $to]) {
            $a = $point($from);
            $b = $point($to);
            if ($a === null || $b === null) {
                continue;
            }
            $owners[$owner] = [$from, $to];
            if ($a[1] === $b[1]) {
                if (abs($a[0] - $b[0]) <= 1) {
                    continue;
                }
                $lane_y = $a[1] - self::DRAW_HALF_H - self::DRAW_SAME_ROW_LANE;
                $vertical[] = [$a[0], $a[1] - self::DRAW_HALF_H, $lane_y, $owner];
                $horizontal[] = [min($a[0], $b[0]), max($a[0], $b[0]), $lane_y, $owner];
                $vertical[] = [$b[0], $lane_y, $b[1] - self::DRAW_HALF_H, $owner];
                continue;
            }
            [$top, $bottom] = $a[1] < $b[1] ? [$a, $b] : [$b, $a];
            $mid_y = ($top[1] + self::DRAW_HALF_H + $bottom[1] - self::DRAW_HALF_H) / 2 + self::DRAW_LOOSE_LANE;
            $vertical[] = [$top[0], $top[1] + self::DRAW_HALF_H, $mid_y, $owner];
            $horizontal[] = [min($top[0], $bottom[0]), max($top[0], $bottom[0]), $mid_y, $owner];
            $vertical[] = [$bottom[0], $mid_y, $bottom[1] - self::DRAW_HALF_H, $owner];
        }

        $crossings = 0;
        $involved = [];
        foreach ($vertical as [$x, $y1, $y2, $v_owner]) {
            $low = min($y1, $y2);
            $high = max($y1, $y2);
            foreach ($horizontal as [$left, $right, $y, $h_owner]) {
                if ($v_owner === $h_owner || $x <= $left + 1 || $x >= $right - 1 || $y <= $low + 1 || $y >= $high - 1) {
                    continue;
                }
                $crossings++;
                foreach (array_merge($owners[$v_owner] ?? [], $owners[$h_owner] ?? []) as $key) {
                    if (isset($unit_of[$key])) {
                        $involved[$unit_of[$key]] = true;
                    }
                }
            }
        }

        return ['crossings' => $crossings, 'length' => $length, 'involved' => $involved];
    }

    /** Zwei Balken derselben Zeile liegen zu nah beieinander fuer dieselbe Hoehenstufe (wie busesOverlap()). */
    private static function buses_overlap(array $a, array $b): bool
    {
        return $a['left'] <= $b['right'] + self::DRAW_BUS_LANE_GAP && $b['left'] <= $a['right'] + self::DRAW_BUS_LANE_GAP;
    }

    /** Wie stackCost(): Kreuzungen, wenn Balken $low unter Balken $high liegt. */
    private static function stack_cost(array $low, array $high): int
    {
        $inside = static function (float $x, array $bus): bool {
            return $x > $bus['left'] + 1 && $x < $bus['right'] - 1;
        };
        $cost = 0;
        foreach ($high['stubs'] as $stub) {
            if ($inside($stub['x'], $low)) {
                $cost++;
            }
        }
        if ($low['origin_x'] !== null && $inside($low['origin_x'], $high)) {
            $cost++;
        }
        return $cost;
    }

    /**
     * Wie stackOrder(): Stapelreihenfolge mit den wenigsten Kreuzungen (Teilmengen-DP,
     * exakt bis LANE_ORDER_LIMIT Balken), bei Gleichstand der zuerst gefundene Weg.
     * $group ist bereits schmal-zuerst sortiert.
     */
    private static function stack_order(array $buses, array $group): array
    {
        $n = count($group);
        if ($n <= 1 || $n > self::LANE_ORDER_LIMIT) {
            return $group;
        }
        $pair = [];
        foreach ($group as $i => $low) {
            foreach ($group as $j => $high) {
                $pair[$i][$j] = $i === $j || !self::buses_overlap($buses[$low], $buses[$high])
                    ? 0 : self::stack_cost($buses[$low], $buses[$high]);
            }
        }
        $full = (1 << $n) - 1;
        $cost = [0 => 0];
        $prev = [];
        $last = [];
        for ($mask = 0; $mask < $full; $mask++) {
            if (!isset($cost[$mask])) {
                continue;
            }
            for ($j = 0; $j < $n; $j++) {
                if ($mask & (1 << $j)) {
                    continue;
                }
                $total = $cost[$mask];
                for ($i = 0; $i < $n; $i++) {
                    if ($mask & (1 << $i)) {
                        $total += $pair[$i][$j];
                    }
                }
                $next = $mask | (1 << $j);
                if (!isset($cost[$next]) || $total < $cost[$next]) {
                    $cost[$next] = $total;
                    $prev[$next] = $mask;
                    $last[$next] = $j;
                }
            }
        }
        $order = [];
        for ($mask = $full; $mask; $mask = $prev[$mask]) {
            array_unshift($order, $group[$last[$mask]]);
        }
        return $order;
    }

    /**
     * Fasst Partner (und gemeinsame Eltern einer Familie) derselben Generation zu einer
     * Einheit zusammen, die im Layout nie auseinandergerissen wird.
     */
    private function build_units(array $structure): array
    {
        $unit_uf = [];
        foreach (array_keys($this->nodes) as $key) {
            $unit_uf[$key] = $key;
        }
        $this->unit_pairs = [];
        foreach ($structure['partners'] as $pair) {
            if ($this->nodes[$pair['a']]['generation'] === $this->nodes[$pair['b']]['generation']) {
                self::uf_union($unit_uf, $pair['a'], $pair['b']);
                $this->unit_pairs[$pair['a']][$pair['b']] = true;
                $this->unit_pairs[$pair['b']][$pair['a']] = true;
            }
        }
        foreach ($structure['families'] as $family) {
            $previous = null;
            foreach ($family['parents'] as $parent_key) {
                if ($previous !== null
                    && $this->nodes[$previous]['generation'] === $this->nodes[$parent_key]['generation']) {
                    self::uf_union($unit_uf, $previous, $parent_key);
                    $this->unit_pairs[$previous][$parent_key] = true;
                    $this->unit_pairs[$parent_key][$previous] = true;
                }
                $previous = $parent_key;
            }
        }

        $units = [];
        foreach ($this->nodes as $key => $node) {
            $unit_id = self::uf_find($unit_uf, $key);
            if (!isset($units[$unit_id])) {
                $units[$unit_id] = [
                    'id'         => $unit_id,
                    'members'    => [],
                    'generation' => (int)$node['generation'],
                    'family'     => null,
                    'families'   => [],
                    'x'          => 0.0,
                ];
            }
            $units[$unit_id]['members'][] = $key;
        }
        foreach ($units as $unit_id => $unit) {
            $units[$unit_id]['members'] = $this->chain_order($unit['members']);
        }

        // Herkunftsfamilie(n) je Einheit merken (steuert den Abstand: Geschwister ruecken
        // eng zusammen, verschiedene Familien bekommen mehr Luft). Ein Paar kann zu zwei
        // Familien gehoeren -- Mama zu ihren Geschwistern, Papa zu seinen.
        $unit_of = self::unit_lookup($units);
        foreach ($structure['families'] as $family) {
            foreach ($family['children'] as $child_key) {
                $child_unit = $unit_of[$child_key] ?? null;
                if ($child_unit === null) {
                    continue;
                }
                if ($units[$child_unit]['family'] === null) {
                    $units[$child_unit]['family'] = $family['id'];
                }
                $units[$child_unit]['families'][$family['id']] = true;
            }
        }

        return $units;
    }

    /** Mindestabstand zwischen den Mittelpunkten zweier benachbarter Einheiten. */
    private static function min_separation(array $left, array $right): float
    {
        $same_family = !empty(array_intersect_key($left['families'], $right['families']));
        return (count($left['members']) - 1) / 2
            + 1 + ($same_family ? self::SIBLING_GAP : self::UNIT_GAP)
            + (count($right['members']) - 1) / 2;
    }

    /**
     * Reihenfolge innerhalb jeder Generation (von oben nach unten), so dass jede
     * Geschwistergruppe zusammenhaengend steht und Familien der Reihenfolge ihrer Eltern
     * folgen. Paare, deren Partner aus zwei verschiedenen Familien stammen, bilden die
     * Nahtstelle: Mamas Geschwister | Mama, Papa | Papas Geschwister. Frueher wurde nur
     * nach Anlage-Reihenfolge sortiert -- dann standen Papas Geschwister zwischen Mamas
     * Geschwistern, und die beiden Geschwisterbalken lagen uebereinander wie ein einziger.
     *
     * Liefert [generation => [unit_id, ...]] plus initiale x-Positionen; die
     * Mitglieder-Reihenfolge innerhalb der Einheiten wird dabei passend ausgerichtet.
     */
    private function order_units(array &$units, array $structure, array $fixed = []): array
    {
        $unit_of = self::unit_lookup($units);

        $family_of_node = [];
        $parents_of_family = [];
        foreach ($structure['families'] as $family) {
            $parents_of_family[$family['id']] = $family['parents'];
            foreach ($family['children'] as $child_key) {
                if (!isset($family_of_node[$child_key])) {
                    $family_of_node[$child_key] = $family['id'];
                }
            }
        }

        $insertion_index = [];
        $index = 0;
        foreach (array_keys($this->nodes) as $key) {
            $insertion_index[$key] = $index++;
        }

        $by_generation = [];
        foreach ($units as $unit_id => $unit) {
            $by_generation[$unit['generation']][] = $unit_id;
        }
        ksort($by_generation);

        $placed = [];
        $order = [];
        foreach ($by_generation as $generation => $unit_ids) {
            // $fixed: vorgegebene Reihenfolgen (Kreuzungssuche) -- nur die Generationen
            // darunter werden neu abgeleitet.
            $sequence = $fixed[$generation]
                ?? $this->sequence_generation($units, $unit_ids, $unit_of, $family_of_node, $parents_of_family, $insertion_index, $placed);

            $x = 0.0;
            foreach ($sequence as $position => $unit_id) {
                if ($position > 0) {
                    $x += self::min_separation($units[$sequence[$position - 1]], $units[$unit_id]);
                }
                $units[$unit_id]['x'] = $x;
                $placed[$unit_id] = true;
            }
            $order[$generation] = $sequence;
        }

        return $order;
    }

    /**
     * Reihenfolge einer Generation: Familien, die ueber Paare miteinander verbunden sind,
     * bilden einen zusammenhaengenden Block, der als Kette abgelaufen wird (siehe
     * append_family()). Bloecke folgen der Position ihrer Eltern, dann der
     * Anlage-Reihenfolge; Einheiten ganz ohne Familie haengen sich hinten an (die
     * Relaxation zieht lose Verbindungen danach noch zu ihrem Anker).
     */
    private function sequence_generation(array &$units, array $unit_ids, array $unit_of, array $family_of_node, array $parents_of_family, array $insertion_index, array $placed): array
    {
        $ctx = [
            'family_of_node'    => $family_of_node,
            'insertion_index'   => $insertion_index,
            'families_of_unit'  => [],
            'units_of_family'   => [],
            'unit_rank'         => [],
            'family_sort'       => [],
        ];

        foreach ($unit_ids as $unit_id) {
            $rank = PHP_INT_MAX;
            $ctx['families_of_unit'][$unit_id] = [];
            foreach ($units[$unit_id]['members'] as $member) {
                $rank = min($rank, $insertion_index[$member] ?? PHP_INT_MAX);
                $family_id = $family_of_node[$member] ?? null;
                if ($family_id !== null) {
                    $ctx['families_of_unit'][$unit_id][$family_id] = true;
                    $ctx['units_of_family'][$family_id][$unit_id] = true;
                }
            }
            $ctx['unit_rank'][$unit_id] = $rank;
        }

        // Sortierschluessel je Familie: mittlere x-Position der bereits platzierten
        // Eltern, danach die frueheste Anlage eines ihrer Mitglieder.
        foreach ($ctx['units_of_family'] as $family_id => $family_units) {
            $parent_xs = [];
            foreach ($parents_of_family[$family_id] ?? [] as $parent_key) {
                $parent_unit = $unit_of[$parent_key] ?? null;
                if ($parent_unit !== null && isset($placed[$parent_unit])) {
                    $parent_xs[] = self::member_x($units[$parent_unit], $parent_key);
                }
            }
            $rank = PHP_INT_MAX;
            foreach (array_keys($family_units) as $unit_id) {
                $rank = min($rank, $ctx['unit_rank'][$unit_id]);
            }
            $ctx['family_sort'][$family_id] = [
                empty($parent_xs) ? INF : array_sum($parent_xs) / count($parent_xs),
                $rank,
            ];
        }

        // Familien, die ueber ein Paar verbunden sind, zu Bloecken zusammenfassen.
        $family_uf = [];
        foreach (array_keys($ctx['units_of_family']) as $family_id) {
            $family_uf[$family_id] = $family_id;
        }
        foreach ($ctx['families_of_unit'] as $unit_families) {
            $family_ids = array_keys($unit_families);
            for ($i = 1, $count = count($family_ids); $i < $count; $i++) {
                self::uf_union($family_uf, $family_ids[0], $family_ids[$i]);
            }
        }
        $clusters = [];
        foreach (array_keys($family_uf) as $family_id) {
            $clusters[self::uf_find($family_uf, $family_id)][] = $family_id;
        }

        $family_sort = $ctx['family_sort'];
        $by_family_sort = function ($a, $b) use ($family_sort) {
            return $family_sort[$a] <=> $family_sort[$b];
        };

        $blocks = [];
        $placed_units = [];
        foreach ($clusters as $family_ids) {
            usort($family_ids, $by_family_sort);

            // Kette an einem Ende beginnen (Familie mit hoechstens einer Paar-Verbindung).
            $start = $family_ids[0];
            foreach ($family_ids as $family_id) {
                $link_count = 0;
                foreach (array_keys($ctx['units_of_family'][$family_id]) as $unit_id) {
                    if (count($ctx['families_of_unit'][$unit_id]) > 1) {
                        $link_count++;
                    }
                }
                if ($link_count <= 1) {
                    $start = $family_id;
                    break;
                }
            }

            $sequence = [];
            $visited = [];
            $this->append_family($units, $ctx, $start, null, $sequence, $visited, $placed_units);
            foreach ($family_ids as $family_id) {
                if (!isset($visited[$family_id])) { // Sicherheitsnetz, sollte nicht vorkommen
                    $this->append_family($units, $ctx, $family_id, null, $sequence, $visited, $placed_units);
                }
            }
            $blocks[] = ['sort' => $family_sort[$family_ids[0]], 'units' => $sequence];
        }

        // Einheiten ohne Familie (Adoptivgeschwister, lose verbundene Cousinen ...) stehen
        // neben der Person, mit der sie verbunden sind -- frueher kamen sie ans Zeilenende
        // und die Verbindungslinie lief quer ueber den ganzen Baum.
        foreach ($unit_ids as $unit_id) {
            if (!empty($ctx['families_of_unit'][$unit_id])) {
                continue;
            }
            $sort = [INF, $ctx['unit_rank'][$unit_id]];
            foreach ($units[$unit_id]['members'] as $member) {
                foreach ($this->layout_links as $link) {
                    $other = $link['from'] === $member ? $link['to'] : ($link['to'] === $member ? $link['from'] : null);
                    if ($other === null || !isset($unit_of[$other])) {
                        continue;
                    }
                    $other_unit = $unit_of[$other];
                    if (isset($placed[$other_unit])) {
                        // Anker in einer bereits platzierten Generation: unter ihn.
                        $candidate = [self::member_x($units[$other_unit], $other), $ctx['unit_rank'][$unit_id]];
                    } elseif (!empty($ctx['families_of_unit'][$other_unit])) {
                        // Anker in derselben Generation: direkt hinter seine Familie.
                        $family_id = (string)array_key_first($ctx['families_of_unit'][$other_unit]);
                        $candidate = [$ctx['family_sort'][$family_id][0], $ctx['family_sort'][$family_id][1] + 0.5];
                    } else {
                        continue;
                    }
                    if ($candidate < $sort) {
                        $sort = $candidate;
                    }
                }
            }
            $blocks[] = ['sort' => $sort, 'units' => [$unit_id]];
        }

        usort($blocks, function ($a, $b) {
            return $a['sort'] <=> $b['sort'];
        });

        $sequence = [];
        foreach ($blocks as $block) {
            foreach ($block['units'] as $unit_id) {
                $sequence[] = $unit_id;
            }
        }
        $sequence = $this->place_beside_coparents($sequence, $unit_of, $ctx['families_of_unit']);
        $sequence = $this->align_secondary_children($units, $sequence, $unit_of, $ctx['families_of_unit'], $ctx['family_of_node'], $placed);
        return $this->place_floating_beside_anchor($sequence, $unit_of, $ctx['families_of_unit']);
    }

    /**
     * Lose verbundene Knoten derselben Zeile (Freund*innen, entfernte Verwandte ...) stehen
     * direkt neben ihrem Anker, und der Anker am Rand seines Geschwisterblocks. Die lose
     * Linie laeuft knapp ueber den Kastenoberkanten -- stand der Anker mitten im Block,
     * schnitt sie jede Stichleitung seiner Geschwister (James Potter zu Mason Reed quer
     * ueber Albus und Lily).
     */
    private function place_floating_beside_anchor(array $sequence, array $unit_of, array $families_of_unit): array
    {
        $floats_of = [];
        foreach ($this->floating_anchor as $floating => $anchor) {
            $float_unit = $unit_of[$floating] ?? null;
            $anchor_unit = $unit_of[$anchor] ?? null;
            if ($float_unit === null || $anchor_unit === null || $float_unit === $anchor_unit
                || !in_array($float_unit, $sequence, true) || !in_array($anchor_unit, $sequence, true)) {
                continue;
            }
            $floats_of[$anchor_unit][$float_unit] = true;
        }

        foreach ($floats_of as $anchor_unit => $float_units) {
            $rest = array_values(array_filter($sequence, function ($unit_id) use ($float_units) {
                return !isset($float_units[$unit_id]);
            }));
            $floats = array_values(array_filter($sequence, function ($unit_id) use ($float_units) {
                return isset($float_units[$unit_id]);
            }));

            // Geschwisterblock des Ankers (ohne die losen Knoten, die selbst zur Familie zaehlen).
            $family_ids = $families_of_unit[$anchor_unit] ?? [];
            $in_block = function (int $position) use ($rest, $families_of_unit, $family_ids): bool {
                return isset($rest[$position])
                    && array_intersect_key($families_of_unit[$rest[$position]] ?? [], $family_ids);
            };
            $index = (int)array_search($anchor_unit, $rest, true);
            $start = $index;
            while ($in_block($start - 1)) {
                $start--;
            }
            $end = $index;
            while ($in_block($end + 1)) {
                $end++;
            }

            // Ein Rand, an dem ein Paar zur Nachbarfamilie steht, bleibt fuer dieses Paar frei.
            $is_seam = function (int $position) use ($rest, $families_of_unit): bool {
                return count($families_of_unit[$rest[$position]] ?? []) > 1;
            };
            $right_free = $end === $index || !$is_seam($end);
            $left_free = $start === $index || !$is_seam($start);
            if (!$right_free && !$left_free) {
                continue;
            }
            $to_right = $right_free && (!$left_free || $end - $index <= $index - $start);

            array_splice($rest, $index, 1);
            if ($to_right) {
                $end--;
                array_splice($rest, $end + 1, 0, array_merge([$anchor_unit], $floats));
            } else {
                array_splice($rest, $start, 0, array_merge(array_reverse($floats), [$anchor_unit]));
            }
            $sequence = $rest;
        }
        return $sequence;
    }

    /**
     * Ein Kind mit Zweitfamilie steht am Rand seines Geschwisterblocks, und zwar an dem,
     * der seinen Zweit-Eltern zugewandt ist. Sonst liefe die Adoptivlinie ueber die
     * Kaesten der eigenen Geschwister hinweg. Innerhalb des Blocks bleibt der
     * Geschwisterbalken dabei unveraendert zusammenhaengend.
     *
     * Hat ein Kind mehrere Zweitfamilien (Louisa: Adoptiveltern UND Stiefvater), entscheidet
     * die am weitesten entfernte -- deren Linie waere sonst die laengste.
     */
    private function align_secondary_children(array $units, array $sequence, array $unit_of, array $families_of_unit, array $family_of_node, array $placed): array
    {
        $pull = [];
        foreach ($this->secondary_families as $family) {
            $secondary_x = $this->placed_center($units, $unit_of, $family['parents'], $placed);
            if ($secondary_x === null) {
                continue;
            }
            foreach ($family['children'] as $child_key) {
                $primary_x = $this->placed_center($units, $unit_of, $this->primary_parents_of[$child_key] ?? [], $placed);
                if ($primary_x === null) {
                    continue;
                }
                $offset = $secondary_x - $primary_x;
                if (!isset($pull[$child_key]) || abs($offset) > abs($pull[$child_key])) {
                    $pull[$child_key] = $offset;
                }
            }
        }

        foreach ($pull as $child_key => $offset) {
            $child_unit = $unit_of[$child_key] ?? null;
            $primary_family = $family_of_node[$child_key] ?? null;
            $index = $child_unit !== null ? array_search($child_unit, $sequence, true) : false;
            if ($index === false || $primary_family === null || abs($offset) < 0.01) {
                continue;
            }

            $in_family = function (int $position) use ($sequence, $families_of_unit, $primary_family): bool {
                return isset($sequence[$position], $families_of_unit[$sequence[$position]][$primary_family]);
            };
            $start = $index;
            while ($in_family($start - 1)) {
                $start--;
            }
            $end = $index;
            while ($in_family($end + 1)) {
                $end++;
            }

            array_splice($sequence, $index, 1);
            array_splice($sequence, $offset < 0 ? $start : $end, 0, [$child_unit]);
        }
        return $sequence;
    }

    /** Mittlere x-Position der Knoten, deren Einheit bereits platziert ist (sonst null). */
    private function placed_center(array $units, array $unit_of, array $keys, array $placed): ?float
    {
        $xs = [];
        foreach ($keys as $key) {
            $unit_id = $unit_of[$key] ?? null;
            if ($unit_id !== null && isset($placed[$unit_id])) {
                $xs[] = self::member_x($units[$unit_id], $key);
            }
        }
        return $xs ? array_sum($xs) / count($xs) : null;
    }

    /**
     * Zweit-Eltern (Adoptiv-, Stief-, Pflegeeltern) ohne eigene Herkunftsfamilie im Baum
     * stuenden sonst am Zeilenende, und die Adoptivlinie liefe quer ueber den ganzen Baum
     * (Adam und Judith Griffin standen neben Chelsea, weit weg von Louisa). Sie ruecken
     * direkt neben die Primaereltern des Kindes -- auf die Seite, auf der kein
     * Geschwisterteil dieser Eltern steht, damit deren Geschwisterbalken nicht zerreisst.
     */
    private function place_beside_coparents(array $sequence, array $unit_of, array $families_of_unit): array
    {
        foreach ($this->secondary_families as $family) {
            $moving = [];
            foreach ($family['parents'] as $parent_key) {
                $unit_id = $unit_of[$parent_key] ?? null;
                if ($unit_id !== null && in_array($unit_id, $sequence, true) && empty($families_of_unit[$unit_id])) {
                    $moving[$unit_id] = true;
                }
            }
            if (!$moving) {
                continue;
            }

            $anchor = null;
            foreach ($family['children'] as $child_key) {
                foreach ($this->primary_parents_of[$child_key] ?? [] as $parent_key) {
                    $unit_id = $unit_of[$parent_key] ?? null;
                    if ($unit_id !== null && !isset($moving[$unit_id]) && in_array($unit_id, $sequence, true)) {
                        $anchor = $unit_id;
                        break 2;
                    }
                }
            }
            if ($anchor === null) {
                continue;
            }

            $moving_ids = array_values(array_filter($sequence, function ($unit_id) use ($moving) {
                return isset($moving[$unit_id]);
            }));
            $rest = array_values(array_filter($sequence, function ($unit_id) use ($moving) {
                return !isset($moving[$unit_id]);
            }));
            $index = (int)array_search($anchor, $rest, true);
            $left = $index > 0 ? $rest[$index - 1] : null;
            $right = $index < count($rest) - 1 ? $rest[$index + 1] : null;
            $shares_family = function (?string $other) use ($anchor, $families_of_unit): bool {
                return $other !== null
                    && !empty(array_intersect_key($families_of_unit[$anchor] ?? [], $families_of_unit[$other] ?? []));
            };
            $before = $left === null
                ? $right !== null
                : ($right !== null && $shares_family($right) && !$shares_family($left));

            array_splice($rest, $before ? $index : $index + 1, 0, $moving_ids);
            $sequence = $rest;
        }
        return $sequence;
    }

    /**
     * Haengt eine Familie an die Sequenz an. $entry_unit ist das Paar, ueber das die
     * Familie erreicht wurde -- es steht bereits links davon. Aufbau von links nach rechts:
     *   [angeheiratetes Paar, Partner*in aussen] [Geschwister] [angeheiratete Paare,
     *   Partner*in aussen] [Paar zur naechsten Familie] [naechste Familie ...]
     * So endet jeder Geschwisterbalken an der Paar-Nahtstelle, statt ueber die
     * Geschwister der anderen Seite hinwegzulaufen.
     */
    private function append_family(array &$units, array $ctx, string $family_id, ?string $entry_unit, array &$sequence, array &$visited, array &$placed_units): void
    {
        $visited[$family_id] = true;

        $own = [];
        $married = [];
        $links = [];
        foreach (array_keys($ctx['units_of_family'][$family_id]) as $unit_id) {
            if ($unit_id === $entry_unit || isset($placed_units[$unit_id])) {
                continue;
            }
            if (count($ctx['families_of_unit'][$unit_id]) > 1) {
                $links[] = $unit_id;
                continue;
            }
            $has_outsider = false;
            foreach ($units[$unit_id]['members'] as $member) {
                if (!isset($ctx['family_of_node'][$member])) {
                    $has_outsider = true;
                }
            }
            if ($has_outsider) {
                $married[] = $unit_id;
            } else {
                $own[] = $unit_id;
            }
        }

        $unit_rank = $ctx['unit_rank'];
        $by_rank = function ($a, $b) use ($unit_rank) {
            return $unit_rank[$a] <=> $unit_rank[$b];
        };
        usort($own, $by_rank);
        usort($married, $by_rank);

        // Verbindungen zu anderen Familien nach deren Sortierschluessel.
        $neighbour_sort = [];
        foreach ($links as $unit_id) {
            $best = [INF, PHP_INT_MAX];
            foreach (array_keys($ctx['families_of_unit'][$unit_id]) as $other_family) {
                if ($other_family !== $family_id && $ctx['family_sort'][$other_family] < $best) {
                    $best = $ctx['family_sort'][$other_family];
                }
            }
            $neighbour_sort[$unit_id] = $best;
        }
        usort($links, function ($a, $b) use ($neighbour_sort) {
            return $neighbour_sort[$a] <=> $neighbour_sort[$b];
        });

        // Ist die linke Seite noch frei (Kettenanfang), darf ein angeheiratetes Paar dort
        // stehen -- aber nur, wenn rechts ohnehin schon etwas anderes aussen steht.
        $left_married = null;
        if ($entry_unit === null && !empty($married) && (!empty($links) || count($married) > 1)) {
            $left_married = array_shift($married);
        }

        $family_of_node = $ctx['family_of_node'];
        $own_first = function (string $member) use ($family_of_node, $family_id): int {
            $member_family = $family_of_node[$member] ?? null;
            if ($member_family === $family_id) {
                return 0;
            }
            return $member_family === null ? 1 : 2;
        };

        if ($left_married !== null) {
            $this->orient_unit($units, $left_married, function (string $member) use ($own_first): int {
                return -$own_first($member);
            }, $ctx['insertion_index'], $ctx['family_of_node']);
            $sequence[] = $left_married;
            $placed_units[$left_married] = true;
        }
        foreach ($own as $unit_id) {
            $sequence[] = $unit_id;
            $placed_units[$unit_id] = true;
        }
        foreach ($married as $unit_id) {
            $this->orient_unit($units, $unit_id, $own_first, $ctx['insertion_index'], $ctx['family_of_node']);
            $sequence[] = $unit_id;
            $placed_units[$unit_id] = true;
        }
        foreach ($links as $unit_id) {
            if (isset($placed_units[$unit_id])) {
                continue; // bereits ueber eine andere Familie der Kette platziert
            }
            $this->orient_unit($units, $unit_id, $own_first, $ctx['insertion_index'], $ctx['family_of_node']);
            $sequence[] = $unit_id;
            $placed_units[$unit_id] = true;

            $next_families = array_keys($ctx['families_of_unit'][$unit_id]);
            $family_sort = $ctx['family_sort'];
            usort($next_families, function ($a, $b) use ($family_sort) {
                return $family_sort[$a] <=> $family_sort[$b];
            });
            foreach ($next_families as $next_family) {
                if (!isset($visited[$next_family])) {
                    $this->append_family($units, $ctx, $next_family, $unit_id, $sequence, $visited, $placed_units);
                }
            }
        }
    }

    /**
     * Sortiert die Mitglieder einer Einheit nach $rank (kleiner = weiter links), stabil nach
     * Anlage. Angeheiratete (ohne eigene Familie im Baum) stehen danach direkt AUSSEN neben
     * ihrer Partner*in: aus "Morgan, Duncan, Katherine, Delia" wird "Katherine, Morgan,
     * Delia, Duncan" -- Louisas leibliche Eltern nebeneinander, jeder Abgang kommt aus der
     * Luecke des richtigen Paares statt scheinbar aus einem fremden Kasten.
     */
    private function orient_unit(array &$units, string $unit_id, callable $rank, array $insertion_index, array $family_of_node): void
    {
        $members = $units[$unit_id]['members'];
        usort($members, function ($a, $b) use ($rank, $insertion_index) {
            return [$rank($a), $insertion_index[$a] ?? 0] <=> [$rank($b), $insertion_index[$b] ?? 0];
        });

        $anchors = array_values(array_filter($members, function ($member) use ($family_of_node) {
            return isset($family_of_node[$member]);
        }));
        if (count($members) > 2 && count($anchors) > 0 && count($anchors) < count($members)) {
            $position = array_flip($anchors);
            $middle = (count($anchors) - 1) / 2;
            $order = [];
            foreach ($members as $index => $member) {
                if (isset($position[$member])) {
                    $order[$member] = [(float)$position[$member], $index];
                    continue;
                }
                $order[$member] = [PHP_INT_MAX, $index];
                foreach (array_keys($this->unit_pairs[$member] ?? []) as $partner) {
                    if (isset($position[$partner])) {
                        $order[$member] = [$position[$partner] + ($position[$partner] < $middle ? -0.5 : 0.5), $index];
                        break;
                    }
                }
            }
            usort($members, function ($a, $b) use ($order) {
                return $order[$a] <=> $order[$b];
            });
        }
        $units[$unit_id]['members'] = $this->chain_order($members);
    }

    /**
     * Bilden die Paare einer Einheit eine Kette (Cathalain - Caiomhe - Simon - Milena),
     * stehen die Mitglieder in genau dieser Kette -- sonst lag die Partnerlinie ueber fremden
     * Kaesten und die Abgaenge der beiden Paare kreuzten sich. Von den zwei Richtungen gilt
     * die, die naeher an der uebergebenen Wunschreihenfolge liegt. Keine Kette (jemand mit
     * drei Partner*innen, Kreis): Reihenfolge bleibt.
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
            if (count($neighbours[$member]) > 2) {
                return $members;
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

    /** x-Position eines Knotens innerhalb seiner Einheit. */
    private static function member_x(array $unit, string $key): float
    {
        $offset = (int)array_search($key, $unit['members'], true);
        return $unit['x'] + ($offset - (count($unit['members']) - 1) / 2);
    }

    /**
     * Iterative Zentrierung (vereinfachtes Sugiyama-Verfahren): abwechselnd Eltern ueber
     * ihre Kinder und Kinderbloecke unter ihre Eltern schieben, danach Ueberlappungen
     * innerhalb der Generation aufloesen. Wenige Durchlaeufe reichen fuer Baeume dieser
     * Groessenordnung und koennen -- anders als eine Rekursion -- nicht entarten.
     */
    private function relax_units(array &$units, array $order, array $structure): void
    {
        $unit_of = self::unit_lookup($units);
        $offset = self::member_offsets($units);
        $generations = array_keys($order);
        sort($generations);

        // Familien einmal vorsortieren (die Kreuzungssuche ruft die Zentrierung sehr oft
        // auf): je Generation der Eltern bzw. der Kinder, Knoten als [Einheit, Versatz].
        $as_entries = function (array $keys, ?int $generation) use ($units, $unit_of, $offset): array {
            $entries = [];
            foreach ($keys as $key) {
                $unit_id = $unit_of[$key] ?? null;
                if ($unit_id !== null && ($generation === null || $units[$unit_id]['generation'] === $generation)) {
                    $entries[] = [$unit_id, $offset[$key]];
                }
            }
            return $entries;
        };
        $by_parent_generation = [];
        $by_child_generation = [];
        foreach ($structure['families'] as $family) {
            if (empty($family['parents'])) {
                continue;
            }
            $parent_unit = $unit_of[$family['parents'][0]] ?? null;
            if ($parent_unit === null) {
                continue;
            }
            $parent_generation = $units[$parent_unit]['generation'];
            $parents = $as_entries($family['parents'], $parent_generation);
            $children = $as_entries($family['children'], null);
            $by_parent_generation[$parent_generation][] = [$parent_unit, $parents, $children];
            $children_by_generation = [];
            foreach ($children as $entry) {
                $children_by_generation[$units[$entry[0]]['generation']][] = $entry;
            }
            foreach ($children_by_generation as $generation => $entries) {
                $by_child_generation[$generation][] = [$parents, $entries];
            }
        }
        // Mittelpunkt der von $entries belegten Spanne.
        $center = static function (array $units, array $entries): ?float {
            if (!$entries) {
                return null;
            }
            $min = INF;
            $max = -INF;
            foreach ($entries as [$unit_id, $member_offset]) {
                $x = $units[$unit_id]['x'] + $member_offset;
                $min = min($min, $x);
                $max = max($max, $x);
            }
            return ($min + $max) / 2;
        };

        for ($iteration = 0; $iteration < 6; $iteration++) {
            // Eltern ueber ihre Kinder (von unten nach oben).
            foreach (array_reverse($generations) as $generation) {
                // Eine Einheit kann Elternteil mehrerer Familien sein (Delia mit Duncan UND
                // mit Morgan). Frueher gewann die zuletzt gelesene Familie und die Einheit
                // wurde bei jedem Durchlauf weiter verschoben -- jetzt zaehlt das Mittel
                // aller ihrer Familien, gemessen an den tatsaechlichen Elternteilen statt an
                // der Mitte der ganzen Einheit.
                $offsets = [];
                foreach ($by_parent_generation[$generation] ?? [] as [$parent_unit, $parents, $children]) {
                    $span = $center($units, $children);
                    $parents_center = $center($units, $parents);
                    if ($span !== null && $parents_center !== null) {
                        $offsets[$parent_unit][] = $span - $parents_center;
                    }
                }
                $desired = [];
                foreach ($offsets as $unit_id => $list) {
                    $desired[$unit_id] = $units[$unit_id]['x'] + array_sum($list) / count($list);
                }
                $this->settle_generation($units, $order[$generation], $desired);
            }

            // Kinderbloecke unter ihre Eltern (von oben nach unten).
            foreach ($generations as $generation) {
                $desired = [];
                foreach ($by_child_generation[$generation] ?? [] as [$parents, $children]) {
                    $span = $center($units, $children);
                    $parents_center = $center($units, $parents);
                    if ($span === null || $parents_center === null) {
                        continue;
                    }
                    // Ganzen Geschwisterblock verschieben statt jede Einheit einzeln auf
                    // denselben Punkt zu ziehen (das wuerde die Gruppe nach rechts kippen).
                    // Ziel ist die Mitte der EIGENEN Eltern, nicht die der ganzen Einheit --
                    // bei Delia/Duncan/Katherine/Morgan liegen die beiden weit auseinander.
                    $delta = $parents_center - $span;
                    foreach ($children as [$child_unit]) {
                        $desired[$child_unit] = $units[$child_unit]['x'] + $delta;
                    }
                }

                // Knoten ohne Platz in der Familienstruktur (lose Verbindungen wie
                // Tante/Cousin ohne Zwischenperson, verknuepfte Fremd-Accounts) zu
                // ihrem Ankerpunkt ziehen, statt sie am Zeilenende haengen zu lassen.
                // Bewusst "weich": dieser Wunsch ist meist unerfuellbar (der Platz ist
                // belegt) und darf deshalb die Ausrichtung der Zeile nicht verzerren.
                $soft = [];
                foreach ($structure['links'] as $link) {
                    foreach ([[$link['to'], $link['from']], [$link['from'], $link['to']]] as $ends) {
                        $target_unit = $unit_of[$ends[0]] ?? null;
                        $source_unit = $unit_of[$ends[1]] ?? null;
                        if ($target_unit === null || $source_unit === null
                            || $target_unit === $source_unit
                            || $units[$target_unit]['generation'] !== $generation
                            || $units[$target_unit]['family'] !== null
                            || isset($desired[$target_unit])) {
                            continue;
                        }
                        $desired[$target_unit] = $units[$source_unit]['x'];
                        $soft[$target_unit] = true;
                    }
                }

                $this->settle_generation($units, $order[$generation], $desired, $soft);
            }
        }
    }

    /** Versatz jedes Knotens gegenueber der Mitte seiner Einheit (siehe member_x()). */
    private static function member_offsets(array $units): array
    {
        $offsets = [];
        foreach ($units as $unit) {
            $half = (count($unit['members']) - 1) / 2;
            foreach ($unit['members'] as $index => $key) {
                $offsets[$key] = $index - $half;
            }
        }
        return $offsets;
    }

    /** Zuordnung Knoten -> Einheit. */
    private static function unit_lookup(array $units): array
    {
        $unit_of = [];
        foreach ($units as $unit_id => $unit) {
            foreach ($unit['members'] as $member) {
                $unit_of[$member] = $unit_id;
            }
        }
        return $unit_of;
    }

    /** Mittelpunkt der von $keys belegten Spanne (optional auf eine Generation begrenzt). */
    private function span_center(array $units, array $unit_of, array $keys, ?int $generation = null): ?float
    {
        $min = null;
        $max = null;
        foreach ($keys as $key) {
            $unit_id = $unit_of[$key] ?? null;
            if ($unit_id === null) {
                continue;
            }
            if ($generation !== null && $units[$unit_id]['generation'] !== $generation) {
                continue;
            }
            $x = self::member_x($units[$unit_id], $key);
            $min = $min === null ? $x : min($min, $x);
            $max = $max === null ? $x : max($max, $x);
        }
        return $min === null ? null : ($min + $max) / 2;
    }

    /**
     * Uebernimmt Wunschpositionen, loest anschliessend Ueberlappungen in der Generation
     * auf (Sweep von links nach rechts) und verschiebt die Zeile danach starr zurueck,
     * damit sie nicht bei jedem Durchlauf nach rechts wandert.
     *
     * $soft markiert Wunschpositionen, die nur "wenn moeglich" gelten und aus der
     * Rueckzentrierung herausgerechnet werden -- sonst zieht ein unerfuellbarer Wunsch
     * die komplette Generation gegenueber ihren Nachbargenerationen aus der Flucht.
     */
    private function settle_generation(array &$units, array $unit_ids, array $desired, array $soft = []): void
    {
        if (empty($unit_ids) || empty($desired)) {
            return;
        }
        $before = 0.0;
        $touched = 0;
        foreach ($desired as $unit_id => $x) {
            if (!isset($units[$unit_id])) {
                continue;
            }
            $units[$unit_id]['x'] = $x;
            if (isset($soft[$unit_id])) {
                continue;
            }
            $before += $x;
            $touched++;
        }

        // Ueberlappungen IMMER aufloesen -- auch wenn nur weiche Wuensche gesetzt wurden.
        // Frueher kehrte die Funktion in diesem Fall vorher zurueck: alle losen Knoten
        // einer Generation blieben auf dem Ankerpunkt uebereinander liegen.
        $count = count($unit_ids);
        for ($i = 1; $i < $count; $i++) {
            $previous = $units[$unit_ids[$i - 1]];
            $minimum = $previous['x'] + self::min_separation($previous, $units[$unit_ids[$i]]);
            if ($units[$unit_ids[$i]]['x'] < $minimum) {
                $units[$unit_ids[$i]]['x'] = $minimum;
            }
        }

        if ($touched === 0) {
            return; // nur weiche Wuensche: keine Rueckzentrierung
        }

        $after = 0.0;
        foreach (array_keys($desired) as $unit_id) {
            if (isset($units[$unit_id]) && !isset($soft[$unit_id])) {
                $after += $units[$unit_id]['x'];
            }
        }
        $shift = ($before - $after) / $touched;
        if (abs($shift) > 0.0001) {
            foreach ($unit_ids as $unit_id) {
                $units[$unit_id]['x'] += $shift;
            }
        }
    }

    /**
     * Schreibt die Einheiten-Positionen auf die Knoten zurueck und richtet den Baum so
     * aus, dass der Wurzel-Account waagerecht in der Mitte (x = 0) steht.
     */
    private function apply_unit_positions(array $units): void
    {
        foreach ($units as $unit) {
            $count = count($unit['members']);
            foreach ($unit['members'] as $offset => $key) {
                $this->nodes[$key]['x'] = $unit['x'] + ($offset - ($count - 1) / 2);
                $this->nodes[$key]['y'] = (int)$this->nodes[$key]['generation'];
            }
        }

        $this->close_empty_columns();

        $root_key = $this->root_key;
        $shift = isset($this->nodes[$root_key]) ? -(float)$this->nodes[$root_key]['x'] : 0.0;
        if (abs($shift) > 0.0001) {
            foreach ($this->nodes as $key => $node) {
                $this->nodes[$key]['x'] = $node['x'] + $shift;
            }
        }
    }
}
