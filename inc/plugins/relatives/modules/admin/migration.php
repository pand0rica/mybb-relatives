<?php
/**
 * relatives ACP - Tab "Migration"
 *
 * Legacy-Import aus dem Altplugin (relatives/wanted + relatives_status/wanted_status +
 * relatives_options). Zeigt vor dem Lauf eine Vorschau: Zuordnung jedes alten
 * Verwandtschaftsgrades und wie viele Personen/Zuordnungen uebernommen bzw.
 * uebersprungen werden. Siehe PROJECT_CONTEXT.md "Migration (Legacy -> Neu)" und
 * Relatives_Migration.
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

$report = null;

if ($mybb->request_method === 'post') {

    $force = $mybb->get_input('confirm_rerun', MyBB::INPUT_INT) ? true : false;
    $report = Relatives_Migration::run($force);
}

$page->add_breadcrumb_item($lang->relatives_tab_migration, 'index.php?module=tools-relatives&action=migration');
$page->output_header($lang->relatives_acp_title);
relatives_acp_nav_tabs('migration');

/**
 * Gibt eine zweispaltige Kennzahlen-Tabelle aus ($rows: Sprach-Key => Wert).
 */
function relatives_acp_migration_output_counts(string $title, array $rows): void
{
    global $lang;

    $table = new Table;
    $table->construct_header($lang->relatives_acp_migration_col_item);
    $table->construct_header($lang->relatives_acp_migration_col_count, ['class' => 'align_center', 'width' => '15%']);
    foreach ($rows as $lang_key => $value) {
        $table->construct_cell(htmlspecialchars_uni($lang->{$lang_key}));
        $table->construct_cell((int)$value, ['class' => 'align_center']);
        $table->construct_row();
    }
    $table->output($title);
}

if ($report !== null) {
    if (!$report['ran']) {
        if ($report['reason'] === 'already_migrated') {
            echo '<p class="error">' . $lang->relatives_acp_migration_already_done . '</p>';
        } elseif ($report['reason'] === 'no_legacy_tables') {
            echo '<p class="error">' . $lang->relatives_acp_migration_no_tables . '</p>';
        } elseif ($report['reason'] === 'unmapped_statuses') {
            echo '<p class="error">' . $lang->relatives_acp_migration_unmapped_error . '</p><ul>';
            foreach ($report['unmapped_statuses'] as $label) {
                echo '<li>' . htmlspecialchars_uni($label) . '</li>';
            }
            echo '</ul>';
        }
    } else {
        relatives_acp_migration_output_counts($lang->relatives_acp_migration_report_title, [
            'relatives_acp_migration_report_types'                => $report['relationship_types_created'],
            'relatives_acp_migration_report_groups'               => $report['attribute_groups_created'],
            'relatives_acp_migration_report_options'              => $report['attribute_options_created'],
            'relatives_acp_migration_report_persons_relatives'    => $report['persons_created_relatives'],
            'relatives_acp_migration_report_persons_wanted'       => $report['persons_created_wanted'],
            'relatives_acp_migration_report_links'                => $report['owner_links_created'],
            'relatives_acp_migration_report_attributes'           => $report['attribute_assignments'],
            'relatives_acp_migration_report_without_relationship' => $report['links_without_relationship'],
            'relatives_acp_migration_report_skipped_no_owner'     => $report['persons_skipped_no_owner'],
            'relatives_acp_migration_report_skipped_invalid'      => $report['persons_skipped_invalid'],
            'relatives_acp_migration_report_skipped_missing_user' => $report['links_skipped_missing_user'],
            'relatives_acp_migration_report_skipped_missing_person' => $report['links_skipped_missing_person'],
            'relatives_acp_migration_report_skipped_duplicate'    => $report['links_skipped_duplicate'],
            'relatives_acp_migration_report_structure_anchored'   => $report['structure_anchored'],
            'relatives_acp_migration_report_structure_ambiguous'  => $report['structure_ambiguous'],
            'relatives_acp_migration_report_structure_unresolved' => $report['structure_unresolved'],
        ]);

        if (!empty($report['missing_neutral_concepts'])) {
            echo '<p><strong>' . $lang->relatives_acp_migration_missing_neutral_title . '</strong></p><ul>';
            foreach ($report['missing_neutral_concepts'] as $concept_key) {
                echo '<li>' . htmlspecialchars_uni($concept_key) . '</li>';
            }
            echo '</ul>';
        }
    }
}

$available = Relatives_Migration::legacy_tables_available();
$already_migrated = Relatives_Migration::already_migrated();

echo '<p>' . $lang->relatives_acp_migration_intro . '</p>';
echo '<ul>';
foreach ($available as $table_name => $exists) {
    $status = $exists ? $lang->relatives_acp_migration_table_found : $lang->relatives_acp_migration_table_missing;
    echo '<li>' . htmlspecialchars_uni($table_name) . ': ' . $status . '</li>';
}
echo '</ul>';

// Vorschau (ohne Schreibzugriff) -- Grundlage, um das Mapping vor dem Lauf zu pruefen.
$preview = Relatives_Migration::preview();

relatives_acp_migration_output_counts($lang->relatives_acp_migration_preview_stats_title, [
    'relatives_acp_migration_preview_persons_with_owner'    => $preview['stats']['persons_with_owner'],
    'relatives_acp_migration_preview_persons_without_owner' => $preview['stats']['persons_without_owner'],
    'relatives_acp_migration_preview_links_valid'           => $preview['stats']['links_valid'],
    'relatives_acp_migration_report_skipped_missing_user'   => $preview['stats']['links_missing_user'],
    'relatives_acp_migration_report_skipped_missing_person' => $preview['stats']['links_missing_person'],
    'relatives_acp_migration_report_skipped_duplicate'      => $preview['stats']['links_duplicate'],
    'relatives_acp_migration_preview_links_invalid_status'  => $preview['stats']['links_invalid_status'],
]);

if (!empty($preview['statuses'])) {
    $table = new Table;
    $table->construct_header($lang->relatives_acp_migration_col_legacy);
    $table->construct_header($lang->relatives_acp_migration_col_target);
    $table->construct_header($lang->relatives_acp_migration_col_concept);
    $table->construct_header($lang->relatives_acp_migration_col_links, ['class' => 'align_center', 'width' => '15%']);

    foreach ($preview['statuses'] as $status) {
        if (!$status['mapped']) {
            $target = '<strong>' . htmlspecialchars_uni($lang->relatives_acp_migration_unmapped_marker) . '</strong>';
        } elseif ($status['concept'] === null) {
            $target = '<em>' . htmlspecialchars_uni($lang->relatives_acp_migration_no_grade) . '</em>';
        } else {
            $target = htmlspecialchars_uni((string)$status['target_label']);
        }

        $table->construct_cell(htmlspecialchars_uni($status['label']));
        $table->construct_cell($target);
        $table->construct_cell(htmlspecialchars_uni((string)$status['concept']));
        $table->construct_cell((int)$status['links'], ['class' => 'align_center']);
        $table->construct_row();
    }
    $table->output($lang->relatives_acp_migration_preview_mapping_title);
}

if ($already_migrated) {
    echo '<p class="error">' . $lang->relatives_acp_migration_rerun_warning . '</p>';
}

$form = new Form('index.php?module=tools-relatives&action=migration', 'post');
if ($already_migrated) {
    $form_container = new FormContainer($lang->relatives_acp_migration_run);
    $form_container->output_row('', '', $form->generate_check_box('confirm_rerun', 1, $lang->relatives_acp_migration_confirm_rerun), 'confirm_rerun');
    $form_container->end();
}

$buttons[] = $form->generate_submit_button($lang->relatives_acp_migration_run);
$form->output_submit_wrapper($buttons);
$form->end();

$page->output_footer();
