<?php
/**
 * CLI-Selbsttest fuer "relatives". Prueft Business-Logik + DB-Roundtrip:
 * Relationship-Type-Gender-Filter, Owner-Checks, Link-Workflow-Zustandsmaschine,
 * relatives_search_persons()-Filterkombinationen, Migrations-Mapping-Woerterbuch.
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
require_once MYBB_ROOT . 'inc/plugins/relatives/core/TreeBuilder.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/LinkManager.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/SearchApi.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Migration.php';

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

if (!$db->table_exists('relatives_persons')) {
    echo "relatives_persons table missing -- run 'php tests/cli-plugin.php install' first.\n";
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
    echo "Need at least 2 existing users on this forum for the link-workflow test -- skipping that section.\n";
}
$uid_a = $existing_users[0] ?? 0;
$uid_b = $existing_users[1] ?? 0;

echo "=== Relationship-Type gender filter (Schritt 4) ===\n";

$type_male_id = Relatives_RelationshipTypes::create([
    'concept_key' => 'selftest_parent', 'gender' => 0, 'label' => $marker . ' Vater',
    'generation_offset' => -1, 'sort_order' => 0, 'is_active' => 1,
]);
$type_female_id = Relatives_RelationshipTypes::create([
    'concept_key' => 'selftest_parent', 'gender' => 1, 'label' => $marker . ' Mutter',
    'generation_offset' => -1, 'sort_order' => 0, 'is_active' => 1,
]);

$for_male_person = Relatives_RelationshipTypes::get_for_person_gender(0);
$found_male = false;
foreach ($for_male_person as $row) {
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

echo "\n=== Personen-CRUD + Owner-Check (Schritt 5) ===\n";

if ($uid_a > 0) {
    $person_id = Relatives_Database::create_person([
        'owner_uid' => $uid_a,
        'name'      => $marker . ' Person A',
        'gender'    => -1,
        'relationship_custom_label' => 'Testverbindung',
        'is_wanted' => 0,
    ]);
    check('create_person returns positive id', true, $person_id > 0);
    check('is_owner(person, owner) == true', true, Relatives_Database::is_owner($person_id, $uid_a));
    check('is_owner(person, other_uid) == false', false, Relatives_Database::is_owner($person_id, $uid_a + 999999));

    $loaded = Relatives_Database::get_person($person_id);
    check('get_person returns correct name', $marker . ' Person A', $loaded['name'] ?? null);
} else {
    $person_id = 0;
    echo "  skip (no user fixture available)\n";
}

echo "\n=== Link-Workflow-Zustandsmaschine (Schritt 6) ===\n";

if ($uid_a > 0 && $uid_b > 0 && $person_id > 0) {
    check('link_status starts at 0 (none)', 0, (int)Relatives_Database::get_person($person_id)['link_status']);

    Relatives_LinkManager::request_link($person_id, $uid_a, $uid_b);
    $after_request = Relatives_Database::get_person($person_id);
    check('link_status == 1 (requested) after request_link', 1, (int)$after_request['link_status']);
    check('linked_uid set to target', $uid_b, (int)$after_request['linked_uid']);

    // Falscher Account darf nicht annehmen (nur linked_uid selbst).
    $wrong_uid = $uid_a; // requester != linked_uid
    check('accept_link by wrong uid fails', false, Relatives_LinkManager::accept_link($person_id, $wrong_uid));

    check('accept_link by linked_uid succeeds', true, Relatives_LinkManager::accept_link($person_id, $uid_b));
    $after_accept = Relatives_Database::get_person($person_id);
    check('link_status == 2 (confirmed) after accept', 2, (int)$after_accept['link_status']);
    check('is_wanted forced to 0 after confirmed link', 0, (int)$after_accept['is_wanted']);

    // Zweite Person fuer den Decline-Pfad.
    $person_id_2 = Relatives_Database::create_person([
        'owner_uid' => $uid_a, 'name' => $marker . ' Person B', 'gender' => -1,
        'relationship_custom_label' => 'Test', 'is_wanted' => 0,
    ]);
    Relatives_LinkManager::request_link($person_id_2, $uid_a, $uid_b);
    check('decline_link by linked_uid succeeds', true, Relatives_LinkManager::decline_link($person_id_2, $uid_b));
    $after_decline = Relatives_Database::get_person($person_id_2);
    check('link_status reset to 0 after decline', 0, (int)$after_decline['link_status']);
    check('linked_uid reset to null after decline', null, $after_decline['linked_uid']);

    Relatives_Database::delete_person($person_id_2);
} else {
    echo "  skip (need 2 distinct user fixtures)\n";
}

echo "\n=== TreeBuilder (Schritt 6) ===\n";

if ($uid_a > 0 && $person_id > 0) {
    $tree = Relatives_TreeBuilder::build($uid_a);
    $has_self = false;
    $has_person = false;
    foreach ($tree['nodes'] as $node) {
        if ($node['type'] === 'self' && (int)$node['uid'] === $uid_a) { $has_self = true; }
        if ($node['type'] === 'person' && (int)$node['id'] === $person_id) { $has_person = true; }
    }
    check('tree contains self node for root uid', true, $has_self);
    check('tree contains the created person node', true, $has_person);
} else {
    echo "  skip (no fixture)\n";
}

echo "\n=== relatives_search_persons() Filterkombinationen (Schritt 8) ===\n";

if ($uid_a > 0) {
    $search_person_id = Relatives_Database::create_person([
        'owner_uid' => $uid_a, 'name' => $marker . ' Wanted Search', 'gender' => 1,
        'relationship_custom_label' => 'Test', 'is_wanted' => 1, 'age' => 25,
    ]);

    $results = Relatives_SearchApi::search(['query' => $marker . ' Wanted', 'is_wanted' => true]);
    $found = false;
    foreach ($results as $row) {
        if ((int)$row['id'] === $search_person_id) { $found = true; }
    }
    check('search by query + is_wanted finds fixture', true, $found);

    $results_wrong_gender = Relatives_SearchApi::search(['query' => $marker . ' Wanted', 'gender' => 0, 'is_wanted' => true]);
    $found_wrong = false;
    foreach ($results_wrong_gender as $row) {
        if ((int)$row['id'] === $search_person_id) { $found_wrong = true; }
    }
    check('search with mismatched gender filter excludes fixture', false, $found_wrong);

    $results_age = Relatives_SearchApi::search(['query' => $marker . ' Wanted', 'age_min' => 20, 'age_max' => 30, 'is_wanted' => true]);
    $found_age = false;
    foreach ($results_age as $row) {
        if ((int)$row['id'] === $search_person_id) { $found_age = true; }
    }
    check('search with matching age range finds fixture', true, $found_age);

    Relatives_Database::delete_person($search_person_id);
} else {
    echo "  skip (no fixture)\n";
}

echo "\n=== Migrations-Mapping-Woerterbuch (Schritt 11) ===\n";

// Realdaten aus mybb_relatives_options.sql (siehe "Alt und zu ueberarbeiten/Verwandte").
$known_pairs = [
    'Mutter' => 'Vater',
    'Bruder' => 'Schwester',
    'Sohn' => 'Tochter',
    'Ehemann' => 'Ehefrau',
    'Ex-Mann' => 'Ex-Frau',
    'Cousin' => 'Cousine',
    'Onkel mütterlicherseits' => 'Tante mütterlicherseits',
    'Großvater väterlicherseits' => 'Großmutter väterlicherseits',
];

$map_property = Relatives_Migration::RELATIONSHIP_MAP;

$all_pairs_mapped = true;
foreach ($known_pairs as $male_label => $female_label) {
    if (!isset($map_property[$male_label]) || !isset($map_property[$female_label])) {
        $all_pairs_mapped = false;
        echo "    missing mapping for pair: {$male_label} / {$female_label}\n";
        continue;
    }
    if ($map_property[$male_label][0] !== $map_property[$female_label][0]) {
        $all_pairs_mapped = false;
        echo "    concept_key mismatch for pair: {$male_label} / {$female_label}\n";
    }
}
check('all known legacy pairs share the same concept_key', true, $all_pairs_mapped);
check("'NPC' maps to neutral gender", 2, $map_property['NPC'][1] ?? null);

// Cleanup Fixtures
if ($person_id > 0) {
    Relatives_Database::delete_person($person_id);
}
Relatives_RelationshipTypes::delete($type_male_id);
Relatives_RelationshipTypes::delete($type_female_id);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
