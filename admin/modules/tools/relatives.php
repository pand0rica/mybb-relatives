<?php
/**
 * Verwandtschaften & Stammbaum - ACP-Tools-Modul
 *
 * Dispatcher fuer die vier Tabs: Verwandtschaftsgrade, Attribut-Gruppen, Herkunft,
 * Migration. Tab-Handler liegen unter inc/plugins/relatives/modules/admin/.
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

$page->add_breadcrumb_item($lang->relatives, "index.php?module=tools-relatives");

/**
 * Gemeinsame Tab-Navigation fuer alle vier ACP-Seiten (siehe globale CLAUDE.md
 * "ACP Page Structure").
 */
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

    return '<form id="' . $form_id . '" action="' . $post_url . '" method="post" style="display:none">'
        . '<input type="hidden" name="id" value="' . $id . '" />'
        . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
        . '</form>'
        . '<a href="javascript:void(0)" onclick="if(confirm(\'' . $lang->relatives_acp_confirm_delete . '\')){document.getElementById(\'' . $form_id . '\').submit();}return false;">'
        . $lang->relatives_acp_delete . '</a>';
}

$action = $mybb->get_input('action');
if ($action === '') {
    $action = 'relationship-types';
}

$allowed_actions = ['relationship-types', 'attributes', 'origin', 'migration', 'graph'];
if (!in_array($action, $allowed_actions, true)) {
    $action = 'relationship-types';
}

require_once MYBB_ROOT . 'inc/plugins/relatives/modules/admin/' . str_replace('-', '_', $action) . '.php';
