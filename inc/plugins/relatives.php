<?php
/**
 * Verwandtschaften & Stammbaum (relatives)
 *
 * Hauptdatei: info()-Block, Lifecycle-Delegation an Relatives_Installer, Hook-
 * Registrierung, Hook-Handler, ACP-Registrierung, Provider-Hooks fuer Jobliste und
 * die oeffentliche Adapter-API relatives_search_persons().
 *
 * Siehe PROJECT_CONTEXT.md fuer Architektur/Tabellen/Hooks/"What NOT to change".
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

// Core-Klassen werden immer geladen (auch vor der Installation -- relatives_install()
// braucht Relatives_Installer bereits beim allerersten Aufruf).
require_once MYBB_ROOT . "inc/plugins/relatives/core/Installer.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/Database.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/RelationshipTypes.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/Attributes.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/TreeBuilder.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/LinkManager.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/SearchApi.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/Migration.php";

// --- info() -------------------------------------------------------------------------

function relatives_info(): array
{
    return [
        "name"          => "Verwandtschaften & Stammbaum",
        "description"   => "Verwaltet pro Charakter Verwandte/verbundene Personen und bietet einen interaktiven, klickbaren Stammbaum mit Account-Verknuepfung.",
        "website"       => "https://github.com/pand0rica/relatives",
        "author"        => "pand0rica",
        "authorsite"    => "https://github.com/pand0rica",
        "version"       => "2.0.0",
        "guid"          => "",
        "codename"      => "relatives",
        "compatibility" => "18*",
    ];
}

// --- Lifecycle (Delegation an Relatives_Installer) -----------------------------------

function relatives_install(): void
{
    Relatives_Installer::install();
}

function relatives_is_installed(): bool
{
    return Relatives_Installer::is_installed();
}

function relatives_uninstall(): void
{
    Relatives_Installer::uninstall();
}

function relatives_activate(): void
{
    Relatives_Installer::activate();
}

function relatives_deactivate(): void
{
    Relatives_Installer::deactivate();
}

// --- Hook-Registrierung ---------------------------------------------------------------

$plugins->add_hook("member_profile_end", "relatives_handle_member_profile");
$plugins->add_hook("usercp_menu", "relatives_handle_usercp_menu");
$plugins->add_hook("datahandler_user_delete_start", "relatives_handle_user_delete");

$plugins->add_hook("admin_tools_menu", "relatives_admin_menu");
$plugins->add_hook("admin_tools_action_handler", "relatives_admin_action");

// Provider-Hooks fuer das optionale Schwester-Plugin "jobliste" (siehe
// PROJECT_CONTEXT.md "Eigene Hooks / API"). Laufen ins Leere, wenn Jobliste nicht
// installiert ist -- reines Provider-Hook-Muster, kein Code-Pfad in relatives haengt
// davon ab.
$plugins->add_hook("jobliste_collect_entities", "relatives_provide_jobliste_entities");
$plugins->add_hook("jobliste_resolve_entity_label", "relatives_resolve_jobliste_entity_label");

// --- Hook-Handler: member_profile_end -------------------------------------------------

/**
 * Rendert die "Gesucht"-Karten (is_wanted=1) auf der Profilseite des jeweiligen
 * Charakters. Responsives CSS statt hartcodierter Theme-tid-Fallunterscheidung.
 */
function relatives_handle_member_profile(): void
{
    global $mybb, $db, $templates, $lang, $memprofile, $relatives_wanted_box;

    if (empty($mybb->settings['relatives_enabled'])) {
        return;
    }
    if (!isset($memprofile['uid'])) {
        return;
    }

    $owner_uid = (int)$memprofile['uid'];
    $persons = Relatives_Database::get_persons_by_owner($owner_uid);
    $wanted = array_filter($persons, static function ($person) {
        return (int)$person['is_wanted'] === 1;
    });

    if (empty($wanted)) {
        return;
    }

    $lang->load('relatives');

    $cards = '';
    foreach ($wanted as $person) {
        $name = htmlspecialchars_uni($person['name']);
        $label = relatives_get_relationship_label($person);
        $label = htmlspecialchars_uni($label);
        $link = '';
        if (!empty($person['wanted_thread_link'])) {
            $safe_url = relatives_sanitize_url((string)$person['wanted_thread_link']);
            if ($safe_url !== '') {
                $link = '<a href="' . htmlspecialchars_uni($safe_url) . '" rel="nofollow">'
                    . htmlspecialchars_uni($lang->relatives_wanted_thread_link_text) . '</a>';
            }
        }
        $description = nl2br(htmlspecialchars_uni((string)$person['description']));

        eval('$relatives_wanted_card = "' . $templates->get('relatives_profile_wanted_card') . '";');
        $cards .= $relatives_wanted_card;
    }

    eval('$relatives_wanted_box = "' . $templates->get('relatives_profile_wanted_box') . '";');
}

/**
 * Liefert das anzuzeigende Verwandtschafts-Label einer Person: entweder aus dem
 * verwalteten relationship_type oder den freien relationship_custom_label.
 */
function relatives_get_relationship_label(array $person): string
{
    global $lang;

    if (!empty($person['relationship_type_id'])) {
        $type = Relatives_RelationshipTypes::get_by_id((int)$person['relationship_type_id']);
        if ($type !== null) {
            return (string)$type['label'];
        }
    }
    if (!empty($person['relationship_custom_label'])) {
        return (string)$person['relationship_custom_label'];
    }

    $lang->load('relatives');
    return $lang->relatives_relationship_unspecified;
}

/**
 * Beschraenkt eine nutzereingegebene URL auf http(s)/relative Pfade (blockt
 * javascript:/data:/vbscript:-Schemas). Gibt einen leeren String zurueck, wenn die URL
 * nicht sicher ist.
 */
function relatives_sanitize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    if (preg_match('#^[a-z0-9_\-./?=&%#]+$#i', $url) && strpos($url, ':') === false) {
        return $url; // relativer Pfad ohne Schema
    }
    return '';
}

// --- Hook-Handler: usercp_menu ---------------------------------------------------------

function relatives_handle_usercp_menu(): void
{
    global $mybb, $templates, $lang, $usercpmenu;

    if (empty($mybb->settings['relatives_enabled'])) {
        return;
    }

    $lang->load('relatives');

    eval('$relatives_usercp_menu = "' . $templates->get('relatives_usercp_menu') . '";');
    $usercpmenu .= $relatives_usercp_menu;
}

// --- Hook-Handler: datahandler_user_delete_start -----------------------------------------

/**
 * Deckt ALLE Loeschwege ab (ACP einzeln/bulk, Merge, Frontend). Loescht alle
 * relatives_persons-Zeilen des geloeschten Users und setzt bei allen Zeilen, die diesen
 * User als linked_uid referenzieren, linked_uid/link_status zurueck.
 */
function relatives_handle_user_delete(&$userhandler): void
{
    global $db;

    if (!isset($userhandler->user['uid'])) {
        return;
    }
    $uid = (int)$userhandler->user['uid'];
    if ($uid <= 0) {
        return;
    }

    $own_persons = Relatives_Database::get_persons_by_owner($uid);
    foreach ($own_persons as $person) {
        Relatives_Database::delete_person((int)$person['id']);
    }

    $linked_persons = Relatives_Database::get_persons_by_linked_uid($uid);
    foreach ($linked_persons as $person) {
        Relatives_Database::update_person((int)$person['id'], [
            'linked_uid'        => null,
            'link_status'       => Relatives_LinkManager::STATUS_NONE,
            'link_requested_by' => null,
            'link_confirmed_at' => null,
        ]);
    }
}

// --- ACP-Registrierung -------------------------------------------------------------------

function relatives_admin_menu(&$sub_menu): void
{
    global $lang;
    $lang->load('relatives', true);

    $sub_menu[] = [
        'id'    => 'relatives',
        'title' => $lang->relatives,
        'link'  => 'index.php?module=tools-relatives',
    ];
}

function relatives_admin_action(&$actions): void
{
    $actions['relatives'] = ['active' => 'relatives', 'file' => 'relatives.php'];
}

// --- Provider-Hooks fuer Jobliste (nur wirksam, wenn Jobliste installiert ist) --------------

/**
 * Vertrag (siehe jobliste-Repo PROJECT_CONTEXT.md): haengt an $args['entities'] Zeilen
 * ['entity_type' => 'relative', 'entity_id' => person_id, 'label' => sanitized name]
 * fuer alle relatives_persons mit owner_uid = $args['uid'] an. Kaputte/fremde Aufrufer
 * werden ignoriert; Escaping bleibt beim Konsumenten (Jobliste).
 */
function relatives_provide_jobliste_entities(&$args): void
{
    global $mybb;

    if (empty($mybb->settings['relatives_enabled'])) {
        return;
    }
    if (!is_array($args) || !isset($args['uid']) || !isset($args['entities']) || !is_array($args['entities'])) {
        return;
    }

    $uid = (int)$args['uid'];
    if ($uid <= 0) {
        return;
    }

    $persons = Relatives_Database::get_persons_by_owner($uid);
    foreach ($persons as $person) {
        $args['entities'][] = [
            'entity_type' => 'relative',
            'entity_id'   => (int)$person['id'],
            'label'       => (string)$person['name'],
        ];
    }
}

function relatives_resolve_jobliste_entity_label(&$args): void
{
    global $mybb;

    if (empty($mybb->settings['relatives_enabled'])) {
        return;
    }
    if (!is_array($args) || !isset($args['entity_type']) || $args['entity_type'] !== 'relative') {
        return;
    }
    if (!isset($args['entity_id'])) {
        return;
    }

    $person = Relatives_Database::get_person((int)$args['entity_id']);
    if ($person !== null) {
        $args['label'] = (string)$person['name'];
    }
}

// --- Oeffentliche Adapter-API --------------------------------------------------------------

/**
 * Einzige erlaubte Zugriffsart auf relatives_*-Daten fuer Fremdplugins (z.B.
 * "anschluss suchmaschine"). Siehe PROJECT_CONTEXT.md fuer die Filter-Keys.
 */
function relatives_search_persons(array $filters): array
{
    return Relatives_SearchApi::search($filters);
}
