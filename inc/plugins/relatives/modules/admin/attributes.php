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
    verify_post_check($mybb->get_input('my_post_key'));
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
        verify_post_check($mybb->get_input('my_post_key'));
        $data = [
            'label'      => trim($mybb->get_input('label')),
            'sort_order' => $mybb->get_input('sort_order', MyBB::INPUT_INT),
            'is_active'  => $mybb->get_input('is_active', MyBB::INPUT_INT) ? 1 : 0,
        ];
        if ($data['label'] === '') {
            flash_message($lang->relatives_acp_error_required_fields, 'error');
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

    $form = new Form('index.php?module=tools-relatives&action=attributes&do=' . $do . ($existing ? '&id=' . $id : ''), 'post');
    echo $form->generate_form_header($do === 'add_group' ? $lang->relatives_acp_add_group : $lang->relatives_acp_edit_group);
    echo $form->generate_text_box('label', $existing['label'] ?? '', ['id' => 'label', 'label' => $lang->relatives_acp_field_label]);
    echo $form->generate_numeric_field('sort_order', $existing['sort_order'] ?? 0, ['id' => 'sort_order', 'label' => $lang->relatives_acp_field_sort_order]);
    echo $form->generate_yes_no_radio('is_active', $existing['is_active'] ?? 1, true, ['id' => 'is_active', 'label' => $lang->relatives_acp_field_is_active]);
    echo $form->generate_submit_buttons($do === 'add_group' ? $lang->relatives_acp_add_group : $lang->relatives_acp_edit_group);
    $form->output_form_footer();

    $page->output_footer();
    exit;
}

// --- Optionen: add/edit/delete -----------------------------------------------------------

if ($do === 'delete_option' && $mybb->request_method === 'post') {
    verify_post_check($mybb->get_input('my_post_key'));
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
        verify_post_check($mybb->get_input('my_post_key'));
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
    echo $form->generate_form_header($title);
    echo $form->generate_text_box('label', $existing['label'] ?? '', ['id' => 'label', 'label' => $lang->relatives_acp_field_label]);
    echo $form->generate_numeric_field('sort_order', $existing['sort_order'] ?? 0, ['id' => 'sort_order', 'label' => $lang->relatives_acp_field_sort_order]);
    echo $form->generate_yes_no_radio('is_active', $existing['is_active'] ?? 1, true, ['id' => 'is_active', 'label' => $lang->relatives_acp_field_is_active]);
    echo $form->generate_submit_buttons($title);
    $form->output_form_footer();

    $page->output_footer();
    exit;
}

// --- Uebersicht ----------------------------------------------------------------------------

$page->add_breadcrumb_item($lang->relatives_tab_attributes, 'index.php?module=tools-relatives&action=attributes');
$page->output_header($lang->relatives_acp_title);
relatives_acp_nav_tabs('attributes');

echo '<p><a href="index.php?module=tools-relatives&action=attributes&do=add_group" class="button">' . $lang->relatives_acp_add_group . '</a></p>';

$groups = Relatives_Attributes::get_groups(false);
if (empty($groups)) {
    echo '<p>' . $lang->relatives_acp_no_groups . '</p>';
}

foreach ($groups as $group) {
    $gid = (int)$group['id'];
    $active_note = (int)$group['is_active'] === 1 ? '' : ' (' . $lang->relatives_acp_inactive . ')';

    $table = new Table();
    $table->construct_header($lang->relatives_acp_col_label);
    $table->construct_header($lang->relatives_acp_col_actions);

    $options = Relatives_Attributes::get_options($gid, false);
    if (empty($options)) {
        $table->construct_cell($lang->relatives_acp_no_options, ['colspan' => 2]);
        $table->construct_row();
    } else {
        foreach ($options as $option) {
            $option_active_note = (int)$option['is_active'] === 1 ? '' : ' (' . $lang->relatives_acp_inactive . ')';
            $table->construct_cell(htmlspecialchars_uni($option['label']) . $option_active_note);
            $edit_link = 'index.php?module=tools-relatives&action=attributes&do=edit_option&id=' . (int)$option['id'] . '&group_id=' . $gid;
            $delete_form = '<form action="index.php?module=tools-relatives&action=attributes&do=delete_option" method="post" style="display:inline">'
                . '<input type="hidden" name="id" value="' . (int)$option['id'] . '" />'
                . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
                . '<input type="submit" value="' . $lang->relatives_acp_delete . '" class="button" onclick="return confirm(\'' . $lang->relatives_acp_confirm_delete . '\');" />'
                . '</form>';
            $table->construct_cell('<a href="' . $edit_link . '">' . $lang->relatives_acp_edit . '</a> | ' . $delete_form);
            $table->construct_row();
        }
    }

    $edit_group_link = 'index.php?module=tools-relatives&action=attributes&do=edit_group&id=' . $gid;
    $add_option_link = 'index.php?module=tools-relatives&action=attributes&do=add_option&group_id=' . $gid;
    $delete_group_form = '<form action="index.php?module=tools-relatives&action=attributes&do=delete_group" method="post" style="display:inline">'
        . '<input type="hidden" name="id" value="' . $gid . '" />'
        . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
        . '<input type="submit" value="' . $lang->relatives_acp_delete . '" class="button" onclick="return confirm(\'' . $lang->relatives_acp_confirm_delete . '\');" />'
        . '</form>';

    $caption = htmlspecialchars_uni($group['label']) . $active_note
        . ' &ndash; <a href="' . $edit_group_link . '">' . $lang->relatives_acp_edit . '</a>'
        . ' | <a href="' . $add_option_link . '">' . $lang->relatives_acp_add_option . '</a>'
        . ' | ' . $delete_group_form;

    $table->output($caption);
}

$page->output_footer();
