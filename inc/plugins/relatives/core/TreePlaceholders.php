<?php
/**
 * relatives - TreePlaceholders
 *
 * Loest lose Aussagen ("Ravena ist Tante von Jolina") fuer die Baumanzeige in echte
 * Eltern-, Geschwister- und Partner-Kanten auf. Fehlt die Zwischenperson, entsteht ein
 * Platzhalter-Knoten ("Elternteil von Jolina"), ueber den die Verbindung laeuft -- statt
 * eines Direktstrichs quer durch den Baum.
 *
 * Nur Anzeige: nichts davon wird gespeichert. Traegt jemand die echte Person ein, ist die
 * Aussage bereits durch die Struktur abgedeckt und der Platzhalter entfaellt von selbst
 * (gleiche Haltung wie bei abgeleiteten Geschwistern, siehe PROJECT_CONTEXT v2.2).
 *
 * Mehrdeutig heisst: die Zwischenperson laesst sich nicht bestimmen (Jolina hat Mutter UND
 * Vater, Ravena ist nur "Tante"). Dann wird nicht geraten -- ein neutraler Platzhalter
 * "Grosselternteil von Jolina" steht eine Generation hoeher, sein Abgang endet auf der
 * Paarlinie der Eltern ('either' an der Familie, gezeichnet vom Frontend).
 *
 * Nicht aufloesbare Aussagen (widerspruechliche Generationen, Familien-Kaesten auf dem Weg,
 * mehr als zwei Eltern, Schwager*in ohne erkennbare Richtung ...) bleiben lose Linie.
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_TreePlaceholders
{
    /**
     * "X ist <Konzept> von A" => [Stufen aufwaerts ab A, Stufen aufwaerts ab X] bis zu den
     * beiden Vorfahr*innen, die Geschwister sind. Tante: ein Elternteil von A und X selbst.
     */
    private const SIBLING_JOINS = [
        'aunt_uncle'         => [1, 0],
        'great_aunt_uncle'   => [2, 0],
        'niece_nephew'       => [0, 1],
        'great_niece_nephew' => [0, 2],
        'cousin'             => [1, 1],
        'second_cousin'      => [2, 2],
    ];

    private const OFFSETS = ['parent' => -1, 'child' => 1, 'sibling' => 0, 'spouse' => 0];

    private array $nodes;
    private array $relations;

    /** Leibliche Eltern nach dem ersten Strukturdurchlauf: Kind => [Elternteil => true]. */
    private array $parents = [];

    /** Eltern jeder Abstammungsgruppe (leiblich, adoptiv, stief ...): Kind => [Elternteil => true]. */
    private array $any_parents = [];

    /** Partner*innen inkl. gemeinsamer Eltern eines Kindes: Knoten => [Knoten => true]. */
    private array $partners = [];

    /** Leibliche Familien (auch reine Geschwisterbalken): Kind => [Familien-id => true]. */
    private array $blood_families = [];

    /** Kinder je leiblicher Familie: Familien-id => [Knoten, ...]. */
    private array $family_children = [];

    /**
     * Arbeitsstand. Wird vor jeder Aussage gesichert und bei Fehlschlag zurueckgesetzt, damit
     * eine halb aufgeloeste Aussage keine verwaisten Platzhalter hinterlaesst.
     * parent_of: Knoten => ihm zugeordneter (Platzhalter-)Elternteil
     * either_of: Kind => sein mehrdeutiges Elternpaar
     * attached_parents: Halb-/Stiefgeschwister => Eltern, an deren Balken es haengt
     */
    private array $state = [
        'nodes'            => [],
        'relations'        => [],
        'edges'            => [],
        'attachments'      => [],
        'parent_of'        => [],
        'either_of'        => [],
        'attached_parents' => [],
    ];

    /**
     * Werden zuerst aufgeloest: Halb-/Stiefgeschwister legen fest, an welchem Elternpaar
     * jemand haengt. Erst danach duerfen Tante/Cousin*e ... Platzhalter anlegen -- sonst
     * bekam "Mischa ist Halbbruder von Jolina UND Cousin von Louisa" eine zweite, erfundene
     * Herkunft, obwohl Jolinas Vater der Bruder von Louisas Mutter ist.
     */
    private const FIRST_PASS = ['half_sibling', 'step_sibling'];

    /** Geheimnis/Linienart der gerade aufgeloesten Aussage -- gilt fuer alle daraus entstehenden Kanten. */
    private bool $secret = false;
    private string $style = 'solid';

    /**
     * @param array $nodes     Knoten des Ausschnitts (key => Knoten mit 'type', 'generation')
     * @param array $relations Aussagen wie in Relatives_TreeBuilder::$relations
     * @param array $structure Ergebnis des ersten Durchlaufs von build_structure()
     */
    public function __construct(array $nodes, array $relations, array $structure)
    {
        $this->nodes = $nodes;
        $this->relations = $relations;

        foreach ($structure['families'] ?? [] as $family) {
            $blood = ($family['group'] ?? 'blood') === 'blood';
            foreach ($family['children'] as $child) {
                foreach ($family['parents'] as $parent) {
                    $this->any_parents[$child][$parent] = true;
                    if ($blood) {
                        $this->parents[$child][$parent] = true;
                    }
                }
                if ($blood) {
                    $this->blood_families[$child][$family['id']] = true;
                    $this->family_children[$family['id']][] = $child;
                }
            }
            if (count($family['parents']) === 2) {
                [$a, $b] = $family['parents'];
                $this->partners[$a][$b] = true;
                $this->partners[$b][$a] = true;
            }
        }
        foreach ($structure['partners'] ?? [] as $pair) {
            $this->partners[$pair['a']][$pair['b']] = true;
            $this->partners[$pair['b']][$pair['a']] = true;
        }
    }

    /**
     * @return array resolved    => [Index in $relations => true] (keine lose Linie mehr)
     *               nodes       => Platzhalter-Knoten (key => Knoten)
     *               relations   => synthetische Aussagen ('synthetic' => true)
     *               edges       => Anlege-Struktur fuers Ein-/Ausklappen
     *               attachments => neutrale Abgaenge auf ein Elternpaar
     */
    public function resolve(): array
    {
        $resolved = [];
        $this->resolve_by_marriage($resolved);
        $this->resolve_pass($resolved, true);
        $this->resolve_mutual_aunts($resolved);
        $this->resolve_combined_joins($resolved);
        $this->resolve_pass($resolved, false);

        return [
            'resolved'    => $resolved,
            'nodes'       => $this->state['nodes'],
            'relations'   => $this->state['relations'],
            'edges'       => array_values($this->state['edges']),
            'attachments' => array_values($this->state['attachments']),
        ];
    }

    /**
     * Angeheiratete Tanten/Onkel: Vilma ist "Tante von Freyja", weil sie mit Aleksi, dem
     * Bruder von Freyjas Vater, verheiratet ist. Das ist durch die eingetragenen Personen
     * schon abgebildet -- ohne diese Pruefung hielte join_ancestors() Vilma fuer ein
     * leibliches Geschwister von Freyjas Eltern und haengte deren Paarlinie an Vilmas
     * Herkunftsfamilie. Gilt genauso fuer Nichte/Neffe und die Gross-Stufen. Laeuft vor allen
     * anderen Durchgaengen, damit auch die gegenseitigen/kombinierten Schluesse diese
     * Aussagen nicht mehr sehen.
     */
    private function resolve_by_marriage(array &$resolved): void
    {
        foreach ($this->relations as $index => $relation) {
            $concept = (string)($relation['concept'] ?? '');
            $node = (string)$relation['node'];
            $anchor = (string)$relation['anchor'];
            if (!isset(self::SIBLING_JOINS[$concept]) || !empty($relation['synthetic']) || $node === $anchor
                || !$this->is_person($node) || !$this->is_person($anchor)) {
                continue;
            }
            [$up_anchor, $up_node] = self::SIBLING_JOINS[$concept];
            if ($this->covered_by_marriage($anchor, $up_anchor, $node, $up_node)) {
                $resolved[$index] = true;
            }
        }
    }

    /**
     * Ist die Tante/Onkel-Seite (die ohne Stufen aufwaerts) Partner*in eines leiblichen
     * Geschwisters der Vorfahr*innen der anderen Seite? Cousin*en (beide Seiten mit Stufen)
     * zaehlen nicht -- angeheiratete Cousins gibt es nicht.
     */
    private function covered_by_marriage(string $a, int $up_a, string $x, int $up_x): bool
    {
        if (($up_a !== 0 && $up_x !== 0) || $this->generation($a) - $up_a !== $this->generation($x) - $up_x) {
            return false;
        }
        [$in_law, $other, $levels] = $up_x === 0 ? [$x, $a, $up_a] : [$a, $x, $up_x];
        $ancestors = $this->real_ancestors($other, $levels);
        foreach (array_keys($this->partners[$in_law] ?? []) as $partner) {
            if (!$this->is_person($partner)) {
                continue;
            }
            foreach ($ancestors as $ancestor) {
                if ($ancestor !== $partner && $this->share_blood_family($ancestor, $partner)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Loest die Aussagen des ersten (Halb-/Stiefgeschwister) bzw. des uebrigen Durchgangs einzeln auf. */
    private function resolve_pass(array &$resolved, bool $first_pass): void
    {
        foreach ($this->relations as $index => $relation) {
            $node = (string)$relation['node'];
            $anchor = (string)$relation['anchor'];
            $concept = (string)($relation['concept'] ?? '');
            if (isset($resolved[$index]) || in_array($concept, self::FIRST_PASS, true) !== $first_pass
                || !empty($relation['synthetic']) || $node === $anchor || !$this->is_person($node) || !$this->is_person($anchor)) {
                continue;
            }

            $backup = $this->state;
            $this->secret = !empty($relation['secret']);
            $this->style = (string)($relation['style'] ?? 'solid');
            if ($this->resolve_statement($concept, $node, $anchor)) {
                $resolved[$index] = true;
            } else {
                $this->state = $backup;
            }
        }
    }

    /**
     * Gegenseitige Tanten: Smilla ist Tante von Marjatta (Eltern Aleksi, Vilma) und Vilma
     * Tante von Thore (Eltern Arvi, Smilla). Einzeln ist jeweils offen, ueber welchen
     * Elternteil es laeuft -- beide Aussagen gelten aber genau dann, wenn Smilla und Vilma
     * Geschwister sind. Statt zweier neutraler Grosseltern-Platzhalter, deren Linien sich
     * kreuzen, entsteht diese eine Geschwisterverbindung.
     */
    private function resolve_mutual_aunts(array &$resolved): void
    {
        $aunt_statements = [];
        foreach ($this->relations as $index => $relation) {
            if (isset($resolved[$index]) || !empty($relation['synthetic']) || ($relation['concept'] ?? '') !== 'aunt_uncle'
                || !$this->is_person((string)$relation['node']) || !$this->is_person((string)$relation['anchor'])) {
                continue;
            }
            $aunt_statements[$index] = [(string)$relation['node'], (string)$relation['anchor']];
        }

        foreach ($aunt_statements as $index => [$aunt, $anchor]) {
            if (isset($resolved[$index])) {
                continue;
            }
            foreach (array_keys($this->parents[$anchor] ?? []) as $parent) {
                if ($parent === $aunt || !$this->is_person($parent) || $this->generation($parent) !== $this->generation($aunt)) {
                    continue;
                }
                foreach ($aunt_statements as $other_index => [$other_aunt, $other_anchor]) {
                    if ($other_index === $index || isset($resolved[$other_index]) || $other_aunt !== $parent
                        || !isset($this->parents[$other_anchor][$aunt])) {
                        continue;
                    }
                    $this->secret = !empty($this->relations[$index]['secret']) || !empty($this->relations[$other_index]['secret']);
                    $this->style = 'solid';
                    $this->add_relation($aunt, $parent, 'sibling');
                    $resolved[$index] = true;
                    $resolved[$other_index] = true;
                    continue 3;
                }
            }
        }
    }

    /**
     * Mehrere Tante/Cousin*e-Aussagen derselben Person zusammen lesen: Einzeln ist offen,
     * ueber welchen Elternteil die Verbindung laeuft, zusammen oft nicht mehr. Megan ist
     * Cousine von Jolina (Eltern Jonathan, Irina) UND von Louisa (Eltern Delia, Morgan);
     * Jonathan und Delia sind Geschwister -- also ist Megans Elternteil eines der
     * Flemming-Geschwister. Bleibt ueber alle Aussagen genau eine Geschwistergruppe
     * uebrig, kommt Megans (Platzhalter-)Elternteil bzw. die Tante selbst direkt dort
     * hinein, statt ueber neutrale Grosseltern-Platzhalter quer ueber den Baum zu laufen.
     */
    private function resolve_combined_joins(array &$resolved): void
    {
        $groups = [];
        foreach ($this->relations as $index => $relation) {
            $concept = (string)($relation['concept'] ?? '');
            $node = (string)$relation['node'];
            $anchor = (string)$relation['anchor'];
            if (isset($resolved[$index]) || !empty($relation['synthetic']) || !in_array($concept, ['aunt_uncle', 'cousin'], true)
                || $node === $anchor || !$this->is_person($node) || !$this->is_person($anchor)) {
                continue;
            }
            $groups[$node . '|' . self::SIBLING_JOINS[$concept][1]][] = $index;
        }

        foreach ($groups as $group_key => $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            $node = (string)$this->relations[$indexes[0]]['node'];
            $up_node = (int)substr($group_key, strrpos($group_key, '|') + 1);
            // Schon eingeordnet: dann greift die normale Pruefung in join_ancestors().
            if (($up_node === 1 && !empty($this->parents[$node])) || ($up_node === 0 && !empty($this->blood_families[$node]))) {
                continue;
            }
            $sibling_generation = $this->generation($node) - $up_node;

            $common = null;
            foreach ($indexes as $index) {
                $anchor = (string)$this->relations[$index]['anchor'];
                $parents = array_keys($this->parents[$anchor] ?? []);
                if (!$parents) {
                    continue 2;
                }
                $candidates = [];
                foreach ($parents as $parent) {
                    if (!$this->is_person($parent) || $this->generation($parent) !== $sibling_generation) {
                        continue 3;
                    }
                    $families = $this->blood_families[$parent] ?? [];
                    if (!$families) {
                        $candidates['solo:' . $parent] = $parent;
                    }
                    foreach (array_keys($families) as $family_id) {
                        $candidates[$family_id] = $parent;
                    }
                }
                $common = $common === null ? $candidates : array_intersect_key($common, $candidates);
            }
            if ($common === null || count($common) !== 1) {
                continue;
            }
            $sibling = (string)reset($common);

            $backup = $this->state;
            $this->secret = !empty($this->relations[$indexes[0]]['secret']);
            $this->style = 'solid';
            $member = $up_node === 1
                ? ($this->state['parent_of'][$node] ?? $this->placeholder_parent($node, $node, 1))
                : $node;
            if ($this->generation($member) !== $this->generation($sibling)) {
                $this->state = $backup;
                continue;
            }
            $this->add_relation($member, $sibling, 'sibling');
            foreach ($indexes as $index) {
                $resolved[$index] = true;
            }
        }
    }

    /** "$node ist $concept von $anchor" -- true, wenn die Aussage keine lose Linie mehr braucht. */
    private function resolve_statement(string $concept, string $node, string $anchor): bool
    {
        if (isset(self::SIBLING_JOINS[$concept])) {
            [$up_anchor, $up_node] = self::SIBLING_JOINS[$concept];
            return $this->join_ancestors($anchor, $up_anchor, $node, $up_node);
        }
        switch ($concept) {
            case 'parent_in_law':
                return $this->join_in_law($node, $anchor);
            case 'child_in_law':
                // X ist Schwiegerkind von A  <=>  A ist Elternteil der Partner*in von X.
                return $this->join_in_law($anchor, $node);
            case 'sibling_in_law':
                return $this->join_sibling_in_law($node, $anchor);
            case 'half_sibling':
                return $this->join_attached_sibling($node, $anchor, 'half');
            case 'step_sibling':
                return $this->join_attached_sibling($node, $anchor, 'step');
        }
        return false;
    }

    // --- Tante, Nichte, Cousin*e ---------------------------------------------------------

    private function join_ancestors(string $a, int $up_a, string $x, int $up_x): bool
    {
        if ($this->generation($a) - $up_a !== $this->generation($x) - $up_x) {
            return false;
        }

        // Schon durch eingetragene Personen abgebildet (Ravena steht bereits als Schwester
        // von Mama im Baum): nichts hinzufuegen, nur die lose Linie entfaellt.
        foreach ($this->real_ancestors($a, $up_a) as $u) {
            foreach ($this->real_ancestors($x, $up_x) as $v) {
                if ($u !== $v && $this->share_blood_family($u, $v)) {
                    return true;
                }
            }
        }

        $side_a = $this->side($a, $up_a);
        $side_x = $side_a !== null ? $this->side($x, $up_x) : null;
        return $side_x !== null && $this->join_siblings($side_a, $side_x);
    }

    /**
     * Echte leibliche Vorfahr*innen genau $levels Stufen ueber $key. Bei angehaengten
     * Halb-/Stiefgeschwistern zaehlt das Elternpaar, an dessen Balken sie haengen -- eine*r
     * davon ist Elternteil.
     */
    private function real_ancestors(string $key, int $levels): array
    {
        $current = [$key];
        for ($i = 0; $i < $levels; $i++) {
            $next = [];
            foreach ($current as $member) {
                $parents = $this->parents[$member] ?? $this->state['attached_parents'][$member] ?? [];
                foreach (array_keys($parents) as $parent) {
                    $next[$parent] = true;
                }
            }
            $current = array_keys($next);
        }
        return $current;
    }

    /**
     * Vorfahr*innen-Stufe $levels ueber $key als "Seite": ['keys', 'origin', 'depth'].
     * Ein Schluessel ist eindeutig (die Person selbst oder ein Platzhalter), zwei heissen
     * "eine*r von beiden" (echtes Elternpaar). origin/depth benennen den Platzhalter:
     * depth 2 ueber Jolina = "Grosselternteil von Jolina".
     */
    private function side(string $key, int $levels): ?array
    {
        $side = ['keys' => [$key], 'origin' => $key, 'depth' => 0];
        for ($i = 0; $i < $levels && $side !== null; $i++) {
            $side = $this->parent_side($side);
        }
        return $side;
    }

    private function parent_side(array $side): ?array
    {
        $depth = $side['depth'] + 1;
        if (count($side['keys']) > 1) {
            return ['keys' => [$this->neutral_parent($side)], 'origin' => $side['origin'], 'depth' => $depth];
        }

        $key = $side['keys'][0];
        if (isset($this->state['parent_of'][$key])) {
            return ['keys' => [$this->state['parent_of'][$key]], 'origin' => $side['origin'], 'depth' => $depth];
        }
        if (isset($this->state['either_of'][$key])) {
            return ['keys' => $this->state['either_of'][$key], 'origin' => $key, 'depth' => 1];
        }

        $parents = $this->is_placeholder($key) ? [] : array_keys($this->parents[$key] ?? []);
        foreach ($parents as $parent) {
            // Ein Familien-Kasten steht fuer mehrere Personen -- kein bestimmbarer Weg.
            if (!$this->is_person($parent)) {
                return null;
            }
        }
        if (count($parents) > 2) {
            return null;
        }
        if (empty($parents)) {
            $parent = $this->placeholder_parent($key, $side['origin'], $depth);
            return ['keys' => [$parent], 'origin' => $side['origin'], 'depth' => $depth];
        }
        if (count($parents) === 1) {
            // Der zweite Elternteil fehlt -- die Verbindung kann genauso gut ueber ihn laufen.
            $parents[] = $this->placeholder_parent($key, $key, 1, 'coparent');
        }
        sort($parents);
        $this->state['either_of'][$key] = $parents;
        return ['keys' => $parents, 'origin' => $key, 'depth' => 1];
    }

    /** Verbindet zwei Seiten derselben Generation als Geschwister. */
    private function join_siblings(array $one, array $two): bool
    {
        if (count($one['keys']) > 1 && count($two['keys']) === 1) {
            [$one, $two] = [$two, $one];
        }
        if ($this->generation($one['keys'][0]) !== $this->generation($two['keys'][0])) {
            return false;
        }

        if (count($two['keys']) === 1) {
            if ($one['keys'][0] === $two['keys'][0]) {
                return false;
            }
            // Die Geschwistergruppe teilt danach ihre Eltern (build_structure()).
            $this->add_relation($two['keys'][0], $one['keys'][0], 'sibling');
            return true;
        }

        if (count($one['keys']) === 1) {
            $single = $one['keys'][0];
            if (in_array($single, $two['keys'], true)) {
                return false;
            }
            if (isset($this->state['parent_of'][$single])) {
                $this->attach($this->state['parent_of'][$single], null, $two['keys']);
                return true;
            }
            if (!$this->is_placeholder($single) && !empty($this->parents[$single])) {
                // Ravenas eigene Eltern sind bekannt: deren Balken bekommt den Abgang zum Paar.
                $this->attach(null, $single, $two['keys']);
                return true;
            }
            $neutral = $this->neutral_parent($two);
            $this->add_relation($single, $neutral, 'child');
            $this->state['parent_of'][$single] = $neutral;
            return true;
        }

        // Beide Seiten mehrdeutig (Cousin*e, beide mit bekannten Eltern).
        if ($one['keys'] === $two['keys']) {
            return false;
        }
        $neutral = $this->neutral_parent($one);
        $this->attach($neutral, null, $two['keys']);
        $this->add_origin($neutral, $two['origin']);
        return true;
    }

    /** Platzhalter-Elternteil von $child (bzw. zweiter Elternteil bei $slot = 'coparent'). */
    private function placeholder_parent(string $child, string $origin, int $depth, string $slot = 'parent'): string
    {
        $key = 'placeholder:' . $slot . ':' . $child;
        if (!isset($this->state['nodes'][$key])) {
            $this->add_placeholder($key, 'ancestor', [$origin], $this->generation($child) - 1, $depth);
            $this->add_relation($key, $child, 'parent');
            if ($slot === 'parent') {
                $this->state['parent_of'][$child] = $key;
            }
        }
        return $key;
    }

    /** Neutraler Platzhalter ueber einem mehrdeutigen Elternpaar ("eine*r von beiden ist sein Kind"). */
    private function neutral_parent(array $side): string
    {
        $key = 'placeholder:neutral:' . implode('|', $side['keys']);
        if (!isset($this->state['nodes'][$key])) {
            $this->add_placeholder($key, 'ancestor', [$side['origin']], $this->generation($side['keys'][0]) - 1, $side['depth'] + 1);
            $this->attach($key, null, $side['keys']);
        } else {
            $this->add_origin($key, $side['origin']);
        }
        return $key;
    }

    // --- Schwiegerfamilie ---------------------------------------------------------------

    /** $parent ist Elternteil der Partner*in von $person. */
    private function join_in_law(string $parent, string $person): bool
    {
        if ($this->generation($parent) !== $this->generation($person) - 1) {
            return false;
        }
        $partners = array_keys($this->partners[$person] ?? []);
        foreach ($partners as $partner) {
            if (isset($this->parents[$partner][$parent])) {
                return true;
            }
        }
        // Mehrere Partner*innen (Ehe + Ex): welche gemeint ist, laesst sich nicht sagen.
        if (count($partners) > 1) {
            return false;
        }

        if (empty($partners)) {
            $partner = 'placeholder:partner:' . $person;
            if (!isset($this->state['nodes'][$partner])) {
                $this->add_placeholder($partner, 'partner', [$person], $this->generation($person), 1);
                // Kein Co-Elternteil: der Platzhalter soll nicht Elternteil der Kinder von $person werden.
                $this->add_relation($partner, $person, 'spouse', ['no_coparent' => true]);
            }
        } else {
            $partner = $partners[0];
            if (!$this->is_person($partner) || $this->generation($partner) !== $this->generation($person)
                || count($this->parents[$partner] ?? []) >= 2) {
                return false;
            }
        }
        $this->add_relation($parent, $partner, 'parent');
        return true;
    }

    /**
     * Schwager*in: Partner*in eines Geschwisters ODER Geschwister der Partner*in. Aufgeloest
     * wird nur, wenn genau eine Seite eine Partner*in hat -- sonst bleibt die lose Linie.
     */
    private function join_sibling_in_law(string $x, string $a): bool
    {
        if ($this->generation($x) !== $this->generation($a)) {
            return false;
        }
        $partners_a = array_keys($this->partners[$a] ?? []);
        $partners_x = array_keys($this->partners[$x] ?? []);
        foreach ($partners_a as $partner) {
            if ($this->share_blood_family($partner, $x)) {
                return true;
            }
        }
        foreach ($partners_x as $partner) {
            if ($this->share_blood_family($partner, $a)) {
                return true;
            }
        }

        foreach ([[$x, $partners_a, $partners_x], [$a, $partners_x, $partners_a]] as [$sibling, $partners, $others]) {
            if (count($partners) === 1 && empty($others) && $this->is_person($partners[0])
                && $this->generation($partners[0]) === $this->generation($sibling)) {
                $this->add_relation($sibling, $partners[0], 'sibling');
                return true;
            }
        }
        return false;
    }

    // --- Halb- und Stiefgeschwister -----------------------------------------------------

    /**
     * Halbgeschwister haengen als Zweitfamilie ('half') am Balken der bekannten Eltern, Stief-
     * geschwister als 'step' -- "Kind (einer*s) dieser Eltern" bzw. "Stiefkind". Das Frontend
     * zeichnet das als eigenes Stueck in der Farbe der Zweitfamilien.
     */
    private function join_attached_sibling(string $x, string $a, string $group): bool
    {
        if ($this->generation($x) !== $this->generation($a)) {
            return false;
        }
        $parents_a = array_keys($this->parents[$a] ?? []);
        $parents_x = array_keys($this->parents[$x] ?? []);

        if (array_intersect_key($this->any_parents[$x] ?? [], $this->parents[$a] ?? [])
            || array_intersect_key($this->any_parents[$a] ?? [], $this->parents[$x] ?? [])) {
            return true;
        }
        if ($group === 'step') {
            foreach ($parents_a as $parent) {
                foreach (array_keys($this->partners[$parent] ?? []) as $partner) {
                    if (isset($this->parents[$x][$partner])) {
                        return true;
                    }
                }
            }
        }

        if (!empty($parents_a) && (empty($parents_x) || $group === 'step')) {
            $this->attach_children($x, $parents_a, $group);
            return true;
        }
        if (!empty($parents_x) && empty($parents_a)) {
            $this->attach_children($a, $parents_x, $group);
            return true;
        }
        if (!empty($parents_a)) {
            // Halbgeschwister mit voellig verschiedenen bekannten Eltern: widerspruechlich.
            return false;
        }

        // Beide ohne bekannte Eltern.
        if ($group === 'half') {
            $pair = [$a, $x];
            sort($pair);
            $shared = 'placeholder:shared:' . implode('|', $pair);
            if (!isset($this->state['nodes'][$shared])) {
                $this->add_placeholder($shared, 'ancestor', $pair, $this->generation($a) - 1, 1);
                $this->add_relation($shared, $a, 'parent');
                $this->add_relation($shared, $x, 'parent');
            }
            return true;
        }
        $parent_a = $this->state['parent_of'][$a] ?? $this->placeholder_parent($a, $a, 1);
        $parent_x = $this->state['parent_of'][$x] ?? $this->placeholder_parent($x, $x, 1);
        if ($parent_a === $parent_x) {
            return false;
        }
        $this->add_relation($parent_x, $parent_a, 'spouse', ['no_coparent' => true]);
        return true;
    }

    private function attach_children(string $child, array $parents, string $group): void
    {
        foreach ($parents as $parent) {
            $this->state['attached_parents'][$child][$parent] = true;
            $this->add_relation($child, $parent, 'child', [
                'group'  => $group,
                'attach' => true,
                'style'  => $this->style,
            ]);
        }
    }

    // --- Hilfen ---------------------------------------------------------------------------

    private function add_placeholder(string $key, string $role, array $of, int $generation, int $depth): void
    {
        $this->state['nodes'][$key] = [
            'key'               => $key,
            'type'              => 'placeholder',
            'id'                => 0,
            'person_id'         => 0,
            'name'              => '',
            'gender'            => -1,
            'placeholder_role'  => $role,
            'placeholder_of'    => $of,
            'placeholder_depth' => $depth,
            'can_edit'          => false,
            'has_more'          => false,
            'generation'        => $generation,
        ];
        foreach ($of as $origin) {
            $this->state['edges'][$origin . '>' . $key] = ['from' => $origin, 'to' => $key];
        }
    }

    /** Ein neutraler Platzhalter kann fuer mehrere Personen stehen ("Grosselternteil von Jolina und Christopher"). */
    private function add_origin(string $key, string $origin): void
    {
        if (!isset($this->state['nodes'][$key]) || in_array($origin, $this->state['nodes'][$key]['placeholder_of'], true)) {
            return;
        }
        $this->state['nodes'][$key]['placeholder_of'][] = $origin;
        $this->state['edges'][$origin . '>' . $key] = ['from' => $origin, 'to' => $key];
    }

    private function attach(?string $parent, ?string $child, array $group): void
    {
        $id = ($parent !== null ? 'parent:' . $parent : 'child:' . $child) . '>' . implode('|', $group);
        $this->state['attachments'][$id] = [
            'parent' => $parent,
            'child'  => $child,
            'group'  => $group,
            'secret' => $this->secret,
        ];
    }

    private function add_relation(string $node, string $anchor, string $concept, array $extra = []): void
    {
        $this->state['relations'][] = $extra + [
            'node'      => $node,
            'anchor'    => $anchor,
            'concept'   => $concept,
            'offset'    => self::OFFSETS[$concept],
            'label'     => '',
            'style'     => 'solid',
            'secret'    => $this->secret,
            'primary'   => false,
            'synthetic' => true,
        ];
    }

    private function share_blood_family(string $a, string $b): bool
    {
        return (bool)array_intersect_key($this->blood_families[$a] ?? [], $this->blood_families[$b] ?? []);
    }

    private function generation(string $key): int
    {
        return (int)($this->nodes[$key]['generation'] ?? $this->state['nodes'][$key]['generation'] ?? 0);
    }

    private function is_person(string $key): bool
    {
        return in_array($this->nodes[$key]['type'] ?? '', ['person', 'self'], true);
    }

    private function is_placeholder(string $key): bool
    {
        return isset($this->state['nodes'][$key]);
    }
}
