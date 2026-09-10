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

// --- do=delete (POST) -----------------------------------------------------------------

if ($do === 'delete' && $mybb->request_method === 'post') {
    verify_post_check($mybb->get_input('my_post_key'));
    Relatives_RelationshipTypes::delete($id);
    flash_message($lang->relatives_acp_deleted, 'success');
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

    $form = new Form('index.php?module=tools-relatives&action=relationship-types&do=' . $do . ($existing ? '&id=' . $id : ''), 'post');
    echo $form->generate_form_header($do === 'add' ? $lang->relatives_acp_add_relationship_type : $lang->relatives_acp_edit_relationship_type);

    echo $form->generate_text_box('concept_key', $existing['concept_key'] ?? '', ['id' => 'concept_key', 'label' => $lang->relatives_acp_field_concept_key, 'description' => $lang->relatives_acp_field_concept_key_desc]);
    echo $form->generate_select_box('gender', $gender_labels, $existing['gender'] ?? Relatives_RelationshipTypes::LABEL_NEUTRAL, ['id' => 'gender', 'label' => $lang->relatives_field_gender]);
    echo $form->generate_text_box('label', $existing['label'] ?? '', ['id' => 'label', 'label' => $lang->relatives_acp_field_label]);
    echo $form->generate_select_box('generation_offset', [
        -2 => $lang->relatives_acp_generation_grandparent,
        -1 => $lang->relatives_acp_generation_parent,
        0  => $lang->relatives_acp_generation_same,
        1  => $lang->relatives_acp_generation_child,
        2  => $lang->relatives_acp_generation_grandchild,
    ], $existing['generation_offset'] ?? 0, ['id' => 'generation_offset', 'label' => $lang->relatives_acp_field_generation_offset]);
    echo $form->generate_numeric_field('sort_order', $existing['sort_order'] ?? 0, ['id' => 'sort_order', 'label' => $lang->relatives_acp_field_sort_order]);
    echo $form->generate_yes_no_radio('is_active', $existing['is_active'] ?? 1, true, ['id' => 'is_active', 'label' => $lang->relatives_acp_field_is_active]);

    echo $form->generate_submit_buttons($do === 'add' ? $lang->relatives_acp_add_relationship_type : $lang->relatives_acp_edit_relationship_type);
    $form->output_form_footer();

    $page->output_footer();
    exit;
}

// --- Uebersicht (gruppiert nach concept_key) ---------------------------------------------

$page->add_breadcrumb_item($lang->relatives_tab_relationship_types, 'index.php?module=tools-relatives&action=relationship-types');
$page->output_header($lang->relatives_acp_title);
relatives_acp_nav_tabs('relationship-types');

echo '<p><a href="index.php?module=tools-relatives&action=relationship-types&do=add" class="button">' . $lang->relatives_acp_add_relationship_type . '</a></p>';

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
                $missing_class = ($gender_key === Relatives_RelationshipTypes::LABEL_NEUTRAL) ? ' style="color:#b33;font-weight:bold;"' : '';
                $table->construct_cell('<span' . $missing_class . '>' . $lang->relatives_acp_missing . '</span>');
            } else {
                $edit_link = 'index.php?module=tools-relatives&action=relationship-types&do=edit&id=' . (int)$variant['id'];
                $delete_form = '<form action="index.php?module=tools-relatives&action=relationship-types&do=delete" method="post" style="display:inline">'
                    . '<input type="hidden" name="id" value="' . (int)$variant['id'] . '" />'
                    . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
                    . '<input type="submit" value="' . $lang->relatives_acp_delete . '" class="button" onclick="return confirm(\'' . $lang->relatives_acp_confirm_delete . '\');" />'
                    . '</form>';
                $active_note = (int)$variant['is_active'] === 1 ? '' : ' (' . $lang->relatives_acp_inactive . ')';
                $table->construct_cell(htmlspecialchars_uni($variant['label']) . $active_note . '<br /><a href="' . $edit_link . '">' . $lang->relatives_acp_edit . '</a> | ' . $delete_form);
            }
        }
        $table->construct_row();
    }
}

$table->output($lang->relatives_acp_relationship_types_overview);

$page->output_footer();
