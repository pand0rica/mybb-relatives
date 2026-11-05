<?php
/**
 * relatives ACP - Tab "Attribut-Gruppen"
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

$do = $mybb->get_input('do');
$id = $mybb->get_input('id', MyBB::INPUT_INT);
$group_id = $mybb->get_input('group_id', MyBB::INPUT_INT);

// --- Gruppen: add/edit/delete -----------------------------------------------------------

if ($do === 'delete_group' && $mybb->request_method === 'post') {
    Relatives_Attributes::delete_group($id);
    flash_message($lang->relatives_acp_deleted, 'success');
    admin_redirect('index.php?module=tools-relatives&action=attributes');
}

if ($do === 'add_group' || $do === 'edit_group') {
    $existing = null;
    if ($do === 'edit_group') {
        $existing = Relatives_Attributes::get_group($id);
        if ($existing === null) {
            flash_message($lang->relatives_acp_not_found, 'error');
            admin_redirect('index.php?module=tools-relatives&action=attributes');
        }
    }

    if ($mybb->request_method === 'post') {
        $data = [
            'label'      => trim($mybb->get_input('label')),
            'applies_to' => $mybb->get_input('applies_to'),
            'field_type' => $mybb->get_input('field_type'),
            'is_multiple' => $mybb->get_input('is_multiple', MyBB::INPUT_INT) ? 1 : 0,
            'sort_order' => $mybb->get_input('sort_order', MyBB::INPUT_INT),
            'is_active'  => $mybb->get_input('is_active', MyBB::INPUT_INT) ? 1 : 0,
        ];
        if ($data['label'] === '') {
            flash_message($lang->relatives_acp_error_required_fields, 'error');
            admin_redirect('index.php?module=tools-relatives&action=attributes&do=' . $do . '&id=' . $id);
        }
        // Freitext nur fuer reine Familien-Gruppen -- lieber ablehnen als still umstellen.
        if ($data['field_type'] === Relatives_Attributes::TYPE_TEXT
            && $data['applies_to'] !== Relatives_Attributes::APPLIES_FAMILY) {
            flash_message($lang->relatives_acp_error_text_family_only, 'error');
            admin_redirect('index.php?module=tools-relatives&action=attributes&do=' . $do . '&id=' . $id);
        }
        if ($do === 'add_group') {
            Relatives_Attributes::create_group($data);
        } else {
            Relatives_Attributes::update_group($id, $data);
        }
        flash_message($lang->relatives_acp_saved, 'success');
        admin_redirect('index.php?module=tools-relatives&action=attributes');
    }

    $page->add_breadcrumb_item($lang->relatives_tab_attributes, 'index.php?module=tools-relatives&action=attributes');
    $page->output_header($lang->relatives_acp_title);
    relatives_acp_nav_tabs('attributes');

    $title = $do === 'add_group' ? $lang->relatives_acp_add_group : $lang->relatives_acp_edit_group;
    $form = new Form('index.php?module=tools-relatives&action=attributes&do=' . $do . ($existing ? '&id=' . $id : ''), 'post');
    $form_container = new FormContainer($title);
    $form_container->output_row($lang->relatives_acp_field_label, '', $form->generate_text_box('label', $existing['label'] ?? '', ['id' => 'label']), 'label');
    $form_container->output_row(
        $lang->relatives_acp_field_applies_to,
        $lang->relatives_acp_field_applies_to_desc,
        $form->generate_select_box('applies_to', [
            Relatives_Attributes::APPLIES_PERSON => $lang->relatives_acp_applies_person,
            Relatives_Attributes::APPLIES_FAMILY => $lang->relatives_acp_applies_family,
            Relatives_Attributes::APPLIES_BOTH   => $lang->relatives_acp_applies_both,
        ], [$existing['applies_to'] ?? Relatives_Attributes::APPLIES_PERSON], ['id' => 'applies_to']),
        'applies_to'
    );
    $form_container->output_row(
        $lang->relatives_acp_field_field_type,
        $lang->relatives_acp_field_field_type_desc,
        $form->generate_select_box('field_type', [
            Relatives_Attributes::TYPE_SELECT => $lang->relatives_acp_field_type_select,
            Relatives_Attributes::TYPE_TEXT   => $lang->relatives_acp_field_type_text,
        ], [$existing['field_type'] ?? Relatives_Attributes::TYPE_SELECT], ['id' => 'field_type']),
        'field_type'
    );
    $form_container->output_row(
        $lang->relatives_acp_field_is_multiple,
        $lang->relatives_acp_field_is_multiple_desc,
        $form->generate_yes_no_radio('is_multiple', $existing['is_multiple'] ?? 0, true, ['id' => 'is_multiple']),
        'is_multiple'
    );
    $form_container->output_row($lang->relatives_acp_field_sort_order, '', $form->generate_numeric_field('sort_order', $existing['sort_order'] ?? 0, ['id' => 'sort_order']), 'sort_order');
    $form_container->output_row($lang->relatives_acp_field_is_active, '', $form->generate_yes_no_radio('is_active', $existing['is_active'] ?? 1, true, ['id' => 'is_active']), 'is_active');
    $form_container->end();

    $buttons[] = $form->generate_submit_button($title);
    $form->output_submit_wrapper($buttons);
    $form->end();

    $page->output_footer();
    exit;
}

// --- Optionen: add/edit/delete -----------------------------------------------------------

if ($do === 'delete_option' && $mybb->request_method === 'post') {
    Relatives_Attributes::delete_option($id);
    flash_message($lang->relatives_acp_deleted, 'success');
    admin_redirect('index.php?module=tools-relatives&action=attributes');
}

if ($do === 'add_option' || $do === 'edit_option') {
    $existing = null;
    if ($do === 'edit_option') {
        $existing = Relatives_Attributes::get_option($id);
        if ($existing === null) {
            flash_message($lang->relatives_acp_not_found, 'error');
            admin_redirect('index.php?module=tools-relatives&action=attributes');
        }
        $group_id = (int)$existing['group_id'];
    }

    $group = Relatives_Attributes::get_group($group_id);
    if ($group === null) {
        flash_message($lang->relatives_acp_not_found, 'error');
        admin_redirect('index.php?module=tools-relatives&action=attributes');
    }

    if ($mybb->request_method === 'post') {
        $data = [
            'group_id'   => $group_id,
            'label'      => trim($mybb->get_input('label')),
            'sort_order' => $mybb->get_input('sort_order', MyBB::INPUT_INT),
            'is_active'  => $mybb->get_input('is_active', MyBB::INPUT_INT) ? 1 : 0,
        ];
        if ($data['label'] === '') {
            flash_message($lang->relatives_acp_error_required_fields, 'error');
            admin_redirect('index.php?module=tools-relatives&action=attributes&do=' . $do . '&id=' . $id . '&group_id=' . $group_id);
        }
        if ($do === 'add_option') {
            Relatives_Attributes::create_option($data);
        } else {
            Relatives_Attributes::update_option($id, $data);
        }
        flash_message($lang->relatives_acp_saved, 'success');
        admin_redirect('index.php?module=tools-relatives&action=attributes');
    }

    $page->add_breadcrumb_item($lang->relatives_tab_attributes, 'index.php?module=tools-relatives&action=attributes');
    $page->output_header($lang->relatives_acp_title);
    relatives_acp_nav_tabs('attributes');

    $form = new Form('index.php?module=tools-relatives&action=attributes&do=' . $do . ($existing ? '&id=' . $id : '') . '&group_id=' . $group_id, 'post');
    $title = ($do === 'add_option' ? $lang->relatives_acp_add_option : $lang->relatives_acp_edit_option) . ' (' . htmlspecialchars_uni($group['label']) . ')';
    $form_container = new FormContainer($title);
    $form_container->output_row($lang->relatives_acp_field_label, '', $form->generate_text_box('label', $existing['label'] ?? '', ['id' => 'label']), 'label');
    $form_container->output_row($lang->relatives_acp_field_sort_order, '', $form->generate_numeric_field('sort_order', $existing['sort_order'] ?? 0, ['id' => 'sort_order']), 'sort_order');
    $form_container->output_row($lang->relatives_acp_field_is_active, '', $form->generate_yes_no_radio('is_active', $existing['is_active'] ?? 1, true, ['id' => 'is_active']), 'is_active');
    $form_container->end();

    $buttons[] = $form->generate_submit_button($title);
    $form->output_submit_wrapper($buttons);
    $form->end();

    $page->output_footer();
    exit;
}

// --- Uebersicht ----------------------------------------------------------------------------

$page->add_breadcrumb_item($lang->relatives_tab_attributes, 'index.php?module=tools-relatives&action=attributes');
$page->output_header($lang->relatives_acp_title);
relatives_acp_nav_tabs('attributes');

$actions_table = new Table();
$actions_table->construct_cell(
    '<form action="index.php" method="get" class="relatives_acp_action_form">'
    . '<input type="hidden" name="module" value="tools-relatives" />'
    . '<input type="hidden" name="action" value="attributes" />'
    . '<input type="hidden" name="do" value="add_group" />'
    . '<input type="submit" class="submit_button" value="' . htmlspecialchars_uni($lang->relatives_acp_add_group) . '" />'
    . '</form>'
    . '<p class="smalltext relatives_acp_action_desc">' . $lang->relatives_acp_add_group_desc . '</p>'
);
$actions_table->construct_row();
$actions_table->output($lang->relatives_acp_actions_title);

$groups = Relatives_Attributes::get_groups(false, null);
if (empty($groups)) {
    echo '<p>' . $lang->relatives_acp_no_groups . '</p>';
}

foreach ($groups as $group) {
    $gid = (int)$group['id'];
    $is_text_group = Relatives_Attributes::is_text_group($group);
    $active_note = (int)$group['is_active'] === 1 ? '' : ' (' . $lang->relatives_acp_inactive . ')';
    $scope_labels = [
        Relatives_Attributes::APPLIES_PERSON => $lang->relatives_acp_applies_person,
        Relatives_Attributes::APPLIES_FAMILY => $lang->relatives_acp_applies_family,
        Relatives_Attributes::APPLIES_BOTH   => $lang->relatives_acp_applies_both,
    ];
    $scope_note = ' [' . ($scope_labels[$group['applies_to'] ?? ''] ?? $lang->relatives_acp_applies_person)
        . ', ' . ($is_text_group ? $lang->relatives_acp_field_type_text : $lang->relatives_acp_field_type_select)
        . (!$is_text_group && Relatives_Attributes::is_multiple_group($group) ? ', ' . $lang->relatives_acp_multiple_note : '')
        . ']';

    $table = new Table();
    $table->construct_header($lang->relatives_acp_col_label);
    $table->construct_header($lang->relatives_acp_col_actions);

    $options = Relatives_Attributes::get_options($gid, false);
    if ($is_text_group) {
        // Freitext braucht keine Optionen; vorhandene bleiben erhalten, falls die Gruppe
        // wieder auf Vorauswahl umgestellt wird.
        $table->construct_cell($lang->relatives_acp_text_group_no_options, ['colspan' => 2]);
        $table->construct_row();
    } elseif (empty($options)) {
        $table->construct_cell($lang->relatives_acp_no_options, ['colspan' => 2]);
        $table->construct_row();
    } else {
        foreach ($options as $option) {
            $option_active_note = (int)$option['is_active'] === 1 ? '' : ' (' . $lang->relatives_acp_inactive . ')';
            $table->construct_cell(htmlspecialchars_uni($option['label']) . $option_active_note);
            $edit_link = 'index.php?module=tools-relatives&action=attributes&do=edit_option&id=' . (int)$option['id'] . '&group_id=' . $gid;
            $delete_link = relatives_acp_delete_link(
                'relatives_delete_option_' . (int)$option['id'],
                'index.php?module=tools-relatives&action=attributes&do=delete_option',
                (int)$option['id']
            );
            $table->construct_cell('<a href="' . $edit_link . '">' . $lang->relatives_acp_edit . '</a> | ' . $delete_link);
            $table->construct_row();
        }
    }

    $edit_group_link = 'index.php?module=tools-relatives&action=attributes&do=edit_group&id=' . $gid;
    $add_option_link = 'index.php?module=tools-relatives&action=attributes&do=add_option&group_id=' . $gid;
    $delete_group_link = relatives_acp_delete_link(
        'relatives_delete_group_' . $gid,
        'index.php?module=tools-relatives&action=attributes&do=delete_group',
        $gid
    );

    $caption = htmlspecialchars_uni($group['label']) . htmlspecialchars_uni($scope_note) . $active_note
        . ' &ndash; <a href="' . $edit_group_link . '">' . $lang->relatives_acp_edit . '</a>'
        . ($is_text_group ? '' : ' | <a href="' . $add_option_link . '">' . $lang->relatives_acp_add_option . '</a>')
        . ' | ' . $delete_group_link;

    $table->output($caption);
}

$page->output_footer();
