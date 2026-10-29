<?php
/**
 * relatives - Kinship
 *
 * Berechnet, wie jede Person zu einem Charakter steht -- also das, was die Jobliste als
 * "Onkel von Megan Fawley" anzeigen soll.
 *
 * Seit v2.2 gibt es nur noch EIN gemeinsames Geflecht. Jeder gespielte Charakter ist
 * darin ein Knoten (Person mit bestaetigtem linked_uid, siehe Relatives_Graph); die
 * Rechnung startet an diesem Knoten statt an einem abstrakten "self".
 *
 * Vorgehen: Aus relatives_relations wird ein Graph gebaut (structure_from()), von der
 * Wurzel aus bekommt jede blutsverwandte Person eine genealogische Koordinate
 * (up, down) = Schritte hinauf zum gemeinsamen Vorfahren und wieder hinunter
 * (blood_degrees()). Daraus folgt das Konzept eindeutig; alles jenseits von
 * Urgrosseltern bzw. Grossonkel/Grosscousin*e faellt auf einen Sammelbegriff
 * (concept_for_degree()).
 *
 * Das Ergebnis wird in relatives_person_owners.computed_* gecacht. Weil das Geflecht
 * geteilt ist, kann jede Aenderung in jedem Stammbaum nachwirken -- deshalb rechnet
 * recompute_all() immer alles, schreibt aber nur geaenderte Werte.
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_Kinship
{
    /** Strukturklassen einer Kante. */
    public const CONCEPTS_PARTNER = ['spouse', 'ex_spouse', 'partner', 'fiance'];
    public const CONCEPTS_PARENT  = ['parent', 'step_parent', 'adoptive_parent', 'foster_parent'];
    public const CONCEPTS_CHILD   = ['child', 'step_child', 'adoptive_child', 'foster_child'];
    public const CONCEPTS_SIBLING = ['sibling', 'step_sibling', 'half_sibling', 'adoptive_sibling'];

    /**
     * Konzepte, die bewusst NICHT zu einer strukturellen Eltern-/Kind-Kante werden:
     * ohne Zwischenknoten liesse sich daraus kein korrekter Stammbaum zeichnen. Die
     * angeheirateten Konzepte stehen explizit hier, weil ihr generation_offset (+/-1)
     * sonst als Eltern-/Kind-Kante missverstanden wuerde.
     */
    public const CONCEPTS_LOOSE = [
        'aunt_uncle', 'niece_nephew', 'cousin', 'grandparent', 'grandchild',
        'sibling_in_law', 'parent_in_law', 'child_in_law', 'great_grandparent', 'great_grandchild',
        'great_aunt_uncle', 'great_niece_nephew', 'second_cousin',
        'distant_ancestor', 'distant_descendant', 'distant_relative',
    ];

    /**
     * Strukturell Eltern/Kind/Geschwister, aber ohne Blutsverwandtschaft. Sie zaehlen
     * fuers Layout, erzeugen aber keine (up, down)-Koordinate -- sonst waere die
     * Adoptivgrossmutter ploetzlich deine leibliche Urahnin.
     */
    private const NON_BLOOD_CONCEPTS = [
        'step_parent', 'step_child', 'step_sibling',
        'adoptive_parent', 'adoptive_child', 'adoptive_sibling',
        'foster_parent', 'foster_child',
    ];

    /**
     * Gegenrichtung: wenn B "Sohn" von A ist, ist A "Elternteil" von B. Symmetrische
     * Konzepte (Geschwister, Partner*innen) fehlen absichtlich -- inverse_concept()
     * gibt fuer sie das Ausgangskonzept zurueck.
     */
    private const INVERSE_CONCEPTS = [
        'parent'             => 'child',
        'child'              => 'parent',
        'step_parent'        => 'step_child',
        'step_child'         => 'step_parent',
        'adoptive_parent'    => 'adoptive_child',
        'adoptive_child'     => 'adoptive_parent',
        'foster_parent'      => 'foster_child',
        'foster_child'       => 'foster_parent',
        'grandparent'        => 'grandchild',
        'grandchild'         => 'grandparent',
        'great_grandparent'  => 'great_grandchild',
        'great_grandchild'   => 'great_grandparent',
        'aunt_uncle'         => 'niece_nephew',
        'niece_nephew'       => 'aunt_uncle',
        'great_aunt_uncle'   => 'great_niece_nephew',
        'great_niece_nephew' => 'great_aunt_uncle',
        'parent_in_law'      => 'child_in_law',
        'child_in_law'       => 'parent_in_law',
        'distant_ancestor'   => 'distant_descendant',
        'distant_descendant' => 'distant_ancestor',
    ];

    /** Kategorien fuer relatives_person_owners.computed_category. */
    public const CATEGORY_EXACT              = 'exact';
    public const CATEGORY_DISTANT_ANCESTOR   = 'distant_ancestor';
    public const CATEGORY_DISTANT_DESCENDANT = 'distant_descendant';
    public const CATEGORY_DISTANT_RELATIVE   = 'distant_relative';
    public const CATEGORY_CUSTOM             = 'custom';
    /** Kein Weg zur Wurzel -- das Label nennt die naechste konkrete Verbindung ("Adoptivvater von Louisa"). */
    public const CATEGORY_VIA                = 'via';
    public const CATEGORY_UNKNOWN            = 'unknown';

    /** (up, down) -> concept_key fuer alles, was noch einen gaengigen Namen hat. */
    private const DEGREE_CONCEPTS = [
        '1|0' => 'parent',
        '2|0' => 'grandparent',
        '3|0' => 'great_grandparent',
        '0|1' => 'child',
        '0|2' => 'grandchild',
        '0|3' => 'great_grandchild',
        '1|1' => 'sibling',
        '2|1' => 'aunt_uncle',
        '1|2' => 'niece_nephew',
        '2|2' => 'cousin',
        '3|1' => 'great_aunt_uncle',
        '1|3' => 'great_niece_nephew',
        '3|3' => 'second_cousin',
    ];

    /**
     * Partner*in einer/eines Blutsverwandten -> angeheiratetes Konzept. Nicht
     * hinterlegte Kombinationen werden zu "entfernte*r Verwandte*r" statt geraten.
     */
    private const IN_LAW_CONCEPTS = [
        'sibling'           => 'sibling_in_law',
        'child'             => 'child_in_law',
        'parent'            => 'step_parent',
        'grandparent'       => 'grandparent',
        'great_grandparent' => 'great_grandparent',
        'aunt_uncle'        => 'aunt_uncle',
        'great_aunt_uncle'  => 'great_aunt_uncle',
        'grandchild'        => 'grandchild',
        'niece_nephew'      => 'niece_nephew',
        'cousin'            => 'cousin',
    ];

    /** Einmal je Request geladenes Geflecht (siehe world()). */
    private static ?array $world = null;

    /** Zwischengespeicherte Grad-Tabelle (siehe type_map()). */
    private static ?array $types = null;

    public static function inverse_concept(?string $concept): ?string
    {
        if ($concept === null || $concept === '') {
            return null;
        }
        return self::INVERSE_CONCEPTS[$concept] ?? $concept;
    }

    /**
     * Ordnet eine Relation einer Strukturklasse zu. Fuer im ACP selbst angelegte,
     * unbekannte Keys entscheidet der generation_offset (-1 Elternteil, +1 Kind).
     */
    public static function classify_relation(?string $concept, int $offset): string
    {
        if ($concept !== null && $concept !== '') {
            if (in_array($concept, self::CONCEPTS_PARTNER, true)) { return 'partner'; }
            if (in_array($concept, self::CONCEPTS_PARENT, true))  { return 'parent'; }
            if (in_array($concept, self::CONCEPTS_CHILD, true))   { return 'child'; }
            if (in_array($concept, self::CONCEPTS_SIBLING, true)) { return 'sibling'; }
            if (in_array($concept, self::CONCEPTS_LOOSE, true))   { return 'other'; }
        }
        if ($offset === -1) { return 'parent'; }
        if ($offset === 1)  { return 'child'; }
        return 'other';
    }

    public static function is_blood_concept(?string $concept): bool
    {
        return $concept === null || !in_array($concept, self::NON_BLOOD_CONCEPTS, true);
    }

    /** Naehe der nicht ueber (up, down) beschreibbaren Konzepte, Skala wie in closeness(). */
    private const CLOSENESS_CONCEPTS = [
        'step_parent'      => 11,
        'step_child'       => 11,
        'adoptive_parent'  => 11,
        'adoptive_child'   => 11,
        'foster_parent'    => 11,
        'foster_child'     => 11,
        'spouse'           => 12,
        'partner'          => 12,
        'fiance'           => 12,
        'half_sibling'     => 22,
        'step_sibling'     => 23,
        'adoptive_sibling' => 23,
        'sibling_in_law'   => 45,
        'parent_in_law'    => 45,
        'child_in_law'     => 45,
        'ex_spouse'        => 50,
        'distant_ancestor'   => 80,
        'distant_descendant' => 80,
        'distant_relative'   => 90,
    ];

    /**
     * Wie nah steht eine Person dem Charakter (kleiner = naeher), aus dem gecachten
     * computed_concept_key/-category. Blutsverwandte: 10 je Schritt (up + down), bei
     * Gleichstand die naehere Generation nach oben zuerst. Freie Bezeichnungen und
     * Unbekanntes kommen zuletzt.
     */
    public static function closeness(?string $concept, ?string $category): int
    {
        if ($category === self::CATEGORY_CUSTOM || $category === self::CATEGORY_VIA || $category === self::CATEGORY_UNKNOWN) {
            return 100;
        }
        $degree = self::degree_for_concept($concept);
        if ($degree !== null) {
            return 10 * ($degree[0] + $degree[1]) + $degree[0];
        }
        return $concept !== null && isset(self::CLOSENESS_CONCEPTS[$concept]) ? self::CLOSENESS_CONCEPTS[$concept] : 100;
    }

    /** concept_key, generation_offset, label, line_style je relationship_type_id. */
    public static function type_map(): array
    {
        if (self::$types !== null) {
            return self::$types;
        }
        $types = [];
        foreach (Relatives_RelationshipTypes::get_all(false) as $type) {
            $types[(int)$type['id']] = [
                'concept'    => (string)$type['concept_key'],
                'offset'     => (int)$type['generation_offset'],
                'label'      => (string)$type['label'],
                'line_style' => isset($type['line_style']) ? (string)$type['line_style'] : 'solid',
            ];
        }
        self::$types = $types;
        return $types;
    }

    /**
     * Verwirft das geladene Geflecht und die Grad-Tabelle (nach Schreibvorgaengen im
     * selben Request -- sonst saehe ein danach gebauter Baum den alten Stand und neu
     * angelegte Verwandtschaftsgrade gar nicht).
     */
    public static function reset(): void
    {
        self::$world = null;
        self::$types = null;
        // Die Familien-Zugehoerigkeit wird aus demselben Geflecht gelesen.
        if (class_exists('Relatives_Graph', false)) {
            Relatives_Graph::reset_family_memberships();
        }
    }

    /**
     * Personen, Kanten und der daraus gebaute Graph -- einmal je Request.
     *
     * @return array ['persons' => id => row, 'relations' => [rows], 'types' => ..., 'graph' => ...]
     */
    public static function world(): array
    {
        global $db;
        if (self::$world !== null) {
            return self::$world;
        }

        $persons = [];
        $query = $db->simple_select('relatives_persons', '*');
        while ($row = $db->fetch_array($query)) {
            $persons[(int)$row['id']] = $row;
        }
        $relations = Relatives_Database::get_all_relations();
        $types = self::type_map();

        self::$world = [
            'persons'   => $persons,
            'relations' => $relations,
            'types'     => $types,
            'graph'     => self::structure_from($persons, $relations, $types),
        ];
        return self::$world;
    }

    /**
     * Baut den Verwandtschaftsgraphen. Knotenschluessel: 'p:<id>' fuer Personen,
     * 'f:<id>' fuer Familien-Kaesten (wirken wie ein Elternteil ohne eigenen Namen).
     * Reine Funktion ohne DB-Zugriffe (testbar).
     *
     * @param array $relations flache Liste von relatives_relations-Zeilen
     * @return array ['parents','children','partners','siblings','edges']
     */
    public static function structure_from(array $persons, array $relations, array $types): array
    {
        $graph = [
            'parents'  => [],
            'children' => [],
            'partners' => [],
            'siblings' => [],
            // Jede Aussage roh ([subject, anchor, concept, custom_label]) -- daraus
            // werden die direkt an der Wurzel haengenden Angaben gelesen.
            'edges'    => [],
        ];

        foreach ($relations as $relation) {
            $person_id = (int)$relation['person_id'];
            if (!isset($persons[$person_id])) {
                continue;
            }
            $subject = 'p:' . $person_id;
            $anchor = self::anchor_key($relation, $persons);
            if ($anchor === null || $anchor === $subject) {
                continue;
            }

            $type_id = !empty($relation['relationship_type_id']) ? (int)$relation['relationship_type_id'] : 0;
            $type = $types[$type_id] ?? null;
            $concept = $type !== null ? $type['concept'] : null;
            $offset = $type !== null ? $type['offset'] : 0;

            $graph['edges'][] = [$subject, $anchor, $concept, (string)($relation['relationship_custom_label'] ?? '')];

            $blood = self::is_blood_concept($concept);
            switch (self::classify_relation($concept, $offset)) {
                case 'parent':
                    // "X ist Vater von A" -> A ist das Kind, X der Elternteil.
                    self::add_parent($graph, $anchor, $subject, $concept, $blood);
                    break;
                case 'child':
                    // "X ist Sohn von A" -> X ist das Kind, A der Elternteil.
                    self::add_parent($graph, $subject, $anchor, $concept, $blood);
                    break;
                case 'partner':
                    $graph['partners'][$subject][$anchor] = $concept;
                    $graph['partners'][$anchor][$subject] = $concept;
                    break;
                case 'sibling':
                    $graph['siblings'][$subject][$anchor] = $concept;
                    $graph['siblings'][$anchor][$subject] = $concept;
                    break;
                default:
                    // Tante, Cousin, freie Bezeichnung: behaupten ein Verhaeltnis, ohne
                    // den Weg dorthin zu beschreiben -- nur als direkte Angabe nutzbar.
                    break;
            }
        }

        // Ein Kind mit nur EINEM eingetragenen leiblichen Elternteil bekommt dessen einzige*n
        // Partner*in als zweiten Elternteil ("Ethan ist Sohn von Ava", Ava ist Olivers
        // Ehefrau -> Oliver ist Ethans Vater). Dieselbe Annahme trifft der Baum. Bei mehreren
        // Partner*innen bleibt es offen, statt zu raten.
        foreach ($graph['parents'] as $child => $parents) {
            $blood = array_filter($parents, static function ($edge) {
                return $edge['blood'];
            });
            if (count($blood) !== 1) {
                continue;
            }
            $parent = (string)array_key_first($blood);
            $partners = array_keys($graph['partners'][$parent] ?? []);
            if (count($partners) !== 1 || $partners[0] === $child || isset($parents[$partners[0]])) {
                continue;
            }
            self::add_parent($graph, (string)$child, (string)$partners[0], $blood[$parent]['concept'], true, true);
        }

        return $graph;
    }

    private static function anchor_key(array $relation, array $persons): ?string
    {
        $family_id = !empty($relation['related_to_family_id']) ? (int)$relation['related_to_family_id'] : 0;
        if ($family_id > 0) {
            return 'f:' . $family_id;
        }
        $related_id = !empty($relation['related_to_person_id']) ? (int)$relation['related_to_person_id'] : 0;
        return ($related_id > 0 && isset($persons[$related_id])) ? 'p:' . $related_id : null;
    }

    /**
     * $inferred markiert abgeleitete Elternschaft (Partner*in eines Elternteils). Sie wirkt
     * nur nach unten: Ethan wird ueber Oliver zum Cousin, aber Ava wird dadurch nicht
     * blutsverwandt -- sonst waeren Avas Eltern ploetzlich Megans Grosseltern.
     */
    private static function add_parent(array &$graph, string $child, string $parent, ?string $concept, bool $blood, bool $inferred = false): void
    {
        $graph['parents'][$child][$parent] = ['concept' => $concept, 'blood' => $blood, 'inferred' => $inferred];
        $graph['children'][$parent][$child] = ['concept' => $concept, 'blood' => $blood, 'inferred' => $inferred];
    }

    /**
     * Angaben, die unmittelbar zwischen der Wurzel und einer anderen Person stehen --
     * in beiden Richtungen: "Oliver ist Onkel von Megan" genauso wie "Megan ist
     * Nichte von Oliver" (dann gilt fuer Oliver das Gegenkonzept).
     *
     * @return array ['direct' => key => concept, 'custom' => key => label]
     */
    public static function direct_statements(array $graph, string $root_key): array
    {
        $result = ['direct' => [], 'custom' => []];
        foreach ($graph['edges'] as [$subject, $anchor, $concept, $custom]) {
            if ($anchor === $root_key) {
                if ($concept !== null && $concept !== '') {
                    $result['direct'][$subject] = $result['direct'][$subject] ?? $concept;
                } elseif ($custom !== '') {
                    $result['custom'][$subject] = $result['custom'][$subject] ?? $custom;
                }
            } elseif ($subject === $root_key && strpos($anchor, 'p:') === 0) {
                $inverse = self::inverse_concept($concept);
                if ($inverse !== null) {
                    $result['direct'][$anchor] = $result['direct'][$anchor] ?? $inverse;
                }
            }
        }
        return $result;
    }

    /**
     * (up, down) je Knoten, ausschliesslich ueber Blutskanten. Die kleinste Summe
     * up+down gewinnt, bei Gleichstand der kleinere up-Wert (die direktere Linie).
     *
     * @return array key => ['up' => int, 'down' => int]
     */
    public static function blood_degrees(array $graph, string $root_key): array
    {
        $degrees = [$root_key => ['up' => 0, 'down' => 0]];

        // Direkt an der Wurzel eingetragene Grade ohne Zwischenperson ("Oliver ist mein
        // Onkel") verankern ebenfalls eine Koordinate -- sonst bliebe alles, was an
        // Oliver haengt (sein Sohn Ethan), ohne Weg zur Wurzel.
        foreach (self::direct_statements($graph, $root_key)['direct'] as $key => $concept) {
            $degree = self::degree_for_concept($concept);
            if ($degree !== null) {
                self::keep_shortest($degrees, $key, $degree[0], $degree[1]);
            }
        }

        // Ausbreiten, bis sich nichts mehr verbessert: ein Knoten kann seine Koordinate
        // erst ueber eine Geschwisterkante bekommen, danach muessen seine Kinder erben.
        for ($round = 0; $round < 24; $round++) {
            $changed = false;

            foreach ($degrees as $key => $degree) {
                $up = (int)$degree['up'];
                $down = (int)$degree['down'];

                foreach ($graph['children'][$key] ?? [] as $child => $edge) {
                    if ($edge['blood'] && $child !== $root_key
                        && self::keep_shortest($degrees, $child, $up, $down + 1)) {
                        $changed = true;
                    }
                }

                // Elternteil: einen Schritt naeher am gemeinsamen Vorfahren bzw. in
                // direkter Linie eine Generation hoeher. (0, 0) waere die Wurzel selbst.
                $parent_up = $down === 0 ? $up + 1 : $up;
                $parent_down = $down === 0 ? 0 : $down - 1;
                if ($parent_up > 0 || $parent_down > 0) {
                    foreach ($graph['parents'][$key] ?? [] as $parent => $edge) {
                        if ($edge['blood'] && empty($edge['inferred']) && $parent !== $root_key
                            && self::ancestor_slot_free($degrees, $parent, $parent_up, $parent_down)
                            && self::keep_shortest($degrees, $parent, $parent_up, $parent_down)) {
                            $changed = true;
                        }
                    }
                }

                // Geschwister teilen sich die Eltern: aus (1,0) Mutter wird (2,1) Onkel;
                // seitlich stehende Personen geben ihre Koordinate unveraendert weiter.
                $sibling_up = $down === 0 ? $up + 1 : $up;
                $sibling_down = $down === 0 ? 1 : $down;
                foreach ($graph['siblings'][$key] ?? [] as $sibling => $concept) {
                    if ($sibling !== $root_key && self::is_blood_concept($concept)
                        && self::keep_shortest($degrees, $sibling, $sibling_up, $sibling_down)) {
                        $changed = true;
                    }
                }
            }

            if (!$changed) {
                break;
            }
        }

        unset($degrees[$root_key]);
        return $degrees;
    }

    /** Umkehrung von concept_for_degree() fuer exakt benannte Blutsverwandtschaften. */
    public static function degree_for_concept(?string $concept): ?array
    {
        if ($concept === null || $concept === '') {
            return null;
        }
        $key = array_search($concept, self::DEGREE_CONCEPTS, true);
        if ($key === false) {
            return null;
        }
        [$up, $down] = explode('|', (string)$key);
        return [(int)$up, (int)$down];
    }

    /**
     * Hoechstens 2^n Vorfahren je Ebene. Im gemeinsamen Geflecht widersprechen sich Listen
     * ("Damien ist Sohn von Delia", obwohl Damien der Bruder der Wurzel ist und die Wurzel
     * schon Mutter und Vater hat) -- solche Angaben duerfen keine dritte Mutter erzeugen,
     * von der aus sich der Fehler durch den ganzen Baum fortpflanzt.
     */
    private static function ancestor_slot_free(array $degrees, string $key, int $up, int $down): bool
    {
        if ($down !== 0 || $up <= 0) {
            return true;
        }
        $taken = 0;
        foreach ($degrees as $other => $degree) {
            if ($other !== $key && (int)$degree['down'] === 0 && (int)$degree['up'] === $up) {
                $taken++;
            }
        }
        return $taken < (1 << min($up, 20));
    }

    private static function keep_shortest(array &$degrees, string $key, int $up, int $down): bool
    {
        if (!isset($degrees[$key])) {
            $degrees[$key] = ['up' => $up, 'down' => $down];
            return true;
        }
        $old = $degrees[$key];
        $old_sum = $old['up'] + $old['down'];
        $new_sum = $up + $down;
        if ($new_sum < $old_sum || ($new_sum === $old_sum && $up < $old['up'])) {
            $degrees[$key] = ['up' => $up, 'down' => $down];
            return true;
        }
        return false;
    }

    /**
     * Die Begrenzungsregel: direkte Linie bis Urgrosseltern/Urenkel, Seitenlinien bis
     * Grossonkel, Grossnichte, Grosscousin*e. Alles Weitere bekommt einen Sammelbegriff.
     *
     * @return array [concept_key, category]
     */
    public static function concept_for_degree(int $up, int $down): array
    {
        $key = $up . '|' . $down;
        if (isset(self::DEGREE_CONCEPTS[$key])) {
            return [self::DEGREE_CONCEPTS[$key], self::CATEGORY_EXACT];
        }
        if ($down === 0 && $up >= 4) {
            return ['distant_ancestor', self::CATEGORY_DISTANT_ANCESTOR];
        }
        if ($up === 0 && $down >= 4) {
            return ['distant_descendant', self::CATEGORY_DISTANT_DESCENDANT];
        }
        return ['distant_relative', self::CATEGORY_DISTANT_RELATIVE];
    }

    /**
     * Verwandtschaft der uebergebenen Personen zur Wurzel. Reine Rechen-Funktion bis
     * auf die Label-Suche im ACP-Bestand.
     *
     * @param array $persons id => Personen-Zeile (die zu beschreibenden Personen)
     * @return array person_id => ['concept_key','label','category','up','down']
     */
    public static function compute_from(array $persons, array $graph, string $root_key): array
    {
        $degrees = self::blood_degrees($graph, $root_key);
        $statements = self::direct_statements($graph, $root_key);

        $result = [];
        foreach ($persons as $person_id => $person) {
            $key = 'p:' . (int)$person_id;
            $result[(int)$person_id] = $key === $root_key
                ? self::unknown()
                : self::describe($key, $person, $degrees, $graph, $statements, $root_key);
        }
        return $result;
    }

    private static function unknown(): array
    {
        return ['concept_key' => null, 'label' => null, 'category' => self::CATEGORY_UNKNOWN, 'up' => null, 'down' => null, 'via_person_id' => null];
    }

    private static function describe(string $key, array $person, array $degrees, array $graph, array $statements, string $root_key): array
    {
        $gender = Relatives_RelationshipTypes::map_person_gender_to_label_gender((int)$person['gender']);

        // 1. Was direkt zwischen Wurzel und Person eingetragen wurde, gilt.
        if (isset($statements['direct'][$key])) {
            return self::finish($statements['direct'][$key], self::CATEGORY_EXACT, null, null, $gender);
        }
        if (isset($statements['custom'][$key])) {
            return ['concept_key' => null, 'label' => $statements['custom'][$key], 'category' => self::CATEGORY_CUSTOM, 'up' => null, 'down' => null, 'via_person_id' => null];
        }

        // 2. Blutsverwandtschaft ueber die Koordinate.
        if (isset($degrees[$key])) {
            $up = (int)$degrees[$key]['up'];
            $down = (int)$degrees[$key]['down'];
            [$concept, $category] = self::concept_for_degree($up, $down);
            return self::finish($concept, $category, $up, $down, $gender);
        }

        // 3. Angeheiratet.
        $in_law = self::in_law_concept($key, $degrees, $graph, $root_key);
        if ($in_law !== null) {
            return self::finish($in_law, self::CATEGORY_EXACT, null, null, $gender);
        }

        // 4. Kein benennbarer Weg zur Wurzel. Statt eines nichtssagenden "entfernte*r
        //    Verwandte*r" wird die naechste konkrete Verbindung genannt
        //    ("Adoptivvater von Louisa Griffin") -- bevorzugt zu jemandem, dessen
        //    Verhaeltnis zur Wurzel bekannt ist.
        $via = self::via_statement($key, $degrees, $graph, $statements, $root_key, $gender);
        if ($via !== null) {
            // Nur Grad + Person speichern; den Satz bildet display_label() erst bei der
            // Anzeige -- sonst stuende er in der Sprache dessen im Cache, der zuletzt
            // gespeichert hat.
            return [
                'concept_key'   => null,
                'label'         => $via['label'],
                'category'      => self::CATEGORY_VIA,
                'up'            => null,
                'down'          => null,
                'via_person_id' => $via['other'],
            ];
        }

        return self::unknown();
    }

    /**
     * Naechste konkrete Verbindung einer Person, aus ihrer Sicht formuliert.
     *
     * @return array|null ['label' => string, 'other' => person_id, 'rank' => int]
     */
    private static function via_statement(string $key, array $degrees, array $graph, array $statements, string $root_key, int $gender): ?array
    {
        $best = null;
        foreach ($graph['edges'] as [$subject, $anchor, $concept, $custom]) {
            if ($subject === $key) {
                $other = $anchor;
                $own_concept = $concept;
            } elseif ($anchor === $key && $concept !== null && $concept !== '') {
                $other = $subject;
                $own_concept = self::inverse_concept($concept);
            } else {
                continue;
            }
            if (strpos($other, 'p:') !== 0 || $other === $root_key) {
                continue;
            }

            $rank = 1000;
            if (isset($degrees[$other])) {
                $rank = (int)$degrees[$other]['up'] + (int)$degrees[$other]['down'];
            } elseif (isset($statements['direct'][$other])) {
                $rank = 50;
            }
            if ($best !== null && $best['rank'] <= $rank) {
                continue;
            }

            $label = null;
            if ($own_concept !== null && $own_concept !== '') {
                $row = self::label_row($own_concept, $gender);
                $label = $row !== null ? (string)$row['label'] : null;
            } elseif ($subject === $key && $custom !== '') {
                $label = $custom;
            }
            if ($label === null) {
                continue;
            }
            $best = ['label' => $label, 'other' => (int)substr($other, 2), 'rank' => $rank];
        }
        return $best;
    }

    /**
     * Anzeige-Text eines Ergebnisses in der Sprache der ansehenden Person: bei "via" mit
     * dem Namen der anderen Person ("Adoptivvater von Louisa Griffin"), sonst der Grad.
     */
    public static function display_label(?string $label, $via_person_id): ?string
    {
        if ($label === null || $label === '' || empty($via_person_id)) {
            return $label;
        }
        return self::relation_of($label, self::person_name((int)$via_person_id));
    }

    private static function person_name(int $person_id): string
    {
        if (isset(self::$world['persons'][$person_id])) {
            return (string)self::$world['persons'][$person_id]['name'];
        }
        if (class_exists('Relatives_Database')) {
            $person = Relatives_Database::get_person($person_id);
            return $person !== null ? (string)$person['name'] : '';
        }
        return '';
    }

    /** "<Grad> von <Name>" ueber die Sprachdatei (Frontend und ACP haben den Key). */
    private static function relation_of(string $label, string $name): string
    {
        global $lang;
        if (is_object($lang)) {
            if (!isset($lang->relatives_relation_of)) {
                $lang->load('relatives', false, true);
            }
            if (isset($lang->relatives_relation_of)) {
                return $lang->sprintf($lang->relatives_relation_of, $label, $name);
            }
        }
        return $name !== '' ? $label . ' (' . $name . ')' : $label;
    }

    private static function in_law_concept(string $key, array $degrees, array $graph, string $root_key): ?string
    {
        foreach ($graph['partners'][$key] ?? [] as $other => $concept) {
            if ($other === $root_key) {
                return $concept !== null && $concept !== '' ? $concept : 'spouse';
            }
            if (!isset($degrees[$other])) {
                continue;
            }
            [$other_concept] = self::concept_for_degree((int)$degrees[$other]['up'], (int)$degrees[$other]['down']);
            if (isset(self::IN_LAW_CONCEPTS[$other_concept])) {
                return self::IN_LAW_CONCEPTS[$other_concept];
            }
        }

        // Umgekehrt: Blutsverwandte der eigenen Partner*in ("Schwiegermutter").
        foreach ($graph['partners'][$root_key] ?? [] as $partner => $concept) {
            if (isset($graph['parents'][$partner][$key])) {
                return 'parent_in_law';
            }
            if (isset($graph['children'][$partner][$key])) {
                return 'step_child';
            }
            if (isset($graph['siblings'][$partner][$key])) {
                return 'sibling_in_law';
            }
        }
        return null;
    }

    private static function finish(?string $concept, string $category, ?int $up, ?int $down, int $label_gender): array
    {
        $label = null;
        if ($concept !== null && $concept !== '') {
            $row = self::label_row($concept, $label_gender);
            $label = $row !== null ? (string)$row['label'] : null;
        }
        return [
            'concept_key' => $concept,
            'label'       => $label,
            'category'    => $label === null ? self::CATEGORY_UNKNOWN : $category,
            'up'          => $up,
            'down'        => $down,
            'via_person_id' => null,
        ];
    }

    /** Label-Zeile mit Prozess-Cache; faellt auf die neutrale Variante zurueck. */
    private static function label_row(string $concept, int $gender): ?array
    {
        static $cache = [];
        $key = $concept . '|' . $gender;
        if (!array_key_exists($key, $cache)) {
            $row = Relatives_RelationshipTypes::get_by_concept_and_gender($concept, $gender);
            if ($row === null && $gender !== Relatives_RelationshipTypes::LABEL_NEUTRAL) {
                $row = Relatives_RelationshipTypes::get_by_concept_and_gender($concept, Relatives_RelationshipTypes::LABEL_NEUTRAL);
            }
            $cache[$key] = $row;
        }
        return $cache[$key];
    }

    // ------------------------------------------------------------------
    // Cache
    // ------------------------------------------------------------------

    /** Verwandtschaft aller Personen in der Liste eines Accounts (ohne zu schreiben). */
    public static function compute(int $owner_uid): array
    {
        $persons = [];
        foreach (Relatives_Database::get_persons_by_owner($owner_uid) as $row) {
            $persons[(int)$row['id']] = $row;
        }
        if (!$persons) {
            return [];
        }
        $root = Relatives_Graph::find_account_person_id($owner_uid);
        if ($root === 0) {
            return array_map(static function () {
                return self::unknown();
            }, $persons);
        }
        return self::compute_from($persons, self::world()['graph'], 'p:' . $root);
    }

    /** Einzelner Account -- intern ebenfalls ueber recompute_all(), s. Klassenkommentar. */
    public static function recompute_owner(int $owner_uid): int
    {
        return self::recompute_all();
    }

    /** Nach jeder Aenderung an einer Person: das geteilte Geflecht wirkt ueberall nach. */
    public static function recompute_for_person(int $person_id): void
    {
        self::recompute_all();
    }

    /**
     * Rechnet alle Listen neu und schreibt nur Zuordnungen, deren Wert sich geaendert hat.
     *
     * @return int Anzahl geschriebener Zuordnungen
     */
    public static function recompute_all(): int
    {
        global $db;

        self::reset();
        $world = self::world();

        $by_owner = [];
        $query = $db->simple_select(
            'relatives_person_owners',
            'owner_uid, person_id, computed_concept_key, computed_label, computed_up, computed_down, computed_category, computed_via_person_id, computed_at'
        );
        while ($row = $db->fetch_array($query)) {
            $by_owner[(int)$row['owner_uid']][(int)$row['person_id']] = $row;
        }

        $written = 0;
        foreach ($by_owner as $uid => $cached_rows) {
            $persons = [];
            foreach (array_keys($cached_rows) as $person_id) {
                if (isset($world['persons'][$person_id])) {
                    $persons[$person_id] = $world['persons'][$person_id];
                }
            }
            $root = Relatives_Graph::find_account_person_id($uid);
            $computed = $root > 0
                ? self::compute_from($persons, $world['graph'], 'p:' . $root)
                : array_map(static function () {
                    return self::unknown();
                }, $persons);

            foreach ($computed as $person_id => $values) {
                $cached = $cached_rows[$person_id];
                if ((int)$cached['computed_at'] > 0
                    && (string)$cached['computed_concept_key'] === (string)$values['concept_key']
                    && (string)$cached['computed_label'] === (string)$values['label']
                    && (string)$cached['computed_category'] === (string)$values['category']
                    && (string)$cached['computed_up'] === (string)$values['up']
                    && (string)$cached['computed_down'] === (string)$values['down']
                    && (string)$cached['computed_via_person_id'] === (string)($values['via_person_id'] ?? '')) {
                    continue;
                }
                Relatives_Database::update_computed((int)$person_id, (int)$uid, $values);
                $written++;
            }
        }
        return $written;
    }

    /**
     * Gecachte Verwandtschaft einer Person aus Sicht eines Accounts (oeffentlicher
     * Lesepfad fuer relatives_get_relation()). Leerer Cache wird einmalig nachgerechnet.
     */
    public static function get_cached(int $person_id, int $owner_uid): ?array
    {
        global $db;
        if ($person_id <= 0 || $owner_uid <= 0) {
            return null;
        }

        $fields = 'computed_concept_key, computed_label, computed_up, computed_down, computed_category, computed_via_person_id, computed_at';
        $where = "person_id = " . (int)$person_id . " AND owner_uid = " . (int)$owner_uid;
        $row = $db->fetch_array($db->simple_select('relatives_person_owners', $fields, $where, ['limit' => 1]));
        if (!$row) {
            return null;
        }
        if ((int)$row['computed_at'] === 0) {
            self::recompute_all();
            $row = $db->fetch_array($db->simple_select('relatives_person_owners', $fields, $where, ['limit' => 1]));
            if (!$row) {
                return null;
            }
        }

        return [
            'concept_key' => $row['computed_concept_key'],
            'label'       => $row['computed_label'],
            'category'    => $row['computed_category'] !== null ? (string)$row['computed_category'] : self::CATEGORY_UNKNOWN,
            'up'          => $row['computed_up'] !== null ? (int)$row['computed_up'] : null,
            'down'        => $row['computed_down'] !== null ? (int)$row['computed_down'] : null,
            'via_person_id' => $row['computed_via_person_id'] !== null ? (int)$row['computed_via_person_id'] : null,
        ];
    }
}
