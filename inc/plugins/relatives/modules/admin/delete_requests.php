<?php
/**
 * relatives ACP - Tab "Loeschantraege"
 *
 * Offene Loeschantraege fuer NPCs (Relatives_DeleteRequests). Es gibt keine Frist; haengt
 * ein Antrag (z.B. an inaktiven Accounts), entscheidet hier das Team: sofort loeschen
 * oder verwerfen (dann bleibt die Person, die antragstellende Person wird ausgetragen).
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

$base_url = 'index.php?module=tools-relatives&action=delete-requests';

if ($mybb->request_method === 'post') {
    verify_post_check($mybb->get_input('my_post_key'));

    $request_id = $mybb->get_input('id', MyBB::INPUT_INT);
    $decision = $mybb->get_input('decision');
    if (!in_array($decision, ['delete', 'discard'], true)
        || !Relatives_DeleteRequests::force($request_id, $decision === 'delete')) {
        flash_message($lang->relatives_acp_delete_request_error, 'error');
        admin_redirect($base_url);
    }

    log_admin_action(['id' => $request_id, 'decision' => $decision]);
    flash_message(
        $decision === 'delete' ? $lang->relatives_acp_delete_request_deleted : $lang->relatives_acp_delete_request_discarded,
        'success'
    );
    admin_redirect($base_url);
}

/** Row action as a text link that submits a hidden POST form after a confirm (see relatives_acp_head()). */
function relatives_acp_delete_request_action(int $request_id, string $decision, string $label, string $confirm): string
{
    global $mybb;

    $form_id = 'relatives_dr_' . $decision . '_' . $request_id;
    return '<form id="' . $form_id . '" action="index.php?module=tools-relatives&amp;action=delete-requests" method="post" hidden>'
        . '<input type="hidden" name="id" value="' . $request_id . '" />'
        . '<input type="hidden" name="decision" value="' . htmlspecialchars_uni($decision) . '" />'
        . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
        . '</form>'
        . '<a href="#" data-relatives-delete-form="' . $form_id . '"'
        . ' data-relatives-confirm="' . htmlspecialchars_uni($confirm) . '">'
        . htmlspecialchars_uni($label) . '</a>';
}

$page->add_breadcrumb_item($lang->relatives_tab_delete_requests, $base_url);
$page->output_header($lang->relatives_acp_title);
relatives_acp_nav_tabs('delete-requests');

$requests = Relatives_DeleteRequests::get_all_pending();

$table = new Table();
$table->construct_header($lang->relatives_acp_delete_request_person);
$table->construct_header($lang->relatives_acp_delete_request_by);
$table->construct_header($lang->relatives_acp_delete_request_date);
$table->construct_header($lang->relatives_acp_delete_request_state, ['class' => 'align_center']);
$table->construct_header($lang->relatives_acp_delete_request_waiting);
$table->construct_header($lang->relatives_acp_col_actions, ['class' => 'align_center']);

if (!$requests) {
    $table->construct_cell($lang->relatives_acp_delete_requests_none, ['colspan' => 6]);
    $table->construct_row();
}

foreach ($requests as $request) {
    $request_id = (int)$request['id'];
    $requester = get_user((int)$request['requested_by']);
    $requester_html = $requester
        ? build_profile_link(htmlspecialchars_uni($requester['username']), (int)$requester['uid'], '_blank')
        : '#' . (int)$request['requested_by'];

    $waiting = [];
    foreach (Relatives_DeleteRequests::get_open_voter_uids($request_id) as $uid) {
        $user = get_user($uid);
        $waiting[] = $user ? build_profile_link(htmlspecialchars_uni($user['username']), $uid, '_blank') : '#' . $uid;
    }

    $confirmed = (int)$request['confirmed'];
    $total = $confirmed + (int)$request['open'];

    $table->construct_cell(htmlspecialchars_uni($request['person_name']));
    $table->construct_cell($requester_html);
    $table->construct_cell(my_date('normal', (int)$request['created_at']));
    $table->construct_cell($confirmed . ' / ' . $total, ['class' => 'align_center']);
    $table->construct_cell($waiting ? implode(', ', $waiting) : '-');
    $table->construct_cell(
        relatives_acp_delete_request_action($request_id, 'delete', $lang->relatives_acp_delete_request_do_delete, $lang->relatives_acp_delete_request_confirm_delete)
        . ' | '
        . relatives_acp_delete_request_action($request_id, 'discard', $lang->relatives_acp_delete_request_do_discard, $lang->relatives_acp_delete_request_confirm_discard),
        ['class' => 'align_center']
    );
    $table->construct_row();
}

$table->output($lang->relatives_tab_delete_requests);

$page->output_footer();
