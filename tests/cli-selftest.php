<?php
/**
 * CLI-Selbsttest fuer "relatives". Prueft Business-Logik + DB-Roundtrip:
 * Relationship-Type-Gender-Filter, Personen mit mehreren Besitzer*innen, Owner-Checks,
 * Namenssuche, Link-Workflow-Zustandsmaschine, TreeBuilder, relatives_search_persons(),
 * Kompositions-Tabelle und das Migrations-Mapping.
 *
 * Voraussetzung: Plugin ist installiert (tests/cli-plugin.php install vorher ausfuehren).
 * Aufruf: php tests/cli-selftest.php
 */

if (PHP_SAPI !== 'cli') {
    die('CLI only.');
}
define('IN_MYBB', 1);
chdir(dirname(__DIR__));
require_once './inc/init.php';

if (!isset($mybb->user) || !is_array($mybb->user)) {
    $mybb->user = [];
}
$mybb->user += ['uid' => 0, 'timezone' => $mybb->settings['timezoneoffset'] ?? 0, 'dst' => 0, 'dateformat' => '', 'timeformat' => ''];

require_once MYBB_ROOT . 'inc/plugins/relatives/core/Database.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/RelationshipTypes.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Attributes.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Families.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Kinship.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Graph.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/TreeBuilder.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/TreeLayout.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/TreePlaceholders.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/LinkManager.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/SearchApi.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Migration.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/StructureInference.php';

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

if (!$db->table_exists('relatives_persons') || !$db->table_exists('relatives_person_owners')) {
    echo "relatives tables missing -- run 'php tests/cli-plugin.php install' first.\n";
    exit(1);
}

$marker = 'SELFTEST ' . TIME_NOW;

// --- Fixture: zwei Test-User (verwendet vorhandene users-Zeilen, keine Inserts in users) ---

$existing_users = [];
$q = $db->simple_select('users', 'uid', '1=1', ['limit' => 2, 'order_by' => 'uid', 'order_dir' => 'ASC']);
while ($row = $db->fetch_array($q)) {
    $existing_users[] = (int)$row['uid'];
}
if (count($existing_users) < 2) {
    echo "Need at least 2 existing users on this forum -- several sections will be skipped.\n";
}
$uid_a = $existing_users[0] ?? 0;
$uid_b = $existing_users[1] ?? 0;

// v2.2: Jeder Charakter ist ein Knoten im gemeinsamen Geflecht und wird bei Bedarf
// angelegt. Vorhandene Knoten merken, damit das Aufraeumen am Ende nur entfernt, was
// der Test selbst angelegt oder verknuepft hat -- echte Testforum-Daten bleiben unberuehrt.
$selftest_started = TIME_NOW;
$preexisting_nodes = [];
foreach ([$uid_a, $uid_b] as $fixture_uid) {
    if ($fixture_uid > 0) {
        $preexisting_nodes[$fixture_uid] = Relatives_Graph::find_account_person_id($fixture_uid);
    }
}

echo "=== Relationship-Type gender filter ===\n";

$type_male_id = Relatives_RelationshipTypes::create([
    'concept_key' => 'selftest_parent', 'gender' => 0, 'label' => $marker . ' Vater',
    'generation_offset' => -1, 'sort_order' => 0, 'is_active' => 1,
]);
$type_female_id = Relatives_RelationshipTypes::create([
    'concept_key' => 'selftest_parent', 'gender' => 1, 'label' => $marker . ' Mutter',
    'generation_offset' => -1, 'sort_order' => 0, 'is_active' => 1,
]);

$found_male = false;
foreach (Relatives_RelationshipTypes::get_for_person_gender(0) as $row) {
    if ((int)$row['id'] === $type_male_id) { $found_male = true; }
}
check('gender=0 relationship type appears for person gender=0', true, $found_male);

$found_male_for_female_person = false;
foreach (Relatives_RelationshipTypes::get_for_person_gender(1) as $row) {
    if ((int)$row['id'] === $type_male_id) { $found_male_for_female_person = true; }
}
check('gender=0 relationship type does NOT appear for person gender=1', false, $found_male_for_female_person);

check('map_person_gender_to_label_gender(-1) == neutral', 2, Relatives_RelationshipTypes::map_person_gender_to_label_gender(-1));
check('map_person_gender_to_label_gender(2) == neutral', 2, Relatives_RelationshipTypes::map_person_gender_to_label_gender(2));
check('map_person_gender_to_label_gender(0) == male', 0, Relatives_RelationshipTypes::map_person_gender_to_label_gender(0));
check('map_person_gender_to_label_gender(1) == female', 1, Relatives_RelationshipTypes::map_person_gender_to_label_gender(1));

echo "\n=== Personen-CRUD + Owner-Check ===\n";

$person_id = 0;
$related_person_id = 0;
if ($uid_a > 0) {
    $person_id = Relatives_Database::create_person([
        'name'      => $marker . ' Person A',
        'gender'    => -1,
        'is_wanted' => 0,
    ]);
    check('create_person returns positive id', true, $person_id > 0);
    check('add_owner returns positive id', true, Relatives_Database::add_owner($person_id, $uid_a, ['relationship_custom_label' => 'Testverbindung']) > 0);
    check('add_owner for an existing owner returns 0', 0, Relatives_Database::add_owner($person_id, $uid_a, []));
    check('is_owner(person, owner) == true', true, Relatives_Database::is_owner($person_id, $uid_a));
    check('is_owner(person, other_uid) == false', false, Relatives_Database::is_owner($person_id, $uid_a + 999999));

    $loaded = Relatives_Database::get_person($person_id);
    check('get_person returns correct name', $marker . ' Person A', $loaded['name'] ?? null);
    $loaded_for_owner = Relatives_Database::get_person_for_owner($person_id, $uid_a);
    check('get_person_for_owner returns the owner fields', 'Testverbindung', $loaded_for_owner['relationship_custom_label'] ?? null);

    $related_person_id = Relatives_Database::create_person(['name' => $marker . ' Related', 'gender' => 0]);
    Relatives_Database::add_owner($related_person_id, $uid_a, ['relationship_custom_label' => 'Test', 'related_to_person_id' => $person_id]);
} else {
    echo "  skip (no user fixture available)\n";
}

echo "\n=== Geteilte Personen (mehrere Besitzer*innen) ===\n";

if ($uid_a > 0 && $uid_b > 0 && $person_id > 0) {
    Relatives_Database::add_owner($person_id, $uid_b, ['relationship_custom_label' => 'Zweite Sicht', 'description' => 'Beziehung B']);
    check('count_owners == 2 after second owner', 2, Relatives_Database::count_owners($person_id));
    check('owner B sees own relationship label', 'Zweite Sicht', Relatives_Database::get_person_for_owner($person_id, $uid_b)['relationship_custom_label'] ?? null);
    check('relationship label is shared across characters (v2.2)', 'Zweite Sicht', Relatives_Database::get_person_for_owner($person_id, $uid_a)['relationship_custom_label'] ?? null);
    check('owner B relationship_description stored per owner', 'Beziehung B', Relatives_Database::get_person_for_owner($person_id, $uid_b)['relationship_description'] ?? null);

    $found_by_name = Relatives_Database::find_person_by_name('  ' . mb_strtoupper($marker . ' Person A') . ' ');
    check('find_person_by_name is case-insensitive and trims', $person_id, $found_by_name !== null ? (int)$found_by_name['id'] : null);

    $lena_id = Relatives_Database::create_person(['name' => $marker . ' Lena', 'gender' => 1]);
    check('find_person_by_name does not merge accent variants', null, Relatives_Database::find_person_by_name($marker . ' Léna'));
    Relatives_Database::delete_person($lena_id);

    check('remove_owner(B) keeps the person (returns false)', false, Relatives_Database::remove_owner($person_id, $uid_b));
    check('person still exists after removing one owner', true, Relatives_Database::get_person($person_id) !== null);
    check('count_owners == 1 after removing B', 1, Relatives_Database::count_owners($person_id));
} else {
    echo "  skip (need 2 distinct user fixtures)\n";
}

echo "\n=== Link-Workflow-Zustandsmaschine ===\n";

if ($uid_a > 0 && $uid_b > 0 && $person_id > 0) {
    check('link_status starts at 0 (none)', 0, (int)Relatives_Database::get_person($person_id)['link_status']);
    check('request_link by non-owner fails', false, Relatives_LinkManager::request_link($person_id, $uid_b, $uid_a));

    Relatives_LinkManager::request_link($person_id, $uid_a, $uid_b);
    $after_request = Relatives_Database::get_person($person_id);
    check('link_status == 1 (requested) after request_link', 1, (int)$after_request['link_status']);
    check('linked_uid set to target', $uid_b, (int)$after_request['linked_uid']);

    check('accept_link by wrong uid fails', false, Relatives_LinkManager::accept_link($person_id, $uid_a));
    check('accept_link by linked_uid succeeds', true, Relatives_LinkManager::accept_link($person_id, $uid_b));
    $after_accept = Relatives_Database::get_person($person_id);
    check('link_status == 2 (confirmed) after accept', 2, (int)$after_accept['link_status']);
    check('is_wanted forced to 0 after confirmed link', 0, (int)$after_accept['is_wanted']);

    $person_id_2 = Relatives_Database::create_person(['name' => $marker . ' Person B', 'gender' => -1, 'is_wanted' => 0]);
    Relatives_Database::add_owner($person_id_2, $uid_a, ['relationship_custom_label' => 'Test']);
    Relatives_LinkManager::request_link($person_id_2, $uid_a, $uid_b);
    check('decline_link by linked_uid succeeds', true, Relatives_LinkManager::decline_link($person_id_2, $uid_b));
    $after_decline = Relatives_Database::get_person($person_id_2);
    check('link_status reset to 0 after decline', 0, (int)$after_decline['link_status']);
    check('linked_uid reset to null after decline', null, $after_decline['linked_uid']);

    Relatives_Database::delete_person($person_id_2);
} else {
    echo "  skip (need 2 distinct user fixtures)\n";
}

echo "\n=== TreeBuilder ===\n";

if ($uid_a > 0) {
    $tree_root = Relatives_Graph::account_person_id($uid_a);
    $tree_child = Relatives_Database::create_person(['name' => $marker . ' Tree Child', 'gender' => 1]);
    Relatives_Database::add_owner($tree_child, $uid_a);
    Relatives_Database::replace_relations($tree_child, [
        ['relationship_custom_label' => 'Testkind', 'related_to_person_id' => $tree_root],
    ], $uid_a);

    $tree = Relatives_TreeBuilder::build($uid_a, $uid_a);
    $root_node = null;
    $person_node = null;
    foreach ($tree['nodes'] as $node) {
        if ($node['key'] === $tree['root']) { $root_node = $node; }
        if ($node['key'] === 'person:' . $tree_child) { $person_node = $node; }
    }
    check('tree root is the character node', 'person:' . $tree_root, $tree['root']);
    check('root node is of type self for its account', [$uid_a, 'self'], [(int)($root_node['uid'] ?? 0), $root_node['type'] ?? null]);
    check('tree contains the connected person', true, $person_node !== null);
    check('person node carries owner_count', 1, $person_node['owner_count'] ?? null);
    check('person node carries attribute_option_ids array', true, is_array($person_node['attribute_option_ids'] ?? null));
    check('owner may edit the person in the tree', true, $person_node['can_edit'] ?? null);

    Relatives_Database::delete_person($tree_child);
} else {
    echo "  skip (no fixture)\n";
}

echo "\n=== relatives_search_persons() ===\n";

if ($uid_a > 0) {
    $search_person_id = Relatives_Database::create_person([
        'name' => $marker . ' Wanted Search', 'gender' => 1, 'is_wanted' => 1, 'age' => 25,
    ]);
    Relatives_Database::add_owner($search_person_id, $uid_a, ['relationship_type_id' => $type_female_id]);

    $found = null;
    foreach (Relatives_SearchApi::search(['query' => $marker . ' Wanted', 'is_wanted' => true]) as $row) {
        if ((int)$row['id'] === $search_person_id) { $found = $row; }
    }
    check('search by query + is_wanted finds fixture', true, $found !== null);
    check('search result lists the owner', $uid_a, $found['owners'][0]['owner_uid'] ?? null);

    $found_wrong = false;
    foreach (Relatives_SearchApi::search(['query' => $marker . ' Wanted', 'gender' => 0, 'is_wanted' => true]) as $row) {
        if ((int)$row['id'] === $search_person_id) { $found_wrong = true; }
    }
    check('search with mismatched gender filter excludes fixture', false, $found_wrong);

    $found_age = false;
    foreach (Relatives_SearchApi::search(['query' => $marker . ' Wanted', 'age_min' => 20, 'age_max' => 30, 'is_wanted' => true]) as $row) {
        if ((int)$row['id'] === $search_person_id) { $found_age = true; }
    }
    check('search with matching age range finds fixture', true, $found_age);

    $found_type = false;
    foreach (Relatives_SearchApi::search(['relationship_type_id' => $type_female_id, 'is_wanted' => true]) as $row) {
        if ((int)$row['id'] === $search_person_id) { $found_type = true; }
    }
    check('search by relationship_type_id matches via owner assignment', true, $found_type);

    Relatives_Database::delete_person($search_person_id);
} else {
    echo "  skip (no fixture)\n";
}

echo "\n=== Layout, gemeinsame Eltern, Bezugsperson ===\n";

// Eigener Fixture-Account ohne Verwandte, damit echte (migrierte) Daten das Layout nicht beeinflussen.
$uid_layout = (int)$db->fetch_field($db->query(
    "SELECT u.uid FROM " . TABLE_PREFIX . "users u
     WHERE NOT EXISTS (SELECT 1 FROM " . TABLE_PREFIX . "relatives_person_owners o WHERE o.owner_uid = u.uid)
     ORDER BY u.uid LIMIT 1"
), 'uid');
$type_mother = Relatives_RelationshipTypes::get_by_concept_and_gender('parent', 1);
$type_father = Relatives_RelationshipTypes::get_by_concept_and_gender('parent', 0);
$type_aunt = Relatives_RelationshipTypes::get_by_concept_and_gender('aunt_uncle', 1);
$type_uncle = Relatives_RelationshipTypes::get_by_concept_and_gender('aunt_uncle', 0);
$type_brother = Relatives_RelationshipTypes::get_by_concept_and_gender('sibling', 0);

if ($uid_layout > 0 && $type_mother && $type_father && $type_aunt && $type_uncle && $type_brother) {
    $preexisting_nodes[$uid_layout] = $preexisting_nodes[$uid_layout] ?? Relatives_Graph::find_account_person_id($uid_layout);
    $layout_ids = [];
    $make = function (string $name, int $gender, int $type_id, ?int $related = null) use (&$layout_ids, $marker, $uid_layout): int {
        $pid = Relatives_Database::create_person(['name' => $marker . ' ' . $name, 'gender' => $gender]);
        Relatives_Database::add_owner($pid, $uid_layout, ['relationship_type_id' => $type_id, 'related_to_person_id' => $related]);
        $layout_ids[] = $pid;
        return $pid;
    };
    $mother = $make('Mutter', 1, (int)$type_mother['id']);
    $father = $make('Vater', 0, (int)$type_father['id']);
    $aunts = [
        $make('Tante Eins', 1, (int)$type_aunt['id']),
        $make('Tante Zwei', 1, (int)$type_aunt['id']),
        $make('Tante Drei', 1, (int)$type_aunt['id']),
    ];
    $uncle = $make('Onkel', 0, (int)$type_brother['id'], $mother);

    Relatives_Kinship::recompute_all();
    $tree = Relatives_TreeBuilder::build($uid_layout, $uid_layout);
    $nodes = [];
    foreach ($tree['nodes'] as $node) {
        $nodes[$node['key']] = $node;
    }
    $distinct = true;
    foreach ($aunts as $i => $aunt_a) {
        foreach (array_slice($aunts, $i + 1) as $aunt_b) {
            if (abs($nodes['person:' . $aunt_a]['x'] - $nodes['person:' . $aunt_b]['x']) < 1.0) {
                $distinct = false;
            }
        }
    }
    check('loose nodes of one generation do not overlap', true, $distinct);

    $expected_pair = ['person:' . $father, 'person:' . $mother];
    sort($expected_pair);
    $coparents = false;
    foreach ($tree['partners'] as $pair) {
        $keys = [$pair['a'], $pair['b']];
        sort($keys);
        if ($keys === $expected_pair) {
            $coparents = true;
        }
    }
    check('shared parents are drawn as a couple', true, $coparents);
    check('label from the character\'s view: brother of mother = uncle', (string)$type_uncle['label'], $nodes['person:' . $uncle]['computed_label'] ?? null);

    // Im gemeinsamen Geflecht sind Kreise normal (Geschwister zeigen aufeinander) und
    // Personen anderer Listen erlaubte Bezugspersonen -- abgelehnt wird nur Unsinn.
    check('is_valid_related_to: existing person', true, Relatives_Database::is_valid_related_to($mother, $uncle));
    check('is_valid_related_to: allows cycles in the shared network', true, Relatives_Database::is_valid_related_to($uncle, $mother));
    check('is_valid_related_to: rejects the person itself', false, Relatives_Database::is_valid_related_to($mother, $mother));
    check('is_valid_related_to: rejects a missing person', false, Relatives_Database::is_valid_related_to(PHP_INT_MAX, $mother));

    foreach ($layout_ids as $pid) {
        Relatives_Database::delete_person($pid);
    }
} else {
    echo "  skip (no free user fixture or standard relationship types missing)\n";
}

echo "\n=== Struktur-Ableitung (StructureInference) ===\n";

check('label details: paternal + step', ['side' => 'paternal', 'qualifier' => 'step'],
    Relatives_StructureInference::label_details('Tante väterlicherseits (Stief-, Adoptiv-, angeheiratet,...)'));
check('label details: distant', ['side' => null, 'qualifier' => 'distant'],
    Relatives_StructureInference::label_details('Cousine (entfernt)'));
check('label details: maternal blood', ['side' => 'maternal', 'qualifier' => 'blood'],
    Relatives_StructureInference::label_details('Onkel mütterlicherseits'));

$inference_row = function (int $id, string $name, string $concept, int $gender, ?string $side = null, string $qualifier = 'blood'): array {
    return [
        'person_id' => $id, 'name' => $name, 'concept' => $concept, 'type_gender' => $gender,
        'related_to' => null, 'side' => $side, 'qualifier' => $qualifier, 'inferable' => true,
    ];
};
$inference_rows = [];
foreach ([
    $inference_row(1, 'Gabriele Flemming', 'parent', 1),
    $inference_row(2, 'Matthew Flemming', 'parent', 0),
    $inference_row(3, 'Morgan Vaisey', 'aunt_uncle', 0, 'maternal'),
    $inference_row(4, 'Katherine Vaisey', 'aunt_uncle', 1, 'maternal', 'step'),
    $inference_row(5, 'Jonathan Flemming', 'aunt_uncle', 0, 'paternal'),
    $inference_row(6, 'Irina Flemming', 'aunt_uncle', 1, 'paternal', 'step'),
    $inference_row(7, 'Oliver Flemming', 'aunt_uncle', 0, 'paternal'),
    $inference_row(8, 'Ava Flemming', 'aunt_uncle', 1, 'paternal', 'step'),
    $inference_row(9, 'Siri Vaisey', 'cousin', 1),
    $inference_row(10, 'Jolina Flemming', 'cousin', 1),
    $inference_row(11, 'Ethan Flemming', 'cousin', 0),
    $inference_row(12, 'Ferne Tante', 'aunt_uncle', 1, 'paternal', 'distant'),
    $inference_row(13, 'Delia Flemming', 'aunt_uncle', 1, 'paternal'),
    $inference_row(14, 'Louisa Griffin', 'cousin', 1),
] as $inference_fixture) {
    $inference_rows[$inference_fixture['person_id']] = $inference_fixture;
}

$facts = Relatives_StructureInference::empty_facts();
// Liste von Jolina (Account = Person 10): Jonathan Vater, Irina Mutter
Relatives_StructureInference::collect_list_facts($facts, [
    ['person_id' => 5, 'concept' => 'parent', 'gender' => 0, 'qualifier' => 'blood'],
    ['person_id' => 6, 'concept' => 'parent', 'gender' => 1, 'qualifier' => 'blood'],
], 10);
// Liste eines Geschwisters von Matthew: Jonathan, Oliver und Matthew als Brueder. Jonathan und
// Oliver sind damit auch untereinander Geschwister -- darf sie nicht gegenseitig blockieren.
Relatives_StructureInference::collect_list_facts($facts, [
    ['person_id' => 5, 'concept' => 'sibling', 'gender' => 0, 'qualifier' => 'blood'],
    ['person_id' => 7, 'concept' => 'sibling', 'gender' => 0, 'qualifier' => 'blood'],
    ['person_id' => 2, 'concept' => 'sibling', 'gender' => 0, 'qualifier' => 'blood'],
], 0);
// Liste von Louisa (Account = Person 14): Delia Mutter, Morgan Vater
Relatives_StructureInference::collect_list_facts($facts, [
    ['person_id' => 13, 'concept' => 'parent', 'gender' => 1, 'qualifier' => 'blood'],
    ['person_id' => 3, 'concept' => 'parent', 'gender' => 0, 'qualifier' => 'blood'],
], 14);
check('facts: parents of one list are partners', true, Relatives_StructureInference::has_fact($facts, 6, 'spouse', 5));
check('facts: account identity gives child-of', true, Relatives_StructureInference::has_fact($facts, 10, 'child', 5));
check('facts: two siblings of one list are siblings', true, Relatives_StructureInference::has_fact($facts, 5, 'sibling', 2));

$outcome = Relatives_StructureInference::infer_owner($inference_rows, $facts);
$decisions = $outcome['decisions'];
$expected_decisions = [
    3 => [1, 'sibling'], 4 => [3, 'spouse'], 5 => [2, 'sibling'], 6 => [5, 'spouse'], 7 => [2, 'sibling'],
    8 => [7, 'spouse'], 9 => [3, 'child'], 10 => [5, 'child'], 13 => [2, 'sibling'],
];
foreach ($expected_decisions as $pid => [$anchor, $relation]) {
    check(
        "inference: {$inference_rows[$pid]['name']} -> {$inference_rows[$anchor]['name']} ({$relation})",
        [$anchor, $relation],
        isset($decisions[$pid]) ? [$decisions[$pid]['related_to'], $decisions[$pid]['concept']] : null
    );
}
check('inference: distant aunt stays loose', false, isset($decisions[12]));
check('inference: ambiguous cousins stay loose (Ethan, Louisa)', [false, false], [isset($decisions[11]), isset($decisions[14])]);
check('inference: ambiguous count', 2, $outcome['ambiguous']);

echo "\n=== Kompositions-Tabelle ===\n";

check("compose(sibling, spouse) == sibling_in_law", 'sibling_in_law', Relatives_RelationshipTypes::compose_concept('sibling', 'spouse'));
check("compose(spouse, parent) == parent_in_law", 'parent_in_law', Relatives_RelationshipTypes::compose_concept('spouse', 'parent'));
check("compose(parent, half_sibling) == aunt_uncle", 'aunt_uncle', Relatives_RelationshipTypes::compose_concept('parent', 'half_sibling'));

echo "\n=== Migrations-Mapping ===\n";

$cases = [
    'Mutter'                                                         => ['parent', 1],
    'Onkel mütterlicherseits (Stief-, Adoptiv-, angeheiratet,...)'   => ['aunt_uncle', 0],
    'Cousin (Stief-, Adoptiv-, angeheiratet ,...)'                   => ['cousin', 0],
    'Cousine (entfernt)'                                             => ['distant_relative', 1],
    'Onkel väterlicherseits (entfernt)'                              => ['distant_relative', 0],
    'Halbschwester'                                                  => ['half_sibling', 1],
    'Großvater väterlicherseits (Stief-, Adoptiv-,...)'              => ['grandparent', 0],
    'Urgroßmutter väterlicherseits'                                  => ['great_grandparent', 1],
    'Schwiegersohn'                                                  => ['child_in_law', 0],
    'Verlobte'                                                       => ['fiance', 1],
];
foreach ($cases as $label => [$concept, $gender]) {
    $mapping = Relatives_Migration::map_status_label($label);
    check("map '{$label}'", [true, $concept, $gender], [$mapping['mapped'], $mapping['concept'], $mapping['gender']]);
}
check("'NPC' is mapped without relationship type", [true, null], array_values(array_slice(Relatives_Migration::map_status_label('NPC'), 0, 2)));
check('unknown label is reported as unmapped', false, Relatives_Migration::map_status_label('Quatsch')['mapped']);

$all_targets_are_defaults = true;
foreach (Relatives_Migration::STATUS_BASE_MAP as $label => $target) {
    if ($target !== null && Relatives_RelationshipTypes::default_label($target[0], $target[1]) === null) {
        $all_targets_are_defaults = false;
        echo "    mapping target not in COMMON_DEFAULTS: {$label} -> {$target[0]}\n";
    }
}
check('every mapping target is a COMMON_DEFAULTS concept', true, $all_targets_are_defaults);

if ($db->table_exists('relatives_options')) {
    $preview = Relatives_Migration::preview();
    check('preview: no used legacy relationship type is unmapped', [], $preview['unmapped']);
}

echo "=== v2.2: gemeinsames Geflecht ===\n";

if ($uid_a > 0 && $uid_b > 0 && $db->table_exists('relatives_relation_conflicts')) {
    Relatives_RelationshipTypes::create_common_defaults();
    Relatives_Kinship::reset();
    $kin_type = static function (string $concept, int $gender): int {
        $row = Relatives_RelationshipTypes::find_by_concept_and_gender($concept, $gender);
        return $row ? (int)$row['id'] : 0;
    };
    $kin_m = Relatives_RelationshipTypes::LABEL_MALE;
    $kin_f = Relatives_RelationshipTypes::LABEL_FEMALE;

    $node_a = Relatives_Graph::account_person_id($uid_a);
    check('character node exists and is confirmed', 2, (int)(Relatives_Database::get_person($node_a)['link_status'] ?? 0));
    check('account_person_id() is stable', $node_a, Relatives_Graph::account_person_id($uid_a));

    $kin = [];
    foreach (['delia' => 1, 'oliver' => 0, 'ethan' => 0, 'morgan' => 0, 'louisa' => 1] as $kin_key => $kin_gender) {
        $kin[$kin_key] = Relatives_Database::create_person(['name' => $marker . ' kin ' . $kin_key, 'gender' => $kin_gender]);
        Relatives_Database::add_owner($kin[$kin_key], $uid_a);
    }

    // Megan-Szenario: Delia ist Mutter des Charakters, Oliver ihr Bruder, Ethan Olivers Sohn.
    Relatives_Database::replace_relations($kin['delia'], [
        ['relationship_type_id' => $kin_type('parent', $kin_f), 'related_to_person_id' => $node_a],
    ], $uid_a);
    Relatives_Database::replace_relations($kin['oliver'], [
        ['relationship_type_id' => $kin_type('sibling', $kin_m), 'related_to_person_id' => $kin['delia']],
    ], $uid_a);
    Relatives_Database::replace_relations($kin['ethan'], [
        ['relationship_type_id' => $kin_type('child', $kin_m), 'related_to_person_id' => $kin['oliver']],
    ], $uid_a);
    Relatives_Database::replace_relations($kin['morgan'], [
        ['relationship_type_id' => $kin_type('partner', $kin_m), 'related_to_person_id' => $kin['delia']],
    ], $uid_a);
    // Louisa: heimliche leibliche Tochter von Delia und Morgan, adoptiert von Oliver.
    Relatives_Database::replace_relations($kin['louisa'], [
        ['relationship_type_id' => $kin_type('child', $kin_f), 'related_to_person_id' => $kin['delia'], 'is_secret' => 1, 'is_primary' => 1],
        ['relationship_type_id' => $kin_type('child', $kin_f), 'related_to_person_id' => $kin['morgan'], 'is_secret' => 1],
        ['relationship_type_id' => $kin_type('adoptive_child', $kin_f), 'related_to_person_id' => $kin['oliver']],
    ], $uid_a);

    $louisa_relations = Relatives_Database::get_relations_for_person($kin['louisa']);
    check('louisa has three relation edges', 3, count($louisa_relations));
    check('exactly one primary edge', 1, count(array_filter($louisa_relations, static function ($row) {
        return (int)$row['is_primary'] === 1;
    })));
    check('edges belong to nobody (owner_uid 0), creator recorded', [0, $uid_a], [(int)$louisa_relations[0]['owner_uid'], (int)$louisa_relations[0]['created_by']]);
    check('legacy alias related_to_person_id shows the primary edge', $kin['delia'], (int)(Relatives_Database::get_person_for_owner($kin['louisa'], $uid_a)['related_to_person_id'] ?? 0));

    Relatives_Database::update_owner($kin['louisa'], $uid_a, ['description' => 'selftest']);
    check('update_owner() without relation keys keeps all edges', 3, count(Relatives_Database::get_relations_for_person($kin['louisa'])));

    Relatives_Kinship::recompute_all();
    $kin_concept = static function (int $person_id, int $uid): ?string {
        $cached = Relatives_Kinship::get_cached($person_id, $uid);
        return $cached['concept_key'] ?? null;
    };
    check('delia is parent', 'parent', $kin_concept($kin['delia'], $uid_a));
    check('oliver (brother of mother) is aunt_uncle', 'aunt_uncle', $kin_concept($kin['oliver'], $uid_a));
    check('ethan (son of uncle) is cousin', 'cousin', $kin_concept($kin['ethan'], $uid_a));
    check('louisa (secret daughter of mother) is sibling', 'sibling', $kin_concept($kin['louisa'], $uid_a));

    // Geteilt statt je Liste
    Relatives_Database::add_owner($kin['louisa'], $uid_b);
    check('a second character sees the very same edges', 3, count(Relatives_Database::get_relations_for_person($kin['louisa'])));

    // Rechte
    check('connected character may edit a non-played person', true, Relatives_Graph::can_edit_person($kin['louisa'], $uid_b));
    check('unconnected character may not edit', false, Relatives_Graph::can_edit_person($kin['oliver'], $uid_b));
    check('a played character node is editable only by its account', false, Relatives_Graph::can_edit_person($node_a, $uid_b));
    check('own character node is editable', true, Relatives_Graph::can_edit_person($node_a, $uid_a));

    // Widerspruch und Duplikat, egal von welcher Seite
    $contradiction = Relatives_Graph::find_existing_statement($kin['delia'], $kin['oliver'], null, 'parent', null);
    check('contradicting statement is detected', false, $contradiction['same'] ?? null);
    $duplicate = Relatives_Graph::find_existing_statement($kin['delia'], $kin['oliver'], null, 'sibling', null);
    check('same statement from the other side is detected', true, $duplicate['same'] ?? null);

    // Neuer Bruder von Oliver -> automatisch Geschwister von Delia
    $kin['brother'] = Relatives_Database::create_person(['name' => $marker . ' kin brother', 'gender' => 0]);
    Relatives_Database::add_owner($kin['brother'], $uid_a);
    Relatives_Database::replace_relations($kin['brother'], [
        ['relationship_type_id' => $kin_type('sibling', $kin_m), 'related_to_person_id' => $kin['oliver']],
    ], $uid_a);
    Relatives_Kinship::recompute_all();
    check('new brother of Oliver is an uncle as well', 'aunt_uncle', $kin_concept($kin['brother'], $uid_a));

    $kin_tree = Relatives_TreeBuilder::build($uid_a, $uid_a);
    $kin_nodes = [];
    foreach ($kin_tree['nodes'] as $kin_node) {
        $kin_nodes[$kin_node['key']] = $kin_node;
    }
    check('character node is the tree root', 'person:' . $node_a, $kin_tree['root']);
    $derived_to_delia = false;
    foreach ($kin_nodes['person:' . $kin['brother']]['relations'] ?? [] as $kin_relation) {
        if ($kin_relation['other_key'] === 'person:' . $kin['delia'] && !empty($kin_relation['derived'])) {
            $derived_to_delia = true;
        }
    }
    check('brother is recognised as sibling of Delia (derived, not stored)', true, $derived_to_delia);

    $louisa_key = 'person:' . $kin['louisa'];
    $louisa_families = array_values(array_filter($kin_tree['families'], static function ($family) use ($louisa_key) {
        return in_array($louisa_key, $family['children'], true);
    }));
    check('louisa sits in two families (birth + adoptive)', 2, count($louisa_families));
    check('only one of them positions her', 1, count(array_filter($louisa_families, static function ($family) {
        return !empty($family['primary']);
    })));
    // Delia + Morgan sind auch die (offenen) Eltern des Charakters -- geheim ist nur
    // Louisas Abstammung, nicht die ganze Familie.
    check('birth family marks louisa as secret child', true, (bool)array_filter($louisa_families, static function ($family) use ($louisa_key) {
        return $family['group'] === 'blood' && in_array($louisa_key, $family['secret_children'] ?? [], true);
    }));
    check('... but the shared family itself is not secret', false, (bool)array_filter($louisa_families, static function ($family) {
        return !empty($family['secret']) && $family['group'] === 'blood';
    }));
    check('tree node carries relation_count', 3, $kin_nodes[$louisa_key]['relation_count'] ?? null);

    // Verstorben
    Relatives_Database::update_person($kin['ethan'], ['is_deceased' => 1, 'death_note' => '1999']);
    $ethan = Relatives_Database::get_person($kin['ethan']);
    check('is_deceased stored', 1, (int)$ethan['is_deceased']);
    check('death_note stored', '1999', (string)$ethan['death_note']);

    // Familien-Kasten als Eltern-Ersatz
    $family_id = Relatives_Families::create(['name' => $marker . ' Familie Flemming', 'color' => '#abc', 'created_by' => $uid_a]);
    check('family colour is normalised', '#aabbcc', Relatives_Families::get_by_id($family_id)['color']);
    check('find_by_name() reuses the family', $family_id, (int)(Relatives_Families::find_by_name(' ' . $marker . ' familie flemming ')['id'] ?? 0));
    foreach (['sib_a' => 0, 'sib_b' => 1] as $kin_key => $kin_gender) {
        $kin[$kin_key] = Relatives_Database::create_person(['name' => $marker . ' kin ' . $kin_key, 'gender' => $kin_gender]);
        Relatives_Database::add_owner($kin[$kin_key], $uid_a);
        Relatives_Database::replace_relations($kin[$kin_key], [
            ['relationship_type_id' => $kin_type('child', $kin_gender === 0 ? $kin_m : $kin_f), 'related_to_family_id' => $family_id],
            ['relationship_type_id' => $kin_type('sibling', $kin_gender === 0 ? $kin_m : $kin_f), 'related_to_person_id' => $node_a],
        ], $uid_a);
    }
    check('family member count', 2, Relatives_Families::get_member_counts()[$family_id] ?? 0);

    // Gleiche Familie: Mitglieder sind auch ohne eigenen Listeneintrag bearbeitbar.
    $kin['fam_aunt'] = Relatives_Database::create_person(['name' => $marker . ' kin fam_aunt', 'gender' => 1]);
    Relatives_Database::add_owner($kin['fam_aunt'], $uid_b);
    Relatives_Database::replace_relations($kin['fam_aunt'], [
        ['relationship_type_id' => $kin_type('sibling', $kin_f), 'related_to_person_id' => $kin['sib_a']],
    ], $uid_b);
    Relatives_Kinship::reset();
    check('sibling of a family child belongs to the family', true, Relatives_Graph::shares_family($kin['fam_aunt'], $node_a));
    check('family member of the own character is editable', true, Relatives_Graph::can_edit_person($kin['fam_aunt'], $uid_a));
    check('a character outside the family may not edit its members', false, Relatives_Graph::can_edit_person($kin['sib_a'], $uid_b));

    // Reine Mitgliedschaft: Familie ohne Verwandtschaftsgrad.
    $kin['fam_member'] = Relatives_Database::create_person(['name' => $marker . ' kin fam_member', 'gender' => 0]);
    Relatives_Database::add_owner($kin['fam_member'], $uid_b);
    Relatives_Database::replace_relations($kin['fam_member'], [
        ['related_to_family_id' => $family_id, 'is_primary' => 1],
    ], $uid_b);
    Relatives_Kinship::reset();
    $member_edges = Relatives_Database::get_relations_for_person($kin['fam_member']);
    check('plain family membership is stored without a type', [1, null], [count($member_edges), $member_edges[0]['relationship_type_id'] ?? null]);
    check('a plain member belongs to the family', true, Relatives_Graph::shares_family($kin['fam_member'], $node_a));
    $member_tree = Relatives_TreeBuilder::build($uid_a, $uid_a);
    $member_node_found = false;
    foreach ($member_tree['nodes'] as $kin_node) {
        if ($kin_node['key'] === 'person:' . $kin['fam_member']) {
            $member_node_found = true;
        }
    }
    check('a plain member is reached through the family box', true, $member_node_found);
    $family_tree = Relatives_TreeBuilder::build($uid_a, $uid_a);
    $family_node = null;
    foreach ($family_tree['nodes'] as $kin_node) {
        if ($kin_node['key'] === 'family:' . $family_id) {
            $family_node = $kin_node;
        }
    }
    check('family box becomes a tree node', 'family', $family_node['type'] ?? null);

    // Familie freut sich nicht ueber neue Mitglieder: gespielte Charaktere kommen nicht
    // mehr dazu, NPCs schon (die koennen spaeter bespielt werden).
    check('families welcome new members by default', true, Relatives_Families::welcomes_new_members(Relatives_Families::get_by_id($family_id)));
    Relatives_Families::update($family_id, ['welcomes_new_members' => 0]);
    check('welcomes_new_members can be switched off', false, Relatives_Families::welcomes_new_members(Relatives_Families::get_by_id($family_id)));
    $node_b = Relatives_Graph::account_person_id($uid_b);
    $join_family = [['relationship_type_id' => null, 'related_to_family_id' => $family_id]];
    check('a new NPC may still join a closed family', [], Relatives_Graph::closed_family_violations(0, $join_family, true));
    $closed_violations = Relatives_Graph::closed_family_violations($node_b, $join_family, false);
    check('a played character may not join a closed family', [$family_id, $node_b], [
        (int)($closed_violations[0]['family']['id'] ?? 0),
        (int)($closed_violations[0]['person']['id'] ?? 0),
    ]);
    $closed_violations = Relatives_Graph::closed_family_violations(0, [
        ['relationship_type_id' => $kin_type('sibling', $kin_m), 'related_to_person_id' => $kin['sib_a']],
        ['relationship_type_id' => $kin_type('parent', $kin_m), 'related_to_person_id' => $node_b],
    ], true);
    check('a character joins a closed family through a new NPC parent', $node_b, (int)($closed_violations[0]['person']['id'] ?? 0));
    check('an existing member is not a new member', [], Relatives_Graph::closed_family_violations($node_a, $join_family, false));

    // Familien-Attribute: Vorauswahl und Freitext.
    $blood_group = Relatives_Attributes::create_group(['label' => $marker . ' Blutstatus', 'applies_to' => 'family', 'field_type' => 'select']);
    $blood_pure = Relatives_Attributes::create_option(['group_id' => $blood_group, 'label' => 'Reinblütig']);
    $motto_group = Relatives_Attributes::create_group(['label' => $marker . ' Motto', 'applies_to' => 'family', 'field_type' => 'text']);
    $person_text_group = Relatives_Attributes::create_group(['label' => $marker . ' Person text', 'applies_to' => 'person', 'field_type' => 'text']);
    check('person groups are always a preset', 'select', (string)Relatives_Attributes::get_group($person_text_group)['field_type']);
    $both_group = Relatives_Attributes::create_group(['label' => $marker . ' Both', 'applies_to' => 'both', 'field_type' => 'text']);
    check('groups for persons and families are always a preset', ['both', 'select'], [
        (string)Relatives_Attributes::get_group($both_group)['applies_to'],
        (string)Relatives_Attributes::get_group($both_group)['field_type'],
    ]);
    $group_ids_for = static function (string $applies_to): array {
        return array_map(static function ($group) {
            return (int)$group['id'];
        }, Relatives_Attributes::get_groups(true, $applies_to));
    };
    $family_group_ids = $group_ids_for('family');
    $person_group_ids = $group_ids_for('person');
    check('family groups are listed separately from person groups', [true, false, false], [
        in_array($blood_group, $family_group_ids, true),
        in_array($person_text_group, $family_group_ids, true),
        in_array($blood_group, $person_group_ids, true),
    ]);
    check('a group for both appears for persons and families', [true, true], [
        in_array($both_group, $family_group_ids, true),
        in_array($both_group, $person_group_ids, true),
    ]);
    Relatives_Attributes::set_family_attributes($family_id, [
        $blood_group => (string)$blood_pure,
        $motto_group => '  Toujours pur  ',
        $person_text_group => 'ignored',
    ]);
    $family_values = Relatives_Attributes::get_family_values($family_id);
    check('family attribute values are stored', [$blood_pure, 'Toujours pur', false], [
        $family_values[$blood_group]['option_id'] ?? 0,
        $family_values[$motto_group]['value'] ?? '',
        isset($family_values[$person_text_group]),
    ]);
    check('filter lookup returns only preset values', [$blood_group => $blood_pure], Relatives_Attributes::get_family_option_ids_bulk([$family_id])[$family_id] ?? []);
    $family_display = Relatives_Attributes::get_family_attributes_bulk([$family_id])[$family_id] ?? [];
    check('family attributes are displayed with their labels', ['Reinblütig', 'Toujours pur'], array_column($family_display, 'value'));
    Relatives_Attributes::set_family_attributes($family_id, [$blood_group => '999999999']);
    check('a foreign option id is rejected', [], Relatives_Attributes::get_family_values($family_id));
    Relatives_Attributes::set_family_attributes($family_id, [$blood_group => (string)$blood_pure]);

    Relatives_Families::delete($family_id);
    check('deleting a family removes its attribute values', [], Relatives_Attributes::get_family_values($family_id));
    foreach ([$blood_group, $motto_group, $person_text_group, $both_group] as $attribute_group_id) {
        Relatives_Attributes::delete_group($attribute_group_id);
    }
    check('deleting a family removes its edges', 1, count(Relatives_Database::get_relations_for_person($kin['sib_a'])));

    // Zwei Eintraege derselben Person zusammenfuehren
    $duplicate_person = Relatives_Database::create_person(['name' => $marker . ' kin louisa duplicate', 'gender' => 1]);
    Relatives_Database::add_owner($duplicate_person, $uid_b);
    // "Kind von Morgan" hat Louisa schon (geheim) -> wird verworfen; die Partnerschaft
    // mit Ethan ist neu -> wird umgehaengt.
    Relatives_Database::replace_relations($duplicate_person, [
        ['relationship_type_id' => $kin_type('child', $kin_f), 'related_to_person_id' => $kin['morgan']],
        ['relationship_type_id' => $kin_type('partner', $kin_f), 'related_to_person_id' => $kin['ethan']],
    ], $uid_b);
    $merge_report = Relatives_Graph::merge_person($duplicate_person, $kin['louisa']);
    check('merged duplicate is gone', null, Relatives_Database::get_person($duplicate_person));
    check('duplicate statement is dropped, new one moved', [1, 1], [$merge_report['duplicates_removed'], $merge_report['relations_moved']]);
    check('surviving person has its own edges plus the new one', 4, count(Relatives_Database::get_relations_for_person($kin['louisa'])));
    $morgan_edges = array_values(array_filter(Relatives_Database::get_relations_for_person($kin['louisa']), static function ($row) use ($kin) {
        return (int)$row['related_to_person_id'] === $kin['morgan'];
    }));
    check('the surviving person\'s own (secret) statement wins', [1, 1], [count($morgan_edges), (int)($morgan_edges[0]['is_secret'] ?? 0)]);

    // Aus der Liste entfernen laesst das Geflecht intakt
    check('removing a connected person from a list keeps it (returns false)', false, Relatives_Database::remove_owner($kin['oliver'], $uid_a));
    check('... together with its connections', true, Relatives_Database::get_person($kin['oliver']) !== null
        && count(Relatives_Database::get_relations_touching($kin['oliver'])) > 0);

    // Alt-Migration: dieselbe Person in zwei Listen behaelt beide Aussagen (Caiomhe ist bei
    // Kayley Mutter, bei Ciaran Tante) -- keine Liste ueberschreibt die andere.
    $kin['list_person'] = Relatives_Database::create_person(['name' => $marker . ' kin list person', 'gender' => 1]);
    Relatives_Database::add_owner($kin['list_person'], $uid_a);
    Relatives_Database::add_list_relation($kin['list_person'], $uid_a, ['relationship_type_id' => $kin_type('parent', $kin_f)]);
    Relatives_Database::add_owner($kin['list_person'], $uid_b);
    Relatives_Database::add_list_relation($kin['list_person'], $uid_b, ['relationship_type_id' => $kin_type('aunt_uncle', $kin_f)]);
    $list_edges = [];
    foreach (Relatives_Database::get_relations_for_person($kin['list_person']) as $row) {
        $list_edges[(int)$row['owner_uid']] = (int)$row['relationship_type_id'];
    }
    check('each list keeps its own statement', [$uid_a => $kin_type('parent', $kin_f), $uid_b => $kin_type('aunt_uncle', $kin_f)], $list_edges);
    Relatives_Database::update_list_relation($kin['list_person'], $uid_b, ['related_to_person_id' => $kin['delia']]);
    $anchors = [];
    foreach (Relatives_Database::get_relations_for_person($kin['list_person']) as $row) {
        $anchors[(int)$row['owner_uid']] = $row['related_to_person_id'] !== null ? (int)$row['related_to_person_id'] : null;
    }
    check('update_list_relation() only touches that list', [$uid_a => null, $uid_b => $kin['delia']], $anchors);

    foreach ($kin as $kin_person_id) {
        Relatives_Database::delete_person($kin_person_id);
    }
} else {
    echo "  skip v2.2 section (needs two users and the v2.2 tables -- deactivate/activate the plugin once)\n";
}

echo "\n=== datahandler_user_delete_start (relatives_handle_user_delete) ===\n";

// Geister-uids oberhalb der hoechsten echten uid: der Handler loescht so nie Daten
// echter Testforum-Accounts. Kein Insert in users -- der Handler liest nur delete_uids.
require_once MYBB_ROOT . 'inc/plugins/relatives.php';
$max_uid = (int)$db->fetch_field($db->simple_select('users', 'MAX(uid) AS max_uid'), 'max_uid');
$ghost_a = $max_uid + 1000000;
$ghost_b = $ghost_a + 1;
$ghost_keep = $ghost_a + 2;

$del_solo_a = Relatives_Database::create_person(['name' => $marker . ' del solo A', 'gender' => 0]);
Relatives_Database::add_owner($del_solo_a, $ghost_a);
$del_solo_b = Relatives_Database::create_person(['name' => $marker . ' del solo B', 'gender' => 1]);
Relatives_Database::add_owner($del_solo_b, $ghost_b);
$del_shared = Relatives_Database::create_person(['name' => $marker . ' del shared', 'gender' => 1]);
Relatives_Database::add_owner($del_shared, $ghost_a);
Relatives_Database::add_owner($del_shared, $ghost_keep);
$del_linked = Relatives_Database::create_person([
    'name' => $marker . ' del linked', 'gender' => 0,
    'linked_uid' => $ghost_b, 'link_status' => Relatives_LinkManager::STATUS_CONFIRMED,
    'link_requested_by' => $ghost_keep, 'link_confirmed_at' => TIME_NOW,
]);
Relatives_Database::add_owner($del_linked, $ghost_keep);

// Altes (fehlerhaftes) Objektformat: MyBB setzt kein $user -> darf nichts anfassen.
$legacy_handler = (object)['user' => ['uid' => $ghost_a]];
relatives_handle_user_delete($legacy_handler);
check('handler ignores an object without delete_uids', true, Relatives_Database::is_owner($del_solo_a, $ghost_a));

// Bulk-Loeschung wie im ACP: mehrere uids, ungueltige Eintraege werden uebersprungen.
$bulk_handler = (object)['delete_uids' => [$ghost_a, 0, -5, (string)$ghost_b]];
relatives_handle_user_delete($bulk_handler);
check('sole-owner person of deleted user A is removed', null, Relatives_Database::get_person($del_solo_a));
check('sole-owner person of deleted user B is removed (bulk)', null, Relatives_Database::get_person($del_solo_b));
check('shared person survives with the remaining owner', [true, false, 1], [
    Relatives_Database::get_person($del_shared) !== null,
    Relatives_Database::is_owner($del_shared, $ghost_a),
    Relatives_Database::count_owners($del_shared),
]);
check('deleted user A owns no persons anymore', [], Relatives_Database::get_persons_by_owner($ghost_a));
// array_key_exists statt ??: die erwarteten Werte sind gerade NULL.
$linked_after = Relatives_Database::get_person($del_linked) ?? [];
$link_field = static function (string $key) use ($linked_after) {
    return array_key_exists($key, $linked_after) ? $linked_after[$key] : 'missing';
};
check('linked_uid reference to deleted user B is reset', [null, Relatives_LinkManager::STATUS_NONE, null, null], [
    $link_field('linked_uid'),
    (int)$link_field('link_status'),
    $link_field('link_requested_by'),
    $link_field('link_confirmed_at'),
]);
check('no person references deleted user B anymore', [], Relatives_Database::get_persons_by_linked_uid($ghost_b));

// Komma-String-Form (defensiv) -- raeumt zugleich die restlichen Fixtures ab.
$string_handler = (object)['delete_uids' => (string)$ghost_keep];
relatives_handle_user_delete($string_handler);
check('comma-string delete_uids is accepted', [], Relatives_Database::get_persons_by_owner($ghost_keep));
foreach ([$del_shared, $del_linked] as $del_person_id) {
    if (Relatives_Database::get_person($del_person_id) !== null) {
        Relatives_Database::delete_person($del_person_id);
    }
}

// Cleanup Fixtures
if ($person_id > 0) {
    // Die Fixture-Person ist inzwischen verbunden und ein bestaetigter Charakter-Knoten --
    // "aus der Liste entfernen" loescht sie deshalb bewusst NICHT mehr, delete_person() schon.
    check('remove_owner() keeps a connected person in the shared network', false, Relatives_Database::remove_owner($person_id, $uid_a));
    check('delete_person() removes it completely', true, Relatives_Database::delete_person($person_id));
    check('person is gone after delete_person', null, Relatives_Database::get_person($person_id));
    if ($related_person_id > 0) {
        $related = Relatives_Database::get_person_for_owner($related_person_id, $uid_a);
        // array_key_exists statt ??: der erwartete Wert ist gerade NULL.
        check(
            'related_to_person_id cleared when the referenced person is removed',
            true,
            $related !== null && array_key_exists('related_to_person_id', $related) && $related['related_to_person_id'] === null
        );
    }
}
if ($related_person_id > 0) {
    Relatives_Database::delete_person($related_person_id);
}

// Charakter-Knoten, die nur fuer den Test entstanden sind, wieder entfernen; eine durch den
// Test mit einem Account verknuepfte echte Person bekommt ihre Verknuepfung zurueck.
foreach ($preexisting_nodes as $fixture_uid => $node_before) {
    if ($node_before > 0) {
        continue;
    }
    foreach (Relatives_Database::get_persons_by_linked_uid((int)$fixture_uid) as $node_row) {
        if ((int)$node_row['created_at'] >= $selftest_started) {
            Relatives_Database::delete_person((int)$node_row['id']);
        } elseif ((int)$node_row['link_status'] === 2) {
            Relatives_Database::update_person((int)$node_row['id'], [
                'linked_uid' => null, 'link_status' => 0, 'link_requested_by' => null, 'link_confirmed_at' => null,
            ]);
        }
    }
}

Relatives_RelationshipTypes::delete($type_male_id);
Relatives_RelationshipTypes::delete($type_female_id);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
