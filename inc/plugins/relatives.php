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
require_once MYBB_ROOT . "inc/plugins/relatives/core/Text.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/Installer.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/Database.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/RelationshipTypes.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/Attributes.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/Families.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/Kinship.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/Graph.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/TreeBuilder.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/TreeLayout.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/TreePlaceholders.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/LinkManager.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/Alerts.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/DeleteRequests.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/RelationRequests.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/SearchApi.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/Migration.php";
require_once MYBB_ROOT . "inc/plugins/relatives/core/StructureInference.php";

// --- info() -------------------------------------------------------------------------

function relatives_info(): array
{
    return [
        "name"          => "Verwandtschaften & Stammbaum",
        "description"   => "Verwaltet pro Charakter Verwandte/verbundene Personen und bietet einen interaktiven, klickbaren Stammbaum mit Account-Verknuepfung.",
        "website"       => "https://github.com/pand0rica/relatives",
        "author"        => "pand0rica",
        "authorsite"    => "https://github.com/pand0rica",
        "version"       => "2.2.0",
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

// Stammbaum-Link mit dem Seiten-Templatecache laden statt je eine Query beim ersten get().
if (defined('THIS_SCRIPT') && in_array(THIS_SCRIPT, ['member.php', 'memberlist.php', 'showthread.php', 'newreply.php', 'editpost.php'], true)) {
    global $templatelist;
    $templatelist = (isset($templatelist) && $templatelist !== '' ? $templatelist . ',' : '') . 'relatives_tree_link';
    if (THIS_SCRIPT === 'member.php') {
        $templatelist .= ',relatives_profile_wanted_box,relatives_profile_wanted_card,'
            . 'relatives_profile_wanted_initial,relatives_profile_wanted_details,relatives_profile_wanted_link';
    }
}

// --- Hook-Registrierung ---------------------------------------------------------------

$plugins->add_hook("member_profile_end", "relatives_handle_member_profile");
$plugins->add_hook("postbit", "relatives_handle_postbit");
$plugins->add_hook("postbit_prev", "relatives_handle_postbit");
$plugins->add_hook("memberlist_user", "relatives_handle_memberlist_user");
$plugins->add_hook("usercp_menu", "relatives_handle_usercp_menu");
$plugins->add_hook("datahandler_user_delete_start", "relatives_handle_user_delete");
// MyAlerts-Formatter (optional): global_start fuer die Alert-Seite, xmlhttp fuers Glocken-Dropdown.
$plugins->add_hook("global_start", "relatives_register_alert_formatter");
$plugins->add_hook("xmlhttp", "relatives_register_alert_formatter");

$plugins->add_hook("admin_tools_menu", "relatives_admin_menu");
$plugins->add_hook("admin_tools_action_handler", "relatives_admin_action");
$plugins->add_hook("admin_tools_permissions", "relatives_admin_permissions");
$plugins->add_hook("admin_config_settings_begin", "relatives_admin_settings_lang");

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
    global $mybb, $db, $templates, $lang, $plugins, $memprofile, $relatives_wanted_box, $relatives_tree_link;

    $relatives_tree_link = '';
    if (empty($mybb->settings['relatives_enabled'])) {
        return;
    }
    if (!isset($memprofile['uid'])) {
        return;
    }

    if (!empty($mybb->settings['relatives_tree_link_profile'])) {
        $relatives_tree_link = relatives_tree_link_html((int)$memprofile['uid'], (string)$memprofile['username']);
    }

    $relatives_wanted_box = '';
    $owner_uid = (int)$memprofile['uid'];
    // Nur Verwandte (freie Bezeichnungen stehen nicht im Stammbaum, siehe
    // Relatives_TreeBuilder::is_non_kin()). Nicht-verwandte Gesuche haengen andere
    // Plugins ueber den Hook relatives_profile_wanted_cards an.
    $wanted = isset(relatives_uids_with_tree()[$owner_uid]) ? relatives_profile_wanted_persons($owner_uid) : [];

    $lang->load('relatives');

    $cards = '';
    foreach ($wanted as $person) {
        $name = htmlspecialchars_uni($person['name']);

        // Die Avatarperson wird nicht mehr angezeigt -- die Karte zeigt den Anfangsbuchstaben.
        $initial = htmlspecialchars_uni(my_strtoupper(my_substr(trim((string)$person['name']), 0, 1)));
        eval('$avatar = "' . $templates->get('relatives_profile_wanted_initial') . '";');

        $meta_parts = [];
        if ($person['label'] !== '') {
            $meta_parts[] = htmlspecialchars_uni($person['label']);
        }
        if ($person['age'] !== null && $person['age'] !== '') {
            $meta_parts[] = htmlspecialchars_uni($lang->sprintf($lang->relatives_wanted_age, (int)$person['age']));
        }
        if (($person['job'] ?? '') !== '') {
            $meta_parts[] = htmlspecialchars_uni((string)$person['job']);
        }
        $meta = implode(' &middot; ', $meta_parts);

        $details = '';
        $description = nl2br(htmlspecialchars_uni(trim((string)$person['description'])));
        $relationship_description = nl2br(htmlspecialchars_uni(trim((string)$person['relationship_description'])));
        if ($description !== '' || $relationship_description !== '') {
            eval('$details = "' . $templates->get('relatives_profile_wanted_details') . '";');
        }

        $link = '';
        $safe_url = relatives_sanitize_url((string)($person['wanted_thread_link'] ?? ''));
        if ($safe_url !== '') {
            $link_url = htmlspecialchars_uni($safe_url);
            eval('$link = "' . $templates->get('relatives_profile_wanted_link') . '";');
        }

        eval('$relatives_wanted_card = "' . $templates->get('relatives_profile_wanted_card') . '";');
        $cards .= $relatives_wanted_card;
    }

    // Provider-Hook: Fremdplugins (z. B. eine Anschluss-Suche) haengen fertig
    // gerendertes, selbst escaptes Karten-HTML an und erhoehen den Zaehler.
    $hook_args = ['uid' => $owner_uid, 'cards' => '', 'count' => 0];
    if (is_object($plugins)) {
        $plugins->run_hooks('relatives_profile_wanted_cards', $hook_args);
    }
    if (is_string($hook_args['cards'] ?? null) && $hook_args['cards'] !== '') {
        $cards .= $hook_args['cards'];
    }
    $count = count($wanted) + max(0, (int)($hook_args['count'] ?? 0));
    if ($count === 0 && $cards === '') {
        return;
    }
    eval('$relatives_wanted_box = "' . $templates->get('relatives_profile_wanted_box') . '";');
}

/**
 * Gesuchte Personen fuer die Profil-Box, einheitlich aufbereitet (name, label, age,
 * description, relationship_description, wanted_thread_link).
 *
 * Mit gemeinsamem Geflecht: alle gesuchten Personen im Stammbaum-Ausschnitt des
 * Charakters (auch fremd eingetragene). Sonst Rueckfall auf die eigene Liste.
 */
function relatives_profile_wanted_persons(int $uid): array
{
    $result = [];

    if (Relatives_Graph::is_merged()) {
        foreach (Relatives_TreeBuilder::wanted_nodes($uid) as $node) {
            $result[] = [
                'name'                     => (string)$node['name'],
                'label'                    => (string)($node['computed_label'] ?? ''),
                'age'                      => $node['age'],
                'birth_year'               => $node['birth_year'] ?? null,
                'job'                      => (string)($node['job'] ?? ''),
                'description'              => (string)($node['description'] ?? ''),
                'relationship_description' => (string)($node['relationship_description'] ?? ''),
                'wanted_thread_link'       => (string)($node['wanted_thread_link'] ?? ''),
            ];
        }
        return $result;
    }

    $persons = Relatives_Database::get_persons_by_owner($uid);
    $labels = relatives_get_relationship_labels($persons);
    foreach ($persons as $person) {
        if ((int)$person['is_wanted'] !== 1) {
            continue;
        }
        $result[] = [
            'name'                     => (string)$person['name'],
            'label'                    => (string)($labels[(int)$person['id']] ?? ''),
            'age'                      => Relatives_Database::computed_age($person),
            'description'              => (string)($person['description'] ?? ''),
            'relationship_description' => (string)($person['relationship_description'] ?? ''),
            'wanted_thread_link'       => (string)($person['wanted_thread_link'] ?? ''),
        ];
    }
    return $result;
}

// --- Hook-Handler: postbit / memberlist_user ------------------------------------------

/** Stammbaum-Link der Autor*in als {$post['relatives_tree_link']}. */
function relatives_handle_postbit(array $post): array
{
    global $mybb;

    $post['relatives_tree_link'] = '';
    if (!empty($mybb->settings['relatives_enabled']) && !empty($mybb->settings['relatives_tree_link_postbit'])
        && isset($post['uid'], $post['username'])) {
        $post['relatives_tree_link'] = relatives_tree_link_html((int)$post['uid'], (string)$post['username']);
    }
    return $post;
}

/** Stammbaum-Link des Mitglieds als {$user['relatives_tree_link']}. */
function relatives_handle_memberlist_user(array $user): array
{
    global $mybb;

    $user['relatives_tree_link'] = '';
    if (!empty($mybb->settings['relatives_enabled']) && !empty($mybb->settings['relatives_tree_link_memberlist'])
        && isset($user['uid'], $user['username'])) {
        $user['relatives_tree_link'] = relatives_tree_link_html((int)$user['uid'], (string)$user['username']);
    }
    return $user;
}

/**
 * Link zur (auch fuer Gaeste freigegebenen) Popup-Ansicht des Stammbaums von $uid.
 * Leer, wenn der Account keinen Baum hat -- ein Link ins Leere waere schlimmer als keiner.
 */
function relatives_tree_link_html(int $uid, string $username): string
{
    global $mybb, $lang, $templates;

    if ($uid <= 0 || !Relatives_Graph::is_merged() || !isset(relatives_uids_with_tree()[$uid])) {
        return '';
    }

    $lang->load('relatives');
    return relatives_tree_popup_link(
        $uid,
        // relatives_tree_title_for uses %s (shared with the tree page title), not {1}.
        sprintf($lang->relatives_tree_title_for, $username),
        htmlspecialchars_uni($lang->relatives_tree_link)
    );
}

/**
 * The one popup-opening link (template relatives_tree_link) -- used by profile/postbit/
 * memberlist and by the popup icon in the UserCP nav, so the window.open() call lives
 * in a single template.
 *
 * @param string $title   plain text, escaped here
 * @param string $content ready HTML (escaped text or an icon)
 */
function relatives_tree_popup_link(int $uid, string $title, string $content, string $class = 'relatives_tree_link'): string
{
    global $mybb, $lang, $templates;

    $relatives_tree_link_uid = $uid;
    $relatives_tree_link_title = htmlspecialchars_uni($title);
    $relatives_tree_link_content = $content;
    $relatives_tree_link_class = htmlspecialchars_uni($class);
    eval('$link = "' . $templates->get('relatives_tree_link') . '";');
    return $link;
}

/**
 * Alle Accounts mit Stammbaum (eigene Personen oder als Person bestaetigt verknuepft),
 * einmal pro Request geladen -- Postbit und Mitgliederliste fragen je Zeile, das soll
 * nicht pro Zeile eine Query kosten.
 *
 * @return array<int, true>
 */
function relatives_uids_with_tree(): array
{
    global $db;
    static $uids = null;

    if ($uids === null) {
        $uids = [];
        $query = $db->query("SELECT DISTINCT owner_uid AS uid FROM " . TABLE_PREFIX . "relatives_person_owners
            UNION SELECT DISTINCT linked_uid AS uid FROM " . TABLE_PREFIX . "relatives_persons WHERE link_status = 2");
        while ($row = $db->fetch_array($query)) {
            if ((int)$row['uid'] > 0) {
                $uids[(int)$row['uid']] = true;
            }
        }
    }
    return $uids;
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
 * Anzeige-Labels aller Personen einer Liste (aus get_persons_by_owner()) aus Sicht des
 * Besitzers: mit Bezugsperson komponiert ("Bruder" von "Mutter" = "Onkel"), sonst Grad
 * bzw. freie Bezeichnung.
 *
 * @return array person_id => string
 */
function relatives_get_relationship_labels(array $persons): array
{
    $labels = [];
    foreach ($persons as $person) {
        $person_id = (int)$person['id'];
        // Der Cache aus Relatives_Kinship ist bereits aus Sicht des Besitzers
        // gerechnet und auf "entfernte*r Verwandte*r" begrenzt -- er ist die
        // Standardquelle. Nur wenn er (noch) leer ist, faellt die Anzeige auf den
        // roh eingetragenen Grad zurueck.
        if (!empty($person['computed_label'])) {
            $labels[$person_id] = (string)Relatives_Kinship::display_label(
                (string)$person['computed_label'],
                $person['computed_via_person_id'] ?? null
            );
            continue;
        }
        $labels[$person_id] = relatives_get_relationship_label($person);
    }
    return $labels;
}

/**
 * Oeffentliche API: Wie steht Person $person_id zum Charakter $uid?
 * Einziger vorgesehener Zugriffsweg fuer Fremdplugins (Jobliste) auf die berechnete
 * Verwandtschaft -- kein direkter Tabellenzugriff.
 *
 * @return array ['concept_key' => ?string, 'label' => ?string, 'category' => string,
 *                'up' => ?int, 'down' => ?int, 'owner_name' => string,
 *                'text' => string]  text = "Onkel von Megan Fawley" (leer, wenn unbekannt)
 */
function relatives_get_relation(int $person_id, int $uid): array
{
    global $lang;

    $empty = [
        'concept_key' => null,
        'label'       => null,
        'category'    => Relatives_Kinship::CATEGORY_UNKNOWN,
        'up'          => null,
        'down'        => null,
        'owner_name'  => '',
        'text'        => '',
    ];

    if ($person_id <= 0 || $uid <= 0) {
        return $empty;
    }

    $relation = Relatives_Kinship::get_cached($person_id, $uid);
    if ($relation === null) {
        return $empty;
    }

    $user = get_user($uid);
    $relation['owner_name'] = $user ? (string)$user['username'] : '';
    $relation['text'] = '';

    if (!empty($relation['label']) && $relation['owner_name'] !== '') {
        $lang->load('relatives');
        // "via" nennt bereits eine andere Person ("Adoptivvater von Louisa Griffin") --
        // ein angehaengtes "von Megan Fawley" wuerde den Satz falsch machen.
        $relation['text'] = $relation['category'] === Relatives_Kinship::CATEGORY_VIA
            ? (string)Relatives_Kinship::display_label($relation['label'], $relation['via_person_id'] ?? null)
            : $lang->sprintf($lang->relatives_relation_of, $relation['label'], $relation['owner_name']);
    }

    return $relation;
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

/**
 * Die Zeile selbst ist statisch in usercp_nav_misc eingebacken (siehe
 * Relatives_Installer::install_usercp_nav_misc_link()) -- kein Platzhalter-Global, weil
 * die Core-Funktion usercp_menu() (aufgerufen aus relatives.php:34) usercp_nav_misc
 * innerhalb ihres eigenen Funktions-Scopes eval()t und ein per Hook gesetztes
 * Custom-Global dort nicht sichtbar waere. Dieser Hook laedt nur die Sprachdatei, damit
 * {$lang->relatives_usercp_menu_item} im Template etwas findet -- $lang ist ein Objekt,
 * seine Aenderungen sind ueberall sichtbar, wo dieselbe Instanz referenziert wird.
 */
function relatives_handle_usercp_menu(): void
{
    global $lang;

    $lang->load('relatives');
}

// --- Hook-Handler: datahandler_user_delete_start -----------------------------------------

/**
 * Deckt ALLE Loeschwege ab (ACP einzeln/bulk, Merge, Frontend). Entfernt den geloeschten
 * User als Besitzer*in aller seiner Personen (Personen, die sonst niemand mehr hat, werden
 * dabei geloescht) und setzt bei allen Zeilen, die diesen User als linked_uid
 * referenzieren, linked_uid/link_status zurueck.
 *
 * UserDataHandler::delete_user() setzt KEIN $user-Property, sondern legt die zu
 * loeschenden uids vor dem Hook in $delete_uids ab (Array, bei Bulk-Loeschung mehrere).
 */
function relatives_handle_user_delete(&$userhandler): void
{
    if (!is_object($userhandler) || !isset($userhandler->delete_uids)) {
        return;
    }
    $uids = is_array($userhandler->delete_uids)
        ? $userhandler->delete_uids
        : explode(',', (string)$userhandler->delete_uids);

    foreach ($uids as $uid) {
        $uid = (int)$uid;
        if ($uid <= 0) {
            continue;
        }
        relatives_cleanup_deleted_user($uid);
    }
}

/** Raeumt alle Daten eines einzelnen geloeschten Accounts auf. */
function relatives_cleanup_deleted_user(int $uid): void
{
    // Vor dem Austragen: eigene offene Loeschantraege platzen, eigene offene Antworten
    // entfallen (und koennen fremde Antraege damit abschliessen).
    Relatives_DeleteRequests::handle_user_delete($uid);
    Relatives_RelationRequests::handle_user_delete($uid);

    $own_persons = Relatives_Database::get_persons_by_owner($uid);
    foreach ($own_persons as $person) {
        Relatives_Database::remove_owner((int)$person['id'], $uid);
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

// --- MyAlerts ------------------------------------------------------------------------------

/**
 * Registriert je Alert-Typ einen Formatter. Best effort: tut still nichts, wenn MyAlerts
 * fehlt oder inkompatibel ist. Statischer Guard gegen Doppelregistrierung.
 */
function relatives_register_alert_formatter(): void
{
    global $mybb, $lang;
    static $registered = false;

    if ($registered) {
        return;
    }
    if (!class_exists('MybbStuff_MyAlerts_AlertFormatterManager')
        || !class_exists('MybbStuff_MyAlerts_Formatter_AbstractFormatter')) {
        return;
    }

    $lang->load('relatives_myalerts');

    $manager = MybbStuff_MyAlerts_AlertFormatterManager::getInstance();
    if (!$manager) {
        $manager = MybbStuff_MyAlerts_AlertFormatterManager::createInstance($mybb, $lang);
    }
    if (!$manager) {
        return;
    }

    require_once MYBB_ROOT . 'inc/plugins/relatives/myalerts_formatter.php';
    if (!class_exists('Relatives_MyAlerts_Formatter')) {
        return;
    }

    foreach (Relatives_Alerts::codes() as $code) {
        $manager->registerFormatter(new Relatives_MyAlerts_Formatter($mybb, $lang, $code));
    }
    $registered = true;
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

/**
 * Registers "tools -> relatives" as an admin permission. Without it MyBB lets every
 * admin in and the page cannot be restricted per admin/group (check_admin_permissions()
 * only enforces registered actions).
 */
function relatives_admin_permissions(&$admin_permissions): void
{
    global $lang;
    $lang->load('relatives');

    $admin_permissions['relatives'] = $lang->relatives_acp_permission;
}

/**
 * The setting titles in the settings table are the German fallback; MyBB replaces them
 * with $lang->setting_<name> / setting_<name>_desc / setting_group_<group> when those
 * keys are loaded. config-settings only loads its own language file, so load ours.
 */
function relatives_admin_settings_lang(): void
{
    global $lang;
    $lang->load('relatives');
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
            // Verwandtschaft zum Charakter, bereits begrenzt ("entfernte*r
            // Verwandte*r" statt einer erfundenen Bezeichnung). Optionales Extra --
            // Konsumenten, die das Feld nicht kennen, ignorieren es einfach.
            'relation_label' => isset($person['computed_label'])
                ? (string)Relatives_Kinship::display_label((string)$person['computed_label'], $person['computed_via_person_id'] ?? null)
                : '',
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
    if ($person === null) {
        return;
    }
    $args['label'] = (string)$person['name'];

    // Optionales Extra (Jobliste >= 2.1): bestaetigt verknuepfter Charakter-Account, damit
    // die Person dort als Account statt doppelt als Verwandte:r erscheint.
    if (array_key_exists('linked_uid', $args)
        && (int)$person['link_status'] === 2 && !empty($person['linked_uid'])) {
        $args['linked_uid'] = (int)$person['linked_uid'];
    }

    // Optionales Extra (Jobliste >= 2.1): wer die Person eingetragen hat und in welchem
    // Grad, aus dem Verwandtschafts-Cache. Nur fuellen, wenn der Konsument es anfragt.
    if (isset($args['relations']) && is_array($args['relations'])) {
        foreach (Relatives_Database::get_owner_uids((int)$person['id']) as $owner_uid) {
            $relation = relatives_get_relation((int)$person['id'], (int)$owner_uid);
            $args['relations'][] = [
                'uid'            => (int)$owner_uid,
                'relation_label' => !empty($relation['label'])
                    ? (string)Relatives_Kinship::display_label((string)$relation['label'], $relation['via_person_id'] ?? null)
                    : '',
            ];
        }
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

/**
 * Anzahl der Treffer von relatives_search_persons() mit denselben Filtern
 * (limit/offset/order werden ignoriert) -- fuer Pagination in Fremdplugins.
 */
function relatives_count_persons(array $filters): int
{
    return Relatives_SearchApi::count($filters);
}
