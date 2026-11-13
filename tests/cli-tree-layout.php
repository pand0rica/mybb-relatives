<?php
/**
 * Sichtbarkeit und Layout des Stammbaums -- laeuft OHNE MyBB-Installation (kein DB-Zugriff):
 *
 *   php tests/cli-tree-layout.php
 *
 * Fixture: Megan Fawleys Baum aus dem Test-Forum (Familien, Paare, Generationen wie
 * Relatives_TreeBuilder sie dort ermittelt hat, Geburtsjahre aus dem Entwurf).
 * Geprueft wird erst, wer ohne Aufklappen sichtbar ist (visible_graph_keys()), dann das
 * Layout dieses Ausschnitts (TreeBuilder::layout() per Reflection).
 */

if (PHP_SAPI !== 'cli') {
    die('CLI only.');
}
define('IN_MYBB', 1);
chdir(dirname(__DIR__));

require_once './inc/plugins/relatives/core/Kinship.php';
require_once './inc/plugins/relatives/core/Graph.php';
require_once './inc/plugins/relatives/core/TreeBuilder.php';
require_once './inc/plugins/relatives/core/TreeLayout.php';
require_once './inc/plugins/relatives/core/TreePlaceholders.php';

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

// --- Fixture -------------------------------------------------------------------------

const ROOT = 241;

// id => [name, generation, year of birth]
const PEOPLE = [
    241 => ['Megan', 0, 1996], 1 => ['Gwendolyn', -1, 1970], 2 => ['Matthew', -1, 1969],
    4 => ['Sue', 0, 2000], 5 => ['Brian', 0, 2004], 38 => ['Stella', 0, 2000], 3 => ['Damien', 0, 1994],
    125 => ['Cyril', -1, 1977], 212 => ['Percival', -2, null], 207 => ['Cora', -1, 1974], 210 => ['Owain', 0, 2009],
    127 => ['Algernon', -2, 1948], 206 => ['Cameron', 0, 1988], 211 => ['Aaron', -1, 1978], 93 => ['Oliver', -1, 1966],
    32 => ['Jonathan', -1, 1974], 214 => ['Winifred', -1, 1973], 131 => ['Ethan', 0, 1993], 130 => ['Ava', -1, 1975],
    11 => ['Nathaniel', -1, 1971], 126 => ['Cassandra', -2, 1948], 209 => ['Rhiannon', 0, 2004], 45 => ['Phoebe', -2, null],
    107 => ['Louisa', 0, 2003], 292 => ['Silvanus', -3, 1895], 213 => ['Isadora', -2, null], 293 => ['Harriet', -3, 1896],
    287 => ['Beatrix', -1, 1967], 84 => ['Jaron', 0, 1996], 208 => ['Idris', -1, 1970], 302 => ['Charles', -2, 1925],
    303 => ['Oswald', -2, 1928], 304 => ['Wilmot', -2, 1933], 238 => ['Abel', -1, null], 33 => ['Mama Griffin', -1, null],
    112 => ['Levi', 0, null], 311 => ['Erasmus', -1, 1972], 298 => ['Ambrose', -3, 1887], 306 => ['Lucius', -1, 1948],
    110 => ['Zacharias', 0, null], 109 => ['Judith', -1, null], 251 => ['Lumina', 0, 1998], 237 => ['Jolina', 0, 2006],
    111 => ['Penelope', 0, null], 310 => ['Chelsea', -1, 1976], 290 => ['Malcolm', -2, null], 113 => ['Nathan', 0, null],
    108 => ['Adam', -1, null], 294 => ['Everard', -4, 1871], 83 => ['Rebecca', -1, 1979], 297 => ['Cordelia', -3, 1891],
    295 => ['Florence', -4, 1872], 314 => ['Hector', -4, 1874], 48 => ['Maysilee', 0, null], 124 => ['Elodie', -1, 1979],
    301 => ['Honoria', -5, 1851], 300 => ['Aloysius', -5, 1845], 47 => ['Kaine', -1, null], 116 => ['Isabella', -1, null],
    289 => ['Andrew', -2, null], 313 => ['Mabel', 0, 2007], 288 => ['Nathalie', -2, null], 96 => ['Gunnar', 0, 1978],
    309 => ['Edith', -2, 1938], 46 => ['Orion', -2, null], 31 => ['Drusilla', -1, 1971], 305 => ['Lavinia', -2, 1925],
    316 => ['Augustus', -3, 1902], 312 => ['Clement', -1, 1972], 308 => ['Delilah', 1, 2009], 98 => ['Marlene', 1, 2003],
    307 => ['Aurora', -2, 1950],
];

// box => [name, generation]
const BOXES = [
    12 => ['Autrien', -2], 18 => ['Travers', -3], 21 => ['Ollivander', -3], 22 => ['Shacklebolt', -4], 11 => ['Fawley', -6],
    25 => ['Shafiq', -6], 27 => ['Solomon', -3], 13 => ['Rowle', -3], 14 => ['Avery', -3], 16 => ['Slughorn', -2], 20 => ['Vaughn', -2],
];

// [parents, children, concept of the child statement, primary]
const FAMILIES = [
    [[1, 2], [241, 4, 5, 38, 3], 'child', false], [[126, 127], [1, 125, 214], 'child', false],
    [[212, 213], [2, 207, 211, 93, 32, 11], 'child', false], [[292, 293], [212, 302, 303, 304], 'child', false],
    [[207, 208], [210, 209], 'child', false], [['f14'], [127, 45], 'child', false], [[11, 287], [206], 'child', false],
    [[130, 93], [131], 'child', false], [['f16'], [130], 'child', false], [['f13'], [126, 311], 'child', false],
    [[108, 109], [107], 'adoptive_child', true], [[11, 33], [107], 'child', false],
    [[294, 295], [292, 298, 297], 'child', false], [['f21'], [213], 'child', false], [['f22'], [293], 'child', false],
    [[290], [287], 'child', false], [[32, 83], [84, 251, 237], 'child', false], [[45, 46], [238, 47], 'child', false],
    [[108, 109], [112, 110, 111, 113], 'child', false], [[302, 305], [306], 'child', false], [[304, 309], [310], 'child', false],
    [['f18'], [290, 289], 'child', false], [[300, 301], [294, 314], 'child', false], [[288, 289], [83], 'child', false],
    [[238], [48], 'child', false], [['f12'], [124], 'child', false], [['f25'], [301], 'child', false], [['f11'], [300], 'child', false],
    [[310, 312], [313], 'child', false], [[306, 307], [96], 'child', false], [['f27'], [46, 307], 'child', false],
    [[314], [316], 'child', false], [[96], [308, 98], 'child', false],
];

const PARTNERS = [
    [32, 83], [207, 208], [127, 126], [125, 124], [11, 31], [288, 289], [212, 213], [302, 305], [306, 307], [304, 309],
    [310, 311], [130, 93], [2, 1], [292, 293], [287, 11], [109, 108], [294, 295], [46, 45], [300, 301], [312, 310], [11, 33],
];

const TYPES = [
    1 => ['concept' => 'child', 'offset' => 1, 'label' => 'Kind', 'line_style' => 'solid'],
    2 => ['concept' => 'adoptive_child', 'offset' => 1, 'label' => 'Adoptivkind', 'line_style' => 'dashed'],
    3 => ['concept' => 'spouse', 'offset' => 0, 'label' => 'Ehepartner*in', 'line_style' => 'solid'],
    4 => ['concept' => 'half_sibling', 'offset' => 0, 'label' => 'Halbgeschwister', 'line_style' => 'solid'],
];
const TYPE_OF = ['child' => 1, 'adoptive_child' => 2, 'spouse' => 3, 'half_sibling' => 4];

/** All statements: [subject id, concept (null = membership), anchor id or 'f<id>', primary]. */
function statements(): array
{
    $statements = [];
    foreach (FAMILIES as [$parents, $children, $concept, $primary]) {
        foreach ($children as $child) {
            foreach ($parents as $parent) {
                $statements[] = [$child, $concept, $parent, $primary];
            }
        }
    }
    foreach (PARTNERS as [$a, $b]) {
        $statements[] = [$a, 'spouse', $b, false];
    }
    $statements[] = [116, 'half_sibling', 238, false];
    $statements[] = [208, null, 'f20', false];
    return $statements;
}

/** Graph key of a fixture id ('f14' -> 'f:14', 12 -> 'p:12'). */
function graph_key($id): string
{
    return is_string($id) ? 'f:' . substr($id, 1) : 'p:' . $id;
}

function tree_key_of($id): string
{
    return is_string($id) ? 'family:' . substr($id, 1) : 'person:' . $id;
}

function world_graph(): array
{
    $persons = [];
    foreach (PEOPLE as $id => $person) {
        $persons[$id] = ['id' => $id, 'name' => $person[0]];
    }
    $relations = [];
    foreach (statements() as [$subject, $concept, $anchor]) {
        $relations[] = [
            'person_id'             => $subject,
            'related_to_person_id'  => is_string($anchor) ? null : $anchor,
            'related_to_family_id'  => is_string($anchor) ? (int)substr($anchor, 1) : null,
            'relationship_type_id'  => $concept !== null ? TYPE_OF[$concept] : null,
            'relationship_custom_label' => '',
        ];
    }
    return Relatives_Kinship::structure_from($persons, $relations, TYPES);
}

/**
 * Lays out the given fixture ids like collect() would hand them over.
 *
 * @param array $origins person id => box id (origin labels of folded in-laws)
 */
function layout_tree(array $visible, array $origins = []): array
{
    $builder = new Relatives_TreeBuilder();
    $nodes = [];
    foreach (PEOPLE as $id => [$name, $generation, $birth]) {
        if (!isset($visible[graph_key($id)])) {
            continue;
        }
        $key = tree_key_of($id);
        $nodes[$key] = [
            'key' => $key, 'type' => $id === ROOT ? 'self' : 'person', 'name' => $name, 'gender' => 2,
            'generation' => $generation, 'birth_year' => $birth,
            'core' => $id === ROOT || $visible[graph_key($id)] === 'core',
        ];
    }
    foreach (BOXES as $id => [$name, $generation]) {
        if (!isset($visible['f:' . $id])) {
            continue;
        }
        $nodes['family:' . $id] = ['key' => 'family:' . $id, 'type' => 'family', 'name' => $name, 'generation' => $generation];
    }

    $origin_tags = [];
    foreach ($origins as $person => $box) {
        $tag_key = 'origin:' . $box . ':' . $person;
        $nodes[$tag_key] = ['key' => $tag_key, 'type' => 'family', 'name' => BOXES[$box][0], 'generation' => PEOPLE[$person][1], 'tag' => true];
        $origin_tags[$tag_key] = 'person:' . $person;
    }

    $relations = [];
    foreach (statements() as [$subject, $concept, $anchor, $primary]) {
        if (!isset($nodes[tree_key_of($subject)], $nodes[tree_key_of($anchor)])) {
            continue;
        }
        $type = $concept !== null ? TYPES[TYPE_OF[$concept]] : null;
        $relations[] = [
            'node' => tree_key_of($subject), 'anchor' => tree_key_of($anchor),
            'concept' => $concept, 'offset' => $type['offset'] ?? 0, 'label' => $type['label'] ?? 'Mitglied',
            'style' => $type['line_style'] ?? 'solid', 'secret' => false, 'primary' => $primary,
            'membership' => $concept === null,
        ];
    }

    $set = static function (string $property, $value) use ($builder): void {
        $reflection = new ReflectionProperty(Relatives_TreeBuilder::class, $property);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        $reflection->setValue($builder, $value);
    };
    $set('nodes', $nodes);
    $set('relations', $relations);
    $set('root_key', tree_key_of(ROOT));
    $set('origin_tags', $origin_tags);

    $layout = new ReflectionMethod(Relatives_TreeBuilder::class, 'layout');
    if (PHP_VERSION_ID < 80100) {
        $layout->setAccessible(true);
    }
    [$structure, $partners] = $layout->invoke($builder);
    $nodes_property = new ReflectionProperty(Relatives_TreeBuilder::class, 'nodes');
    if (PHP_VERSION_ID < 80100) {
        $nodes_property->setAccessible(true);
    }
    return $structure + ['partner_pairs' => $partners, 'nodes' => $nodes_property->getValue($builder)];
}

// --- Sichtbarkeit ----------------------------------------------------------------------

echo "\n=== Sichtbarkeit ohne Aufklappen ===\n";
$graph = world_graph();
$visible = Relatives_TreeBuilder::visible_graph_keys($graph, 'p:' . ROOT);
$shown = static function (array $ids) use ($visible): array {
    $result = [];
    foreach ($ids as $id) {
        $result[$id] = isset($visible[graph_key($id)]);
    }
    return $result;
};
check('distant blood relatives are visible', array_fill_keys([300, 301, 314, 316, 96, 98, 308, 298, 297, 116, 313, 48], true),
    $shown([300, 301, 314, 316, 96, 98, 308, 298, 297, 116, 313, 48]));
check('partners and co-parents are visible', array_fill_keys([83, 287, 33, 31, 130, 208, 295, 312, 307, 305], true),
    $shown([83, 287, 33, 31, 130, 208, 295, 312, 307, 305]));
check('in-laws\' parents stay folded', array_fill_keys([288, 289, 290], false), $shown([288, 289, 290]));
check('adoptive family of a cousin stays folded', array_fill_keys([108, 109, 110, 111, 112, 113], false),
    $shown([108, 109, 110, 111, 112, 113]));
check('cousin adopted elsewhere is still visible', true, isset($visible['p:107']));
check('box of a visible person is visible', [true, true, true], [isset($visible['f:14']), isset($visible['f:16']), isset($visible['f:20'])]);
check('box behind folded in-laws is not', false, isset($visible['f:18']));
check('in-laws are attached, not core', ['attached', 'attached', 'core'], [$visible['p:83'], $visible['p:287'], $visible['p:107']]);
check('origin of an in-law through her ancestors', [18], Relatives_Graph::origin_families_from($graph, 'p:83'));
check('origin of an in-law through her father', [18], Relatives_Graph::origin_families_from($graph, 'p:287'));
check('no origin for a person without boxes above', [], Relatives_Graph::origin_families_from($graph, 'p:33'));

// Own adoptive line: an adopted root sees the adoptive parents' family.
$adopted = Relatives_TreeBuilder::visible_graph_keys($graph, 'p:107');
check('adopted root: adoptive parents and siblings are visible', true,
    isset($adopted['p:108'], $adopted['p:109'], $adopted['p:110'], $adopted['p:113']));
check('adopted root: blood relatives are visible too', true, isset($adopted['p:11'], $adopted['p:212']));

// --- Layout ----------------------------------------------------------------------------

echo "\n=== Layout (Megan) ===\n";
// collect() adds an origin label for each folded in-law (origin_families_from()).
$t = layout_tree($visible, [83 => 18, 287 => 18]);
$n = $t['nodes'];
$x = static function ($id) use ($n): float {
    return (float)$n[tree_key_of($id)]['x'];
};

$rows = [];
foreach ($n as $key => $node) {
    if (empty($node['tag'])) {
        $rows[(string)$node['y']][$key] = (float)$node['x'];
    }
}
$overlaps = [];
foreach ($rows as $y => $row) {
    asort($row);
    $previous = null;
    foreach ($row as $key => $value) {
        if ($previous !== null && $value - $row[$previous] < 0.999) {
            $overlaps[] = "{$previous}/{$key}";
        }
        $previous = $key;
    }
}
check('no boxes overlap in any row', [], $overlaps);

$width = max(array_merge(...array_values(array_map('array_values', $rows)))) - min(array_merge(...array_values(array_map('array_values', $rows))));
echo "       (width: " . round($width, 1) . " columns, was ~40 before)\n";
check('tree is clearly narrower than before', true, $width < 34);

$apart = [];
foreach ([[2, 1], [32, 83], [207, 208], [93, 130], [125, 124], [127, 126], [212, 213], [300, 301], [294, 295], [292, 293], [46, 45], [304, 309], [310, 311]] as [$a, $b]) {
    // Partners that each hang on their own family box stand 1 + UNIT_GAP apart, so both boxes fit above them.
    $distance = abs($x($a) - $x($b));
    if (abs($distance - 1.0) > 0.001 && abs($distance - (1.0 + Relatives_TreeLayout::UNIT_GAP)) > 0.001) {
        $apart[] = PEOPLE[$a][0] . '/' . PEOPLE[$b][0];
    }
}
check('couples stand side by side', [], $apart);
check('partners with their own family boxes are moved apart', 1.0 + Relatives_TreeLayout::UNIT_GAP, round(abs($x(127) - $x(126)), 3));

$order = static function (array $ids) use ($x): array {
    usort($ids, static function ($a, $b) use ($x) {
        return $x($a) <=> $x($b);
    });
    return array_map(static function ($id) {
        return PEOPLE[$id][0];
    }, $ids);
};
$years = array_map(static function ($name) {
    foreach (PEOPLE as $person) {
        if ($person[0] === $name) {
            return $person[2];
        }
    }
    return null;
}, $order([241, 4, 5, 38, 3]));
$sorted = $years;
sort($sorted);
check('Megan and her siblings by year of birth', $sorted, $years);

/** Nobody from outside the family stands between the outermost children (spouses may). */
$contiguous = static function (array $children) use ($n, $x, $t): bool {
    $keys = array_map('tree_key_of', $children);
    $allowed = array_flip($keys);
    foreach ($t['partner_pairs'] as $pair) {
        if (isset($allowed[$pair['a']]) || isset($allowed[$pair['b']])) {
            $allowed[$pair['a']] = true;
            $allowed[$pair['b']] = true;
        }
    }
    $xs = array_map($x, $children);
    $y = $n[$keys[0]]['y'];
    foreach ($n as $key => $node) {
        if (empty($node['tag']) && $node['y'] === $y && $node['x'] > min($xs) && $node['x'] < max($xs) && !isset($allowed[$key])) {
            return false;
        }
    }
    return true;
};
check('Megan\'s sibling group is contiguous', true, $contiguous([241, 4, 5, 38, 3]));
check('Matthew\'s sibling group is contiguous', true, $contiguous([2, 207, 211, 93, 32, 11]));
check('Gwendolyn\'s sibling group is contiguous', true, $contiguous([1, 125, 214]));
check('Jonathan\'s children are contiguous', true, $contiguous([84, 251, 237]));

check('seam: Gwendolyn and Matthew at the inner edges of their sibling groups', [true, true], [
    $x(1) === max(array_map($x, [1, 125, 214])) || $x(1) === min(array_map($x, [1, 125, 214])),
    $x(2) === max(array_map($x, [2, 207, 211, 93, 32, 11])) || $x(2) === min(array_map($x, [2, 207, 211, 93, 32, 11])),
]);
$span = array_map($x, [2, 207, 211, 93, 32, 11]);
check('Percival and Isadora stand above their children', true,
    min($x(212), $x(213)) >= min($span) - 0.001 && max($x(212), $x(213)) <= max($span) + 0.001);
$span = array_map($x, [1, 125, 214]);
check('Algernon and Cassandra stand above their children', true,
    min($x(127), $x(126)) >= min($span) - 1.001 && max($x(127), $x(126)) <= max($span) + 1.001);
check('parents stand right above Megan\'s sibling group', true,
    abs(($x(1) + $x(2)) / 2 - (min(array_map($x, [241, 4, 5, 38, 3])) + max(array_map($x, [241, 4, 5, 38, 3]))) / 2) < 0.001);

check('origin labels above Rebecca and Beatrix', [true, true], [
    abs($n['origin:18:83']['x'] - $x(83)) < 0.001 && $n['origin:18:83']['y'] < $n['person:83']['y'],
    abs($n['origin:18:287']['x'] - $x(287)) < 0.001 && $n['origin:18:287']['y'] < $n['person:287']['y'],
]);
check('box holding only in-laws becomes one label per person', [true, true], [
    !empty($n['family:27']['tag']) && !empty($n['origin:27:307']['tag'] ?? $n['origin:27:46']['tag'] ?? null),
    abs($n['origin:27:307']['x'] - $x(307)) < 0.001 || abs($n['family:27']['x'] - $x(307)) < 0.001,
]);

$rows = new ReflectionMethod(Relatives_TreeBuilder::class, 'partners_in_one_row');
if (PHP_VERSION_ID < 80100) {
    $rows->setAccessible(true);
}
// Lucius (blood, -1) and Aurora (in-law, -2 via her brother Orion) are married.
$generation = $rows->invoke(null,
    ['person:306' => -1, 'person:307' => -2, 'person:46' => -2],
    ['person:306' => 4, 'person:46' => 5, 'person:307' => 7],
    ['person:306' => [['person:307', 0, 'partner', 0]], 'person:307' => [['person:306', 0, 'partner', 0]]],
    ['person:306' => true]
);
check('in-law moves into the row of the blood-related partner', [-1, -1, -2],
    [$generation['person:306'], $generation['person:307'], $generation['person:46']]);
check('family boxes with one person become labels', [true, true, true, false], [
    !empty($n['family:16']['tag']), !empty($n['family:21']['tag']), !empty($n['family:20']['tag']), !empty($n['family:14']['tag']),
]);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
