<?php
/**
 * Verwandtschaften & Stammbaum - ACP-Tools-Modul
 *
 * Dispatcher fuer die Tabs: Verwandtschaftsgrade, Attribut-Gruppen, Herkunft,
 * Migration, Geflecht, Loeschantraege. Tab-Handler liegen unter inc/plugins/relatives/modules/admin/.
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

$lang->load("relatives");

require_once MYBB_ROOT . 'inc/plugins/relatives/core/RelationshipTypes.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Attributes.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Database.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Migration.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Families.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Kinship.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Graph.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Alerts.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/DeleteRequests.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/RelationRequests.php';

$page->add_breadcrumb_item($lang->relatives, "index.php?module=tools-relatives");

/**
 * Gemeinsame Tab-Navigation fuer alle vier ACP-Seiten (siehe globale CLAUDE.md
 * "ACP Page Structure").
 */

/**
 * Schreibende ACP-Aktionen: verify_post_check() bricht im ACP nicht ab,
 * daher Rückgabewert prüfen (debugging.md #23).
 */
function relatives_acp_verify_key(): void
{
    global $mybb, $lang;

    if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
        flash_message($lang->invalid_post_verify_key2, 'error');
        admin_redirect('index.php?module=tools-relatives');
    }
}

function relatives_acp_nav_tabs(string $active): void
{
    global $page, $lang;

    $tabs = [
        'relationship-types' => [
            'title'       => $lang->relatives_tab_relationship_types,
            'link'        => 'index.php?module=tools-relatives&action=relationship-types',
            'description' => $lang->relatives_tab_desc_relationship_types,
        ],
        'attributes' => [
            'title'       => $lang->relatives_tab_attributes,
            'link'        => 'index.php?module=tools-relatives&action=attributes',
            'description' => $lang->relatives_tab_desc_attributes,
        ],
        'origin' => [
            'title'       => $lang->relatives_tab_origin,
            'link'        => 'index.php?module=tools-relatives&action=origin',
            'description' => $lang->relatives_tab_desc_origin,
        ],
        'migration' => [
            'title'       => $lang->relatives_tab_migration,
            'link'        => 'index.php?module=tools-relatives&action=migration',
            'description' => $lang->relatives_tab_desc_migration,
        ],
        'graph' => [
            'title'       => $lang->relatives_tab_graph,
            'link'        => 'index.php?module=tools-relatives&action=graph',
            'description' => $lang->relatives_tab_desc_graph,
        ],
        'delete-requests' => [
            'title'       => $lang->relatives_tab_delete_requests,
            'link'        => 'index.php?module=tools-relatives&action=delete-requests',
            'description' => $lang->relatives_tab_desc_delete_requests,
        ],
    ];

    $page->output_nav_tabs($tabs, $active);
}

/**
 * Renders a delete action as a plain text link (not a button) that submits a hidden
 * POST form on click, so all ACP row actions (Bearbeiten/Loeschen) look uniform while
 * delete keeps its POST + verify_post_check() CSRF protection.
 */
function relatives_acp_delete_link(string $form_id, string $post_url, int $id): string
{
    global $mybb, $lang;

    return '<form id="' . $form_id . '" action="' . $post_url . '" method="post" hidden>'
        . '<input type="hidden" name="id" value="' . $id . '" />'
        . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
        . '</form>'
        . '<a href="#" data-relatives-delete-form="' . htmlspecialchars_uni($form_id) . '"'
        . ' data-relatives-confirm="' . htmlspecialchars_uni($lang->relatives_acp_confirm_delete) . '">'
        . $lang->relatives_acp_delete . '</a>';
}

/**
 * Shared ACP styles and the delete confirmation, emitted once in the page head. The ACP
 * has no plugin stylesheet mechanism, so this replaces scattered inline style/onclick
 * attributes. Must run before a tab handler calls $page->output_header().
 */
function relatives_acp_head(): string
{
    return '<style>'
        . '.relatives_acp_action_form{margin:0}'
        . '.relatives_acp_action_desc{margin:6px 0 0}'
        . '.relatives_acp_inline_form{display:inline;margin:0}'
        . '.relatives_acp_missing_neutral{font-weight:bold}'
        . '</style>'
        . '<script>'
        . 'document.addEventListener("click",function(e){'
        . 'var a=e.target.closest?e.target.closest("[data-relatives-delete-form]"):null;'
        . 'if(!a){return;}e.preventDefault();'
        . 'var f=document.getElementById(a.getAttribute("data-relatives-delete-form"));'
        . 'if(f&&window.confirm(a.getAttribute("data-relatives-confirm"))){f.submit();}'
        . '});'
        . '</script>';
}
$page->extra_header .= relatives_acp_head();

$action = $mybb->get_input('action');
if ($action === '') {
    $action = 'relationship-types';
}

$allowed_actions = ['relationship-types', 'attributes', 'origin', 'migration', 'graph', 'delete-requests'];
if (!in_array($action, $allowed_actions, true)) {
    $action = 'relationship-types';
}

require_once MYBB_ROOT . 'inc/plugins/relatives/modules/admin/' . str_replace('-', '_', $action) . '.php';
