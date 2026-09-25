<?php
/**
 * relatives - StructureInference
 *
 * Leitet fuer migrierte Altdaten die Stammbaum-Struktur ab. Das Altplugin speicherte jede
 * Person nur "relativ zum Charakter" (Onkel, Cousine ...). Damit der Baum echte Familien
 * zeichnen kann, wird je Zuordnung eine Bezugsperson gesucht und der Grad relativ zu ihr
 * umgeschrieben -- so, dass die Komposition (RelationshipTypes::COMPOSE) wieder exakt das
 * alte Konzept ergibt ("Onkel mütterlicherseits" -> "Bruder von Mutter" = Onkel).
 *
 * Belege, in dieser Rangfolge:
 * 1. Fakten aus den Listen ALLER Charaktere des Altplugins (auch geloeschter Accounts):
 *    wer bei einem Charakter "Mutter" und "Vater" ist, ist ein Paar; "Mutter" und
 *    "Bruder" derselben Liste sind Elternteil und Kind; zwei "Geschwister" sind
 *    Geschwister. Ein Account, dessen Name genau einer Person entspricht, gilt als diese
 *    Person (seine Liste beschreibt dann deren Verwandte).
 * 2. Zusaetze des alten Grades: "mütterlicherseits"/"väterlicherseits" legen die Seite
 *    fest, "(Stief-, Adoptiv-, angeheiratet,...)" heisst angeheiratet, "(entfernt)" wird
 *    nie zugeordnet (dafuer gibt es kein Konzept).
 * 3. Eindeutigkeit: genau eine passende Bezugsperson -- aber nur, wo die Menge moeglicher
 *    Bezugspersonen tatsaechlich abgeschlossen ist ("Onkel väterlicherseits" -> der eine
 *    Vater). Bei offenen Graden (Cousin*e -> Kind einer Tante, Nichte/Neffe -> Kind eines
 *    Geschwisters ...) beweist "nur eine Tante eingetragen" nichts; dort zaehlen nur Fakten
 *    oder der Nachname (siehe GUESS_RULES).
 * 4. Nachname als letzter Tie-Breaker.
 * Alles andere bleibt unveraendert (lose Verbindung) -- lieber nicht zugeordnet als falsch.
 * Pro Runde wird nur EINE Entscheidung getroffen und danach wieder mit den Fakten
 * begonnen, damit schwache Belege nie einer spaeter moeglichen Fakten-Entscheidung
 * zuvorkommen.
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_StructureInference
{
    public const SIDE_MATERNAL = 'maternal';
    public const SIDE_PATERNAL = 'paternal';

    public const QUALIFIER_BLOOD = 'blood';
    public const QUALIFIER_STEP = 'step';
    public const QUALIFIER_DISTANT = 'distant';

    /** Konzepte, die ohne Bezugsperson direkt am Charakter die Struktur tragen. */
    private const DIRECT_CONCEPTS = [
        'parent', 'step_parent', 'adoptive_parent',
        'sibling', 'half_sibling', 'step_sibling', 'adoptive_sibling',
        'child', 'step_child', 'adoptive_child',
        'spouse', 'ex_spouse', 'partner', 'fiance',
    ];

    private const PARTNER_CONCEPTS = ['spouse', 'ex_spouse', 'partner', 'fiance'];

    /** Konzepte, bei denen die Seite (mütterlicher-/väterlicherseits) die Bezugsperson einschraenkt. */
    private const SIDED_CONCEPTS = ['aunt_uncle', 'grandparent', 'great_grandparent'];

    /**
     * "Konzept|Konzept der Bezugsperson|neuer Grad" fuer Regeln, deren moegliche
     * Bezugspersonen eine OFFENE Menge sind: eine Cousine kann Kind jeder Tante und jedes
     * Onkels sein, auch nicht eingetragener. Dass in einer Liste nur eine Tante steht, ist
     * deshalb kein Beleg -- genau so entstand im Testforum "Christopher Lancaster ist Sohn
     * von Delia" aus Jolinas Liste. Fuer diese Regeln reicht Eindeutigkeit nicht.
     */
    private const GUESS_RULES = [
        'cousin|aunt_uncle|child'            => true,
        'niece_nephew|sibling|child'         => true,
        'niece_nephew|half_sibling|child'    => true,
        'niece_nephew|adoptive_sibling|child' => true,
        'grandchild|child|child'             => true,
        'child_in_law|child|spouse'          => true,
        'sibling_in_law|sibling|spouse'      => true,
    ];

    /**
     * Nur fuer replay_legacy_guesses(): spielt die Ableitung mit den ALTEN Regeln nach, die
     * Eindeutigkeit auch bei offenen Graden genuegen liessen -- so laesst sich erkennen,
     * welche migrierten Verbindungen damals geraten wurden.
     */
    private static bool $legacy_rules = false;

    private static function is_guess(string $concept, string $anchor_concept, string $relation): bool
    {
        return isset(self::GUESS_RULES[$concept . '|' . $anchor_concept . '|' . $relation]);
    }

    /**
     * Konzept der Person => Qualifier => [[Konzept der Bezugsperson, neuer Grad relativ zu
     * ihr], ...]. COMPOSE[Bezugsperson][neuer Grad] muss wieder das Konzept der Person
     * ergeben (wird zur Laufzeit geprueft).
     */
    private const RULES = [
        'aunt_uncle' => [
            'blood' => [['parent', 'sibling']],
            'step'  => [['aunt_uncle', 'spouse']],
        ],
        'grandparent' => [
            'blood' => [['parent', 'parent']],
            'step'  => [['parent', 'step_parent']],
        ],
        'great_grandparent' => [
            'blood' => [['grandparent', 'parent']],
        ],
        'cousin' => [
            'blood' => [['aunt_uncle', 'child']],
            'step'  => [['aunt_uncle', 'child']],
        ],
        'niece_nephew' => [
            'blood' => [['sibling', 'child'], ['half_sibling', 'child'], ['adoptive_sibling', 'child']],
            'step'  => [['sibling', 'child'], ['half_sibling', 'child'], ['adoptive_sibling', 'child']],
        ],
        'grandchild'     => ['blood' => [['child', 'child']]],
        'child_in_law'   => ['blood' => [['child', 'spouse']]],
        'parent_in_law'  => ['blood' => [['spouse', 'parent']]],
        'sibling_in_law' => ['blood' => [['spouse', 'sibling'], ['sibling', 'spouse']]],
    ];

    /** Seite und Qualifier eines alten Grad-Labels. */
    public static function label_details(string $label): array
    {
        $side = null;
        if (preg_match('/mütterlicherseits/iu', $label)) {
            $side = self::SIDE_MATERNAL;
        } elseif (preg_match('/väterlicherseits/iu', $label)) {
            $side = self::SIDE_PATERNAL;
        }

        $qualifier = self::QUALIFIER_BLOOD;
        if (preg_match('/\(\s*entfernt\s*\)/iu', $label)) {
            $qualifier = self::QUALIFIER_DISTANT;
        } elseif (preg_match('/\(.*(stief|adoptiv|angeheiratet).*\)/iu', $label)) {
            $qualifier = self::QUALIFIER_STEP;
        }

        return ['side' => $side, 'qualifier' => $qualifier];
    }

    // --- Fakten ---------------------------------------------------------------------------

    public static function empty_facts(): array
    {
        return ['partner' => [], 'sibling' => [], 'parent_of' => []];
    }

    /** $kind: 'partner' | 'sibling' (symmetrisch) | 'parent_of' ($a ist Elternteil von $b). */
    public static function add_fact(array &$facts, string $kind, int $a, int $b): void
    {
        if ($a <= 0 || $b <= 0 || $a === $b) {
            return;
        }
        $facts[$kind][$a][$b] = true;
        if ($kind !== 'parent_of') {
            $facts[$kind][$b][$a] = true;
        }
    }

    /** Ist "X ist <relation> von Y" durch Fakten belegt? */
    public static function has_fact(array $facts, int $x, string $relation, int $y): bool
    {
        if (in_array($relation, self::PARTNER_CONCEPTS, true)) {
            return isset($facts['partner'][$x][$y]);
        }
        if ($relation === 'sibling') {
            return isset($facts['sibling'][$x][$y]);
        }
        if ($relation === 'child') {
            return isset($facts['parent_of'][$y][$x]);
        }
        if ($relation === 'parent') {
            return isset($facts['parent_of'][$x][$y]);
        }
        return false;
    }

    /**
     * Fakten aus der Liste eines Charakters.
     *
     * @param array $entries        [['person_id' => int, 'concept' => string, 'gender' => int, 'qualifier' => string], ...]
     * @param int   $self_person_id Person, die diesem Charakter entspricht (0 = keine)
     */
    public static function collect_list_facts(array &$facts, array $entries, int $self_person_id): void
    {
        $entries = array_values(array_filter($entries, static function ($entry) {
            return $entry['person_id'] > 0 && $entry['concept'] !== null && $entry['qualifier'] !== self::QUALIFIER_DISTANT;
        }));

        if ($self_person_id > 0) {
            foreach ($entries as $entry) {
                if ($entry['qualifier'] !== self::QUALIFIER_BLOOD) {
                    continue;
                }
                if ($entry['concept'] === 'parent') {
                    self::add_fact($facts, 'parent_of', $entry['person_id'], $self_person_id);
                } elseif ($entry['concept'] === 'child') {
                    self::add_fact($facts, 'parent_of', $self_person_id, $entry['person_id']);
                } elseif ($entry['concept'] === 'sibling') {
                    self::add_fact($facts, 'sibling', $entry['person_id'], $self_person_id);
                } elseif (in_array($entry['concept'], self::PARTNER_CONCEPTS, true)) {
                    self::add_fact($facts, 'partner', $entry['person_id'], $self_person_id);
                }
            }
        }

        $count = count($entries);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                self::pair_facts($facts, $entries[$i], $entries[$j]);
                self::pair_facts($facts, $entries[$j], $entries[$i]);
            }
        }
    }

    private static function pair_facts(array &$facts, array $a, array $b): void
    {
        $a_blood = $a['qualifier'] === self::QUALIFIER_BLOOD;
        $b_blood = $b['qualifier'] === self::QUALIFIER_BLOOD;
        $opposite = in_array($a['gender'], [0, 1], true) && in_array($b['gender'], [0, 1], true) && $a['gender'] !== $b['gender'];

        if ($a['concept'] === 'parent' && $a_blood && in_array($b['concept'], ['parent', 'step_parent'], true) && $opposite) {
            self::add_fact($facts, 'partner', $a['person_id'], $b['person_id']);
        }
        if ($a['concept'] === 'parent' && $a_blood && $b['concept'] === 'sibling' && $b_blood) {
            self::add_fact($facts, 'parent_of', $a['person_id'], $b['person_id']);
        }
        if ($a['concept'] === 'sibling' && $b['concept'] === 'sibling' && $a_blood && $b_blood) {
            self::add_fact($facts, 'sibling', $a['person_id'], $b['person_id']);
        }
        if ($a['concept'] === 'child' && $b['concept'] === 'child' && $a_blood && $b_blood) {
            self::add_fact($facts, 'sibling', $a['person_id'], $b['person_id']);
        }
    }

    // --- Ableitung fuer einen Charakter ---------------------------------------------------

    /**
     * Ermittelt fuer die Personen EINES Charakters Bezugspersonen (reine Funktion, ohne DB).
     *
     * @param array $rows person_id => [
     *   'person_id' => int, 'name' => string, 'concept' => ?string (Konzept des aktuellen Grades),
     *   'type_gender' => int, 'related_to' => ?int, 'side' => ?string, 'qualifier' => string,
     *   'inferable' => bool]
     * @return array ['decisions' => [person_id => ['related_to' => int, 'concept' => string]],
     *                'ambiguous' => int, 'unresolved' => int]
     */
    public static function infer_owner(array $rows, array $facts): array
    {
        $decisions = [];
        $max_rounds = count($rows) + 5;

        for ($round = 0; $round < $max_rounds; $round++) {
            $decided = false;
            foreach (['facts', 'unique', 'surname'] as $level) {
                foreach (array_keys($rows) as $x) {
                    if (!self::is_open($rows[$x])) {
                        continue;
                    }
                    $candidates = self::candidates($rows, $facts, $x);
                    if (empty($candidates)) {
                        continue;
                    }
                    if (self::has_pending_fact($rows, $facts, $x, $candidates)) {
                        // Ein Fakt verweist auf eine noch nicht platzierte Person: warten, sonst
                        // gewinnt der zuerst platzierte von zwei belegten Elternteilen.
                        continue;
                    }
                    if ($level !== 'facts' && self::has_open_potential_anchor($rows, $facts, $x)) {
                        // Schwache Belege erst, wenn keine moegliche Bezugsperson mehr offen ist --
                        // sonst entscheidet z.B. der Nachname zwischen einem Teil der Onkel.
                        continue;
                    }
                    $choice = self::choose($rows, $candidates, $level, $x);
                    if ($choice === null) {
                        continue;
                    }
                    $rows[$x]['related_to'] = $choice['anchor'];
                    $rows[$x]['concept'] = $choice['relation'];
                    $decisions[$x] = [
                        'related_to'     => $choice['anchor'],
                        'concept'        => $choice['relation'],
                        'level'          => $level,
                        'anchor_concept' => $choice['anchor_concept'],
                    ];
                    $decided = true;
                    break 2;
                }
            }
            if (!$decided) {
                break;
            }
        }

        $ambiguous = 0;
        $unresolved = 0;
        foreach (array_keys($rows) as $x) {
            if (!self::is_open($rows[$x])) {
                continue;
            }
            if (empty(self::candidates($rows, $facts, $x))) {
                $unresolved++;
            } else {
                $ambiguous++;
            }
        }

        return ['decisions' => $decisions, 'ambiguous' => $ambiguous, 'unresolved' => $unresolved];
    }

    private static function is_open(array $row): bool
    {
        return $row['inferable'] && $row['related_to'] === null && $row['concept'] !== null
            && isset(self::RULES[$row['concept']][$row['qualifier']]);
    }

    /** @return array [['anchor' => int, 'relation' => string, 'fact' => bool], ...] */
    private static function candidates(array $rows, array $facts, int $x): array
    {
        $row = $rows[$x];
        $result = [];
        foreach (self::RULES[$row['concept']][$row['qualifier']] ?? [] as [$anchor_concept, $relation]) {
            if (Relatives_RelationshipTypes::compose_concept($anchor_concept, $relation) !== $row['concept']) {
                continue;
            }
            foreach ($rows as $y => $candidate) {
                if ($y === $x || self::chain_contains($rows, $y, $x)) {
                    continue;
                }
                if (self::resolved_concept($rows, $y) !== $anchor_concept) {
                    continue;
                }
                $has_fact = self::has_fact($facts, $x, $relation, (int)$y);
                $placed = self::is_placed($rows, $y);
                // Eine selbst lose Bezugsperson (Onkel ohne eingetragene Mutter) nur mit Fakt:
                // "Evelyn + Harvey sind Eltern von #794" belegt Evelyn als Ehefrau von Harvey,
                // auch wenn Harvey nur lose am Charakter haengt. Nie an "(entfernt)", und nicht
                // beim Nachspielen der alten Regeln (replay_legacy_guesses()).
                if (!$placed && (!$has_fact || self::$legacy_rules || $candidate['qualifier'] === self::QUALIFIER_DISTANT)) {
                    continue;
                }
                if ($row['side'] !== null && in_array($row['concept'], self::SIDED_CONCEPTS, true)
                    && self::side_of($rows, $y) !== $row['side']) {
                    continue;
                }
                if (in_array($relation, self::PARTNER_CONCEPTS, true)) {
                    // Angeheiratet: nur an Blutsverwandte und gegengeschlechtlich.
                    if (self::is_partner_anchored($candidate)) {
                        continue;
                    }
                    if (in_array($row['type_gender'], [0, 1], true) && in_array($candidate['type_gender'], [0, 1], true)
                        && $row['type_gender'] === $candidate['type_gender']) {
                        continue;
                    }
                }
                $result[] = [
                    'anchor'         => (int)$y,
                    'relation'       => $relation,
                    'anchor_concept' => $anchor_concept,
                    'fact'           => $has_fact,
                    'placed'         => $placed,
                ];
            }
        }
        return $result;
    }

    private static function choose(array $rows, array $candidates, string $level, int $x): ?array
    {
        if ($level !== 'facts') {
            // Lose Bezugspersonen sind nur fuer Fakten-Entscheidungen zugelassen (candidates()).
            $candidates = array_values(array_filter($candidates, static function ($candidate) {
                return $candidate['placed'];
            }));
        }
        $fact_backed = array_values(array_filter($candidates, static function ($candidate) {
            return $candidate['fact'];
        }));

        if ($level === 'facts') {
            $pool = $fact_backed;
        } elseif (!empty($fact_backed)) {
            if ($level === 'unique') {
                return null; // mehrere Fakten: erst der Nachname darf entscheiden
            }
            $pool = self::filter_surname($rows, $fact_backed, $x);
        } else {
            $pool = array_values(array_filter($candidates, static function ($candidate) use ($rows, $x) {
                return !in_array($candidate['relation'], self::PARTNER_CONCEPTS, true)
                    || !self::has_partner($rows, $candidate['anchor'], $x);
            }));
            if ($level === 'unique' && !self::$legacy_rules) {
                // Offene Grade: "nur eine Tante eingetragen" ist kein Beleg (GUESS_RULES).
                $concept = (string)$rows[$x]['concept'];
                $pool = array_values(array_filter($pool, static function ($candidate) use ($concept) {
                    return !self::is_guess($concept, (string)$candidate['anchor_concept'], (string)$candidate['relation']);
                }));
            }
            if ($level === 'surname') {
                // Bei offenen Graden beweist ein passender Nachname nur etwas, wenn er
                // zwischen mehreren moeglichen Bezugspersonen unterscheidet (Siri "Vaisey"
                // unter mehreren Tanten und Onkeln). Gibt es ohnehin nur eine Kandidatin,
                // ist "Damien Flemming -> Delia Flemming" in einer Familie, die ueberall
                // Flemming heisst, genauso geraten wie ohne Nachnamen.
                $concept = (string)$rows[$x]['concept'];
                $open_ended = count(array_filter($pool, static function ($candidate) use ($concept) {
                    return self::is_guess($concept, (string)$candidate['anchor_concept'], (string)$candidate['relation']);
                })) > 0;
                if ($open_ended && !self::$legacy_rules && count(self::collapse_couples($rows, $pool)) < 2) {
                    return null;
                }
                $pool = self::filter_surname($rows, $pool, $x);
            }
        }

        $pool = self::collapse_couples($rows, $pool);
        return count($pool) === 1 ? $pool[0] : null;
    }

    /** Kandidaten-Paare auf das blutsverwandte Mitglied reduzieren (Cousine gehoert zu Onkel UND Tante). */
    private static function collapse_couples(array $rows, array $pool): array
    {
        $anchors = [];
        foreach ($pool as $candidate) {
            $anchors[$candidate['anchor']] = true;
        }
        $result = [];
        foreach ($pool as $candidate) {
            $row = $rows[$candidate['anchor']];
            if (self::is_partner_anchored($row) && isset($anchors[$row['related_to']])) {
                continue;
            }
            $result[$candidate['anchor']] = $candidate;
        }
        return array_values($result);
    }

    private static function filter_surname(array $rows, array $pool, int $x): array
    {
        $surname = self::surname($rows[$x]['name']);
        if ($surname === '') {
            return [];
        }
        return array_values(array_filter($pool, static function ($candidate) use ($rows, $surname) {
            return self::surname($rows[$candidate['anchor']]['name']) === $surname;
        }));
    }

    private static function surname(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name));
        return count($parts) > 1 ? mb_strtolower((string)end($parts)) : '';
    }

    /** Gibt es einen Fakt zu einer Person, die (noch) nicht als Kandidat in Frage kommt? */
    private static function has_pending_fact(array $rows, array $facts, int $x, array $candidates): bool
    {
        $candidate_anchors = [];
        foreach ($candidates as $candidate) {
            $candidate_anchors[$candidate['relation'] . '|' . $candidate['anchor']] = true;
        }
        $row = $rows[$x];
        foreach (self::RULES[$row['concept']][$row['qualifier']] ?? [] as [$anchor_concept, $relation]) {
            foreach (array_keys($rows) as $y) {
                // Nur Personen, die ueberhaupt Bezugsperson sein koennen (ihr Konzept zum
                // Charakter aendert sich durch das Platzieren nicht). Sonst blockieren sich
                // z.B. Onkel gegenseitig, die laut Fakten Geschwister sind.
                if ($y !== $x && self::has_fact($facts, $x, $relation, (int)$y)
                    && !isset($candidate_anchors[$relation . '|' . $y])
                    && !self::is_placed($rows, (int)$y)
                    && self::resolved_concept($rows, (int)$y) === $anchor_concept) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Gibt es eine noch nicht platzierte, aber platzierbare Person, die spaeter als
     * Bezugsperson in Frage kaeme? Angeheiratete kommen fuer angeheiratete nicht in Frage
     * (sonst blockieren sie sich gegenseitig).
     */
    private static function has_open_potential_anchor(array $rows, array $facts, int $x): bool
    {
        $row = $rows[$x];
        foreach (self::RULES[$row['concept']][$row['qualifier']] ?? [] as [$anchor_concept, $relation]) {
            $partner_relation = in_array($relation, self::PARTNER_CONCEPTS, true);
            foreach ($rows as $y => $other) {
                if ($y === $x || self::is_placed($rows, (int)$y) || !self::is_open($other)
                    || self::resolved_concept($rows, (int)$y) !== $anchor_concept) {
                    continue;
                }
                if ($partner_relation && ($other['qualifier'] === self::QUALIFIER_STEP
                    || (in_array($row['type_gender'], [0, 1], true) && $row['type_gender'] === $other['type_gender']))) {
                    continue;
                }
                if (!empty(self::candidates($rows, $facts, (int)$y))) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function resolved_concept(array $rows, int $person_id, int $depth = 0): ?string
    {
        if ($depth > 20 || !isset($rows[$person_id]) || $rows[$person_id]['concept'] === null) {
            return null;
        }
        $row = $rows[$person_id];
        if ($row['related_to'] === null || !isset($rows[$row['related_to']])) {
            return $row['concept'];
        }
        $anchor = self::resolved_concept($rows, $row['related_to'], $depth + 1);
        return $anchor === null ? null : Relatives_RelationshipTypes::compose_concept($anchor, $row['concept']);
    }

    /** Steht die Person strukturell im Baum (direkt am Charakter oder ueber eine Kette dorthin)? */
    private static function is_placed(array $rows, int $person_id, int $depth = 0): bool
    {
        if ($depth > 20 || !isset($rows[$person_id])) {
            return false;
        }
        $row = $rows[$person_id];
        if ($row['related_to'] === null || !isset($rows[$row['related_to']])) {
            return in_array($row['concept'], self::DIRECT_CONCEPTS, true);
        }
        return self::is_placed($rows, $row['related_to'], $depth + 1);
    }

    private static function chain_contains(array $rows, int $start, int $needle): bool
    {
        $current = $start;
        for ($steps = 0; $steps <= 20 && $current !== null && isset($rows[$current]); $steps++) {
            if ($current === $needle) {
                return true;
            }
            $current = $rows[$current]['related_to'];
        }
        return false;
    }

    /** Seite einer Person aus Sicht des Charakters: ueber die Kette bis zum Elternteil. */
    private static function side_of(array $rows, int $person_id): ?string
    {
        $current = $person_id;
        for ($steps = 0; $steps <= 20 && $current !== null && isset($rows[$current]); $steps++) {
            $row = $rows[$current];
            if ($row['related_to'] === null) {
                if ($row['concept'] === 'parent') {
                    if ($row['type_gender'] === 1) {
                        return self::SIDE_MATERNAL;
                    }
                    return $row['type_gender'] === 0 ? self::SIDE_PATERNAL : null;
                }
                return $row['side'];
            }
            $current = $row['related_to'];
        }
        return null;
    }

    private static function is_partner_anchored(array $row): bool
    {
        return $row['related_to'] !== null && in_array($row['concept'], self::PARTNER_CONCEPTS, true);
    }

    private static function has_partner(array $rows, int $person_id, int $except): bool
    {
        if (self::is_partner_anchored($rows[$person_id])) {
            return true;
        }
        foreach ($rows as $other_id => $other) {
            if ($other_id !== $except && $other['related_to'] === $person_id
                && in_array($other['concept'], self::PARTNER_CONCEPTS, true)) {
                return true;
            }
        }
        return false;
    }

    // --- Anwendung auf migrierte Daten ----------------------------------------------------

    /**
     * Wendet die Ableitung auf alle Zuordnungen aus relatives_status an. Nur Zuordnungen
     * ohne Bezugsperson werden angefasst (idempotent, manuell gesetzte bleiben).
     *
     * @return array ['anchored' => int, 'ambiguous' => int, 'unresolved' => int]
     */
    public static function apply_to_migrated_data(): array
    {
        global $db;

        $result = ['anchored' => 0, 'ambiguous' => 0, 'unresolved' => 0];
        $context = self::legacy_context();
        if ($context === null) {
            return $result;
        }
        $options = $context['options'];
        $option_by_link = $context['option_by_link'];
        $facts = $context['facts'];

        // Zuordnungen je Charakter
        return self::apply_decisions($options, $option_by_link, $facts, $result);
    }

    /**
     * Laedt alles, was die Ableitung aus den Altdaten braucht: alte Grade, die Zuordnung
     * alte relatives-id -> Person, Account -> Person und die Fakten aus allen Listen.
     *
     * @return array|null null, wenn die Alttabellen fehlen
     */
    private static function legacy_context(): ?array
    {
        global $db;

        foreach (['relatives', 'relatives_status', 'relatives_options'] as $table) {
            if (!$db->table_exists($table)) {
                return null;
            }
        }

        // Alte Grade: option-id -> Konzept, Label-Geschlecht, Seite, Qualifier
        $options = [];
        $query = $db->simple_select('relatives_options', 'id, option_value', "option_name = 'status'");
        while ($row = $db->fetch_array($query)) {
            $mapping = Relatives_Migration::map_status_label((string)$row['option_value']);
            if (!$mapping['mapped'] || $mapping['concept'] === null) {
                continue;
            }
            $options[(int)$row['id']] = [
                'concept' => $mapping['concept'],
                'gender'  => (int)$mapping['gender'],
            ] + self::label_details((string)$row['option_value']);
        }

        // Migrierte Personen: alte relatives-id -> Personen-id; Namen fuer die Account-Identitaet
        $person_by_legacy = [];
        $person_ids_by_name = [];
        $names = [];
        $query = $db->simple_select('relatives_persons', 'id, name, legacy_source, legacy_id');
        while ($row = $db->fetch_array($query)) {
            if ($row['legacy_source'] === 'relatives' && $row['legacy_id'] !== null) {
                $person_by_legacy[(int)$row['legacy_id']] = (int)$row['id'];
            }
            $person_ids_by_name[mb_strtolower(trim((string)$row['name']))][] = (int)$row['id'];
            $names[(int)$row['id']] = (string)$row['name'];
        }

        $self_person_by_uid = [];
        $query = $db->simple_select('users', 'uid, username');
        while ($row = $db->fetch_array($query)) {
            $key = mb_strtolower(trim((string)$row['username']));
            if (isset($person_ids_by_name[$key]) && count($person_ids_by_name[$key]) === 1) {
                $self_person_by_uid[(int)$row['uid']] = $person_ids_by_name[$key][0];
            }
        }

        // Fakten aus den Listen aller Charaktere (auch geloeschter Accounts)
        $facts = self::empty_facts();
        $lists = [];
        $option_by_link = [];
        $query = $db->simple_select('relatives_status', 'id, relative_id, owner_uid, status', '', ['order_by' => 'id', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($query)) {
            $option_id = (int)$row['status'];
            $option_by_link[(int)$row['id']] = $option_id;
            $person_id = $person_by_legacy[(int)$row['relative_id']] ?? 0;
            if (!isset($options[$option_id]) || $person_id === 0) {
                continue;
            }
            $lists[(int)$row['owner_uid']][] = [
                'person_id' => $person_id,
                'concept'   => $options[$option_id]['concept'],
                'gender'    => $options[$option_id]['gender'],
                'qualifier' => $options[$option_id]['qualifier'],
                'option_id' => $option_id,
            ];
        }
        foreach ($lists as $owner_uid => $entries) {
            self::collect_list_facts($facts, $entries, $self_person_by_uid[$owner_uid] ?? 0);
        }

        return [
            'options'        => $options,
            'option_by_link' => $option_by_link,
            'facts'          => $facts,
            'lists'          => $lists,
            'names'          => $names,
        ];
    }

    /**
     * Spielt die urspruengliche Ableitung auf den Altdaten nach -- mit den ALTEN Regeln,
     * die bei offenen Graden Eindeutigkeit genuegen liessen -- und liefert genau die
     * Entscheidungen, die heute als geraten gelten (Stufe "unique" + GUESS_RULES).
     * Grundlage von Relatives_Graph::revert_legacy_guesses().
     *
     * @return array Liste von ['owner_uid','person_id','anchor_id','relation',
     *               'legacy_concept','legacy_gender']
     */
    public static function replay_legacy_guesses(): array
    {
        $context = self::legacy_context();
        if ($context === null) {
            return [];
        }

        $guesses = [];
        self::$legacy_rules = true;
        try {
            foreach ($context['lists'] as $owner_uid => $entries) {
                $rows = [];
                foreach ($entries as $entry) {
                    $person_id = (int)$entry['person_id'];
                    if (isset($rows[$person_id])) {
                        continue; // doppelte Zuordnung: die Migration nahm die aelteste
                    }
                    $option = $context['options'][$entry['option_id']];
                    $rows[$person_id] = [
                        'person_id'   => $person_id,
                        'name'        => $context['names'][$person_id] ?? '',
                        'concept'     => $entry['concept'],
                        'type_gender' => (int)$entry['gender'],
                        'related_to'  => null,
                        'side'        => $option['side'],
                        'qualifier'   => $option['qualifier'],
                        'inferable'   => true,
                    ];
                }
                // Dieselbe Reihenfolge wie beim Migrationslauf (Name, dann id).
                uasort($rows, static function ($a, $b) {
                    return [$a['name'], $a['person_id']] <=> [$b['name'], $b['person_id']];
                });

                $outcome = self::infer_owner($rows, $context['facts']);
                foreach ($outcome['decisions'] as $person_id => $decision) {
                    if ($decision['level'] !== 'unique'
                        || !self::is_guess((string)$rows[$person_id]['concept'], (string)$decision['anchor_concept'], (string)$decision['concept'])) {
                        continue;
                    }
                    $guesses[] = [
                        'owner_uid'      => (int)$owner_uid,
                        'person_id'      => (int)$person_id,
                        'anchor_id'      => (int)$decision['related_to'],
                        'relation'       => (string)$decision['concept'],
                        'legacy_concept' => (string)$rows[$person_id]['concept'],
                        'legacy_gender'  => (int)$rows[$person_id]['type_gender'],
                    ];
                }
            }
        } finally {
            self::$legacy_rules = false;
        }
        return $guesses;
    }

    /** Schreibt die Ableitung fuer alle Zuordnungen ohne Bezugsperson (Teil von apply_to_migrated_data()). */
    private static function apply_decisions(array $options, array $option_by_link, array $facts, array $result): array
    {
        global $db;

        // Zuordnungen je Charakter
        $rows_by_owner = [];
        // Grad und Bezugsperson stehen in der Listen-Kante des jeweiligen Charakters
        // (Relatives_Database::add_list_relation()) -- nicht in der Primaerkante der Person,
        // die fuer alle Listen dieselbe waere.
        $query = $db->query("SELECT o.owner_uid, o.person_id, r.related_to_person_id, o.legacy_id, p.name, p.legacy_source,
                t.concept_key, t.gender AS type_gender
            FROM " . TABLE_PREFIX . "relatives_person_owners o
            INNER JOIN " . TABLE_PREFIX . "relatives_persons p ON p.id = o.person_id
            LEFT JOIN " . TABLE_PREFIX . "relatives_relations r ON r.person_id = o.person_id AND r.owner_uid = o.owner_uid
            LEFT JOIN " . TABLE_PREFIX . "relatives_relationship_types t ON t.id = r.relationship_type_id
            ORDER BY o.owner_uid, p.name, p.id");
        while ($row = $db->fetch_array($query)) {
            $details = ['side' => null, 'qualifier' => self::QUALIFIER_BLOOD];
            $inferable = false;
            if ($row['legacy_source'] === 'relatives' && $row['legacy_id'] !== null
                && isset($option_by_link[(int)$row['legacy_id']], $options[$option_by_link[(int)$row['legacy_id']]])) {
                $option = $options[$option_by_link[(int)$row['legacy_id']]];
                $details = ['side' => $option['side'], 'qualifier' => $option['qualifier']];
                $inferable = true;
            }
            $rows_by_owner[(int)$row['owner_uid']][(int)$row['person_id']] = [
                'person_id'   => (int)$row['person_id'],
                'name'        => (string)$row['name'],
                'concept'     => $row['concept_key'] !== null ? (string)$row['concept_key'] : null,
                'type_gender' => $row['type_gender'] !== null ? (int)$row['type_gender'] : Relatives_RelationshipTypes::LABEL_NEUTRAL,
                'related_to'  => $row['related_to_person_id'] !== null ? (int)$row['related_to_person_id'] : null,
                'side'        => $details['side'],
                'qualifier'   => $details['qualifier'],
                'inferable'   => $inferable,
            ];
        }

        // Im gemeinsamen Geflecht zeigt "relativ zu mir" auf den Knoten des Accounts --
        // fuer die Ableitung ist das gleichbedeutend mit "noch ohne Bezugsperson".
        foreach ($rows_by_owner as $owner_uid => $rows) {
            $account_person = Relatives_Graph::find_account_person_id((int)$owner_uid);
            if ($account_person <= 0) {
                continue;
            }
            foreach ($rows as $person_id => $row) {
                if ($row['related_to'] === $account_person) {
                    $rows_by_owner[$owner_uid][$person_id]['related_to'] = null;
                }
            }
        }

        $type_cache = [];
        foreach ($rows_by_owner as $owner_uid => $rows) {
            $outcome = self::infer_owner($rows, $facts);
            foreach ($outcome['decisions'] as $person_id => $decision) {
                $gender = $rows[$person_id]['type_gender'];
                $cache_key = $decision['concept'] . '|' . $gender;
                if (!array_key_exists($cache_key, $type_cache)) {
                    $type_cache[$cache_key] = Relatives_RelationshipTypes::find_by_concept_and_gender($decision['concept'], $gender);
                }
                if ($type_cache[$cache_key] === null) {
                    $result['unresolved']++;
                    continue;
                }
                Relatives_Database::update_list_relation((int)$person_id, (int)$owner_uid, [
                    'related_to_person_id' => $decision['related_to'],
                    'relationship_type_id' => (int)$type_cache[$cache_key]['id'],
                ]);
                $result['anchored']++;
            }
            $result['ambiguous'] += $outcome['ambiguous'];
            $result['unresolved'] += $outcome['unresolved'];
        }

        return $result;
    }
}
