<?php
/**
 * CLI-Testlauf der Legacy-Migration fuer "relatives". Macht die Migration beliebig oft
 * wiederholbar: Tabellen des neuen Plugins leeren, Migration (+ optional Zusammenfuehren
 * zum Geflecht) frisch ausfuehren, Ergebnis als diff-bare Textausgabe pruefen.
 *
 * NUR FUER DAS TESTFORUM. reset/run leeren alle Datentabellen des neuen Plugins.
 * Schreibende Befehle laufen nur, wenn in inc/config.php freigeschaltet:
 *     $config['relatives_allow_migration_reset'] = 1;
 * und nur mit --yes. Ohne Alt-Tabellen verweigert reset (sonst waere nichts zum
 * Wiederaufbauen da).
 *
 * Aufruf (auf dem Server, im Forum-Root):
 *   php tests/cli-migration.php status                 Zeilenzahlen alt/neu, Settings
 *   php tests/cli-migration.php preview                Grad-Mapping + Kennzahlen (read-only)
 *   php tests/cli-migration.php backup [--out=file]    SQL-Sicherung der neuen Tabellen
 *   php tests/cli-migration.php reset --yes            Sichern + neue Datentabellen leeren
 *   php tests/cli-migration.php run --yes [--merge]    backup + activate + reset + Migration (+ Geflecht)
 *   php tests/cli-migration.php merge --yes            Nur Geflecht zusammenfuehren
 *   php tests/cli-migration.php verify                 Soll/Ist-Abgleich + lose Verbindungen
 * Option --keep-attributes: Attribut-Gruppen/-Optionen beim reset behalten.
 *
 * Ausgabe sichern und Laeufe vergleichen:
 *   php tests/cli-migration.php run --yes --merge > run1.txt
 *   php tests/cli-migration.php verify >> run1.txt
 *   diff run1.txt run2.txt
 *
 * Frische Altdaten aus dem Live-Forum holen (Prefix/DB-Namen anpassen; die users-Tabelle
 * gehoert dazu, sonst werden Zuordnungen zu "fehlenden" Accounts verworfen):
 *   mysqldump -u <user> -p <live_db> mybb_relatives mybb_relatives_status \
 *       mybb_relatives_options mybb_wanted mybb_wanted_status mybb_users > legacy.sql
 *   mysql -u <user> -p <test_db> < legacy.sql
 * Vorher ein Backup der Test-DB ziehen.
 */

if (PHP_SAPI !== 'cli') {
    die('CLI only.');
}
define('IN_MYBB', 1);
chdir(dirname(__DIR__));
require_once './inc/init.php';

if (!defined('MYBB_ADMIN_DIR')) {
    $admin_dir = isset($config['admin_dir']) ? $config['admin_dir'] : 'admin';
    define('MYBB_ADMIN_DIR', MYBB_ROOT . $admin_dir . '/');
}
require_once MYBB_ROOT . 'inc/plugins/relatives.php';

if (!isset($mybb->user) || !is_array($mybb->user)) {
    $mybb->user = [];
}
$mybb->user += ['uid' => 0, 'timezone' => $mybb->settings['timezoneoffset'] ?? 0, 'dst' => 0, 'dateformat' => '', 'timeformat' => ''];

/** Datentabellen, die reset leert. Verwandtschaftsgrade bleiben (ACP-Konfiguration). */
const RELATIVES_CLI_DATA_TABLES = [
    'relatives_persons',
    'relatives_person_owners',
    'relatives_relations',
    'relatives_relation_conflicts',
    'relatives_families',
    'relatives_family_attributes',
    'relatives_person_attributes',
];
const RELATIVES_CLI_ATTRIBUTE_TABLES = ['relatives_attribute_options', 'relatives_attribute_groups'];
const RELATIVES_CLI_LEGACY_TABLES = ['relatives', 'relatives_status', 'relatives_options', 'wanted', 'wanted_status'];

/**
 * Grade, die StructureInference an eine Bezugsperson zu haengen versucht. Steht so eine
 * Kante noch direkt am Charakter, konnte die Ableitung sie nicht eindeutig zuordnen.
 */
const RELATIVES_CLI_INDIRECT_CONCEPTS = [
    'grandparent', 'grandchild', 'great_grandparent', 'great_grandchild',
    'aunt_uncle', 'niece_nephew', 'cousin',
    'sibling_in_law', 'parent_in_law', 'child_in_law',
];

function relatives_cli_line(string $text = ''): void
{
    echo $text . "\n";
}

function relatives_cli_heading(string $text): void
{
    relatives_cli_line();
    relatives_cli_line('== ' . $text . ' ==');
}

function relatives_cli_count(string $table): ?int
{
    global $db;
    if (!$db->table_exists($table)) {
        return null;
    }
    return (int)$db->fetch_field($db->simple_select($table, 'COUNT(*) AS c'), 'c');
}

function relatives_cli_require_write_access(array $flags): void
{
    global $config;
    if (empty($config['relatives_allow_migration_reset'])) {
        relatives_cli_line("Refused: set \$config['relatives_allow_migration_reset'] = 1; in inc/config.php (test forum only).");
        exit(1);
    }
    if (!in_array('--yes', $flags, true)) {
        relatives_cli_line('Refused: this command deletes data. Add --yes to confirm.');
        exit(1);
    }
}

function relatives_cli_set_setting(string $name, string $value): void
{
    global $db, $mybb;
    $db->update_query('settings', ['value' => $db->escape_string($value)], "name = '" . $db->escape_string($name) . "'");
    $mybb->settings[$name] = $value;
}

function relatives_cli_username(int $uid): string
{
    static $names = [];
    if (!isset($names[$uid])) {
        $user = get_user($uid);
        $names[$uid] = $user ? (string)$user['username'] : '#' . $uid . ' (deleted)';
    }
    return $names[$uid];
}

// ------------------------------------------------------------------
// Befehle
// ------------------------------------------------------------------

function relatives_cli_status(): void
{
    global $mybb;

    relatives_cli_heading('Legacy tables');
    foreach (RELATIVES_CLI_LEGACY_TABLES as $table) {
        $count = relatives_cli_count($table);
        relatives_cli_line(sprintf('  %-32s %s', $table, $count === null ? 'MISSING' : (string)$count));
    }
    relatives_cli_line(sprintf('  %-32s %d', 'users', (int)relatives_cli_count('users')));

    relatives_cli_heading('New tables');
    foreach (array_merge(RELATIVES_CLI_DATA_TABLES, RELATIVES_CLI_ATTRIBUTE_TABLES, ['relatives_relationship_types']) as $table) {
        $count = relatives_cli_count($table);
        relatives_cli_line(sprintf('  %-32s %s', $table, $count === null ? 'MISSING' : (string)$count));
    }

    relatives_cli_heading('Settings');
    foreach (['relatives_legacy_migrated', 'relatives_graph_merged'] as $name) {
        $value = isset($mybb->settings[$name]) ? (int)$mybb->settings[$name] : 0;
        relatives_cli_line(sprintf('  %-32s %s', $name, $value > 0 ? date('Y-m-d H:i:s', $value) : 'no'));
    }
}

function relatives_cli_preview(): bool
{
    $preview = Relatives_Migration::preview();

    relatives_cli_heading('Legacy statistics');
    foreach ($preview['stats'] as $key => $value) {
        relatives_cli_line(sprintf('  %-28s %d', $key, $value));
    }

    relatives_cli_heading('Status mapping (legacy id | uses | label -> target)');
    foreach ($preview['statuses'] as $status) {
        if (!$status['mapped']) {
            $target = 'UNMAPPED';
        } elseif ($status['concept'] === null) {
            $target = '(no relationship type)';
        } else {
            $target = $status['concept'] . ' / ' . $status['target_label'];
        }
        relatives_cli_line(sprintf('  %4d | %4d | %s -> %s', $status['legacy_id'], $status['links'], $status['label'], $target));
    }

    if (!empty($preview['unmapped'])) {
        relatives_cli_heading('BLOCKING: used but unmapped statuses (extend STATUS_BASE_MAP)');
        foreach ($preview['unmapped'] as $label) {
            relatives_cli_line('  ' . $label);
        }
        return false;
    }
    return true;
}

/**
 * SQL-Sicherung aller Tabellen des neuen Plugins ueber die MyBB-DB-Verbindung (keine
 * Zugangsdaten noetig). Standardziel liegt eine Ebene ueber dem Forum-Root, damit die
 * Datei nicht per Web erreichbar ist.
 */
function relatives_cli_backup(array $flags): string
{
    global $db;

    $file = dirname(rtrim(MYBB_ROOT, '/\\')) . '/relatives-backup-' . date('Ymd-His') . '.sql';
    foreach ($flags as $flag) {
        if (strpos($flag, '--out=') === 0) {
            $file = substr($flag, 6);
        }
    }

    $handle = fopen($file, 'wb');
    if ($handle === false) {
        relatives_cli_line('Backup failed: cannot write ' . $file);
        exit(1);
    }
    fwrite($handle, "SET NAMES utf8;\nSET FOREIGN_KEY_CHECKS = 0;\n");

    relatives_cli_heading('Backup');
    $tables = array_merge(RELATIVES_CLI_DATA_TABLES, RELATIVES_CLI_ATTRIBUTE_TABLES, ['relatives_relationship_types']);
    foreach ($tables as $table) {
        if (!$db->table_exists($table)) {
            continue;
        }
        $full = TABLE_PREFIX . $table;
        $create = $db->fetch_array($db->write_query('SHOW CREATE TABLE `' . $full . '`'));
        fwrite($handle, "\nDROP TABLE IF EXISTS `{$full}`;\n" . $create['Create Table'] . ";\n");

        $rows = 0;
        $query = $db->write_query('SELECT * FROM `' . $full . '`');
        while ($row = $db->fetch_array($query)) {
            $values = [];
            foreach ($row as $value) {
                $values[] = $value === null ? 'NULL' : "'" . $db->escape_string((string)$value) . "'";
            }
            fwrite($handle, 'INSERT INTO `' . $full . '` (`' . implode('`, `', array_keys($row)) . '`) VALUES (' . implode(', ', $values) . ");\n");
            $rows++;
        }
        relatives_cli_line(sprintf('  %-32s %d rows', $table, $rows));
    }

    // Settings, die reset/run veraendern -- zum Zuruecksetzen per Hand.
    foreach (['relatives_legacy_migrated', 'relatives_graph_merged'] as $name) {
        $value = (string)$db->fetch_field($db->simple_select('settings', 'value', "name = '" . $db->escape_string($name) . "'"), 'value');
        fwrite($handle, "UPDATE `" . TABLE_PREFIX . "settings` SET `value` = '" . $db->escape_string($value) . "' WHERE `name` = '{$name}';\n");
    }
    fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($handle);
    @chmod($file, 0600);

    relatives_cli_line('  written to ' . $file . ' (settings need a rebuild after restore: ACP -> Settings, save once)');
    return $file;
}

function relatives_cli_reset(bool $keep_attributes): void
{
    global $db;

    $available = Relatives_Migration::legacy_tables_available();
    if (!($available['relatives'] && $available['relatives_status']) && !($available['wanted'] && $available['wanted_status'])) {
        relatives_cli_line('Refused: no legacy tables found -- nothing to rebuild the data from.');
        exit(1);
    }

    $tables = RELATIVES_CLI_DATA_TABLES;
    if (!$keep_attributes) {
        $tables = array_merge($tables, RELATIVES_CLI_ATTRIBUTE_TABLES);
    }

    relatives_cli_heading('Reset');
    foreach ($tables as $table) {
        if (!$db->table_exists($table)) {
            relatives_cli_line('  ' . $table . ': MISSING (skipped)');
            continue;
        }
        $db->write_query('TRUNCATE TABLE ' . TABLE_PREFIX . $table);
        relatives_cli_line('  ' . $table . ': emptied');
    }

    relatives_cli_set_setting('relatives_legacy_migrated', '0');
    relatives_cli_set_setting('relatives_graph_merged', '0');
    rebuild_settings();
    Relatives_Kinship::reset();
    relatives_cli_line('  settings relatives_legacy_migrated / relatives_graph_merged: reset');
}

function relatives_cli_run(): bool
{
    $report = Relatives_Migration::run(true);
    Relatives_Kinship::reset();

    relatives_cli_heading('Migration report');
    if (!$report['ran']) {
        relatives_cli_line('  NOT RUN: ' . $report['reason']);
        foreach ($report['unmapped_statuses'] as $label) {
            relatives_cli_line('    unmapped: ' . $label);
        }
        return false;
    }
    foreach ($report as $key => $value) {
        if (in_array($key, ['ran', 'reason'], true)) {
            continue;
        }
        relatives_cli_line(sprintf('  %-30s %s', $key, is_array($value) ? implode(', ', $value) : (string)$value));
    }
    return true;
}

function relatives_cli_merge(): void
{
    $report = Relatives_Graph::merge();
    Relatives_Kinship::reset();

    relatives_cli_heading('Graph merge report');
    foreach ($report as $key => $value) {
        relatives_cli_line(sprintf('  %-30s %s', $key, is_array($value) ? implode(', ', $value) : (string)$value));
    }
}

function relatives_cli_verify(): void
{
    global $db;

    $merged = Relatives_Graph::is_merged();
    $stats = Relatives_Migration::preview()['stats'];

    relatives_cli_heading('Expected vs. actual' . ($merged ? ' (after merge: persons may be fewer, owner links unchanged)' : ''));
    $persons = [];
    $query = $db->query("SELECT legacy_source, COUNT(*) AS c FROM " . TABLE_PREFIX . "relatives_persons GROUP BY legacy_source");
    while ($row = $db->fetch_array($query)) {
        $persons[(string)$row['legacy_source']] = (int)$row['c'];
    }
    $migrated_persons = ($persons['relatives'] ?? 0) + ($persons['wanted'] ?? 0);
    $owner_links = (int)$db->fetch_field($db->simple_select('relatives_person_owners', 'COUNT(*) AS c', 'legacy_id IS NOT NULL'), 'c');

    $rows = [
        ['persons with owner -> persons', $stats['persons_with_owner'], $migrated_persons, !$merged],
        // Nach dem Zusammenfuehren fallen Zuordnungen weg, wenn ein Charakter zwei
        // gleichnamige Personen hatte (UNIQUE person/owner).
        ['valid links -> owner links', $stats['links_valid'], $owner_links, !$merged],
    ];
    foreach ($rows as [$label, $expected, $actual, $strict]) {
        $mark = $expected === $actual ? 'OK' : ($strict ? 'MISMATCH' : 'differs');
        relatives_cli_line(sprintf('  %-32s expected %5d  actual %5d  %s', $label, $expected, $actual, $mark));
    }
    $account_nodes_new = (int)$db->fetch_field($db->simple_select('relatives_persons', 'COUNT(*) AS c', 'legacy_source IS NULL AND link_status = 2'), 'c');
    relatives_cli_line(sprintf('  %-32s %d', 'character nodes (not migrated)', $account_nodes_new));
    relatives_cli_line(sprintf('  %-32s %d', 'other non-migrated persons', array_sum($persons) - $migrated_persons - $account_nodes_new));

    // Kanten nach Ziel: am Charakter selbst (NULL bzw. Account-Knoten), an Person, an Familie.
    relatives_cli_heading('Relations');
    $account_nodes = [];
    $query = $db->simple_select('relatives_persons', 'id, linked_uid', 'link_status = 2 AND linked_uid IS NOT NULL');
    while ($row = $db->fetch_array($query)) {
        $account_nodes[(int)$row['id']] = (int)$row['linked_uid'];
    }

    $counts = ['to character' => 0, 'to other person' => 0, 'to family' => 0, 'no relationship' => 0];
    $loose = [];
    $query = $db->query("SELECT r.person_id, r.related_to_person_id, r.related_to_family_id, r.owner_uid,
            r.relationship_type_id, r.relationship_custom_label, t.concept_key, t.label, p.name
        FROM " . TABLE_PREFIX . "relatives_relations r
        INNER JOIN " . TABLE_PREFIX . "relatives_persons p ON p.id = r.person_id
        LEFT JOIN " . TABLE_PREFIX . "relatives_relationship_types t ON t.id = r.relationship_type_id");
    while ($row = $db->fetch_array($query)) {
        if (empty($row['relationship_type_id']) && ($row['relationship_custom_label'] === null || $row['relationship_custom_label'] === '')) {
            $counts['no relationship']++;
        }
        if ($row['related_to_family_id'] !== null) {
            $counts['to family']++;
            continue;
        }
        $target = $row['related_to_person_id'] !== null ? (int)$row['related_to_person_id'] : null;
        if ($target !== null && !isset($account_nodes[$target])) {
            $counts['to other person']++;
            continue;
        }
        $counts['to character']++;

        $character_uid = $target === null ? (int)$row['owner_uid'] : $account_nodes[$target];
        if (in_array((string)$row['concept_key'], RELATIVES_CLI_INDIRECT_CONCEPTS, true)) {
            $loose[relatives_cli_username($character_uid)][] = sprintf('%s (%s)', $row['name'], $row['label']);
        }
    }
    foreach ($counts as $label => $count) {
        relatives_cli_line(sprintf('  %-20s %d', $label, $count));
    }
    relatives_cli_line(sprintf('  %-20s %d', 'conflicts', (int)relatives_cli_count('relatives_relation_conflicts')));

    relatives_cli_heading('Loosely connected (indirect degree still attached to the character)');
    if (!$loose) {
        relatives_cli_line('  none');
    }
    ksort($loose, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($loose as $character => $entries) {
        sort($entries, SORT_NATURAL | SORT_FLAG_CASE);
        relatives_cli_line('  ' . $character . ' (' . count($entries) . ')');
        foreach ($entries as $entry) {
            relatives_cli_line('    - ' . $entry);
        }
    }

    // Nach dem Zusammenfuehren: nach alten Regeln geratene Kanten duerfen nicht mehr existieren.
    if ($merged) {
        relatives_cli_heading('Legacy guesses still present (should be 0)');
        relatives_cli_line('  ' . count(Relatives_Graph::revert_legacy_guesses(true)));
    }
}

// ------------------------------------------------------------------
// Einstieg
// ------------------------------------------------------------------

$cmd = isset($argv[1]) ? $argv[1] : 'status';
$flags = array_slice($argv, 2);
$keep_attributes = in_array('--keep-attributes', $flags, true);

switch ($cmd) {
    case 'status':
        relatives_cli_status();
        break;
    case 'preview':
        relatives_cli_preview();
        break;
    case 'backup':
        relatives_cli_backup($flags);
        break;
    case 'reset':
        relatives_cli_require_write_access($flags);
        relatives_cli_backup($flags);
        relatives_cli_reset($keep_attributes);
        relatives_cli_status();
        break;
    case 'run':
        relatives_cli_require_write_access($flags);
        if (!relatives_cli_preview()) {
            exit(1);
        }
        relatives_cli_backup($flags);
        // Schema/Templates/Settings auf den hochgeladenen Code-Stand bringen (wie
        // Re-Aktivieren im ACP) -- sonst fehlen Spalten neuerer Versionen.
        relatives_activate();
        relatives_cli_reset($keep_attributes);
        if (!relatives_cli_run()) {
            exit(1);
        }
        if (in_array('--merge', $flags, true)) {
            relatives_cli_merge();
        }
        relatives_cli_status();
        break;
    case 'merge':
        relatives_cli_require_write_access($flags);
        relatives_cli_merge();
        break;
    case 'verify':
        relatives_cli_verify();
        break;
    default:
        relatives_cli_line('Usage: php tests/cli-migration.php status|preview|backup|reset|run|merge|verify [--yes] [--merge] [--keep-attributes] [--out=file]');
        exit(1);
}
