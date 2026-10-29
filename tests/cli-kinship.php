<?php
/**
 * Reiner Rechen-Test der Verwandtschafts-Engine -- laeuft OHNE MyBB-Installation
 * (kein DB-Zugriff), deshalb lokal ausfuehrbar:
 *
 *   php tests/cli-kinship.php
 *
 * Getestet werden die DB-freien Teile von Relatives_Kinship auf dem gemeinsamen
 * Geflecht (v2.2): Graphaufbau (structure_from), direkte Angaben an der Wurzel
 * (direct_statements), genealogische Koordinaten (blood_degrees) und die
 * Begrenzungsregel (concept_for_degree). Die Wurzel ist der Knoten des Charakters.
 */

if (PHP_SAPI !== 'cli') {
    die('CLI only.');
}
define('IN_MYBB', 1);
chdir(dirname(__DIR__));

// Lokale PHP-Installationen ohne mbstring: nur fuer diesen Test (das Forum hat mbstring).
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value): string
    {
        return strtolower($value);
    }
}

require_once './inc/plugins/relatives/core/Kinship.php';
require_once './inc/plugins/relatives/core/RelationshipTypes.php';
require_once './inc/plugins/relatives/core/StructureInference.php';
require_once './inc/plugins/relatives/core/Graph.php';

$pass = 0;
$fail = 0;

function check(string $name, $expected, $actual): void
{
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        echo "  ok   {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
    }
}

/** Minimaler Typen-Katalog wie ihn Relatives_Kinship::type_map() liefern wuerde. */
$types = [];
$type_id = 0;
foreach ([
    'parent' => -1, 'child' => 1, 'sibling' => 0, 'spouse' => 0, 'ex_spouse' => 0,
    'adoptive_parent' => -1, 'adoptive_child' => 1, 'step_parent' => -1,
    'aunt_uncle' => -1, 'niece_nephew' => 1, 'cousin' => 0,
    'adoptive_sibling' => 0, 'step_sibling' => 0, 'half_sibling' => 0,
] as $concept => $offset) {
    $types[++$type_id] = ['concept' => $concept, 'offset' => $offset, 'label' => $concept, 'line_style' => 'solid'];
}
$type_of = static function (string $concept) use ($types): int {
    foreach ($types as $id => $type) {
        if ($type['concept'] === $concept) {
            return $id;
        }
    }
    throw new RuntimeException('unknown concept ' . $concept);
};

/**
 * Flache Kantenliste wie Relatives_Database::get_all_relations().
 * $rows: [person_id, concept, anchor_person_id|null, anchor_family_id|null, extra]
 */
function relations(array $rows, callable $type_of): array
{
    $list = [];
    foreach ($rows as $row) {
        $list[] = array_merge([
            'person_id'                 => $row[0],
            'related_to_person_id'      => $row[2],
            'related_to_family_id'      => $row[3] ?? null,
            'relationship_type_id'      => $type_of($row[1]),
            'relationship_custom_label' => null,
            'is_secret'                 => 0,
            'is_primary'                => 1,
        ], $row[4] ?? []);
    }
    return $list;
}

function persons(array $ids): array
{
    $persons = [];
    foreach ($ids as $id) {
        $persons[$id] = ['id' => $id, 'gender' => -1];
    }
    return $persons;
}

/** Konzept einer Person aus Sicht der Wurzel (nur Blutskoordinate). */
function concept_of(array $degrees, int $person_id): string
{
    $key = 'p:' . $person_id;
    if (!isset($degrees[$key])) {
        return 'none';
    }
    return Relatives_Kinship::concept_for_degree($degrees[$key]['up'], $degrees[$key]['down'])[0];
}

$megan = 99;

// --- Flemming-Szenario ------------------------------------------------------------------
// Delia ist Megans Mutter. Oliver, Jonathan, Matthew sind Delias Geschwister. Ava ist
// Olivers Ehefrau, Ethan ihr Sohn.

echo "=== Flemming-Szenario ===\n";

$ids = ['delia' => 1, 'oliver' => 2, 'jonathan' => 3, 'matthew' => 5, 'ava' => 6, 'ethan' => 7];
$graph = Relatives_Kinship::structure_from(persons(array_merge(array_values($ids), [$megan])), relations([
    [$ids['delia'],    'parent',  $megan],
    [$ids['oliver'],   'sibling', $ids['delia']],
    [$ids['jonathan'], 'sibling', $ids['delia']],
    [$ids['matthew'],  'sibling', $ids['delia']],
    [$ids['ava'],      'spouse',  $ids['oliver']],
    [$ids['ethan'],    'child',   $ids['oliver']],
], $type_of), $types);
$degrees = Relatives_Kinship::blood_degrees($graph, 'p:' . $megan);

check('Delia ist Elternteil', 'parent', concept_of($degrees, $ids['delia']));
check('Oliver ist Tante/Onkel', 'aunt_uncle', concept_of($degrees, $ids['oliver']));
check('Jonathan ist Tante/Onkel', 'aunt_uncle', concept_of($degrees, $ids['jonathan']));
check('Matthew ist Tante/Onkel', 'aunt_uncle', concept_of($degrees, $ids['matthew']));
check('Ethan ist Cousin', 'cousin', concept_of($degrees, $ids['ethan']));
check('Ava ist nicht blutsverwandt', 'none', concept_of($degrees, $ids['ava']));
check('Ava ist Partnerin von Oliver', true, isset($graph['partners']['p:' . $ids['ava']]['p:' . $ids['oliver']]));
check('Megan selbst hat keine Koordinate', false, isset($degrees['p:' . $megan]));

// --- Neuer Bruder von Oliver: automatisch Geschwister aller anderen ------------------------

echo "=== Neuer Bruder von Oliver ===\n";

$new_brother = 8;
$graph_nb = Relatives_Kinship::structure_from(persons(array_merge(array_values($ids), [$megan, $new_brother])), relations([
    [$ids['delia'],    'parent',  $megan],
    [$ids['oliver'],   'sibling', $ids['delia']],
    [$new_brother,     'sibling', $ids['oliver']],
], $type_of), $types);
$degrees_nb = Relatives_Kinship::blood_degrees($graph_nb, 'p:' . $megan);
check('Bruder von Oliver ist ebenfalls Onkel', 'aunt_uncle', concept_of($degrees_nb, $new_brother));

// --- Louisa: heimliche leibliche Tochter + Adoptiveltern ---------------------------------

echo "=== Louisa (heimlich + adoptiert) ===\n";

$l = ['delia' => 1, 'morgan' => 2, 'louisa' => 3, 'adopt_a' => 4, 'adopt_b' => 5];
$lgraph = Relatives_Kinship::structure_from(persons(array_merge(array_values($l), [$megan])), relations([
    [$l['delia'],   'parent',         $megan],
    [$l['morgan'],  'spouse',         $l['delia']],
    [$l['louisa'],  'child',          $l['delia'],   null, ['is_secret' => 1]],
    [$l['louisa'],  'child',          $l['morgan'],  null, ['is_secret' => 1, 'is_primary' => 0]],
    [$l['louisa'],  'adoptive_child', $l['adopt_a'], null, ['is_primary' => 0]],
    [$l['louisa'],  'adoptive_child', $l['adopt_b'], null, ['is_primary' => 0]],
    [$l['adopt_a'], 'cousin',         $megan],
], $type_of), $types);
$ldegrees = Relatives_Kinship::blood_degrees($lgraph, 'p:' . $megan);

check('Louisa hat vier Elternkanten', 4, count($lgraph['parents']['p:' . $l['louisa']] ?? []));
check('Louisa ist ueber die leibliche Linie Geschwisterteil', 'sibling', concept_of($ldegrees, $l['louisa']));
check('Adoptionskante macht Adoptiveltern nicht blutsverwandt', 'cousin', concept_of($ldegrees, $l['adopt_a']));
check('Adoptivelternteil ohne direkte Angabe bleibt ohne Koordinate', 'none', concept_of($ldegrees, $l['adopt_b']));

// --- Direkte Angaben an der Wurzel, in beiden Richtungen ---------------------------------

echo "=== Direkte Angaben ===\n";

$d = ['oliver' => 1, 'ethan' => 2];
$dgraph = Relatives_Kinship::structure_from(persons([$d['oliver'], $d['ethan'], $megan]), relations([
    [$d['oliver'], 'aunt_uncle', $megan],
    [$d['ethan'],  'child',      $d['oliver']],
], $type_of), $types);
check('Sohn eines direkt eingetragenen Onkels ist Cousin', 'cousin', concept_of(Relatives_Kinship::blood_degrees($dgraph, 'p:' . $megan), $d['ethan']));

$rgraph = Relatives_Kinship::structure_from(persons([$d['oliver'], $d['ethan'], $megan]), relations([
    [$megan,       'niece_nephew', $d['oliver']],
    [$d['ethan'],  'child',        $d['oliver']],
], $type_of), $types);
check('Megan als Nichte von Oliver: Oliver ist direkt Onkel', 'aunt_uncle', Relatives_Kinship::direct_statements($rgraph, 'p:' . $megan)['direct']['p:' . $d['oliver']] ?? null);
check('... und Ethan damit Cousin', 'cousin', concept_of(Relatives_Kinship::blood_degrees($rgraph, 'p:' . $megan), $d['ethan']));

check('degree_for_concept(aunt_uncle)', [2, 1], Relatives_Kinship::degree_for_concept('aunt_uncle'));
check('Sammelbegriff hat keine Koordinate', null, Relatives_Kinship::degree_for_concept('distant_relative'));

// --- Widerspruechliche Listen im gemeinsamen Geflecht ------------------------------------
// Megan hat Mutter + Vater und einen Bruder Damien. Eine fremde Liste behauptet, Damien
// sei Sohn von Delia (Matthews Schwester). Delia darf dadurch nicht Megans Mutter werden.

echo "=== Widerspruechliche Angaben ===\n";

$w = ['gabriele' => 1, 'matthew' => 2, 'damien' => 3, 'delia' => 4, 'duncan' => 5, 'oliver' => 6, 'ava' => 7, 'ethan' => 8];
$wgraph = Relatives_Kinship::structure_from(persons(array_merge(array_values($w), [$megan])), relations([
    [$w['gabriele'], 'parent',  $megan],
    [$w['matthew'],  'parent',  $megan],
    [$w['damien'],   'sibling', $megan],
    [$w['delia'],    'sibling', $w['matthew']],
    [$w['damien'],   'child',   $w['delia']],
    [$w['duncan'],   'spouse',  $w['delia']],
    [$w['oliver'],   'sibling', $w['matthew']],
    [$w['ava'],      'spouse',  $w['oliver']],
    [$w['ethan'],    'child',   $w['ava']],
], $type_of), $types);
$wdegrees = Relatives_Kinship::blood_degrees($wgraph, 'p:' . $megan);
check('keine dritte Mutter: Delia bleibt Tante', 'aunt_uncle', concept_of($wdegrees, $w['delia']));
check('Damien bleibt Bruder', 'sibling', concept_of($wdegrees, $w['damien']));
check('einzige*r Partner*in des einzigen Elternteils ist zweiter Elternteil', true, isset($wgraph['parents']['p:' . $w['ethan']]['p:' . $w['oliver']]));
check('... dadurch ist Ethan Cousin', 'cousin', concept_of($wdegrees, $w['ethan']));

// --- Begrenzungsregel -------------------------------------------------------------------

echo "=== Begrenzung entfernter Verwandtschaft ===\n";

check('Urgrosselternteil bleibt exakt', ['great_grandparent', 'exact'], Relatives_Kinship::concept_for_degree(3, 0));
check('Ur-Urgrosselternteil wird entfernte*r Vorfahr*in', ['distant_ancestor', 'distant_ancestor'], Relatives_Kinship::concept_for_degree(4, 0));
check('Urenkelkind bleibt exakt', ['great_grandchild', 'exact'], Relatives_Kinship::concept_for_degree(0, 3));
check('Ur-Urenkelkind wird entfernte*r Nachfahr*in', ['distant_descendant', 'distant_descendant'], Relatives_Kinship::concept_for_degree(0, 4));
check('Grossonkel bleibt exakt', ['great_aunt_uncle', 'exact'], Relatives_Kinship::concept_for_degree(3, 1));
check('Grosscousin*e bleibt exakt', ['second_cousin', 'exact'], Relatives_Kinship::concept_for_degree(3, 3));
check('Cousin*e einmal versetzt wird entfernt', ['distant_relative', 'distant_relative'], Relatives_Kinship::concept_for_degree(2, 3));
check('Vierte Seitenlinie wird entfernt', ['distant_relative', 'distant_relative'], Relatives_Kinship::concept_for_degree(4, 1));

// --- Familien-Kasten als Eltern-Ersatz --------------------------------------------------

echo "=== Familien-Kasten ===\n";

$f = ['matthew' => 1, 'delia' => 2];
$fgraph = Relatives_Kinship::structure_from(persons([$f['matthew'], $f['delia'], $megan]), relations([
    [$f['matthew'], 'child',  null,         9],
    [$f['delia'],   'child',  null,         9],
    [$f['delia'],   'parent', $megan],
], $type_of), $types);
check('Familien-Kasten wird Elternknoten', true, isset($fgraph['parents']['p:' . $f['matthew']]['f:9']));
check('Beide Kinder haengen am selben Kasten', 2, count($fgraph['children']['f:9'] ?? []));
check('Geschwister ueber den Kasten: Matthew ist Onkel', 'aunt_uncle', concept_of(Relatives_Kinship::blood_degrees($fgraph, 'p:' . $megan), $f['matthew']));

// --- Familien-Zugehoerigkeit: Adoptiv-/Stiefgeschwister ziehen keine Familie mit -------

echo "=== Familien-Zugehoerigkeit ===
";

$m = ['lenja' => 1, 'nick' => 2, 'stepsis' => 3, 'halfbro' => 4, 'sister' => 5];
$mgraph = Relatives_Kinship::structure_from(persons(array_values($m)), relations([
    [$m['lenja'],   'child',            null,         7],
    [$m['nick'],    'adoptive_sibling', $m['lenja']],
    [$m['stepsis'], 'step_sibling',     $m['lenja']],
    [$m['halfbro'], 'half_sibling',     $m['lenja']],
    [$m['sister'],  'sibling',          $m['lenja']],
], $type_of), $types);
$members = Relatives_Graph::memberships_from($mgraph);
check('Direkt am Kasten ist Mitglied', true, isset($members[$m['lenja']][7]));
check('Adoptivbruder ist kein Mitglied', false, isset($members[$m['nick']][7]));
check('Stiefschwester ist kein Mitglied', false, isset($members[$m['stepsis']][7]));
check('Halbbruder bleibt Mitglied', true, isset($members[$m['halfbro']][7]));
check('Vollgeschwister bleibt Mitglied', true, isset($members[$m['sister']][7]));

// --- Struktur-Ableitung der Alt-Migration: keine Rateergebnisse bei offenen Graden -------
// Befund Testforum: In Jolinas alter Liste stand nur Delia als Tante; alle "Cousins"
// wurden deshalb zu Delias Kindern. Eine Tante mit Seitenangabe gehoert dagegen sicher zum
// einzigen Vater dieser Seite.

echo "=== Struktur-Ableitung (Altdaten) ===\n";

$inference_row = static function (int $id, string $name, string $concept, int $gender, ?string $side = null): array {
    return [
        'person_id' => $id, 'name' => $name, 'concept' => $concept, 'type_gender' => $gender,
        'related_to' => null, 'side' => $side, 'qualifier' => 'blood', 'inferable' => true,
    ];
};
$guess_rows = [];
foreach ([
    $inference_row(21, 'Jonathan Flemming', 'parent', 0),
    $inference_row(22, 'Delia Flemming', 'aunt_uncle', 1, 'paternal'),
    $inference_row(23, 'Christopher Lancaster', 'cousin', 0),
    $inference_row(24, 'Damien Flemming', 'cousin', 0),
] as $guess_row) {
    $guess_rows[$guess_row['person_id']] = $guess_row;
}
$guess_outcome = Relatives_StructureInference::infer_owner($guess_rows, Relatives_StructureInference::empty_facts());
$decision_of = static function (array $outcome, int $id): ?array {
    return isset($outcome['decisions'][$id]) ? [$outcome['decisions'][$id]['related_to'], $outcome['decisions'][$id]['concept']] : null;
};
check('Tante mit Seitenangabe haengt sicher am einzigen Vater', [21, 'sibling'], $decision_of($guess_outcome, 22));
check('Cousin wird NICHT zum Kind der einzigen Tante geraten', null, $decision_of($guess_outcome, 23));
check('... auch nicht, wenn nur der Nachname passt und es keine Alternative gibt', null, $decision_of($guess_outcome, 24));

// Nachname, der zwischen mehreren Kandidat*innen unterscheidet, zaehlt weiterhin (Siri Vaisey).
$surname_rows = [];
foreach ([
    $inference_row(31, 'Gabriele Flemming', 'parent', 1),
    $inference_row(32, 'Matthew Flemming', 'parent', 0),
    $inference_row(33, 'Morgan Vaisey', 'aunt_uncle', 0, 'maternal'),
    $inference_row(34, 'Delia Flemming', 'aunt_uncle', 1, 'paternal'),
    $inference_row(35, 'Siri Vaisey', 'cousin', 1),
] as $surname_row) {
    $surname_rows[$surname_row['person_id']] = $surname_row;
}
$surname_outcome = Relatives_StructureInference::infer_owner($surname_rows, Relatives_StructureInference::empty_facts());
check('Nachname unterscheidet zwischen Onkel und Tante: Siri ist Tochter von Morgan', [33, 'child'], $decision_of($surname_outcome, 35));

// Befund Testforum (Kjartan): keine Mutter eingetragen, die Onkel muetterlicherseits bleiben
// deshalb lose. Fakten aus anderen Listen belegen trotzdem, wer ihre Ehefrau und ihre Kinder
// sind -- dann darf an den losen Onkel gehaengt werden (nur mit Fakt, nie an "(entfernt)").
$kjartan_rows = [];
foreach ([
    [41, 'Åke Guðmundursson', 'parent', 0, null, 'blood'],
    [42, 'Harvey Davies', 'aunt_uncle', 0, 'maternal', 'blood'],
    [43, 'Evelyn Davies', 'aunt_uncle', 1, 'maternal', 'step'],
    [44, 'Landon Slughorn', 'aunt_uncle', 0, 'maternal', 'step'],
    [45, 'Alyn Slughorn', 'cousin', 1, null, 'step'],
    [46, 'Teagan Slughorn', 'cousin', 0, null, 'blood'],
    [47, 'Amara Davies', 'cousin', 1, null, 'blood'],
    [48, 'Orion Solomon', 'aunt_uncle', 0, 'paternal', 'distant'],
    [49, 'Abel Solomon', 'cousin', 0, null, 'blood'],
] as [$id, $name, $concept, $gender, $side, $qualifier]) {
    $kjartan_rows[$id] = array_merge($inference_row($id, $name, $concept, $gender, $side), ['qualifier' => $qualifier]);
}
$kjartan_facts = Relatives_StructureInference::empty_facts();
// Liste eines Kindes von Harvey + Evelyn
Relatives_StructureInference::collect_list_facts($kjartan_facts, [
    ['person_id' => 42, 'concept' => 'parent', 'gender' => 0, 'qualifier' => 'blood'],
    ['person_id' => 43, 'concept' => 'parent', 'gender' => 1, 'qualifier' => 'blood'],
], 0);
// Liste eines Kindes von Landon: Alyn Schwester (Fakt), Teagan nur Halbbruder (kein Fakt)
Relatives_StructureInference::collect_list_facts($kjartan_facts, [
    ['person_id' => 44, 'concept' => 'parent', 'gender' => 0, 'qualifier' => 'blood'],
    ['person_id' => 45, 'concept' => 'sibling', 'gender' => 1, 'qualifier' => 'blood'],
    ['person_id' => 46, 'concept' => 'half_sibling', 'gender' => 0, 'qualifier' => 'blood'],
], 0);
// Liste eines Kindes von Orion: Abel Bruder -- Orion ist bei Kjartan aber "(entfernt)"
Relatives_StructureInference::collect_list_facts($kjartan_facts, [
    ['person_id' => 48, 'concept' => 'parent', 'gender' => 0, 'qualifier' => 'blood'],
    ['person_id' => 49, 'concept' => 'sibling', 'gender' => 0, 'qualifier' => 'blood'],
], 0);
$kjartan_outcome = Relatives_StructureInference::infer_owner($kjartan_rows, $kjartan_facts);
check('Onkel muetterlicherseits ohne Mutter bleibt lose', null, $decision_of($kjartan_outcome, 42));
check('Angeheiratete Tante mit Fakt haengt am losen Onkel', [42, 'spouse'], $decision_of($kjartan_outcome, 43));
check('Cousine mit Fakt haengt am losen Onkel', [44, 'child'], $decision_of($kjartan_outcome, 45));
check('Halbgeschwister ist kein Beleg fuer den Elternteil', null, $decision_of($kjartan_outcome, 46));
check('Cousine ohne Fakt bleibt lose', null, $decision_of($kjartan_outcome, 47));
check('Nie an einen "(entfernt)"-Onkel haengen', null, $decision_of($kjartan_outcome, 49));

// --- Gegenkonzepte ----------------------------------------------------------------------

echo "=== Gegenkonzepte ===\n";

check('Kind -> Elternteil', 'parent', Relatives_Kinship::inverse_concept('child'));
check('Adoptivelternteil -> Adoptivkind', 'adoptive_child', Relatives_Kinship::inverse_concept('adoptive_parent'));
check('Geschwister bleibt Geschwister', 'sibling', Relatives_Kinship::inverse_concept('sibling'));
check('Ehepartner*in bleibt symmetrisch', 'spouse', Relatives_Kinship::inverse_concept('spouse'));

// --- Naehe der Verwandtschaft (closeness) -------------------------------------------------

echo "=== Naehe (closeness) ===\n";

$closeness = static function (string $concept, string $category = 'exact'): int {
    return Relatives_Kinship::closeness($concept, $category);
};
check('Kind vor Elternteil', true, $closeness('child') < $closeness('parent'));
check('Elternteil vor Ehepartner*in', true, $closeness('parent') < $closeness('spouse'));
check('Ehepartner*in vor Geschwister', true, $closeness('spouse') < $closeness('sibling'));
check('Geschwister vor Grosseltern', true, $closeness('sibling') < $closeness('grandparent'));
check('Halbgeschwister nach Geschwister', true, $closeness('sibling') < $closeness('half_sibling'));
check('Grosseltern vor Tante/Onkel', true, $closeness('grandparent') < $closeness('aunt_uncle'));
check('Tante/Onkel vor Cousin*e', true, $closeness('aunt_uncle') < $closeness('cousin'));
check('Cousin*e vor Schwager/Schwaegerin', true, $closeness('cousin') < $closeness('sibling_in_law'));
check('Angeheiratet vor Ex-Partner*in', true, $closeness('sibling_in_law') < $closeness('ex_spouse'));
check('Ex-Partner*in vor entfernt verwandt', true, $closeness('ex_spouse') < $closeness('distant_relative', 'distant_relative'));
check('Freie Bezeichnung zuletzt', true, $closeness('distant_relative', 'distant_relative') < Relatives_Kinship::closeness(null, 'custom'));
check('Unbekannt = frei', Relatives_Kinship::closeness(null, 'custom'), Relatives_Kinship::closeness(null, 'unknown'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
