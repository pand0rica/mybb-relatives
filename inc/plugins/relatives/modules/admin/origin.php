<?php
/**
 * relatives ACP - Tab "Herkunft"
 *
 * Kurzform der beiden Herkunfts-Settings (relatives_origin_mode,
 * relatives_origin_profilefield_id). Validiert beim Speichern, dass eine angegebene
 * Profilfeld-fid tatsaechlich existiert.
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

if ($mybb->request_method === 'post') {
    verify_post_check($mybb->get_input('my_post_key'));

    $mode = $mybb->get_input('relatives_origin_mode');
    if (!in_array($mode, ['freetext', 'profilefield'], true)) {
        $mode = 'freetext';
    }
    $fid = $mybb->get_input('relatives_origin_profilefield_id', MyBB::INPUT_INT);

    if ($mode === 'profilefield' && $fid > 0 && !Relatives_Database::origin_profilefield_exists($fid)) {
        flash_message($lang->relatives_acp_error_invalid_fid, 'error');
        admin_redirect('index.php?module=tools-relatives&action=origin');
    }

    $db->update_query('settings', ['value' => $db->escape_string($mode)], "name = 'relatives_origin_mode'");
    $db->update_query('settings', ['value' => (int)$fid], "name = 'relatives_origin_profilefield_id'");
    rebuild_settings();

    flash_message($lang->relatives_acp_saved, 'success');
    admin_redirect('index.php?module=tools-relatives&action=origin');
}

$page->add_breadcrumb_item($lang->relatives_tab_origin, 'index.php?module=tools-relatives&action=origin');
$page->output_header($lang->relatives_acp_title);
relatives_acp_nav_tabs('origin');

echo '<p>' . $lang->relatives_acp_origin_intro . '</p>';

$form = new Form('index.php?module=tools-relatives&action=origin', 'post');
$form_container = new FormContainer($lang->relatives_tab_origin);
$form_container->output_row(
    $lang->relatives_field_origin,
    '',
    $form->generate_select_box('relatives_origin_mode', [
        'freetext'     => $lang->relatives_acp_origin_mode_freetext,
        'profilefield' => $lang->relatives_acp_origin_mode_profilefield,
    ], $mybb->settings['relatives_origin_mode'] ?? 'freetext', ['id' => 'relatives_origin_mode']),
    'relatives_origin_mode'
);
$form_container->output_row(
    $lang->relatives_acp_field_origin_fid,
    $lang->relatives_acp_field_origin_fid_desc,
    $form->generate_numeric_field('relatives_origin_profilefield_id', $mybb->settings['relatives_origin_profilefield_id'] ?? 0, ['id' => 'relatives_origin_profilefield_id']),
    'relatives_origin_profilefield_id'
);
$form_container->end();

$buttons[] = $form->generate_submit_button($lang->relatives_acp_saved);
$form->output_submit_wrapper($buttons);
$form->end();

$page->output_footer();
