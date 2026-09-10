<?php
/**
 * relatives ACP - Tab "Migration"
 *
 * Legacy-Import aus mybb_relatives/mybb_wanted/mybb_relatives_options. Siehe
 * PROJECT_CONTEXT.md "Migration (Legacy -> Neu)" und Relatives_Migration.
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

$report = null;

if ($mybb->request_method === 'post') {
    verify_post_check($mybb->get_input('my_post_key'));

    $force = $mybb->get_input('confirm_rerun', MyBB::INPUT_INT) ? true : false;
    $report = Relatives_Migration::run($force);
}

$page->add_breadcrumb_item($lang->relatives_tab_migration, 'index.php?module=tools-relatives&action=migration');
$page->output_header($lang->relatives_acp_title);
relatives_acp_nav_tabs('migration');

if ($report !== null) {
    if (!$report['ran']) {
        if ($report['reason'] === 'already_migrated') {
            echo '<p class="error">' . $lang->relatives_acp_migration_already_done . '</p>';
        } elseif ($report['reason'] === 'no_legacy_tables') {
            echo '<p class="error">' . $lang->relatives_acp_migration_no_tables . '</p>';
        }
    } else {
        echo '<div class="relatives_acp_migration_report">';
        echo '<p>' . sprintf(
            $lang->relatives_acp_migration_report_summary,
            (int)$report['relationship_types_created'],
            (int)$report['attribute_groups_created'],
            (int)$report['attribute_options_created'],
            (int)$report['persons_migrated'],
            (int)$report['wanted_migrated'],
            (int)$report['skipped_rows']
        ) . '</p>';

        if (!empty($report['missing_neutral_concepts'])) {
            echo '<p><strong>' . $lang->relatives_acp_migration_missing_neutral_title . '</strong></p><ul>';
            foreach ($report['missing_neutral_concepts'] as $concept_key) {
                echo '<li>' . htmlspecialchars_uni($concept_key) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
}

$available = Relatives_Migration::legacy_tables_available();
$already_migrated = Relatives_Migration::already_migrated();

echo '<p>' . $lang->relatives_acp_migration_intro . '</p>';
echo '<ul>';
foreach ($available as $table => $exists) {
    $status = $exists ? $lang->relatives_acp_migration_table_found : $lang->relatives_acp_migration_table_missing;
    echo '<li>' . htmlspecialchars_uni($table) . ': ' . $status . '</li>';
}
echo '</ul>';

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
