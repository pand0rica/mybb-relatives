<?php
/**
 * Verwandtschaften & Stammbaum - Frontend-Root
 *
 * Actions: list, add, edit, delete (POST), tree, link_request/link_accept/link_decline
 * (POST). Siehe PROJECT_CONTEXT.md "Interaktiver Stammbaum" und "Konto-Verknuepfung".
 */

define('IN_MYBB', 1);
define('THIS_SCRIPT', 'relatives.php');

require_once './global.php';
require_once MYBB_ROOT . 'inc/functions_user.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Database.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/RelationshipTypes.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Attributes.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/TreeBuilder.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/LinkManager.php';

$lang->load('relatives');

if (empty($mybb->settings['relatives_enabled'])) {
    error($lang->relatives_disabled);
}
if ((int)$mybb->user['uid'] <= 0) {
    error_no_permission();
}

$my_uid = (int)$mybb->user['uid'];
$action = $mybb->get_input('action');

// Baut $usercpnav (die UserCP-Sidebar, inkl. unseres eigenen usercp_menu-Eintrags) fuer
// die Seitentemplates -- dieselbe Core-Funktion, die usercp.php selbst aufruft.
usercp_menu();

add_breadcrumb($lang->relatives, 'relatives.php');

// --- kleine Ausgabe-Helfer ------------------------------------------------------------

function relatives_json_response(array $data): void
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}

function relatives_error_response(string $message): void
{
    if (isset($GLOBALS['mybb']) && $GLOBALS['mybb']->get_input('ajax')) {
        relatives_json_response(['success' => false, 'error' => $message]);
    }
    global $lang;
    error($message);
}

// --- action: delete (POST) -------------------------------------------------------------

if ($action === 'delete') {
    verify_post_check($mybb->get_input('my_post_key'));
    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    if (!Relatives_Database::is_owner($id, $my_uid)) {
        error_no_permission();
    }
    Relatives_Database::delete_person($id);
    redirect('relatives.php?action=list', $lang->relatives_deleted);
}

// --- action: add / edit (GET zeigt Formular, POST speichert) ----------------------------

if ($action === 'add' || $action === 'edit') {
    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    $is_edit = ($action === 'edit');
    $existing = null;

    if ($is_edit) {
        $existing = Relatives_Database::get_person($id);
        if ($existing === null || (int)$existing['owner_uid'] !== $my_uid) {
            error_no_permission();
        }
    }

    if ($mybb->request_method === 'post') {
        verify_post_check($mybb->get_input('my_post_key'));

        $name = trim($mybb->get_input('name'));
        $gender = $mybb->get_input('gender', MyBB::INPUT_INT);
        if (!in_array($gender, [-1, 0, 1, 2], true)) {
            $gender = -1;
        }
        $relationship_type_id = $mybb->get_input('relationship_type_id', MyBB::INPUT_INT);
        $relationship_custom_label = trim($mybb->get_input('relationship_custom_label'));
        $age_raw = trim($mybb->get_input('age'));
        $origin = trim($mybb->get_input('origin'));
        $avatar = trim($mybb->get_input('avatar'));
        $description = $mybb->get_input('description');
        $is_wanted = $mybb->get_input('is_wanted', MyBB::INPUT_INT) ? 1 : 0;
        $wanted_thread_link = trim($mybb->get_input('wanted_thread_link'));
        $attribute_option_ids = $mybb->get_input('attribute_option_ids', MyBB::INPUT_ARRAY);
        if (!is_array($attribute_option_ids)) {
            $attribute_option_ids = [];
        }

        if ($name === '') {
            relatives_error_response($lang->relatives_error_name_required);
        }
        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }

        if (!$is_edit) {
            $max = (int)($mybb->settings['relatives_max_persons_per_user'] ?? 0);
            if ($max > 0 && Relatives_Database::count_persons_by_owner($my_uid) >= $max) {
                relatives_error_response($lang->relatives_error_limit_reached);
            }
        }

        $relationship_type_id_final = null;
        $relationship_custom_label_final = null;
        if ($relationship_type_id > 0) {
            $type = Relatives_RelationshipTypes::get_by_id($relationship_type_id);
            if ($type === null || (int)$type['is_active'] !== 1) {
                relatives_error_response($lang->relatives_error_invalid_relationship_type);
            }
            $expected_gender = Relatives_RelationshipTypes::map_person_gender_to_label_gender($gender);
            if ((int)$type['gender'] !== $expected_gender) {
                relatives_error_response($lang->relatives_error_relationship_gender_mismatch);
            }
            $relationship_type_id_final = $relationship_type_id;
        } else {
            if ($relationship_custom_label === '') {
                relatives_error_response($lang->relatives_error_custom_label_required);
            }
            $relationship_custom_label_final = mb_substr($relationship_custom_label, 0, 255);
        }

        $age = null;
        if ($age_raw !== '') {
            $age = (int)$age_raw;
            if ($age < 0 || $age > 999) {
                relatives_error_response($lang->relatives_error_invalid_age);
            }
        }

        if ($origin !== '' && isset($mybb->settings['relatives_origin_mode']) && $mybb->settings['relatives_origin_mode'] === 'profilefield') {
            $origin_options = Relatives_Database::get_origin_options();
            if (!empty($origin_options) && !in_array($origin, $origin_options, true)) {
                relatives_error_response($lang->relatives_error_invalid_origin);
            }
        }

        $avatar_final = $avatar !== '' ? relatives_sanitize_url($avatar) : null;
        $wanted_thread_link_final = $wanted_thread_link !== '' ? relatives_sanitize_url($wanted_thread_link) : null;

        $data = [
            'owner_uid'                 => $my_uid,
            'name'                      => $name,
            'gender'                    => $gender,
            'relationship_type_id'      => $relationship_type_id_final,
            'relationship_custom_label' => $relationship_custom_label_final,
            'age'                       => $age,
            'origin'                    => $origin !== '' ? $origin : null,
            'avatar'                    => $avatar_final,
            'description'               => $description !== '' ? $description : null,
            'is_wanted'                 => $is_wanted,
            'wanted_thread_link'        => $wanted_thread_link_final,
        ];

        if ($is_edit) {
            Relatives_Database::update_person($id, $data);
            $person_id = $id;
        } else {
            $person_id = Relatives_Database::create_person($data);
        }
        Relatives_Attributes::set_person_attributes($person_id, $attribute_option_ids);

        if ($mybb->get_input('ajax')) {
            relatives_json_response(['success' => true, 'id' => $person_id]);
        }
        redirect('relatives.php?action=list', $is_edit ? $lang->relatives_saved : $lang->relatives_added);
    }

    // GET: Formular rendern
    add_breadcrumb($is_edit ? $lang->relatives_edit_person_title : $lang->relatives_add_person, 'relatives.php?action=' . $action);

    $relatives_form_title = $is_edit ? $lang->relatives_edit_person_title : $lang->relatives_add_person;
    $relatives_form_action = $is_edit ? 'edit' : 'add';
    $relatives_form_id = $is_edit ? (int)$existing['id'] : 0;
    $relatives_form_name = htmlspecialchars_uni($is_edit ? $existing['name'] : '');
    $relatives_form_custom_label = htmlspecialchars_uni($is_edit ? (string)$existing['relationship_custom_label'] : '');
    $relatives_form_age = $is_edit && $existing['age'] !== null ? (int)$existing['age'] : '';
    $relatives_form_avatar = htmlspecialchars_uni($is_edit ? (string)$existing['avatar'] : '');
    $relatives_form_description = htmlspecialchars_uni($is_edit ? (string)$existing['description'] : '');
    $relatives_form_is_wanted_checked = ($is_edit && (int)$existing['is_wanted'] === 1) ? 'checked="checked"' : '';
    $relatives_form_wanted_thread_link = htmlspecialchars_uni($is_edit ? (string)$existing['wanted_thread_link'] : '');
    $relatives_form_relationship_type_id = $is_edit && $existing['relationship_type_id'] !== null ? (int)$existing['relationship_type_id'] : '';

    $current_gender = $is_edit ? (int)$existing['gender'] : -1;
    $gender_labels = [
        -1 => $lang->relatives_gender_any,
        0  => $lang->relatives_gender_male,
        1  => $lang->relatives_gender_female,
        2  => $lang->relatives_gender_diverse,
    ];
    $relatives_form_gender_options = '';
    foreach ($gender_labels as $value => $label) {
        $selected = ($value === $current_gender) ? ' selected="selected"' : '';
        $relatives_form_gender_options .= '<option value="' . $value . '"' . $selected . '>' . htmlspecialchars_uni($label) . '</option>';
    }

    if (isset($mybb->settings['relatives_origin_mode']) && $mybb->settings['relatives_origin_mode'] === 'profilefield') {
        $origin_options = Relatives_Database::get_origin_options();
        if (!empty($origin_options)) {
            $current_origin = $is_edit ? (string)$existing['origin'] : '';
            $relatives_form_origin_field = '<select id="relatives_origin" name="origin"><option value="">' . htmlspecialchars_uni($lang->relatives_origin_none) . '</option>';
            foreach ($origin_options as $option) {
                $selected = ($option === $current_origin) ? ' selected="selected"' : '';
                $relatives_form_origin_field .= '<option value="' . htmlspecialchars_uni($option) . '"' . $selected . '>' . htmlspecialchars_uni($option) . '</option>';
            }
            $relatives_form_origin_field .= '</select>';
        } else {
            $relatives_form_origin_field = '<input type="text" id="relatives_origin" name="origin" maxlength="255" value="' . htmlspecialchars_uni($is_edit ? (string)$existing['origin'] : '') . '" />';
        }
    } else {
        $relatives_form_origin_field = '<input type="text" id="relatives_origin" name="origin" maxlength="255" value="' . htmlspecialchars_uni($is_edit ? (string)$existing['origin'] : '') . '" />';
    }

    $selected_option_ids = [];
    if ($is_edit) {
        foreach (Relatives_Attributes::get_person_attributes($relatives_form_id) as $pa) {
            $selected_option_ids[] = (int)$pa['option_id'];
        }
    }
    $relatives_form_attribute_groups = '';
    foreach (Relatives_Attributes::get_options_grouped(true) as $entry) {
        $relatives_form_attribute_groups .= '<fieldset><legend>' . htmlspecialchars_uni($entry['group']['label']) . '</legend>';
        foreach ($entry['options'] as $option) {
            $checked = in_array((int)$option['id'], $selected_option_ids, true) ? ' checked="checked"' : '';
            $relatives_form_attribute_groups .= '<label style="display:inline-block;margin-right:8px;"><input type="checkbox" name="attribute_option_ids[]" value="' . (int)$option['id'] . '"' . $checked . ' /> ' . htmlspecialchars_uni($option['label']) . '</label>';
        }
        $relatives_form_attribute_groups .= '</fieldset>';
    }

    $relatives_form_link_section = '';
    if ($is_edit) {
        $relatives_form_link_section = relatives_render_link_section($existing);
    }

    $relatives_relationship_types_json = htmlspecialchars_uni(json_encode(Relatives_RelationshipTypes::get_active_json_payload()));

    eval('$page = "' . $templates->get('relatives_form') . '";');
    output_page($page);
}

// --- action: link_request / link_accept / link_decline (POST) --------------------------

if ($action === 'link_request') {
    verify_post_check($mybb->get_input('my_post_key'));
    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    $target_username = trim($mybb->get_input('target_username'));

    if (!Relatives_Database::is_owner($id, $my_uid)) {
        error_no_permission();
    }
    if ($target_username === '') {
        redirect('relatives.php?action=edit&id=' . $id, $lang->relatives_error_username_required);
    }

    $target = get_user_by_username($target_username);
    if (!$target || (int)$target['uid'] <= 0) {
        redirect('relatives.php?action=edit&id=' . $id, $lang->relatives_error_user_not_found);
    }

    $success = Relatives_LinkManager::request_link($id, $my_uid, (int)$target['uid']);
    redirect('relatives.php?action=edit&id=' . $id, $success ? $lang->relatives_link_requested : $lang->relatives_error_generic);
}

if ($action === 'link_accept' || $action === 'link_decline') {
    verify_post_check($mybb->get_input('my_post_key'));
    $id = $mybb->get_input('id', MyBB::INPUT_INT);

    $success = $action === 'link_accept'
        ? Relatives_LinkManager::accept_link($id, $my_uid)
        : Relatives_LinkManager::decline_link($id, $my_uid);

    redirect('relatives.php?action=list', $success ? $lang->relatives_link_updated : $lang->relatives_error_generic);
}

// --- action: tree ------------------------------------------------------------------------

if ($action === 'tree') {
    $uid = $mybb->get_input('uid', MyBB::INPUT_INT);
    if ($uid <= 0) {
        $uid = $my_uid;
    }

    if ($mybb->get_input('ajax')) {
        relatives_json_response(Relatives_TreeBuilder::build($uid));
    }

    $target_user = ($uid === $my_uid) ? $mybb->user : get_user($uid);
    if (!$target_user || !isset($target_user['uid'])) {
        error($lang->relatives_error_user_not_found);
    }

    add_breadcrumb($lang->relatives_view_tree, 'relatives.php?action=tree');

    $relatives_tree_title = sprintf($lang->relatives_tree_title_for, htmlspecialchars_uni($target_user['username']));
    $relatives_tree_json = htmlspecialchars_uni(json_encode(Relatives_TreeBuilder::build($uid)));
    $relatives_relationship_types_json = htmlspecialchars_uni(json_encode(Relatives_RelationshipTypes::get_active_json_payload()));
    $relatives_attribute_groups_json = htmlspecialchars_uni(json_encode(Relatives_Attributes::get_options_grouped(true)));
    $relatives_tree_config_json = htmlspecialchars_uni(json_encode([
        'rootUid'   => $uid,
        'viewerUid' => $my_uid,
        'postCode'  => $mybb->post_code,
    ]));
    $relatives_tree_lang_json = htmlspecialchars_uni(json_encode([
        'add_person'               => $lang->relatives_add_person,
        'edit_person'               => $lang->relatives_edit_person_title,
        'field_name'                => $lang->relatives_field_name,
        'field_gender'               => $lang->relatives_field_gender,
        'field_relationship'        => $lang->relatives_field_relationship,
        'field_relationship_custom' => $lang->relatives_field_relationship_custom,
        'field_age'                  => $lang->relatives_field_age,
        'field_origin'               => $lang->relatives_field_origin,
        'field_description'         => $lang->relatives_field_description,
        'field_attributes'          => $lang->relatives_field_attributes,
        'relationship_other'        => $lang->relatives_relationship_other,
        'relationship_unspecified'  => $lang->relatives_relationship_unspecified,
        'gender_any'                 => $lang->relatives_gender_any,
        'gender_male'                => $lang->relatives_gender_male,
        'gender_female'              => $lang->relatives_gender_female,
        'gender_diverse'             => $lang->relatives_gender_diverse,
        'save'                        => $lang->relatives_save,
        'save_failed'                => $lang->relatives_error_generic,
    ]));

    eval('$page = "' . $templates->get('relatives_tree') . '";');
    output_page($page);
}

// --- action: list (Default) ---------------------------------------------------------------

$persons = Relatives_Database::get_persons_by_owner($my_uid);
$relatives_rows = '';
foreach ($persons as $person) {
    $relatives_row_id = (int)$person['id'];
    $relatives_row_name = htmlspecialchars_uni($person['name']);
    $relatives_row_relationship = htmlspecialchars_uni(relatives_get_relationship_label($person));
    $relatives_row_age = $person['age'] !== null ? (int)$person['age'] : '-';
    $relatives_row_wanted_badge = (int)$person['is_wanted'] === 1
        ? '<span class="relatives_badge relatives_badge_wanted">' . htmlspecialchars_uni($lang->relatives_badge_wanted) . '</span>'
        : '';
    $relatives_row_link_status = '';
    if ((int)$person['link_status'] === 1) {
        $relatives_row_link_status = '<span class="relatives_badge relatives_badge_link_requested">' . htmlspecialchars_uni($lang->relatives_badge_link_requested) . '</span>';
    } elseif ((int)$person['link_status'] === 2) {
        $relatives_row_link_status = '<span class="relatives_badge relatives_badge_link_confirmed">' . htmlspecialchars_uni($lang->relatives_badge_link_confirmed) . '</span>';
    }

    eval('$relatives_row = "' . $templates->get('relatives_list_row') . '";');
    $relatives_rows .= $relatives_row;
}

$relatives_empty_notice = empty($persons) ? '<p>' . htmlspecialchars_uni($lang->relatives_no_persons_yet) . '</p>' : '';

$relatives_link_requests_box = '';
$pending = Relatives_Database::get_persons_by_linked_uid($my_uid);
$pending = array_filter($pending, static function ($person) {
    return (int)$person['link_status'] === 1;
});
if (!empty($pending)) {
    $relatives_link_requests_rows = '';
    foreach ($pending as $person) {
        $relatives_lr_id = (int)$person['id'];
        eval('$relatives_lr_row = "' . $templates->get('relatives_link_requests_row') . '";');
        $relatives_link_requests_rows .= $relatives_lr_row;
    }
    eval('$relatives_link_requests_box = "' . $templates->get('relatives_link_requests') . '";');
}

add_breadcrumb($lang->relatives_my_persons_title, 'relatives.php?action=list');

eval('$page = "' . $templates->get('relatives_list') . '";');
output_page($page);

// --- lokale Hilfsfunktionen (nur fuer diese Datei) -----------------------------------------

/**
 * Rendert den "Mit Account verknuepfen"-Abschnitt am Ende des Bearbeiten-Formulars.
 */
function relatives_render_link_section(array $person): string
{
    global $lang, $mybb;

    $id = (int)$person['id'];
    $status = (int)$person['link_status'];

    if ($status === 2) {
        $linked_user = get_user((int)$person['linked_uid']);
        $username = $linked_user ? htmlspecialchars_uni($linked_user['username']) : '#' . (int)$person['linked_uid'];
        return '<div class="relatives_field"><p>' . htmlspecialchars_uni($lang->relatives_link_confirmed_with) . ' ' . $username . '</p></div>';
    }

    if ($status === 1) {
        return '<div class="relatives_field"><p>' . htmlspecialchars_uni($lang->relatives_link_pending) . '</p></div>';
    }

    return '<div class="relatives_field"><form action="relatives.php?action=link_request" method="post">'
        . '<label for="relatives_target_username">' . htmlspecialchars_uni($lang->relatives_field_link_username) . '</label>'
        . '<input type="text" id="relatives_target_username" name="target_username" maxlength="255" />'
        . '<input type="hidden" name="id" value="' . $id . '" />'
        . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '" />'
        . '<input type="submit" class="button" value="' . htmlspecialchars_uni($lang->relatives_link_send_request) . '" />'
        . '</form></div>';
}
