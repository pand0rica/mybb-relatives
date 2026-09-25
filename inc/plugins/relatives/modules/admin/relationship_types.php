<?php
/**
 * relatives ACP - Tab "Verwandtschaftsgrade"
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

$do = $mybb->get_input('do');
$id = $mybb->get_input('id', MyBB::INPUT_INT);

$gender_labels = [
    Relatives_RelationshipTypes::LABEL_MALE    => $lang->relatives_gender_male,
    Relatives_RelationshipTypes::LABEL_FEMALE  => $lang->relatives_gender_female,
    Relatives_RelationshipTypes::LABEL_NEUTRAL => $lang->relatives_gender_diverse_neutral,
];

$line_style_labels = [
    'solid'   => $lang->relatives_acp_line_style_solid,
    'dashed'  => $lang->relatives_acp_line_style_dashed,
    'dotted'  => $lang->relatives_acp_line_style_dotted,
    'dashdot' => $lang->relatives_acp_line_style_dashdot,
];

// --- do=recompute (POST) ----------------------------------------------------------------

if ($do === 'recompute' && $mybb->request_method === 'post') {
    verify_post_check($mybb->get_input('my_post_key'));
    $updated = Relatives_Kinship::recompute_all();
    flash_message($lang->sprintf($lang->relatives_acp_recompute_done, $updated), 'success');
    admin_redirect('index.php?module=tools-relatives&action=relationship-types');
}

// --- do=delete (POST) -----------------------------------------------------------------

if ($do === 'delete' && $mybb->request_method === 'post') {
    verify_post_check($mybb->get_input('my_post_key'));
    Relatives_RelationshipTypes::delete($id);
    flash_message($lang->relatives_acp_deleted, 'success');
    admin_redirect('index.php?module=tools-relatives&action=relationship-types');
}

// --- do=seed_defaults (POST) ------------------------------------------------------------

if ($do === 'seed_defaults' && $mybb->request_method === 'post') {
    verify_post_check($mybb->get_input('my_post_key'));
    $created = Relatives_RelationshipTypes::create_common_defaults();
    if ($created > 0) {
        flash_message(sprintf($lang->relatives_acp_seed_defaults_done, $created), 'success');
    } else {
        flash_message($lang->relatives_acp_seed_defaults_nothing, 'success');
    }
    admin_redirect('index.php?module=tools-relatives&action=relationship-types');
}

// --- do=add / do=edit -------------------------------------------------------------------

if ($do === 'add' || $do === 'edit') {
    $existing = null;
    if ($do === 'edit') {
        $existing = Relatives_RelationshipTypes::get_by_id($id);
        if ($existing === null) {
            flash_message($lang->relatives_acp_not_found, 'error');
            admin_redirect('index.php?module=tools-relatives&action=relationship-types');
        }
    }

    if ($mybb->request_method === 'post') {
        verify_post_check($mybb->get_input('my_post_key'));

        $data = [
            'concept_key'       => trim($mybb->get_input('concept_key')),
            'gender'            => $mybb->get_input('gender', MyBB::INPUT_INT),
            'label'             => trim($mybb->get_input('label')),
            'generation_offset' => $mybb->get_input('generation_offset', MyBB::INPUT_INT),
            'line_style'        => Relatives_RelationshipTypes::sanitize_line_style($mybb->get_input('line_style')),
            'sort_order'        => $mybb->get_input('sort_order', MyBB::INPUT_INT),
            'is_active'         => $mybb->get_input('is_active', MyBB::INPUT_INT) ? 1 : 0,
        ];

        if ($data['concept_key'] === '' || $data['label'] === '') {
            flash_message($lang->relatives_acp_error_required_fields, 'error');
            admin_redirect('index.php?module=tools-relatives&action=relationship-types&do=' . $do . '&id=' . $id);
        }

        if ($do === 'add') {
            Relatives_RelationshipTypes::create($data);
        } else {
            Relatives_RelationshipTypes::update($id, $data);
        }

        flash_message($lang->relatives_acp_saved, 'success');
        admin_redirect('index.php?module=tools-relatives&action=relationship-types');
    }

    $page->add_breadcrumb_item($lang->relatives_tab_relationship_types, 'index.php?module=tools-relatives&action=relationship-types');
    $page->output_header($lang->relatives_acp_title);
    relatives_acp_nav_tabs('relationship-types');

    $title = $do === 'add' ? $lang->relatives_acp_add_relationship_type : $lang->relatives_acp_edit_relationship_type;
    $form = new Form('index.php?module=tools-relatives&action=relationship-types&do=' . $do . ($existing ? '&id=' . $id : ''), 'post');
    $form_container = new FormContainer($title);

    $form_container->output_row($lang->relatives_acp_field_concept_key, $lang->relatives_acp_field_concept_key_desc, $form->generate_text_box('concept_key', $existing['concept_key'] ?? '', ['id' => 'concept_key']), 'concept_key');
    $form_container->output_row($lang->relatives_field_gender, '', $form->generate_select_box('gender', $gender_labels, $existing['gender'] ?? Relatives_RelationshipTypes::LABEL_NEUTRAL, ['id' => 'gender']), 'gender');
    $form_container->output_row($lang->relatives_acp_field_label, '', $form->generate_text_box('label', $existing['label'] ?? '', ['id' => 'label']), 'label');
    $form_container->output_row($lang->relatives_acp_field_generation_offset, '', $form->generate_select_box('generation_offset', [
        -2 => $lang->relatives_acp_generation_grandparent,
        -1 => $lang->relatives_acp_generation_parent,
        0  => $lang->relatives_acp_generation_same,
        1  => $lang->relatives_acp_generation_child,
        2  => $lang->relatives_acp_generation_grandchild,
    ], $existing['generation_offset'] ?? 0, ['id' => 'generation_offset']), 'generation_offset');
    $form_container->output_row($lang->relatives_acp_field_line_style, $lang->relatives_acp_field_line_style_desc, $form->generate_select_box('line_style', $line_style_labels, $existing['line_style'] ?? 'solid', ['id' => 'line_style']), 'line_style');
    $form_container->output_row($lang->relatives_acp_field_sort_order, '', $form->generate_numeric_field('sort_order', $existing['sort_order'] ?? 0, ['id' => 'sort_order']), 'sort_order');
    $form_container->output_row($lang->relatives_acp_field_is_active, '', $form->generate_yes_no_radio('is_active', $existing['is_active'] ?? 1, true, ['id' => 'is_active']), 'is_active');
    $form_container->end();

    $buttons[] = $form->generate_submit_button($title);
    $form->output_submit_wrapper($buttons);
    $form->end();

    $page->output_footer();
    exit;
}

// --- Uebersicht (gruppiert nach concept_key) ---------------------------------------------

$page->add_breadcrumb_item($lang->relatives_tab_relationship_types, 'index.php?module=tools-relatives&action=relationship-types');
$page->output_header($lang->relatives_acp_title);
relatives_acp_nav_tabs('relationship-types');

$actions_table = new Table();
$actions_table->construct_cell(
    '<form action="index.php" method="get" style="margin:0">'
    . '<input type="hidden" name="module" value="tools-relatives" />'
    . '<input type="hidden" name="action" value="relationship-types" />'
    . '<input type="hidden" name="do" value="add" />'
    . '<input type="submit" class="submit_button" value="' . htmlspecialchars_uni($lang->relatives_acp_add_relationship_type) . '" />'
    . '</form>'
    . '<p class="smalltext" style="margin:6px 0 0">' . $lang->relatives_acp_add_relationship_type_desc . '</p>',
    ['width' => '34%']
);
$actions_table->construct_cell(
    '<form action="index.php?module=tools-relatives&action=relationship-types&do=seed_defaults" method="post" style="margin:0">'
    . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
    . '<input type="submit" class="submit_button" value="' . htmlspecialchars_uni($lang->relatives_acp_seed_defaults_button) . '" />'
    . '</form>'
    . '<p class="smalltext" style="margin:6px 0 0">' . $lang->relatives_acp_seed_defaults_desc . '</p>',
    ['width' => '33%']
);
$actions_table->construct_cell(
    '<form action="index.php?module=tools-relatives&action=relationship-types&do=recompute" method="post" style="margin:0">'
    . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
    . '<input type="submit" class="submit_button" value="' . htmlspecialchars_uni($lang->relatives_acp_recompute_button) . '" />'
    . '</form>'
    . '<p class="smalltext" style="margin:6px 0 0">' . $lang->relatives_acp_recompute_desc . '</p>',
    ['width' => '33%']
);
$actions_table->construct_row();
$actions_table->output($lang->relatives_acp_actions_title);

$table = new Table();
$table->construct_header($lang->relatives_acp_col_concept_key);
$table->construct_header($lang->relatives_gender_male);
$table->construct_header($lang->relatives_gender_female);
$table->construct_header($lang->relatives_gender_diverse_neutral);

$grouped = Relatives_RelationshipTypes::get_grouped_by_concept();
if (empty($grouped)) {
    $table->construct_cell($lang->relatives_acp_no_relationship_types, ['colspan' => 4]);
    $table->construct_row();
} else {
    foreach ($grouped as $concept_key => $variants) {
        $table->construct_cell(htmlspecialchars_uni($concept_key));
        foreach ([Relatives_RelationshipTypes::LABEL_MALE, Relatives_RelationshipTypes::LABEL_FEMALE, Relatives_RelationshipTypes::LABEL_NEUTRAL] as $gender_key) {
            $variant = $variants[$gender_key];
            if ($variant === null) {
                $missing_class = ($gender_key === Relatives_RelationshipTypes::LABEL_NEUTRAL) ? ' style="font-weight:bold;"' : '';
                $table->construct_cell('<span' . $missing_class . '>' . $lang->relatives_acp_missing . '</span>');
            } else {
                $edit_link = 'index.php?module=tools-relatives&action=relationship-types&do=edit&id=' . (int)$variant['id'];
                $delete_link = relatives_acp_delete_link(
                    'relatives_delete_type_' . (int)$variant['id'],
                    'index.php?module=tools-relatives&action=relationship-types&do=delete',
                    (int)$variant['id']
                );
                $active_note = (int)$variant['is_active'] === 1 ? '' : ' (' . $lang->relatives_acp_inactive . ')';
                $table->construct_cell(htmlspecialchars_uni($variant['label']) . $active_note . '<br /><a href="' . $edit_link . '">' . $lang->relatives_acp_edit . '</a> | ' . $delete_link);
            }
        }
        $table->construct_row();
    }
}

$table->output($lang->relatives_acp_relationship_types_overview);

$page->output_footer();
