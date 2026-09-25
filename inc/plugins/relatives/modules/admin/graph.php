<?php
/**
 * relatives ACP - Tab "Geflecht"
 *
 * Fuehrt die frueheren Einzel-Listen (Verbindungen je Charakter) zu einem gemeinsamen
 * Stammbaum-Geflecht zusammen (v2.2): gleichnamige Personen werden zu einem Eintrag,
 * Personen mit dem Namen eines Accounts werden dessen Knoten, identische Aussagen werden
 * zusammengelegt, Widersprueche landen in der Konfliktliste. Vor dem Lauf zeigt der Tab
 * eine Vorschau ohne Schreibzugriff. Siehe Relatives_Graph.
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

$do = $mybb->get_input('do');
$report = null;

if ($mybb->request_method === 'post') {
    verify_post_check($mybb->get_input('my_post_key'));

    if ($do === 'merge') {
        $report = Relatives_Graph::merge();
    } elseif ($do === 'conflict_accept') {
        Relatives_Graph::accept_conflict($mybb->get_input('id', MyBB::INPUT_INT));
        flash_message($lang->relatives_acp_graph_conflict_accepted, 'success');
        admin_redirect('index.php?module=tools-relatives&action=graph');
    } elseif ($do === 'conflict_discard') {
        Relatives_Graph::discard_conflict($mybb->get_input('id', MyBB::INPUT_INT));
        flash_message($lang->relatives_acp_graph_conflict_discarded, 'success');
        admin_redirect('index.php?module=tools-relatives&action=graph');
    } elseif ($do === 'revert_guesses') {
        $reverted = Relatives_Graph::revert_legacy_guesses(false);
        flash_message($lang->sprintf($lang->relatives_acp_graph_guesses_reverted, count($reverted)), 'success');
        admin_redirect('index.php?module=tools-relatives&action=graph');
    }
}

$page->add_breadcrumb_item($lang->relatives_tab_graph, 'index.php?module=tools-relatives&action=graph');
$page->output_header($lang->relatives_acp_title);
relatives_acp_nav_tabs('graph');

/** Kleines POST-Formular mit einem Knopf (Konflikt uebernehmen/verwerfen). */
function relatives_acp_graph_button(string $do, int $id, string $label): string
{
    global $mybb;
    return '<form action="index.php?module=tools-relatives&amp;action=graph&amp;do=' . $do . '" method="post" class="relatives_acp_inline_form">'
        . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
        . '<input type="hidden" name="id" value="' . $id . '" />'
        . '<input type="submit" class="submit_button" value="' . htmlspecialchars_uni($label) . '" />'
        . '</form>';
}

/** Lesbare Aussage einer Kanten- oder Konfliktzeile ("Tochter von Delia Flemming"). */
function relatives_acp_graph_statement(array $row): string
{
    global $lang;

    $label = '';
    if (!empty($row['relationship_type_id'])) {
        $type = Relatives_RelationshipTypes::get_by_id((int)$row['relationship_type_id']);
        $label = $type !== null ? (string)$type['label'] : '';
    }
    if ($label === '') {
        $label = (string)($row['relationship_custom_label'] ?? '');
    }

    $target = '';
    if (!empty($row['related_to_family_id'])) {
        $family = Relatives_Families::get_by_id((int)$row['related_to_family_id']);
        $target = $family !== null ? (string)$family['name'] : '#' . (int)$row['related_to_family_id'];
    } elseif (!empty($row['related_to_person_id'])) {
        $person = Relatives_Database::get_person((int)$row['related_to_person_id']);
        $target = $person !== null ? (string)$person['name'] : '#' . (int)$row['related_to_person_id'];
    }

    return htmlspecialchars_uni($lang->sprintf($lang->relatives_relation_of, $label, $target));
}

if ($report !== null) {
    $table = new Table;
    $table->construct_header($lang->relatives_acp_migration_col_item);
    $table->construct_header($lang->relatives_acp_migration_col_count, ['class' => 'align_center', 'width' => '15%']);
    foreach ($report as $key => $value) {
        $table->construct_cell(htmlspecialchars_uni($lang->{'relatives_acp_graph_report_' . $key}));
        $table->construct_cell((int)$value, ['class' => 'align_center']);
        $table->construct_row();
    }
    $table->output($lang->relatives_acp_graph_report_title);
}

echo '<p>' . $lang->relatives_acp_graph_intro . '</p>';
if (Relatives_Graph::is_merged()) {
    echo '<p><strong>' . $lang->sprintf($lang->relatives_acp_graph_merged_at, my_date('relative', (int)$mybb->settings['relatives_graph_merged'])) . '</strong></p>';
} else {
    echo '<p class="error">' . $lang->relatives_acp_graph_not_merged . '</p>';
}

// --- Vorschau (ohne Schreibzugriff) ------------------------------------------------------

$preview = Relatives_Graph::preview();

$table = new Table;
$table->construct_header($lang->relatives_acp_migration_col_item);
$table->construct_header($lang->relatives_acp_migration_col_count, ['class' => 'align_center', 'width' => '15%']);
foreach ([
    'relatives_acp_graph_preview_account_links'  => count($preview['account_links']),
    'relatives_acp_graph_preview_duplicates'     => count($preview['duplicate_names']),
    'relatives_acp_graph_preview_new_nodes'      => count($preview['new_account_nodes']),
    'relatives_acp_graph_preview_identical'      => $preview['identical'],
    'relatives_acp_graph_preview_conflicts'      => count($preview['conflicts']),
] as $lang_key => $value) {
    $table->construct_cell(htmlspecialchars_uni($lang->{$lang_key}));
    $table->construct_cell((int)$value, ['class' => 'align_center']);
    $table->construct_row();
}
$table->output($lang->relatives_acp_graph_preview_title);

if (!empty($preview['account_links'])) {
    $table = new Table;
    $table->construct_header($lang->relatives_acp_graph_col_person);
    $table->construct_header($lang->relatives_acp_graph_col_account);
    foreach ($preview['account_links'] as $link) {
        $user = get_user((int)$link['uid']);
        $table->construct_cell(htmlspecialchars_uni($link['name']) . ' (#' . (int)$link['person_id'] . ')');
        $table->construct_cell($user ? htmlspecialchars_uni($user['username']) . ' (uid ' . (int)$link['uid'] . ')' : '#' . (int)$link['uid']);
        $table->construct_row();
    }
    $table->output($lang->relatives_acp_graph_preview_account_links);
}

if (!empty($preview['duplicate_names'])) {
    $table = new Table;
    $table->construct_header($lang->relatives_acp_graph_col_person);
    $table->construct_header($lang->relatives_acp_graph_col_ids);
    foreach ($preview['duplicate_names'] as $duplicate) {
        $table->construct_cell(htmlspecialchars_uni($duplicate['name']));
        $table->construct_cell(htmlspecialchars_uni(implode(', ', $duplicate['ids'])));
        $table->construct_row();
    }
    $table->output($lang->relatives_acp_graph_preview_duplicates);
    echo '<p class="smalltext">' . $lang->relatives_acp_graph_duplicates_hint . '</p>';
}

if (!empty($preview['new_account_nodes'])) {
    echo '<p><strong>' . $lang->relatives_acp_graph_preview_new_nodes . ':</strong> '
        . htmlspecialchars_uni(implode(', ', $preview['new_account_nodes'])) . '</p>';
}

$form = new Form('index.php?module=tools-relatives&action=graph&do=merge', 'post');
$buttons = [];
$buttons[] = $form->generate_submit_button(Relatives_Graph::is_merged()
    ? $lang->relatives_acp_graph_rerun
    : $lang->relatives_acp_graph_run);
$form->output_submit_wrapper($buttons);
$form->end();

// --- Geratene Zuordnungen der Alt-Migration ------------------------------------------------

$guesses = Relatives_Graph::revert_legacy_guesses(true);

$table = new Table;
$table->construct_header($lang->relatives_acp_graph_col_person);
$table->construct_header($lang->relatives_acp_graph_col_guess);
$table->construct_header($lang->relatives_acp_graph_col_original);
$table->construct_header($lang->relatives_acp_graph_col_source);
if (!$guesses) {
    $table->construct_cell($lang->relatives_acp_graph_no_guesses, ['colspan' => 4]);
    $table->construct_row();
}
foreach ($guesses as $item) {
    $person = Relatives_Database::get_person((int)$item['guess']['person_id']);
    $node = $item['node'] > 0 ? Relatives_Database::get_person((int)$item['node']) : null;
    $legacy_type = Relatives_RelationshipTypes::find_by_concept_and_gender($item['guess']['legacy_concept'], $item['guess']['legacy_gender']);
    $source_user = get_user((int)$item['guess']['owner_uid']);

    $table->construct_cell($person !== null ? htmlspecialchars_uni($person['name']) : '#' . (int)$item['guess']['person_id']);
    $table->construct_cell(relatives_acp_graph_statement($item['edge']));
    $table->construct_cell(htmlspecialchars_uni($lang->sprintf(
        $lang->relatives_relation_of,
        $legacy_type !== null ? (string)$legacy_type['label'] : $item['guess']['legacy_concept'],
        $node !== null ? (string)$node['name'] : ''
    )));
    $table->construct_cell($source_user ? htmlspecialchars_uni($source_user['username']) : '&ndash;');
    $table->construct_row();
}
$table->output($lang->relatives_acp_graph_guesses_title);

if ($guesses) {
    echo '<p class="smalltext">' . $lang->relatives_acp_graph_guesses_desc . '</p>';
    $form = new Form('index.php?module=tools-relatives&action=graph&do=revert_guesses', 'post');
    $buttons = [];
    $buttons[] = $form->generate_submit_button($lang->relatives_acp_graph_guesses_button);
    $form->output_submit_wrapper($buttons);
    $form->end();
}

// --- Konfliktliste ------------------------------------------------------------------------

$table = new Table;
$table->construct_header($lang->relatives_acp_graph_col_person);
$table->construct_header($lang->relatives_acp_graph_col_kept);
$table->construct_header($lang->relatives_acp_graph_col_discarded);
$table->construct_header($lang->relatives_acp_graph_col_source);
$table->construct_header($lang->relatives_acp_col_actions ?? '', ['class' => 'align_center']);

$conflicts = Relatives_Graph::get_conflicts();
if (!$conflicts) {
    $table->construct_cell($lang->relatives_acp_graph_no_conflicts, ['colspan' => 5]);
    $table->construct_row();
}
foreach ($conflicts as $conflict) {
    $person = Relatives_Database::get_person((int)$conflict['person_id']);
    $kept = Relatives_Database::get_relation((int)$conflict['kept_relation_id']);
    $source = get_user((int)$conflict['source_uid']);

    $table->construct_cell($person !== null ? htmlspecialchars_uni($person['name']) : '#' . (int)$conflict['person_id']);
    $table->construct_cell($kept !== null ? relatives_acp_graph_statement($kept) : '&ndash;');
    $table->construct_cell(relatives_acp_graph_statement($conflict));
    $table->construct_cell($source ? htmlspecialchars_uni($source['username']) : '&ndash;');
    $table->construct_cell(
        relatives_acp_graph_button('conflict_accept', (int)$conflict['id'], $lang->relatives_acp_graph_accept)
        . ' ' . relatives_acp_graph_button('conflict_discard', (int)$conflict['id'], $lang->relatives_acp_graph_discard),
        ['class' => 'align_center']
    );
    $table->construct_row();
}
$table->output($lang->relatives_acp_graph_conflicts_title);

$page->output_footer();
