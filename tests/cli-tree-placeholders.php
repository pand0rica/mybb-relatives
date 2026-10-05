<?php
/**
 * Platzhalter-Aufloesung im Stammbaum -- laeuft OHNE MyBB-Installation (kein DB-Zugriff):
 *
 *   php tests/cli-tree-placeholders.php
 *
 * Baut kleine Ausschnitte von Hand (Knoten + Aussagen, wie Relatives_TreeBuilder::collect()
 * sie liefert) und prueft Struktur und Layout ueber TreeBuilder::layout() (per Reflection).
 * Wurzel ist immer 'person:1'.
 */

if (PHP_SAPI !== 'cli') {
    die('CLI only.');
}
define('IN_MYBB', 1);
chdir(dirname(__DIR__));

require_once './inc/plugins/relatives/core/Kinship.php';
require_once './inc/plugins/relatives/core/TreeBuilder.php';
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

const OFFSETS = [
    'parent' => -1, 'child' => 1, 'sibling' => 0, 'spouse' => 0,
    'aunt_uncle' => -1, 'niece_nephew' => 1, 'cousin' => 0, 'great_aunt_uncle' => -2,
    'grandparent' => -2, 'parent_in_law' => -1, 'child_in_law' => 1, 'sibling_in_law' => 0,
    'half_sibling' => 0, 'step_sibling' => 0, 'friend' => 0,
];

/**
 * @param array $generations person-id => Generation
 * @param array $statements  [[person-id, Konzept, Anker-id, geheim?], ...] -- "Person ist Konzept von Anker"
 */
function tree(array $generations, array $statements): array
{
    $builder = new Relatives_TreeBuilder();
    $nodes = [];
    foreach ($generations as $id => $generation) {
        $key = 'person:' . $id;
        $nodes[$key] = ['key' => $key, 'type' => $id === 1 ? 'self' : 'person', 'name' => 'P' . $id, 'gender' => 2, 'generation' => $generation];
    }
    $relations = [];
    foreach ($statements as $statement) {
        $relations[] = [
            'node' => 'person:' . $statement[0], 'anchor' => 'person:' . $statement[2],
            'concept' => $statement[1], 'offset' => OFFSETS[$statement[1]], 'label' => $statement[1],
            'style' => 'solid', 'secret' => !empty($statement[3]), 'primary' => false,
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
    $set('root_key', 'person:1');

    $layout = new ReflectionMethod(Relatives_TreeBuilder::class, 'layout');
    if (PHP_VERSION_ID < 80100) {
        $layout->setAccessible(true);
    }
    [$structure, $partners] = $layout->invoke($builder);

    $nodes_property = new ReflectionProperty(Relatives_TreeBuilder::class, 'nodes');
    $edges_property = new ReflectionProperty(Relatives_TreeBuilder::class, 'edges');
    if (PHP_VERSION_ID < 80100) {
        $nodes_property->setAccessible(true);
        $edges_property->setAccessible(true);
    }
    return $structure + [
        'partner_pairs' => $partners,
        'nodes'         => $nodes_property->getValue($builder),
        'edges'         => $edges_property->getValue($builder),
    ];
}

/** Familie mit genau diesen Eltern (Reihenfolge egal), die alle $children enthaelt. */
function find_family(array $tree, array $parents, array $children = []): ?array
{
    sort($parents);
    foreach ($tree['families'] as $family) {
        $family_parents = $family['parents'];
        sort($family_parents);
        if ($family_parents === $parents && !array_diff($children, $family['children'])) {
            return $family;
        }
    }
    return null;
}

function placeholders(array $tree): array
{
    return array_keys(array_filter($tree['nodes'], static function ($node) {
        return ($node['type'] ?? '') === 'placeholder';
    }));
}

function has_pair(array $pairs, string $a, string $b): bool
{
    foreach ($pairs as $pair) {
        if (($pair['a'] === $a && $pair['b'] === $b) || ($pair['a'] === $b && $pair['b'] === $a)) {
            return true;
        }
    }
    return false;
}

echo "\n=== Tante ohne bekannte Eltern ===\n";
$t = tree([1 => 0, 2 => -1], [[2, 'aunt_uncle', 1]]);
$p = 'placeholder:parent:person:1';
check('no loose line', [], $t['links']);
check('placeholder parent created', 'placeholder', $t['nodes'][$p]['type'] ?? null);
check('placeholder one generation up', -1, $t['nodes'][$p]['generation'] ?? null);
check('placeholder names the root', [['person:1'], 1], [$t['nodes'][$p]['placeholder_of'], $t['nodes'][$p]['placeholder_depth']]);
check('placeholder is parent of the root', true, find_family($t, [$p], ['person:1']) !== null);
check('aunt is sibling of the placeholder', true, find_family($t, [], [$p, 'person:2']) !== null);
check('placeholder is laid out', true, isset($t['nodes'][$p]['x'], $t['nodes'][$p]['y']));
check('placeholder reachable for collapsing', true, in_array(['from' => 'person:1', 'to' => $p], $t['edges'], true));

echo "\n=== Tante, beide Eltern bekannt (neutral) ===\n";
$t = tree([1 => 0, 2 => -1, 3 => -1, 4 => -1], [[3, 'parent', 1], [4, 'parent', 1], [2, 'aunt_uncle', 1]]);
$g = 'placeholder:neutral:person:3|person:4';
check('no loose line', [], $t['links']);
check('only the neutral placeholder', [$g], placeholders($t));
check('neutral placeholder is grandparent of the root', [-2, 2, ['person:1']],
    [$t['nodes'][$g]['generation'] ?? null, $t['nodes'][$g]['placeholder_depth'] ?? null, $t['nodes'][$g]['placeholder_of'] ?? null]);
$family = find_family($t, [$g], ['person:2']);
check('aunt is child of the neutral placeholder', true, $family !== null);
check('its line ends on the parents\' couple line', [['person:3', 'person:4']], $family['either'] ?? null);
$xs = [$t['nodes']['person:2']['x'], $t['nodes']['person:3']['x'], $t['nodes']['person:4']['x']];
check('neutral placeholder stands above aunt and parents', true,
    $t['nodes'][$g]['x'] >= min($xs) - 0.001 && $t['nodes'][$g]['x'] <= max($xs) + 0.001);
check('root keeps its parents', true, find_family($t, ['person:3', 'person:4'], ['person:1']) !== null);

echo "\n=== Tante ist bereits Schwester der Mutter ===\n";
$t = tree([1 => 0, 2 => -1, 3 => -1], [[3, 'parent', 1], [2, 'sibling', 3], [2, 'aunt_uncle', 1]]);
check('no loose line', [], $t['links']);
check('no placeholder needed', [], placeholders($t));

echo "\n=== Tante, nur ein Elternteil bekannt ===\n";
$t = tree([1 => 0, 2 => -1, 3 => -1], [[3, 'parent', 1], [2, 'aunt_uncle', 1]]);
$q = 'placeholder:coparent:person:1';
$g = 'placeholder:neutral:person:3|' . $q;
check('second parent is a placeholder', true, find_family($t, ['person:3', $q], ['person:1']) !== null);
check('known and unknown parent are drawn as a couple', true, has_pair($t['partner_pairs'], 'person:3', $q));
check('aunt hangs on the neutral placeholder', [['person:3', $q]], find_family($t, [$g], ['person:2'])['either'] ?? null);

echo "\n=== Cousine, beide ohne Eltern ===\n";
$t = tree([1 => 0, 2 => 0], [[2, 'cousin', 1]]);
$p1 = 'placeholder:parent:person:1';
$p2 = 'placeholder:parent:person:2';
check('no loose line', [], $t['links']);
check('both parents are placeholders and siblings', true, find_family($t, [], [$p1, $p2]) !== null);
check('cousin hangs below her placeholder parent', true, find_family($t, [$p2], ['person:2']) !== null);

echo "\n=== Cousin, beide Eltern auf beiden Seiten bekannt ===\n";
$t = tree([1 => 0, 2 => 0, 3 => -1, 4 => -1, 5 => -1, 6 => -1],
    [[3, 'parent', 1], [4, 'parent', 1], [5, 'parent', 2], [6, 'parent', 2], [2, 'cousin', 1]]);
$g = 'placeholder:neutral:person:3|person:4';
$family = find_family($t, [$g]);
check('one neutral grandparent above both couples', [['person:3', 'person:4'], ['person:5', 'person:6']], $family['either'] ?? null);
check('it names both grandchildren', ['person:1', 'person:2'], $t['nodes'][$g]['placeholder_of'] ?? null);

echo "\n=== Nichte, Wurzel hat Eltern ===\n";
$t = tree([1 => 0, 2 => 1, 3 => -1, 4 => -1], [[3, 'parent', 1], [4, 'parent', 1], [2, 'niece_nephew', 1]]);
$p = 'placeholder:parent:person:2';
check('niece\'s parent is a placeholder sibling of the root', true, find_family($t, ['person:3', 'person:4'], ['person:1', $p]) !== null);
check('niece is its child', true, find_family($t, [$p], ['person:2']) !== null);

echo "\n=== Grosstante ohne Eltern ===\n";
$t = tree([1 => 0, 2 => -2], [[2, 'great_aunt_uncle', 1]]);
$g = 'placeholder:parent:placeholder:parent:person:1';
check('grandparent placeholder is named after the root', [-2, 2, ['person:1']],
    [$t['nodes'][$g]['generation'] ?? null, $t['nodes'][$g]['placeholder_depth'] ?? null, $t['nodes'][$g]['placeholder_of'] ?? null]);
check('great-aunt is its sibling', true, find_family($t, [], [$g, 'person:2']) !== null);

echo "\n=== Schwiegereltern ===\n";
$t = tree([1 => 0, 2 => -1, 5 => 0], [[5, 'spouse', 1], [2, 'parent_in_law', 1]]);
check('parent-in-law becomes parent of the spouse', true, find_family($t, ['person:2'], ['person:5']) !== null);
check('no placeholder with a known spouse', [], placeholders($t));

$t = tree([1 => 0, 2 => -1], [[2, 'parent_in_law', 1]]);
$s = 'placeholder:partner:person:1';
check('missing spouse becomes a placeholder partner', [true, 'partner'], [has_pair($t['partner_pairs'], 'person:1', $s), $t['nodes'][$s]['placeholder_role'] ?? null]);
check('parent-in-law is its parent', true, find_family($t, ['person:2'], [$s]) !== null);

$t = tree([1 => 0, 2 => 1], [[2, 'child_in_law', 1]]);
$s = 'placeholder:partner:person:2';
check('child-in-law: placeholder partner is child of the root', true, find_family($t, ['person:1'], [$s]) !== null);

echo "\n=== Schwager*in ===\n";
$t = tree([1 => 0, 2 => 0], [[2, 'sibling_in_law', 1]]);
check('undecidable direction keeps the loose line', 1, count($t['links']));
check('... without placeholders', [], placeholders($t));

$t = tree([1 => 0, 2 => 0, 5 => 0], [[5, 'spouse', 1], [2, 'sibling_in_law', 1]]);
check('sibling of the only spouse', true, find_family($t, [], ['person:5', 'person:2']) !== null);
check('no loose line', [], $t['links']);

echo "\n=== Halb- und Stiefgeschwister ===\n";
$t = tree([1 => 0, 2 => 0, 3 => -1, 4 => -1], [[3, 'parent', 1], [4, 'parent', 1], [2, 'half_sibling', 1]]);
$family = find_family($t, ['person:3', 'person:4'], ['person:2']);
check('half-sibling hangs on the parents\' bar', ['half', false, true], [$family['group'] ?? null, $family['primary'] ?? null, $family['positions'] ?? null]);
check('no loose line', [], $t['links']);
check('half-sibling is laid out', true, isset($t['nodes']['person:2']['x']));

$t = tree([1 => 0, 2 => 0], [[2, 'half_sibling', 1]]);
$shared = 'placeholder:shared:person:1|person:2';
check('half-siblings without parents share a placeholder parent', true, find_family($t, [$shared], ['person:1', 'person:2']) !== null);

$t = tree([1 => 0, 2 => 0], [[2, 'step_sibling', 1]]);
$p1 = 'placeholder:parent:person:1';
$p2 = 'placeholder:parent:person:2';
check('step-siblings: placeholder parents are partners', true, has_pair($t['partner_pairs'], $p1, $p2));
check('... but not co-parents of the other child', [true, true], [find_family($t, [$p1], ['person:1']) !== null, find_family($t, [$p2], ['person:2']) !== null]);

echo "\n=== Nicht aufgeloest ===\n";
$t = tree([1 => 0, 2 => -2], [[2, 'grandparent', 1]]);
check('grandparent stays a loose line (out of scope)', 1, count($t['links']));
$t = tree([1 => 0, 2 => 0], [[2, 'aunt_uncle', 1]]);
check('aunt in the wrong generation stays a loose line', [1, []], [count($t['links']), placeholders($t)]);

echo "\n=== Geheim ===\n";
$t = tree([1 => 0, 2 => -1], [[2, 'aunt_uncle', 1, true]]);
check('secret statement keeps its chain secret', true, find_family($t, ['placeholder:parent:person:1'], ['person:1'])['secret'] ?? null);

echo "\n=== Elternloser Elternteil (z. B. Familien-Kasten) ===\n";
// 20 hat keine Eltern im Baum und ist Mutter von 21, die mit 14 (Enkel von 10) verheiratet
// ist. Frueher landete 20 am Zeilenende, und ihr Balken lief ueber die Abgaenge von 12/13.
$t = tree([1 => 0, 10 => -3, 11 => -2, 12 => -2, 13 => -2, 20 => -2, 14 => -1, 15 => -1, 16 => -1, 21 => -1], [
    [11, 'child', 10], [12, 'child', 10], [13, 'child', 10],
    [14, 'child', 11], [15, 'child', 12], [16, 'child', 13],
    [21, 'child', 20], [21, 'spouse', 14], [1, 'child', 14], [1, 'child', 21],
]);
$x = static function (int $id) use ($t): float {
    return (float)$t['nodes']['person:' . $id]['x'];
};
check('rootless parent stands left of foreign siblings 12/13', true, $x(20) < $x(12) && $x(20) < $x(13));
check('rootless parent stands close above its child', true, abs($x(20) - $x(21)) < 1.0);
// Einzelkind genau unter dem Elternstiel -- sonst zeichnet das Frontend einen Knick.
check('single child exactly below its parent (no jog)', true, abs($x(20) - $x(21)) < 0.001);

echo "\n=== Aussagen zusammen lesen ===\n";
// Mischa (6) ist Halbbruder von Jolina (1, Eltern 2+3) und Cousin von Louisa (5), deren
// Mutter Delia (4) Schwester von Jonathan (2) ist: keine zweite, erfundene Herkunft.
$t = tree([1 => 0, 2 => -1, 3 => -1, 4 => -1, 5 => 0, 6 => 0], [
    [2, 'parent', 1], [3, 'parent', 1], [4, 'sibling', 2], [5, 'child', 4],
    [6, 'half_sibling', 1], [6, 'cousin', 5],
]);
check('half-sibling + cousin: no invented parent', false, isset($t['nodes']['placeholder:parent:person:6']));
check('half-sibling hangs at the parents of the half-sibling', true, find_family($t, ['person:2', 'person:3'], ['person:6']) !== null);

// Megan (6) ist Cousine von Jolina (1, Eltern 2+3) UND von Louisa (5, Eltern 4+7); 2 und 4
// sind Geschwister -- Megans Elternteil gehoert in genau diese Geschwistergruppe.
$t = tree([1 => 0, 2 => -1, 3 => -1, 4 => -1, 7 => -1, 5 => 0, 6 => 0], [
    [2, 'parent', 1], [3, 'parent', 1], [4, 'sibling', 2], [4, 'parent', 5], [7, 'parent', 5],
    [6, 'cousin', 1], [6, 'cousin', 5],
]);
$megan_parent = 'placeholder:parent:person:6';
check('combined cousins: parent placeholder in the sibling group', true, find_family($t, [], ['person:2', 'person:4', $megan_parent]) !== null);
check('combined cousins: no neutral grandparent', [], array_values(array_filter(placeholders($t), static function ($key) {
    return strpos($key, 'placeholder:neutral:') === 0;
})));

// Smilla (6) ist Tante von Marjatta (1, Eltern 2+3), Vilma (3) Tante von Thore (4, Eltern
// 5+6): beide Aussagen gelten genau dann, wenn Smilla und Vilma Geschwister sind.
$t = tree([1 => 0, 2 => -1, 3 => -1, 4 => 0, 5 => -1, 6 => -1], [
    [2, 'parent', 1], [3, 'parent', 1], [5, 'parent', 4], [6, 'parent', 4],
    [6, 'aunt_uncle', 1], [3, 'aunt_uncle', 4],
]);
check('mutual aunts become siblings', true, find_family($t, [], ['person:3', 'person:6']) !== null);
check('mutual aunts: no placeholders', [], placeholders($t));

echo "\n=== Anordnung ===\n";
// Freund*in (6) der Wurzel steht direkt neben ihr, die Wurzel am Rand ihres
// Geschwisterblocks -- sonst liefe die lose Linie ueber die Geschwister 4 und 5.
$t = tree([4 => 0, 1 => 0, 5 => 0, 2 => -1, 3 => -1, 6 => 0], [
    [2, 'parent', 1], [3, 'parent', 1], [4, 'sibling', 1], [5, 'sibling', 1], [6, 'friend', 1],
]);
$x = static function (int $id) use ($t): float {
    return (float)$t['nodes']['person:' . $id]['x'];
};
$side = static function (int $id) use ($x): int {
    return $x($id) <=> $x(1);
};
check('loose friend stands right beside the root', true, abs($x(6) - $x(1)) < 2.0);
check('no sibling between root and friend', true, $side(4) === -$side(6) && $side(5) === -$side(6));

// Partnerkette 2-3-4-5 (3 ist Elternteil der Wurzel mit 2 und Partner*in von 4, 4 von 5)
// steht auch dann als Kette nebeneinander, wenn sie in anderer Reihenfolge angelegt wurde.
$t = tree([1 => 0, 5 => -1, 2 => -1, 4 => -1, 3 => -1], [
    [2, 'parent', 1], [3, 'parent', 1], [4, 'spouse', 3], [5, 'spouse', 4],
]);
$x = static function (int $id) use ($t): float {
    return (float)$t['nodes']['person:' . $id]['x'];
};
check('partner chain stays adjacent', [1.0, 1.0, 1.0], [
    round(abs($x(3) - $x(2)), 2), round(abs($x(4) - $x(3)), 2), round(abs($x(5) - $x(4)), 2),
]);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
