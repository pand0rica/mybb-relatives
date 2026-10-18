<?php
/**
 * Verwandtschaften & Stammbaum - Frontend-Root
 *
 * Actions: list, add, edit, delete (POST), adopt (POST), tree (auch als Popup: &popup=1),
 * link_request/link_accept/link_decline (POST), delete_vote (POST). Siehe PROJECT_CONTEXT.md "Interaktiver Stammbaum" und "Konto-Verknuepfung".
 */

define('IN_MYBB', 1);
define('THIS_SCRIPT', 'relatives.php');

require_once './global.php';
require_once MYBB_ROOT . 'inc/functions_user.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Database.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/RelationshipTypes.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Attributes.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Families.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Kinship.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Graph.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/TreeBuilder.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/TreePlaceholders.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/LinkManager.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/Alerts.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/DeleteRequests.php';
require_once MYBB_ROOT . 'inc/plugins/relatives/core/RelationRequests.php';

$lang->load('relatives');

if (empty($mybb->settings['relatives_enabled'])) {
    error($lang->relatives_disabled);
}
$my_uid = (int)$mybb->user['uid'];
$action = $mybb->get_input('action');

// Ausnahme von der Login-Pflicht: die weitergebbare Popup-Ansicht eines Baums
// (inkl. ihres AJAX-Nachladens) -- nur lesend, bewusst mit geheimen Verbindungen.
// Die Gruppenberechtigung "Forum ansehen" gilt trotzdem.
$is_guest_popup = $action === 'tree' && $mybb->get_input('popup', MyBB::INPUT_INT) === 1;
// Ebenso die oeffentliche Familienliste samt Familienseiten, sofern im ACP fuer Gaeste freigegeben.
$is_guest_family_list = ($action === 'family_list' || $action === 'family') && !empty($mybb->settings['relatives_family_list_guests']);
if ($my_uid <= 0 && (!($is_guest_popup || $is_guest_family_list) || empty($mybb->usergroup['canview']))) {
    error_no_permission();
}

// Baut $usercpnav (die UserCP-Sidebar, inkl. unseres eigenen usercp_menu-Eintrags) fuer
// die Seitentemplates -- dieselbe Core-Funktion, die usercp.php selbst aufruft.
if ($my_uid > 0) {
    usercp_menu();
}

add_breadcrumb($lang->relatives, 'relatives.php');

// Shared page script (confirm dialog), cache-busted like relatives-tree.js.
$relatives_js_version = (int)(@filemtime(MYBB_ROOT . 'inc/plugins/relatives/assets/relatives.js') ?: TIME_NOW);

// Bis das Team die frueheren Einzel-Listen im ACP zu einem gemeinsamen Geflecht
// zusammengefuehrt hat, waeren Baum und Formulare inkonsistent (doppelte Knoten,
// Verbindungen je Liste) -- deshalb vorher gar nicht erst anzeigen.
if (!Relatives_Graph::is_merged()) {
    error($lang->relatives_graph_not_merged);
}

// --- kleine Ausgabe-Helfer ------------------------------------------------------------

function relatives_json_response(array $data): void
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}

/**
 * JSON fuer Einbettung in <script type="application/json"> Blocks in Templates. NICHT
 * mit htmlspecialchars_uni() nachbearbeiten -- <script>-Inhalte werden vom Browser nie
 * HTML-entity-dekodiert, das wuerde z.B. jedes " zu &quot; machen und JSON.parse() im
 * Frontend-JS lautlos scheitern lassen. Die JSON_HEX_*-Flags escapen stattdessen
 * <, >, &, ' und " innerhalb der JSON-Werte selbst (< etc.) -- bleibt gueltiges
 * JSON und verhindert trotzdem ein vorzeitiges </script>.
 */
function relatives_json_for_script(array $data): string
{
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function relatives_error_response(string $message): void
{
    global $mybb;

    if ($mybb->get_input('ajax')) {
        relatives_json_response(['success' => false, 'error' => $message]);
    }
    error($message);
}

/**
 * Guard for every data-changing action: POST only, plus a valid my_post_key. A GET link
 * carrying the key would still pass verify_post_check() and leak the key into logs and
 * referrers (debugging.md #3).
 */
function relatives_verify_post(): void
{
    global $mybb;

    if ($mybb->request_method !== 'post') {
        error_no_permission();
    }
    verify_post_check($mybb->get_input('my_post_key'));
}

/**
 * Label of the "relative to your character" anchor option, naming the character
 * ("Aeryn MacCrea (your character)"). A bare "to your character" looks exactly like a
 * freshly added row and hides which person an existing connection points to.
 * Returns raw text -- escape it where it goes into HTML.
 */
function relatives_self_anchor_label(int $account_person_id): string
{
    global $lang;

    $name = relatives_person_name($account_person_id);
    if ($name !== '') {
        return $lang->sprintf($lang->relatives_related_to_self_named, $name);
    }
    return (string)$lang->relatives_related_to_self;
}

/**
 * Shared navigation bar of all relatives UserCP pages: my relatives, families, tree and
 * the "Stammbaum Popup" button (opens the shareable popup view of $popup_uid's tree; in
 * the families section it opens the public family list instead).
 * $active ('list'|'families'|'tree'|'') marks the section the page belongs to.
 * $action_url/$action_label append one page-specific icon button ("add person"); the
 * label becomes its tooltip and accessible name, $action_icon names the icon template.
 */
function relatives_nav(int $popup_uid, string $active = '', string $action_url = '', string $action_label = '', string $action_icon = 'relatives_icon_person_add'): string
{
    global $lang, $templates, $mybb;

    // Attribute strings per nav entry: the current section gets a visible marker and
    // aria-current, so users can tell where they are.
    $nav_attr = static function (string $section) use ($active): string {
        return $section === $active
            ? ' class="relatives_nav_tab relatives_nav_active" aria-current="page"'
            : ' class="relatives_nav_tab"';
    };
    $relatives_nav_attr_list = $nav_attr('list');
    $relatives_nav_attr_families = $nav_attr('families');
    $relatives_nav_attr_tree = $nav_attr('tree');

    // Offene Loeschantraege, auf die ich antworten muss -- auch ohne MyAlerts sichtbar.
    $relatives_nav_open_badge = '';
    $open_votes = Relatives_DeleteRequests::count_open_for_voter((int)$mybb->user['uid'])
        + Relatives_RelationRequests::count_open_for_target((int)$mybb->user['uid']);
    if ($open_votes > 0) {
        $badge_title = htmlspecialchars_uni($lang->sprintf($lang->relatives_nav_open_requests, $open_votes));
        $relatives_nav_open_badge = ' <span class="relatives_nav_count" title="' . $badge_title . '">' . $open_votes . '</span>';
    }

    // Icon only; the link itself (incl. window.open) comes from relatives_tree_link.
    eval('$relatives_popup_icon = "' . $templates->get('relatives_tree_popup_button') . '";');
    if ($active === 'families') {
        // On the family pages the icon leads to the public family list instead of the tree.
        $label = htmlspecialchars_uni($lang->relatives_family_list_open);
        $relatives_tree_popup_button = '<a class="relatives_nav_icon relatives_nav_popup" href="relatives.php?action=family_list" target="_blank" rel="noopener" title="' . $label . '" aria-label="' . $label . '">' . $relatives_popup_icon . '</a>';
    } else {
        $relatives_tree_popup_button = relatives_tree_popup_link($popup_uid, $lang->relatives_tree_popup, $relatives_popup_icon, 'relatives_nav_icon relatives_nav_popup');
    }
    $relatives_nav_actions = '';
    if ($action_url !== '') {
        eval('$relatives_action_icon = "' . $templates->get($action_icon) . '";');
        $label = htmlspecialchars_uni($action_label);
        $relatives_nav_actions = '<a class="relatives_nav_icon relatives_nav_action" href="' . htmlspecialchars_uni($action_url) . '" title="' . $label . '" aria-label="' . $label . '">' . $relatives_action_icon . '</a>';
    }
    eval('$nav = "' . $templates->get('relatives_nav') . '";');
    return $nav;
}

/** Raw name of a person node, '' if unknown. */
function relatives_person_name(int $person_id): string
{
    if ($person_id <= 0 || ($person = Relatives_Database::get_person($person_id)) === null) {
        return '';
    }
    return (string)$person['name'];
}

/**
 * Where the add/edit form leads back to: the tree (or the tree popup) when it was opened
 * from there ("return=tree" / "return=tree_popup"), otherwise the list. Only these fixed
 * targets -- no free URL.
 */
function relatives_form_return_url(): string
{
    global $mybb;

    switch ($mybb->get_input('return')) {
        case 'tree':
            return 'relatives.php?action=tree';
        case 'tree_popup':
            return 'relatives.php?action=tree&popup=1';
        default:
            return 'relatives.php?action=list';
    }
}

/** Hidden inputs that reproduce the query string of a relatives.php?... URL in a GET form. */
function relatives_get_hidden_inputs(string $url): string
{
    $params = [];
    parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
    $html = '';
    foreach ($params as $name => $value) {
        if (is_string($value)) {
            $html .= '<input type="hidden" name="' . htmlspecialchars_uni((string)$name) . '" value="' . htmlspecialchars_uni($value) . '" />';
        }
    }
    return $html;
}

/**
 * Navigation button (GET form + input.button). Navigation actions next to POST action
 * buttons must be real buttons too -- an <a> never looks like input.button in the themes.
 */
function relatives_nav_button(string $url, string $label, string $extra_class = ''): string
{
    global $templates;

    $button_hidden = relatives_get_hidden_inputs($url);
    $button_label = htmlspecialchars_uni($label);
    $button_class = $extra_class !== '' ? ' ' . htmlspecialchars_uni($extra_class) : '';
    $button = '';
    eval('$button = "' . $templates->get('relatives_nav_button') . '";');
    return $button;
}

/**
 * Detached GET form for a navigation button that sits inside another <form> (forms cannot
 * be nested). The button references it via form="$form_id"; the form itself goes after
 * the enclosing form's closing tag.
 */
function relatives_nav_form(string $url, string $form_id): string
{
    return '<form action="relatives.php" method="get" id="' . htmlspecialchars_uni($form_id) . '" class="relatives_inline_form">'
        . relatives_get_hidden_inputs($url) . '</form>';
}

/** Is the relationship type a child-kind degree (Sohn, Tochter, Adoptivkind, ...)? */
function relatives_is_child_type($type_id): bool
{
    $type = (int)$type_id > 0 ? Relatives_RelationshipTypes::get_by_id((int)$type_id) : null;
    return $type !== null
        && Relatives_Kinship::classify_relation((string)$type['concept_key'], (int)$type['generation_offset']) === 'child';
}

/**
 * Abschnitt "Von anderen eingetragene Verbindungen" im Personenformular: Kanten, die an
 * dieser Person haengen, aber bei der anderen Person gespeichert sind ("Nick ist
 * Urgrossvater von Sienna" steht bei Nick). Wer eine der beiden Personen bearbeiten
 * darf, kann den Grad aendern oder trennen -- ohne die andere Person bearbeiten zu
 * muessen. Dazu offene Anfragen, die diese Person betreffen.
 */
function relatives_render_incoming_relations(int $person_id): string
{
    global $mybb, $lang, $templates;

    $uid = (int)$mybb->user['uid'];
    $rows = '';
    $type_options_cache = [];
    foreach (Relatives_Database::get_relations_touching($person_id) as $relation) {
        if ((int)$relation['person_id'] === $person_id || (int)($relation['related_to_person_id'] ?? 0) !== $person_id) {
            continue;
        }
        if (!Relatives_Graph::can_edit_relation($relation, $uid)) {
            continue;
        }
        $subject = Relatives_Database::get_person((int)$relation['person_id']);
        if ($subject === null) {
            continue;
        }

        $relatives_in_id = (int)$relation['id'];
        $relatives_in_text = htmlspecialchars_uni(Relatives_RelationRequests::describe($relation));
        $gender = (int)$subject['gender'];
        if (!isset($type_options_cache[$gender])) {
            $type_options_cache[$gender] = Relatives_RelationshipTypes::get_for_person_gender($gender);
        }
        $options = '<option value="0">' . htmlspecialchars_uni($lang->relatives_relationship_other) . '</option>';
        foreach ($type_options_cache[$gender] as $type) {
            $selected = (int)$type['id'] === (int)$relation['relationship_type_id'] ? ' selected="selected"' : '';
            $options .= '<option value="' . (int)$type['id'] . '"' . $selected . '>' . htmlspecialchars_uni((string)$type['label']) . '</option>';
        }
        $relatives_in_type_options = $options;
        $relatives_in_custom = htmlspecialchars_uni((string)($relation['relationship_custom_label'] ?? ''));
        $relatives_in_subject = htmlspecialchars_uni((string)$subject['name']);
        $relatives_in_return = 'edit:' . $person_id;
        $row = '';
        eval('$row = "' . $templates->get('relatives_incoming_relations_row') . '";');
        $rows .= $row;
    }

    $pending = '';
    foreach (Relatives_RelationRequests::get_open_for_person($person_id) as $request) {
        $pending .= '<li>' . htmlspecialchars_uni(Relatives_RelationRequests::describe($request)) . '</li>';
    }
    if ($pending !== '') {
        $pending = '<p class="relatives_hint">' . $lang->relatives_incoming_pending . '</p><ul class="relatives_incoming_pending">' . $pending . '</ul>';
    }

    if ($rows === '' && $pending === '') {
        return '';
    }
    $relatives_incoming_rows = $rows;
    $relatives_incoming_pending = $pending;
    $box = '';
    eval('$box = "' . $templates->get('relatives_incoming_relations') . '";');
    return $box;
}

/**
 * Kontext fuer die Formular-Hooks anderer Plugins (z. B. Jobliste: Beruf eintragen).
 * linked_uid ist bei gespielten Charakteren der Account -- deren Beruf haengt dort.
 */
function relatives_person_hook_context(int $person_id): array
{
    global $mybb;

    $linked_uid = 0;
    if ($person_id > 0 && ($person = Relatives_Database::get_person($person_id)) !== null
        && (int)$person['link_status'] === 2 && !empty($person['linked_uid'])) {
        $linked_uid = (int)$person['linked_uid'];
    }
    return [
        'person_id'  => $person_id,
        'linked_uid' => $linked_uid,
        'uid'        => (int)$mybb->user['uid'],
    ];
}

/**
 * Provider-Hook relatives_person_form_fields: Felder anderer Plugins im Personenformular.
 * Listener haengen fertiges, selbst escaptes HTML an $args['html'] an (eigene, eindeutig
 * benannte Input-Namen verwenden).
 */
function relatives_person_form_extra_fields(int $person_id): string
{
    global $plugins;

    if (!is_object($plugins)) {
        return '';
    }
    $args = relatives_person_hook_context($person_id) + ['html' => ''];
    $plugins->run_hooks('relatives_person_form_fields', $args);
    return is_string($args['html'] ?? null) ? $args['html'] : '';
}

/**
 * Provider-Hook relatives_person_validate: laeuft vor jedem Schreibzugriff. Listener
 * pruefen ihre Felder und haengen Fehlertexte (roh) an $args['errors'] an; der erste
 * Fehler bricht das Speichern ab. person_id ist 0, wenn die Person neu angelegt wird.
 */
function relatives_person_validate_extra(int $person_id): void
{
    global $plugins;

    if (!is_object($plugins)) {
        return;
    }
    $args = relatives_person_hook_context($person_id) + ['errors' => []];
    $plugins->run_hooks('relatives_person_validate', $args);
    foreach ((array)($args['errors'] ?? []) as $error) {
        if (is_scalar($error) && trim((string)$error) !== '') {
            relatives_error_response(htmlspecialchars_uni((string)$error));
        }
    }
}

/**
 * Provider-Hook relatives_person_saved: nach dem Speichern (Person existiert jetzt).
 * Listener speichern ihre Felder, z. B. den Beruf.
 */
function relatives_person_saved_extra(int $person_id, bool $is_new): void
{
    global $plugins;

    if (!is_object($plugins) || $person_id <= 0) {
        return;
    }
    $args = relatives_person_hook_context($person_id) + ['is_new' => $is_new];
    $plugins->run_hooks('relatives_person_saved', $args);
}

/**
 * Liest die Verbindungen aus dem Formular und validiert sie.
 *
 * Das Formular schickt sie als relations[i][...]; Aufrufer, die nur eine einzige
 * Verbindung kennen (Baum-Panel, Altformular), duerfen weiterhin die Einzelfelder
 * relationship_type_id / relationship_custom_label / related_to_person_id nutzen --
 * daraus wird dann genau eine Zeile.
 *
 * $allow_self = false: eine Zeile ohne Bezugsperson ist ein Fehler statt "zu meinem
 * Charakter" -- beim Bearbeiten eines gespielten Charakters waere das sonst der Charakter
 * der bearbeitenden Person (Team) oder er selbst.
 *
 * @return array|null Liste von Kanten fuer Relatives_Database::replace_relations(),
 *                    oder null, wenn das Formular gar keine Verbindungen mitschickt
 *                    (dann bleiben die bestehenden unangetastet).
 */
function relatives_collect_relations(
    int $owner_uid,
    int $person_id,
    int $effective_gender,
    bool $is_edit,
    bool $gender_from_existing = false,
    bool $allow_self = true
): ?array {
    global $mybb, $lang;

    $rows = $mybb->get_input('relations', MyBB::INPUT_ARRAY);
    if (!is_array($rows) || !$rows) {
        // Altpfad: eine einzige Verbindung aus den Einzelfeldern.
        if ($is_edit && $mybb->get_input('related_to_submitted', MyBB::INPUT_INT) !== 1) {
            return null;
        }
        // Eine einzelne Verbindung darf nie mehrere bestehende ersetzen -- sonst
        // verschwaenden z. B. Adoptiveltern, sobald jemand die Person im Baum-Panel
        // speichert. Das Panel schickt related_to_submitted in dem Fall gar nicht erst;
        // das hier ist das serverseitige Sicherheitsnetz.
        if ($is_edit && count(Relatives_Database::get_relations_for_person($person_id)) > 1) {
            return null;
        }
        $rows = [[
            'relationship_type_id'      => $mybb->get_input('relationship_type_id', MyBB::INPUT_INT),
            'relationship_custom_label' => $mybb->get_input('relationship_custom_label'),
            'related_to_person_id'      => $mybb->get_input('related_to_person_id', MyBB::INPUT_INT),
            'related_to_family_id'      => 0,
            'is_secret'                 => 0,
        ]];
    }

    $primary_index = $mybb->get_input('relations_primary', MyBB::INPUT_INT);
    $families = Relatives_Families::get_all();

    $relations = [];
    foreach ($rows as $index => $raw) {
        if (!is_array($raw)) {
            continue;
        }

        $type_id = isset($raw['relationship_type_id']) ? (int)$raw['relationship_type_id'] : 0;
        $custom_label = isset($raw['relationship_custom_label']) ? trim((string)$raw['relationship_custom_label']) : '';
        $related_person = isset($raw['related_to_person_id']) ? (int)$raw['related_to_person_id'] : 0;
        $related_family = isset($raw['related_to_family_id']) ? (int)$raw['related_to_family_id'] : 0;
        // Das Formular schickt Bezugsperson und Familie in EINEM Auswahlfeld
        // ("p:<id>" / "f:<id>" / "0" = relativ zu dir).
        if (isset($raw['anchor']) && is_string($raw['anchor'])) {
            $related_person = 0;
            $related_family = 0;
            if (preg_match('/^([pf]):(\d+)$/', $raw['anchor'], $anchor_match)) {
                if ($anchor_match[1] === 'p') {
                    $related_person = (int)$anchor_match[2];
                } else {
                    $related_family = (int)$anchor_match[2];
                }
            }
        }
        $is_secret = !empty($raw['is_secret']) ? 1 : 0;
        $expected_gender = Relatives_RelationshipTypes::map_person_gender_to_label_gender($effective_gender);

        $type_id_final = null;
        $custom_label_final = null;
        if ($related_family > 0) {
            // Familie: kein Grad noetig. Ohne Haken reine Mitgliedschaft (weder Grad noch
            // Bezeichnung); "als Eltern anzeigen" wird eine Kind-Kante -- der Kasten steht
            // dann an der Stelle der Eltern. Eine bereits gespeicherte Kind-Variante
            // (z. B. Adoptivkind) bleibt erhalten, sonst gilt "Kind" passend zum Geschlecht.
            if (!empty($raw['family_as_parent'])) {
                $type = $type_id > 0 ? Relatives_RelationshipTypes::get_by_id($type_id) : null;
                if ($type === null || (int)$type['is_active'] !== 1 || (int)$type['gender'] !== $expected_gender
                    || Relatives_Kinship::classify_relation((string)$type['concept_key'], (int)$type['generation_offset']) !== 'child') {
                    $type = Relatives_RelationshipTypes::find_by_concept_and_gender('child', $expected_gender)
                        ?? Relatives_RelationshipTypes::find_by_concept_and_gender('child', Relatives_RelationshipTypes::LABEL_NEUTRAL);
                }
                if ($type === null) {
                    relatives_error_response($lang->relatives_error_invalid_relationship_type);
                }
                $type_id_final = (int)$type['id'];
            }
        } elseif ($type_id <= 0 && $custom_label === '') {
            // Eine Zeile ohne Grad und ohne freie Bezeichnung ist ein uebrig gebliebener
            // Formularblock, kein Fehler.
            continue;
        } elseif ($type_id > 0) {
            $type = Relatives_RelationshipTypes::get_by_id($type_id);
            if ($type === null || (int)$type['is_active'] !== 1) {
                relatives_error_response($lang->relatives_error_invalid_relationship_type);
            }
            if ((int)$type['gender'] !== $expected_gender) {
                // Bei einer uebernommenen Person zaehlt deren hinterlegtes Geschlecht --
                // die Meldung sagt das ausdruecklich, sonst wirkt der Fehler willkuerlich.
                relatives_error_response($gender_from_existing
                    ? $lang->relatives_error_existing_gender_mismatch
                    : $lang->relatives_error_relationship_gender_mismatch);
            }
            $type_id_final = $type_id;
        } else {
            if ($custom_label === '') {
                relatives_error_response($lang->relatives_error_custom_label_required);
            }
            $custom_label_final = mb_substr($custom_label, 0, 255);
        }

        // Bezugsperson ODER Familien-Kasten, nie beides: der Kasten steht bereits an
        // der Stelle der Eltern. Ohne beides meint die Zeile "zu meinem Charakter" --
        // im gemeinsamen Geflecht ist das der Knoten des eigenen Accounts.
        if ($related_family > 0) {
            if (!isset($families[$related_family])) {
                relatives_error_response($lang->relatives_error_invalid_family);
            }
            $related_person = 0;
        } else {
            if ($related_person <= 0) {
                if (!$allow_self) {
                    relatives_error_response($lang->relatives_error_anchor_required);
                }
                $related_person = Relatives_Graph::account_person_id($owner_uid);
            }
            if (!Relatives_Database::is_valid_related_to($related_person, $person_id)) {
                relatives_error_response($lang->relatives_error_invalid_related_to);
            }
        }

        // Steht zwischen den beiden Knoten schon etwas (von der anderen Seite oder von
        // einem anderen Charakter eingetragen)? Gleiche Aussage: nicht doppelt speichern.
        // Andere Aussage: ablehnen, statt das Geflecht widerspruechlich zu machen.
        if ($person_id > 0) {
            $concept = $type_id_final !== null ? (string)Relatives_RelationshipTypes::get_by_id($type_id_final)['concept_key'] : null;
            $existing_statement = Relatives_Graph::find_existing_statement(
                $person_id,
                $related_person > 0 ? $related_person : null,
                $related_family > 0 ? $related_family : null,
                $concept,
                $custom_label_final,
                $is_edit ? $person_id : 0
            );
            if ($existing_statement !== null) {
                if (!empty($existing_statement['same'])) {
                    continue;
                }
                $other = $related_person > 0 ? Relatives_Database::get_person($related_person) : null;
                relatives_error_response($lang->sprintf(
                    $lang->relatives_error_relation_conflict,
                    htmlspecialchars_uni($other !== null ? (string)$other['name'] : ''),
                    htmlspecialchars_uni(relatives_get_relationship_label($existing_statement))
                ));
            }
        }

        $relations[] = [
            'relationship_type_id'      => $type_id_final,
            'relationship_custom_label' => $custom_label_final,
            'related_to_person_id'      => $related_person > 0 ? $related_person : null,
            'related_to_family_id'      => $related_family > 0 ? $related_family : null,
            'is_secret'                 => $is_secret,
            'is_primary'                => (int)$index === $primary_index ? 1 : 0,
        ];
    }

    if (!$relations) {
        // Alle Zeilen standen schon (identisch) im Geflecht -- nichts zu tun, kein Fehler.
        if ($person_id > 0) {
            return [];
        }
        relatives_error_response($lang->relatives_error_custom_label_required);
    }

    // Genau eine Primaerkante: ohne ausdrueckliche Wahl gewinnt die erste Zeile.
    $has_primary = false;
    foreach ($relations as $relation) {
        if ($relation['is_primary']) {
            $has_primary = true;
            break;
        }
    }
    if (!$has_primary) {
        $relations[0]['is_primary'] = 1;
    }

    // Die Primaerkante nach vorn ziehen -- der Aufrufer nimmt relations[0] fuer die
    // Kompatibilitaets-Felder in owner_data.
    // Von Hand statt usort(): PHP 7.4 sortiert nicht stabil, die Reihenfolge der
    // uebrigen Verbindungen soll aber erhalten bleiben.
    foreach ($relations as $position => $relation) {
        if ($relation['is_primary'] && $position > 0) {
            unset($relations[$position]);
            array_unshift($relations, $relation);
            break;
        }
    }
    $relations = array_values($relations);

    return $relations;
}

// --- action: delete (POST) -------------------------------------------------------------

if ($action === 'delete') {
    relatives_verify_post();
    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    if (!Relatives_Database::is_owner($id, $my_uid)) {
        error_no_permission();
    }
    // NPCs gehoeren dem gemeinsamen Geflecht: Loeschen stellt einen Antrag an alle
    // anderen Besitzer*innen (oder loescht sofort, wenn es keine gibt). Gespielte
    // Charaktere werden nur aus der eigenen Liste ausgetragen.
    $outcome = Relatives_DeleteRequests::request($id, $my_uid);
    if ($outcome['result'] === Relatives_DeleteRequests::RESULT_PENDING_EXISTS) {
        relatives_error_response($lang->relatives_error_delete_pending);
    }
    if ($outcome['result'] === Relatives_DeleteRequests::RESULT_FAILED) {
        relatives_error_response($lang->relatives_error_generic);
    }
    if ($outcome['result'] === Relatives_DeleteRequests::RESULT_REQUESTED) {
        $message = $outcome['waiting'] === 1
            ? $lang->relatives_delete_requested_one
            : $lang->sprintf($lang->relatives_delete_requested, $outcome['waiting']);
    } elseif ($outcome['result'] === Relatives_DeleteRequests::RESULT_DELETED) {
        $message = $lang->relatives_deleted_completely;
    } else {
        $message = $lang->relatives_deleted;
    }
    if ($mybb->get_input('ajax')) {
        relatives_json_response(['success' => true, 'result' => $outcome['result'], 'message' => $message]);
    }
    redirect('relatives.php?action=list', $message);
}

// --- action: delete_vote (POST) --------------------------------------------------------

// Antwort auf einen Loeschantrag: vote=confirm stimmt zu, alles andere lehnt ab.
if ($action === 'delete_vote') {
    relatives_verify_post();
    $request_id = $mybb->get_input('id', MyBB::INPUT_INT);
    $confirm = $mybb->get_input('vote') === 'confirm';

    $vote_result = Relatives_DeleteRequests::vote($request_id, $my_uid, $confirm);
    if ($vote_result === Relatives_DeleteRequests::VOTE_RESULT_FAILED) {
        relatives_error_response($lang->relatives_error_delete_vote);
    }
    $messages = [
        Relatives_DeleteRequests::VOTE_RESULT_CONFIRMED => $lang->relatives_delete_vote_confirmed,
        Relatives_DeleteRequests::VOTE_RESULT_COMPLETED => $lang->relatives_delete_vote_completed,
        Relatives_DeleteRequests::VOTE_RESULT_DECLINED  => $lang->relatives_delete_vote_declined,
    ];
    redirect('relatives.php?action=list', $messages[$vote_result]);
}

// --- Verbindungen einzeln: Anfragen beantworten, trennen, aendern (POST) ----------------

/**
 * Ziel nach einer Verbindungs-Aktion: zurueck ins Personenformular ("edit:<id>"), in den
 * Baum oder in die Liste. Nur diese festen Ziele.
 */
function relatives_relation_return_url(): string
{
    global $mybb;
    $return = $mybb->get_input('return');
    if (preg_match('/^edit:(\d+)$/', $return, $match)) {
        return 'relatives.php?action=edit&id=' . (int)$match[1];
    }
    if ($return === 'tree') {
        return 'relatives.php?action=tree';
    }
    return 'relatives.php?action=list';
}

// Antwort auf eine Verbindungsanfrage: accept / decline (angefragte Spieler*in) bzw.
// cancel (anfragende Person).
if ($action === 'relation_request') {
    relatives_verify_post();
    $request_id = $mybb->get_input('id', MyBB::INPUT_INT);
    $answer = $mybb->get_input('answer');
    if ($answer === 'accept') {
        $result = Relatives_RelationRequests::accept($request_id, $my_uid);
        if ($result === Relatives_RelationRequests::RESULT_CONFLICT) {
            relatives_error_response($lang->relatives_relation_request_conflict);
        }
        if ($result !== Relatives_RelationRequests::RESULT_ACCEPTED) {
            relatives_error_response($lang->relatives_error_generic);
        }
        $message = $lang->relatives_relation_request_accepted;
    } elseif ($answer === 'decline') {
        if (!Relatives_RelationRequests::decline($request_id, $my_uid)) {
            relatives_error_response($lang->relatives_error_generic);
        }
        $message = $lang->relatives_relation_request_declined;
    } else {
        if (!Relatives_RelationRequests::cancel($request_id, $my_uid)) {
            relatives_error_response($lang->relatives_error_generic);
        }
        $message = $lang->relatives_relation_request_cancelled;
    }
    redirect('relatives.php?action=list', $message);
}

// Trennen geht immer sofort -- fuer alle, die eine der beiden Personen bearbeiten duerfen.
if ($action === 'relation_delete') {
    relatives_verify_post();
    $relation = Relatives_Database::get_relation($mybb->get_input('id', MyBB::INPUT_INT));
    if ($relation === null || !Relatives_Graph::can_edit_relation($relation, $my_uid)) {
        error_no_permission();
    }
    $subject_id = (int)$relation['person_id'];
    $anchor_id = (int)($relation['related_to_person_id'] ?? 0);
    Relatives_Database::delete_relation((int)$relation['id']);
    Relatives_Database::normalize_primary($subject_id, 0);
    Relatives_Graph::reset_family_memberships();
    Relatives_Kinship::recompute_for_person($subject_id);
    if ($anchor_id > 0) {
        Relatives_Kinship::recompute_for_person($anchor_id);
    }
    if ($mybb->get_input('ajax')) {
        relatives_json_response(['success' => true, 'message' => $lang->relatives_relation_deleted]);
    }
    redirect(relatives_relation_return_url(), $lang->relatives_relation_deleted);
}

// Grad einer bestehenden Verbindung aendern -- auch von der anderen Seite aus ("Nick ist
// Urgrossvater von Sienna" aus Siennas Formular). Betrifft es einen fremden gespielten
// Charakter, wird daraus eine Anfrage; bis zur Bestaetigung bleibt die alte Aussage.
if ($action === 'relation_edit') {
    relatives_verify_post();
    $relation = Relatives_Database::get_relation($mybb->get_input('id', MyBB::INPUT_INT));
    if ($relation === null || !Relatives_Graph::can_edit_relation($relation, $my_uid)) {
        error_no_permission();
    }
    $subject_id = (int)$relation['person_id'];
    $subject = Relatives_Database::get_person($subject_id);
    if ($subject === null) {
        error_no_permission();
    }

    $type_id = $mybb->get_input('relationship_type_id', MyBB::INPUT_INT);
    $custom_label = trim($mybb->get_input('relationship_custom_label'));
    $type = null;
    if ($type_id > 0) {
        $type = Relatives_RelationshipTypes::get_by_id($type_id);
        if ($type === null || (int)$type['is_active'] !== 1) {
            relatives_error_response($lang->relatives_error_invalid_relationship_type);
        }
        if ((int)$type['gender'] !== Relatives_RelationshipTypes::map_person_gender_to_label_gender((int)$subject['gender'])) {
            relatives_error_response($lang->relatives_error_existing_gender_mismatch);
        }
        $custom_label = '';
    } elseif ($custom_label === '') {
        relatives_error_response($lang->relatives_error_custom_label_required);
    }

    $changes = [
        'relationship_type_id'      => $type !== null ? (int)$type['id'] : null,
        'relationship_custom_label' => $type !== null ? null : mb_substr($custom_label, 0, 255),
    ];
    $anchor_id = (int)($relation['related_to_person_id'] ?? 0);

    // Widerspricht die neue Aussage einer anderen Kante zwischen denselben Personen?
    $existing_statement = Relatives_Graph::find_existing_statement(
        $subject_id,
        $anchor_id > 0 ? $anchor_id : null,
        !empty($relation['related_to_family_id']) ? (int)$relation['related_to_family_id'] : null,
        $type !== null ? (string)$type['concept_key'] : null,
        $changes['relationship_custom_label']
    );
    if ($existing_statement !== null && (int)$existing_statement['id'] !== (int)$relation['id'] && empty($existing_statement['same'])) {
        relatives_error_response($lang->sprintf(
            $lang->relatives_error_relation_conflict,
            htmlspecialchars_uni(relatives_person_name($anchor_id)),
            htmlspecialchars_uni(relatives_get_relationship_label($existing_statement))
        ));
    }

    $target = Relatives_RelationRequests::confirming_uid($subject_id, $anchor_id > 0 ? $anchor_id : null, $my_uid);
    if ($target > 0) {
        Relatives_RelationRequests::create($subject_id, array_merge($relation, $changes), $target, $my_uid, (int)$relation['id']);
        $message = $lang->sprintf($lang->relatives_relation_requests_created, 1);
    } else {
        Relatives_Database::update_relation((int)$relation['id'], $changes);
        Relatives_Graph::reset_family_memberships();
        Relatives_Kinship::recompute_for_person($subject_id);
        if ($anchor_id > 0) {
            Relatives_Kinship::recompute_for_person($anchor_id);
        }
        $message = $lang->relatives_saved;
    }
    if ($mybb->get_input('ajax')) {
        relatives_json_response(['success' => true, 'message' => $message, 'pending' => $target > 0]);
    }
    redirect(relatives_relation_return_url(), $message);
}

// --- action: adopt (POST) --------------------------------------------------------------

// Nimmt eine von anderen eingetragene, nicht bespielte Person in die eigene Liste auf --
// dieselbe Wirkung wie das Anlegen einer gleichnamigen Person, nur ohne Umweg ueber das
// Formular. Danach darf der Account sie bearbeiten (Relatives_Graph::can_edit_person()).
if ($action === 'adopt') {
    relatives_verify_post();
    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    $person = Relatives_Database::get_person($id);
    // Gespielte Charaktere gehoeren ihrem Account, nicht einer Liste.
    if ($person === null || ((int)$person['link_status'] === 2 && !empty($person['linked_uid']))) {
        relatives_error_response($lang->relatives_error_generic);
    }
    if (Relatives_Database::is_owner($id, $my_uid)) {
        relatives_error_response($lang->relatives_error_already_owner);
    }
    $max = (int)($mybb->settings['relatives_max_persons_per_user'] ?? 0);
    if ($max > 0 && Relatives_Database::count_persons_by_owner($my_uid) >= $max) {
        relatives_error_response($lang->relatives_error_limit_reached);
    }

    Relatives_Database::add_owner($id, $my_uid);
    Relatives_Kinship::recompute_owner($my_uid);
    if ($mybb->get_input('ajax')) {
        relatives_json_response(['success' => true, 'id' => $id]);
    }
    redirect('relatives.php?action=tree', $lang->relatives_adopted);
}

// --- action: add / edit (GET zeigt Formular, POST speichert) ----------------------------

if ($action === 'add' || $action === 'edit') {
    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    $is_edit = ($action === 'edit');
    $existing = null;

    if ($is_edit) {
        // Bearbeiten darf, wer mit einer nicht bespielten Person verbunden ist, der
        // eigene Account bei seinem Charakter-Knoten, und das Team (Relatives_Graph).
        if (!Relatives_Graph::can_edit_person($id, $my_uid)) {
            error_no_permission();
        }
        $existing = Relatives_Database::get_person_for_owner($id, $my_uid) ?? Relatives_Database::get_person($id);
        if ($existing === null) {
            error_no_permission();
        }
    }
    $in_my_list = $is_edit && Relatives_Database::is_owner($id, $my_uid);
    // Gespielter Charakter: das Formular arbeitet aus Sicht seiner Spieler*in, auch wenn
    // das Team bearbeitet. "Zu meinem Charakter" gibt es dort nicht.
    $is_played_edit = $is_edit && (int)$existing['link_status'] === 2 && !empty($existing['linked_uid']);
    $form_uid = $is_played_edit ? (int)$existing['linked_uid'] : $my_uid;

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
        $birth_year_raw = trim($mybb->get_input('birth_year'));
        $origin = trim($mybb->get_input('origin'));
        $description = $mybb->get_input('description');
        $relationship_description = $mybb->get_input('relationship_description');
        $is_wanted = $mybb->get_input('is_wanted', MyBB::INPUT_INT) ? 1 : 0;
        $is_deceased = $mybb->get_input('is_deceased', MyBB::INPUT_INT) ? 1 : 0;
        $death_note = trim($mybb->get_input('death_note'));
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

        // Gleichnamige Person beim Neuanlegen uebernehmen statt einen Doppelgaenger
        // anzulegen: der Account wird dort als weitere*r Besitzer*in eingetragen.
        $existing_by_name = null;
        if (!$is_edit) {
            $max = (int)($mybb->settings['relatives_max_persons_per_user'] ?? 0);
            if ($max > 0 && Relatives_Database::count_persons_by_owner($my_uid) >= $max) {
                relatives_error_response($lang->relatives_error_limit_reached);
            }
            $existing_by_name = Relatives_Database::find_person_by_name($name);
            if ($existing_by_name !== null && Relatives_Database::is_owner((int)$existing_by_name['id'], $my_uid)) {
                relatives_error_response($lang->relatives_error_already_owner);
            }
        }

        // Bei einer uebernommenen Person zaehlt fuer den Grad deren bereits hinterlegtes
        // Geschlecht (ausser "egal") -- das Formular darf es nicht ueberschreiben.
        $effective_gender = $gender;
        if ($existing_by_name !== null && (int)$existing_by_name['gender'] !== -1) {
            $effective_gender = (int)$existing_by_name['gender'];
        }

        // Mit Inplay-Jahr: Geburtsjahr (das Alter wird daraus berechnet), sonst das alte
        // Feld "Alter".
        $inplay_year = Relatives_Database::inplay_year();
        $age = null;
        $birth_year = null;
        if ($inplay_year !== null) {
            if ($birth_year_raw !== '') {
                if (!preg_match('/^-?\d{1,5}$/', $birth_year_raw) || (int)$birth_year_raw > $inplay_year || $inplay_year - (int)$birth_year_raw > 999) {
                    relatives_error_response($lang->relatives_error_invalid_birth_year);
                }
                $birth_year = (int)$birth_year_raw;
            }
        } elseif ($age_raw !== '') {
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

        $wanted_thread_link_final = $wanted_thread_link !== '' ? relatives_sanitize_url($wanted_thread_link) : '';
        if ($wanted_thread_link_final === '') {
            $wanted_thread_link_final = null;
        }

        // Stammdaten (gelten fuer alle Besitzer*innen der Person) ...
        $person_data = [
            'name'               => $name,
            'gender'             => $gender,
            'age'                => $age,
            'origin'             => $origin !== '' ? $origin : null,
            'description'        => $description !== '' ? $description : null,
            'is_wanted'          => $is_wanted,
            'wanted_thread_link' => $wanted_thread_link_final,
            'is_deceased'        => $is_deceased,
            // Freitext statt Datumsfeld: im RPG stehen dort auch Angaben wie
            // "Winter 1987" oder ein reines Jahr.
            'death_note'         => ($is_deceased && $death_note !== '') ? mb_substr($death_note, 0, 64) : null,
        ];
        // Kein Feld "Avatarperson" mehr: gespeicherte Altwerte bleiben unangetastet.
        if ($inplay_year !== null) {
            // Das Geburtsjahr ersetzt ein altes, fest eingetragenes Alter.
            $person_data['birth_year'] = $birth_year;
            $person_data['age'] = null;
        }
        // ... und die eigene Sicht auf die Person.
        $owner_data = [
            'description' => $relationship_description !== '' ? $relationship_description : null,
        ];

        // Verbindungen: beim Neuanlegen immer, beim Bearbeiten nur, wenn das Formular sie
        // mitschickt (relations[] bzw. related_to_submitted) -- ein Aufrufer ohne diese
        // Felder soll bestehende Verbindungen nicht stillschweigend loeschen.
        $own_person_id = $is_edit ? $id : ($existing_by_name !== null ? (int)$existing_by_name['id'] : 0);
        $relations = relatives_collect_relations(
            $my_uid,
            $own_person_id,
            $effective_gender,
            $is_edit,
            $effective_gender !== $gender,
            !$is_played_edit
        );

        // Familien, die sich nicht ueber neue Mitglieder freuen, nehmen keine weiteren
        // gespielten Charaktere auf. Wer die Familie verwalten darf (anlegende Person,
        // Team), ist davon ausgenommen.
        if ($relations) {
            $violations = Relatives_Graph::closed_family_violations(
                $own_person_id,
                $relations,
                $is_edit || $existing_by_name === null
            );
            foreach ($violations as $violation) {
                if (!relatives_can_manage_family($violation['family'])) {
                    relatives_error_response($lang->sprintf(
                        $lang->relatives_error_family_closed,
                        htmlspecialchars_uni((string)$violation['family']['name']),
                        htmlspecialchars_uni((string)$violation['person']['name'])
                    ));
                }
            }
        }

        // Felder anderer Plugins (z. B. Beruf aus der Jobliste) vor jedem Schreibzugriff pruefen.
        relatives_person_validate_extra($own_person_id);

        // Verbindungen zu fremden gespielten Charakteren muss deren Spieler*in erst
        // bestaetigen (Relatives_RelationRequests) -- sie werden als Anfrage gespeichert.
        $pending_relations = [];
        if ($relations) {
            [$relations, $pending_relations] = Relatives_RelationRequests::split($own_person_id, $relations, $my_uid);
        }

        $joined_existing = false;
        if ($is_edit) {
            Relatives_Database::update_person($id, $person_data);
            if ($in_my_list) {
                Relatives_Database::update_owner($id, $my_uid, $owner_data);
            }
            Relatives_Attributes::set_person_attributes($id, $attribute_option_ids);
            $person_id = $id;
        } elseif ($existing_by_name !== null) {
            $person_id = (int)$existing_by_name['id'];
            $joined_existing = true;

            // Die Stammdaten gehoeren auch anderen Charakteren: nur leere Felder
            // ergaenzen, nie ueberschreiben.
            $fill = [];
            if ((int)$existing_by_name['gender'] === -1 && $gender !== -1) {
                $fill['gender'] = $gender;
            }
            foreach (['age', 'birth_year', 'origin', 'description', 'wanted_thread_link'] as $field) {
                if (!array_key_exists($field, $person_data)) {
                    continue;
                }
                $current = $existing_by_name[$field] ?? null;
                if (($current === null || $current === '') && $person_data[$field] !== null) {
                    $fill[$field] = $person_data[$field];
                }
            }
            if ($fill) {
                Relatives_Database::update_person($person_id, $fill);
            }
            Relatives_Database::add_owner($person_id, $my_uid, $owner_data);
            if (empty(Relatives_Attributes::get_person_attributes($person_id))) {
                Relatives_Attributes::set_person_attributes($person_id, $attribute_option_ids);
            }
        } else {
            $person_id = Relatives_Database::create_person($person_data);
            Relatives_Database::add_owner($person_id, $my_uid, $owner_data);
            Relatives_Attributes::set_person_attributes($person_id, $attribute_option_ids);
        }

        if ($relations !== null) {
            if ($joined_existing) {
                // Die Person gibt es schon mit Verbindungen anderer Charaktere: ergaenzen,
                // nie ersetzen, und deren Hauptverbindung nicht verdraengen.
                $has_relations = (bool)Relatives_Database::get_relations_for_person($person_id);
                foreach ($relations as $relation) {
                    $relation['person_id'] = $person_id;
                    $relation['created_by'] = $my_uid;
                    if ($has_relations) {
                        $relation['is_primary'] = 0;
                    }
                    Relatives_Database::add_relation($relation);
                }
            } else {
                Relatives_Database::replace_relations($person_id, $relations, $my_uid);
            }
        }

        foreach ($pending_relations as $pending) {
            Relatives_RelationRequests::create($person_id, $pending['relation'], $pending['target_uid'], $my_uid, $pending['replaces']);
        }

        relatives_person_saved_extra($person_id, !$is_edit && !$joined_existing);

        // Verwandtschaft zu den Besitzer*innen neu berechnen -- geteilte Personen
        // haengen in mehreren Baeumen, und schon eine geaenderte Geschlechtsangabe
        // veraendert dort das Label.
        Relatives_Kinship::recompute_for_person($person_id);

        $message = $is_edit
            ? $lang->relatives_saved
            : ($joined_existing ? $lang->relatives_added_existing : $lang->relatives_added);
        if ($pending_relations) {
            $message .= ' ' . $lang->sprintf($lang->relatives_relation_requests_created, count($pending_relations));
        }
        if ($mybb->get_input('ajax')) {
            relatives_json_response([
                'success'         => true,
                'id'              => $person_id,
                'joined_existing' => $joined_existing,
                'pending'         => count($pending_relations),
                'message'         => $message,
            ]);
        }
        redirect(relatives_form_return_url(), $message);
    }

    // GET: Formular rendern
    add_breadcrumb($is_edit ? $lang->relatives_edit_person_title : $lang->relatives_add_person, 'relatives.php?action=' . $action);

    $relatives_form_title = $is_edit ? $lang->relatives_edit_person_title : $lang->relatives_add_person;
    $relatives_form_action = $is_edit ? 'edit' : 'add';
    $relatives_form_id = $is_edit ? (int)$existing['id'] : 0;
    $relatives_form_name = htmlspecialchars_uni($is_edit ? $existing['name'] : '');
    $form_inplay_year = Relatives_Database::inplay_year();
    if ($form_inplay_year !== null) {
        $form_birth_year = $is_edit && isset($existing['birth_year']) && $existing['birth_year'] !== null ? (int)$existing['birth_year'] : '';
        // Altbestand ohne Geburtsjahr: aus dem gespeicherten Alter vorschlagen.
        if ($form_birth_year === '' && $is_edit && $existing['age'] !== null) {
            $form_birth_year = $form_inplay_year - (int)$existing['age'];
        }
        $relatives_form_age_field = '<label for="relatives_birth_year">' . $lang->relatives_field_birth_year . '</label>'
            . '<input type="number" id="relatives_birth_year" name="birth_year" max="' . $form_inplay_year . '" value="' . $form_birth_year . '"'
            . ' data-inplay-year="' . $form_inplay_year . '" />'
            . ' <span class="relatives_hint" id="relatives_birth_year_age" data-template="' . htmlspecialchars_uni($lang->relatives_birth_year_age) . '"></span>';
    } else {
        $form_age = $is_edit && $existing['age'] !== null ? (int)$existing['age'] : '';
        $relatives_form_age_field = '<label for="relatives_age">' . $lang->relatives_field_age . '</label>'
            . '<input type="number" id="relatives_age" name="age" min="0" max="999" value="' . $form_age . '" />';
    }
    $relatives_form_description = htmlspecialchars_uni($is_edit ? (string)$existing['description'] : '');
    $relatives_form_relationship_description = htmlspecialchars_uni($is_edit ? (string)($existing['relationship_description'] ?? '') : '');

    $relatives_form_shared_note = '';
    if ($is_edit) {
        $other_owners = Relatives_Database::count_owners($relatives_form_id) - 1;
        if ($other_owners > 0) {
            $relatives_form_shared_note = '<p class="relatives_shared_note">'
                . htmlspecialchars_uni(sprintf($lang->relatives_shared_note, $other_owners)) . '</p>';
        }
    }

    // Moegliche Bezugspersonen: alle Personen im eigenen Baum-Ausschnitt (auch solche,
    // die nicht in der eigenen Liste stehen -- "Bruder von Oliver" soll gehen, auch wenn
    // Oliver jemand anderem "gehoert"), plus die bereits eingetragenen Anker.
    $my_account_person = $is_played_edit ? 0 : Relatives_Graph::account_person_id($my_uid);
    $relatives_form_allow_self = $is_played_edit ? '0' : '1';
    $relatives_form_self_label = htmlspecialchars_uni(relatives_self_anchor_label($my_account_person));
    $relatives_form_self_name = relatives_person_name($my_account_person);
    $relatives_form_self_name = htmlspecialchars_uni($relatives_form_self_name !== '' ? $relatives_form_self_name : $lang->relatives_relation_sentence_self);
    $relatives_form_return = in_array($mybb->get_input('return'), ['tree', 'tree_popup'], true) ? $mybb->get_input('return') : '';
    $relatives_cancel_form = relatives_nav_form(relatives_form_return_url(), 'relatives_cancel_form');
    $anchor_persons = [];
    $anchor_seen = [];
    foreach (Relatives_TreeBuilder::build($form_uid, $my_uid)['nodes'] as $tree_node) {
        $node_person = (int)($tree_node['person_id'] ?? 0);
        if ($node_person <= 0 || $node_person === $my_account_person || ($is_edit && $node_person === $relatives_form_id)) {
            continue;
        }
        $anchor_seen[$node_person] = true;
        $anchor_persons[] = [
            'id'    => $node_person,
            'name'  => (string)$tree_node['name'],
            'label' => (string)($tree_node['computed_label'] ?? ''),
        ];
    }
    // The tree only shows a few steps around the character; persons further out (e.g.
    // the husband of a distant ancestor) are still in the own list and must stay
    // selectable, otherwise they can never be linked to anyone.
    foreach (Relatives_Database::get_persons_by_owner($form_uid) as $owned) {
        $owned_id = (int)$owned['id'];
        if (isset($anchor_seen[$owned_id]) || $owned_id === $my_account_person || ($is_edit && $owned_id === $relatives_form_id)) {
            continue;
        }
        $anchor_seen[$owned_id] = true;
        $anchor_persons[] = [
            'id'    => $owned_id,
            'name'  => (string)$owned['name'],
            'label' => (string)Relatives_Kinship::display_label(
                $owned['computed_label'] !== null ? (string)$owned['computed_label'] : null,
                $owned['computed_via_person_id'] ?? null
            ),
        ];
    }

    $anchor_families = [];
    foreach (Relatives_Families::get_all() as $family) {
        $anchor_families[] = ['id' => (int)$family['id'], 'name' => (string)$family['name']];
    }

    // Bestehende Verbindungen fuer das Wiederholfeld ("p:<id>" = Person, "f:<id>" =
    // Familien-Kasten, "0" = zu meinem Charakter).
    $form_relations = [];
    if ($is_edit) {
        foreach (Relatives_Database::get_relations_for_person($relatives_form_id) as $relation) {
            $anchor = '0';
            if (!empty($relation['related_to_family_id'])) {
                $anchor = 'f:' . (int)$relation['related_to_family_id'];
            } elseif (!empty($relation['related_to_person_id']) && ($my_account_person === 0 || (int)$relation['related_to_person_id'] !== $my_account_person)) {
                $anchor_id = (int)$relation['related_to_person_id'];
                $anchor = 'p:' . $anchor_id;
                if (!isset($anchor_seen[$anchor_id]) && ($anchor_person = Relatives_Database::get_person($anchor_id)) !== null) {
                    $anchor_seen[$anchor_id] = true;
                    $anchor_persons[] = ['id' => $anchor_id, 'name' => (string)$anchor_person['name'], 'label' => ''];
                }
            }
            $form_relations[] = [
                'anchor'       => $anchor,
                'type_id'      => $relation['relationship_type_id'] !== null ? (int)$relation['relationship_type_id'] : 0,
                'custom_label' => (string)($relation['relationship_custom_label'] ?? ''),
                // Familie: eine Kind-Kante heisst "Kasten als Eltern anzeigen".
                'as_parent'    => !empty($relation['related_to_family_id']) && relatives_is_child_type($relation['relationship_type_id']),
                'secret'       => (int)$relation['is_secret'] === 1,
                'primary'      => (int)$relation['is_primary'] === 1,
            ];
        }
    }
    // Alphabetical (the own character is the separate "0" option and stays on top).
    // Collator sorts umlauts correctly ("Ä" next to "A"); without intl a case-insensitive
    // comparison is close enough. The id keeps equal names in a stable order.
    $collator = class_exists('Collator') ? new Collator('de_DE') : null;
    usort($anchor_persons, static function (array $a, array $b) use ($collator): int {
        $by_name = $collator !== null
            ? (int)$collator->compare($a['name'], $b['name'])
            : strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']));
        return $by_name !== 0 ? $by_name : $a['id'] <=> $b['id'];
    });
    $relatives_form_persons_json = relatives_json_for_script($anchor_persons);
    $relatives_form_families_json = relatives_json_for_script($anchor_families);
    $relatives_form_relations_json = relatives_json_for_script($form_relations);

    $relatives_form_is_wanted_checked = ($is_edit && (int)$existing['is_wanted'] === 1) ? 'checked="checked"' : '';
    // The field is type="url", which rejects relative paths -- older entries may hold
    // one ("showthread.php?tid=1"), so show it as an absolute forum URL.
    $wanted_link_value = $is_edit ? (string)$existing['wanted_thread_link'] : '';
    if ($wanted_link_value !== '' && !preg_match('#^https?://#i', $wanted_link_value)) {
        $wanted_link_value = rtrim($mybb->settings['bburl'], '/') . '/' . ltrim($wanted_link_value, '/');
    }
    $relatives_form_wanted_thread_link = htmlspecialchars_uni($wanted_link_value);
    $relatives_form_wanted_placeholder = htmlspecialchars_uni(rtrim($mybb->settings['bburl'], '/') . '/showthread.php?tid=123');
    $relatives_form_is_deceased_checked = ($is_edit && (int)($existing['is_deceased'] ?? 0) === 1) ? 'checked="checked"' : '';
    $relatives_form_death_note = htmlspecialchars_uni($is_edit ? (string)($existing['death_note'] ?? '') : '');

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
        if (empty($entry['options'])) {
            continue;
        }
        $group_label = htmlspecialchars_uni($entry['group']['label']);
        if (Relatives_Attributes::is_multiple_group($entry['group'])) {
            // Several values allowed: checkboxes.
            $relatives_form_attribute_groups .= '<fieldset class="relatives_attribute_group"><legend>' . $group_label . '</legend>'
                . '<div class="relatives_attribute_options">';
            foreach ($entry['options'] as $option) {
                $checked = in_array((int)$option['id'], $selected_option_ids, true) ? ' checked="checked"' : '';
                $relatives_form_attribute_groups .= '<label><input type="checkbox" name="attribute_option_ids[]" value="' . (int)$option['id'] . '"' . $checked . ' /> '
                    . htmlspecialchars_uni($option['label']) . '</label>';
            }
            $relatives_form_attribute_groups .= '</div></fieldset>';
            continue;
        }
        // Exactly one value (hair colour & co.): a dropdown makes that obvious.
        $field_id = 'relatives_attribute_' . (int)$entry['group']['id'];
        $relatives_form_attribute_groups .= '<div class="relatives_attribute_group relatives_attribute_group_single">'
            . '<label for="' . $field_id . '">' . $group_label . '</label>'
            . '<select id="' . $field_id . '" name="attribute_option_ids[]">'
            . '<option value="">' . htmlspecialchars_uni($lang->relatives_family_attribute_none) . '</option>';
        foreach ($entry['options'] as $option) {
            $selected = in_array((int)$option['id'], $selected_option_ids, true) ? ' selected="selected"' : '';
            $relatives_form_attribute_groups .= '<option value="' . (int)$option['id'] . '"' . $selected . '>' . htmlspecialchars_uni($option['label']) . '</option>';
        }
        $relatives_form_attribute_groups .= '</select></div>';
    }

    // Wrapper only when there is something to pick -- no orphaned "Attributes" label.
    if ($relatives_form_attribute_groups !== '') {
        $relatives_form_attribute_groups = '<div class="relatives_field relatives_field_attributes">'
            . '<span class="relatives_field_label">' . htmlspecialchars_uni($lang->relatives_field_attributes) . '</span>'
            . '<div class="relatives_attribute_groups">' . $relatives_form_attribute_groups . '</div></div>';
    }

    $relatives_form_link_section = '';
    if ($in_my_list) {
        $relatives_form_link_section = relatives_render_link_section($existing);
    }
    $relatives_form_incoming = $is_edit ? relatives_render_incoming_relations((int)$existing['id']) : '';
    $relatives_form_incoming_hint = $relatives_form_incoming !== ''
        ? '<p class="relatives_hint">' . htmlspecialchars_uni($lang->relatives_incoming_below) . '</p>'
        : '';
    $relatives_form_extra_fields = relatives_person_form_extra_fields($is_edit ? (int)$existing['id'] : 0);

    $relatives_relationship_types_json = relatives_json_for_script(Relatives_RelationshipTypes::get_active_json_payload());

    $relatives_nav = relatives_nav($my_uid, 'list');
    eval('$page = "' . $templates->get('relatives_form') . '";');
    output_page($page);
    exit;
}

// --- action: link_request / link_accept / link_decline (POST) --------------------------

if ($action === 'link_request') {
    relatives_verify_post();
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
    relatives_verify_post();
    $id = $mybb->get_input('id', MyBB::INPUT_INT);

    // Hatte der Account schon einen (automatisch angelegten) Knoten, wird er in die
    // bestaetigte Person ueberfuehrt -- jeder Charakter hat nur einen Eintrag.
    $previous_node = Relatives_Graph::find_account_person_id($my_uid);

    $success = $action === 'link_accept'
        ? Relatives_LinkManager::accept_link($id, $my_uid)
        : Relatives_LinkManager::decline_link($id, $my_uid);

    if ($success && $action === 'link_accept' && $previous_node > 0 && $previous_node !== $id) {
        Relatives_Graph::merge_person($previous_node, $id);
    }

    if ($success) {
        // Eine bestaetigte Verknuepfung verbindet zwei Baeume -- beide Seiten neu rechnen.
        Relatives_Kinship::recompute_for_person($id);
        Relatives_Kinship::recompute_owner($my_uid);
    }

    redirect('relatives.php?action=list', $success ? $lang->relatives_link_updated : $lang->relatives_error_generic);
}

// --- action: families / family_add / family_edit / family_delete -----------------------

/**
 * Familien-Kaesten sind forumsweit geteilt und haengen in fremden Stammbaeumen --
 * aendern/loeschen duerfen sie deshalb nur die Person, die sie angelegt hat, und das
 * Moderationsteam. Anlegen und Zuordnen darf jede*r.
 */
function relatives_can_manage_family(array $family): bool
{
    global $mybb;
    if ((int)$mybb->user['uid'] <= 0) {
        return false;
    }
    return (int)$family['created_by'] === (int)$mybb->user['uid']
        || !empty($mybb->usergroup['canmodcp'])
        || !empty($mybb->usergroup['cancp']);
}

if ($action === 'family_delete') {
    relatives_verify_post();
    $family_id = $mybb->get_input('id', MyBB::INPUT_INT);
    $family = Relatives_Families::get_by_id($family_id);
    if ($family === null) {
        error($lang->relatives_error_invalid_family);
    }
    if (!relatives_can_manage_family($family)) {
        error_no_permission();
    }
    Relatives_Families::delete($family_id);
    // Die Familie hing womoeglich in vielen Baeumen des gemeinsamen Geflechts.
    Relatives_Kinship::recompute_all();
    redirect('relatives.php?action=families', $lang->relatives_family_deleted);
}

if ($action === 'family_add' || $action === 'family_edit') {
    $family_id = $mybb->get_input('id', MyBB::INPUT_INT);
    $is_family_edit = ($action === 'family_edit');
    $family = null;
    if ($is_family_edit) {
        $family = Relatives_Families::get_by_id($family_id);
        if ($family === null) {
            error($lang->relatives_error_invalid_family);
        }
        if (!relatives_can_manage_family($family)) {
            error_no_permission();
        }
    }

    if ($mybb->request_method === 'post') {
        verify_post_check($mybb->get_input('my_post_key'));

        $family_name = trim($mybb->get_input('name'));
        if ($family_name === '') {
            relatives_error_response($lang->relatives_error_family_name_required);
        }
        $family_name = mb_substr($family_name, 0, 100);

        $family_data = [
            'name'        => $family_name,
            'color'       => $mybb->get_input('color'),
            'description' => $mybb->get_input('description'),
            // Das Formular schickt vor der Checkbox ein verstecktes "0"; fehlt das Feld
            // ganz (anderer Aufrufer), bleibt die Familie offen.
            'welcomes_new_members' => $mybb->get_input('welcomes_new_members') === '0' ? 0 : 1,
        ];

        $family_attribute_input = $mybb->get_input('family_attributes', MyBB::INPUT_ARRAY);
        if ($is_family_edit) {
            Relatives_Families::update($family_id, $family_data);
            Relatives_Attributes::set_family_attributes($family_id, $family_attribute_input);
        } else {
            // Familien sind forumsweit geteilt: ein gleichnamiger Kasten wird
            // wiederverwendet statt verdoppelt (dieselbe Regel wie bei Personen).
            // Dessen Angaben bleiben dann unangetastet -- auch die Attribute.
            $existing_family = Relatives_Families::find_by_name($family_name);
            if ($existing_family !== null) {
                $family_id = (int)$existing_family['id'];
            } else {
                $family_data['created_by'] = $my_uid;
                $family_id = Relatives_Families::create($family_data);
                Relatives_Attributes::set_family_attributes($family_id, $family_attribute_input);
            }
        }

        if ($mybb->get_input('ajax')) {
            relatives_json_response(['success' => true, 'id' => $family_id]);
        }
        redirect('relatives.php?action=families', $is_family_edit ? $lang->relatives_saved : $lang->relatives_family_added);
    }

    add_breadcrumb($is_family_edit ? $lang->relatives_family_edit_title : $lang->relatives_family_add, 'relatives.php?action=' . $action);

    $relatives_family_form_title = $is_family_edit ? $lang->relatives_family_edit_title : $lang->relatives_family_add;
    $relatives_family_form_action = $is_family_edit ? 'family_edit' : 'family_add';
    $relatives_family_form_id = $is_family_edit ? (int)$family['id'] : 0;
    $relatives_family_form_name = htmlspecialchars_uni($is_family_edit ? (string)$family['name'] : '');
    $relatives_family_form_color = htmlspecialchars_uni($is_family_edit
        ? Relatives_Families::sanitize_color((string)$family['color'])
        : Relatives_Families::DEFAULT_COLOR);
    $relatives_family_form_description = htmlspecialchars_uni($is_family_edit ? (string)$family['description'] : '');
    $relatives_family_form_welcomes_checked = (!$is_family_edit || Relatives_Families::welcomes_new_members($family))
        ? ' checked="checked"'
        : '';

    // Familien-Attribute aus dem ACP (z. B. Blutstatus): Vorauswahl als Dropdown,
    // Freitext als Textfeld. Ohne aktive Gruppen entfaellt der Abschnitt "Optional".
    $family_attribute_values = $is_family_edit ? Relatives_Attributes::get_family_values((int)$family['id']) : [];
    $relatives_family_form_attributes = '';
    foreach (Relatives_Attributes::get_options_grouped(true, Relatives_Attributes::APPLIES_FAMILY) as $entry) {
        $group_id = (int)$entry['group']['id'];
        $field_id = 'relatives_family_attribute_' . $group_id;
        $stored = $family_attribute_values[$group_id] ?? ['option_id' => 0, 'value' => ''];
        if (Relatives_Attributes::is_text_group($entry['group'])) {
            $input = '<input type="text" class="textbox" id="' . $field_id . '" name="family_attributes[' . $group_id . ']"'
                . ' maxlength="' . Relatives_Attributes::TEXT_MAX_LENGTH . '" value="' . htmlspecialchars_uni($stored['value']) . '" />';
        } else {
            $input = '<select id="' . $field_id . '" name="family_attributes[' . $group_id . ']">'
                . '<option value="">' . htmlspecialchars_uni($lang->relatives_family_attribute_none) . '</option>';
            foreach ($entry['options'] as $option) {
                $selected = (int)$option['id'] === $stored['option_id'] ? ' selected="selected"' : '';
                $input .= '<option value="' . (int)$option['id'] . '"' . $selected . '>' . htmlspecialchars_uni($option['label']) . '</option>';
            }
            $input .= '</select>';
        }
        $relatives_family_form_attributes .= '<div class="relatives_field">'
            . '<label for="' . $field_id . '">' . htmlspecialchars_uni($entry['group']['label']) . '</label>'
            . $input . '</div>';
    }
    if ($relatives_family_form_attributes !== '') {
        $relatives_family_form_attributes = '<h3 class="relatives_form_section">' . $lang->relatives_form_section_optional . '</h3>'
            . $relatives_family_form_attributes;
    }

    $relatives_nav = relatives_nav($my_uid, 'families');
    $relatives_cancel_form = relatives_nav_form('relatives.php?action=families', 'relatives_cancel_form');
    eval('$page = "' . $templates->get('relatives_family_form') . '";');
    output_page($page);
    exit;
}

/** Laenge des Beschreibungs-Anrisses auf den Familienkarten (Zeichen). */
const RELATIVES_FAMILY_TEASER_LENGTH = 200;

/**
 * Link zur Familienseite. Aus dem UserCP (families) bleibt sie im UserCP-Rahmen, aus der
 * oeffentlichen Liste im oeffentlichen Rahmen. Roh -- beim Ausgeben escapen.
 */
function relatives_family_url(int $family_id, bool $read_only): string
{
    return 'relatives.php?action=family&id=' . $family_id . ($read_only ? '' : '&from=families');
}

/**
 * Familienbeschreibung als HTML: MyCode (BBCode) und HTML erlaubt. Der MyBB-Parser
 * entschaerft dabei wie in Foren mit erlaubtem HTML script/style/meta/base-Tags,
 * javascript:-Links und on*-Event-Attribute.
 */
function relatives_family_description_html(string $description): string
{
    global $parser;
    $description = trim($description);
    if ($description === '') {
        return '';
    }
    if (!is_object($parser)) {
        require_once MYBB_ROOT . 'inc/class_parser.php';
        $parser = new postParser();
    }
    return $parser->parse_message($description, [
        'allow_html'      => 1,
        'allow_mycode'    => 1,
        'allow_smilies'   => 1,
        'allow_imgcode'   => 1,
        'allow_videocode' => 1,
        'filter_badwords' => 1,
        'nl2br'           => 1,
    ]);
}

/** Familienbeschreibung als reiner Text (Markup entfernt), z. B. fuer Anrisse. Roh. */
function relatives_family_description_plain(string $description): string
{
    $html = relatives_family_description_html($description);
    $text = html_entity_decode(strip_tags(str_replace(['<br />', '<br>'], ' ', $html)), ENT_QUOTES, 'UTF-8');
    return trim($text);
}

/** Anriss eines Textes ohne Zeilenumbrueche, gekuerzt an einer Wortgrenze. Roh. */
function relatives_text_teaser(string $text, int $length): string
{
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    if (my_strlen($text) <= $length) {
        return $text;
    }
    $cut = my_substr($text, 0, $length);
    $space = strrpos($cut, ' ');
    if ($space !== false && $space > strlen($cut) / 2) {
        $cut = substr($cut, 0, $space);
    }
    return rtrim($cut, " ,.;:-") . "\u{2026}";
}

/** Familien-Attribute als <dl> (leer, wenn keine). */
function relatives_family_attributes_html(array $attributes): string
{
    $html = '';
    foreach ($attributes as $attribute) {
        $html .= '<dt>' . htmlspecialchars_uni($attribute['label']) . '</dt>'
            . '<dd>' . htmlspecialchars_uni($attribute['value']) . '</dd>';
    }
    return $html !== '' ? '<dl class="relatives_family_card_attributes">' . $html . '</dl>' : '';
}

/** Ist die Person ein gespielter Charakter (bestaetigt verknuepfter Account)? */
function relatives_is_played(array $person): bool
{
    return (int)$person['link_status'] === 2 && !empty($person['linked_uid']);
}

/**
 * "X Personen, davon Y bespielt" -- gezaehlt ueber die volle Mitgliedschaft.
 *
 * @return array ['total' => int, 'played' => int]
 */
function relatives_family_member_counts(array $member_ids, array $persons): array
{
    $total = 0;
    $played = 0;
    foreach ($member_ids as $member_id) {
        if (!isset($persons[$member_id])) {
            continue;
        }
        $total++;
        if (relatives_is_played($persons[$member_id])) {
            $played++;
        }
    }
    return ['total' => $total, 'played' => $played];
}

/** Bearbeiten/Loeschen einer Familie (nur anlegende Person und Team), sonst ''. */
function relatives_family_manage_actions(array $family): string
{
    global $mybb, $lang;

    if (!relatives_can_manage_family($family)) {
        return '';
    }
    $family_id = (int)$family['id'];
    // Ohne eigenen Container: Karte und Familienseite setzen die Buttons in ihre Aktionszeile.
    return ' ' . relatives_nav_button('relatives.php?action=family_edit&id=' . $family_id, $lang->relatives_edit)
        . ' <form action="relatives.php?action=family_delete" method="post" class="relatives_inline_form"'
        . ' data-relatives-confirm="' . htmlspecialchars_uni($lang->relatives_family_confirm_delete) . '"'
        . ' data-relatives-confirm-title="' . htmlspecialchars_uni($lang->sprintf($lang->relatives_confirm_title_named, $family['name'])) . '"'
        . ' data-relatives-confirm-ok="' . htmlspecialchars_uni($lang->relatives_delete) . '"'
        . ' data-relatives-confirm-cancel="' . htmlspecialchars_uni($lang->relatives_cancel) . '">'
        . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
        . '<input type="hidden" name="id" value="' . $family_id . '" />'
        . '<input type="submit" class="button" value="' . htmlspecialchars_uni($lang->relatives_delete) . '" />'
        . '</form>';
}

/**
 * Mitglieder einer Familie, gruppiert in bespielt / gesucht / NPCs, als HTML-Bloecke.
 * Gespielte Charaktere als formatierter Profil-Link plus Link, der ihren Stammbaum als
 * Popup oeffnet.
 */
function relatives_family_member_groups_html(array $member_ids, array $persons): string
{
    global $lang, $templates, $mybb;

    $uids = [];
    foreach ($member_ids as $member_id) {
        if (isset($persons[$member_id]) && relatives_is_played($persons[$member_id])) {
            $uids[] = (int)$persons[$member_id]['linked_uid'];
        }
    }
    $users = relatives_get_users_bulk($uids);

    $groups = ['played' => [], 'wanted' => [], 'npc' => []];
    foreach ($member_ids as $member_id) {
        if (!isset($persons[$member_id])) {
            continue;
        }
        $member = $persons[$member_id];
        $sort_key = (string)$member['name'] . '#' . $member_id;
        $name = htmlspecialchars_uni((string)$member['name']);
        if (relatives_is_played($member)) {
            $linked_uid = (int)$member['linked_uid'];
            if (isset($users[$linked_uid])) {
                $user = $users[$linked_uid];
                $name = build_profile_link(
                    format_name(htmlspecialchars_uni((string)$user['username']), (int)$user['usergroup'], (int)$user['displaygroup']),
                    $linked_uid
                );
            }
            // Stammbaum als Popup -- wie der Stammbaum-Link in Profil und Postbit, auch fuer Gaest*innen.
            // relatives_tree_title_for nutzt %s (geteilt mit dem Seitentitel), nicht {1}.
            $name .= ' ' . relatives_tree_popup_link(
                $linked_uid,
                sprintf($lang->relatives_tree_title_for, (string)$member['name']),
                htmlspecialchars_uni($lang->relatives_family_member_tree),
                'relatives_family_member_tree'
            );
            $groups['played'][$sort_key] = '<li>' . $name . '</li>';
        } elseif ((int)$member['is_wanted'] === 1) {
            $thread = relatives_sanitize_url((string)($member['wanted_thread_link'] ?? ''));
            if ($thread !== '') {
                $name = '<a href="' . htmlspecialchars_uni($thread) . '">' . $name . '</a>';
            }
            $groups['wanted'][$sort_key] = '<li>' . $name . '</li>';
        } else {
            $groups['npc'][$sort_key] = '<li>' . $name . '</li>';
        }
    }

    $titles = [
        'played' => $lang->relatives_family_group_played,
        'wanted' => $lang->relatives_family_group_wanted,
        'npc'    => $lang->relatives_family_group_npc,
    ];
    $html = '';
    foreach ($groups as $group => $items) {
        if (!$items) {
            continue;
        }
        uksort($items, 'strnatcasecmp');
        $relatives_family_group_title = htmlspecialchars_uni($titles[$group]);
        $relatives_family_group_count = count($items);
        $relatives_family_group_items = implode('', $items);
        $relatives_family_group_class = htmlspecialchars_uni($group);
        eval('$html .= "' . $templates->get('relatives_family_page_group') . '";');
    }
    return $html;
}

/**
 * Formular "Zu dieser Familie hinzufuegen" fuer die Familienseite: der eigene Charakter
 * und alle Personen der eigenen Liste, die man bearbeiten darf und die noch nicht
 * Mitglied sind. '' fuer Gaest*innen oder wenn es niemanden mehr hinzuzufuegen gibt.
 */
function relatives_family_join_form(array $family, array $member_ids, bool $in_usercp): string
{
    global $mybb, $lang, $templates;

    $uid = (int)$mybb->user['uid'];
    if ($uid <= 0) {
        return '';
    }
    $members = array_flip(array_map('intval', $member_ids));

    $options = '';
    $account_person = Relatives_Graph::find_account_person_id($uid);
    if ($account_person === 0 || !isset($members[$account_person])) {
        $options .= '<option value="0">' . htmlspecialchars_uni(relatives_self_anchor_label($account_person)) . '</option>';
    }
    $person_options = [];
    foreach (Relatives_Database::get_persons_by_owner($uid) as $person) {
        $person_id = (int)$person['id'];
        if (isset($members[$person_id]) || $person_id === $account_person
            || !Relatives_Graph::can_edit_person($person_id, $uid, $person)) {
            continue;
        }
        $person_options[(string)$person['name'] . '#' . $person_id] = '<option value="' . $person_id . '">'
            . htmlspecialchars_uni((string)$person['name']) . '</option>';
    }
    if ($person_options) {
        uksort($person_options, 'strnatcasecmp');
        $options .= '<optgroup label="' . htmlspecialchars_uni($lang->relatives_anchor_group_persons) . '">'
            . implode('', $person_options) . '</optgroup>';
    }
    if ($options === '') {
        return '';
    }

    $relatives_family_join_id = (int)$family['id'];
    $relatives_family_join_options = $options;
    $relatives_family_join_from = $in_usercp ? 'families' : '';
    $relatives_family_join_closed_hint = Relatives_Families::welcomes_new_members($family) || relatives_can_manage_family($family)
        ? ''
        : '<p class="relatives_hint">' . htmlspecialchars_uni($lang->relatives_family_join_closed_hint) . '</p>';
    $form = '';
    eval('$form = "' . $templates->get('relatives_family_join') . '";');
    return $form;
}

/**
 * Filter + Familienkarten -- geteilt von der UserCP-Seite (action=families, mit
 * Bearbeiten/Loeschen) und der oeffentlichen Liste (action=family_list, reines Ansehen).
 *
 * Filter (GET, damit gefilterte Ansichten verlinkbar sind): neue Mitglieder erlaubt
 * ja/nein und je Familien-Attributgruppe mit Vorauswahl eine Option. Freitextgruppen
 * lassen sich nicht sinnvoll filtern und werden ausgelassen.
 *
 * @param string $page_action families | family_list (Ziel des Filterformulars)
 * @param bool   $read_only   true = keine Aktionen, Charaktere verlinken aufs Profil
 * @return array ['filter' => html, 'rows' => html, 'empty' => html]
 */
function relatives_render_family_overview(string $page_action, bool $read_only): array
{
    global $mybb, $lang, $templates, $theme;

    $families = Relatives_Families::get_all();
    $total_families = count($families);
    $family_attributes = Relatives_Attributes::get_family_attributes_bulk(array_keys($families));

    $filter_welcomes = $mybb->get_input('welcomes');
    if ($filter_welcomes !== '1' && $filter_welcomes !== '0') {
        $filter_welcomes = '';
    }
    $filter_groups = [];
    foreach (Relatives_Attributes::get_options_grouped(true, Relatives_Attributes::APPLIES_FAMILY) as $entry) {
        if (!Relatives_Attributes::is_text_group($entry['group']) && $entry['options']) {
            $filter_groups[(int)$entry['group']['id']] = $entry;
        }
    }
    $filter_attributes = [];
    $raw_filter_attributes = $mybb->get_input('attr', MyBB::INPUT_ARRAY);
    foreach ($filter_groups as $group_id => $entry) {
        $option_id = isset($raw_filter_attributes[$group_id]) && !is_array($raw_filter_attributes[$group_id])
            ? (int)$raw_filter_attributes[$group_id]
            : 0;
        if ($option_id > 0 && in_array($option_id, array_map('intval', array_column($entry['options'], 'id')), true)) {
            $filter_attributes[$group_id] = $option_id;
        }
    }
    $is_filtered = $filter_welcomes !== '' || $filter_attributes;
    if ($is_filtered) {
        $family_option_ids = $filter_attributes ? Relatives_Attributes::get_family_option_ids_bulk(array_keys($families)) : [];
        $families = array_filter($families, static function ($family) use ($filter_welcomes, $filter_attributes, $family_option_ids) {
            if ($filter_welcomes !== '' && Relatives_Families::welcomes_new_members($family) !== ($filter_welcomes === '1')) {
                return false;
            }
            foreach ($filter_attributes as $group_id => $option_id) {
                if (($family_option_ids[(int)$family['id']][$group_id] ?? 0) !== $option_id) {
                    return false;
                }
            }
            return true;
        });
    }

    $page_url = 'relatives.php?action=' . $page_action;
    $filter_html = '';
    if ($total_families > 0) {
        $welcomes_choices = [
            ''  => $lang->relatives_families_filter_all,
            '1' => $lang->relatives_families_filter_welcomes_yes,
            '0' => $lang->relatives_families_filter_welcomes_no,
        ];
        $welcomes_select = '<select id="relatives_filter_welcomes" name="welcomes">';
        foreach ($welcomes_choices as $value => $label) {
            $selected = (string)$value === $filter_welcomes ? ' selected="selected"' : '';
            $welcomes_select .= '<option value="' . $value . '"' . $selected . '>' . htmlspecialchars_uni($label) . '</option>';
        }
        $welcomes_select .= '</select>';
        $filter_fields = '<div class="relatives_families_filter_field">'
            . '<label for="relatives_filter_welcomes">' . htmlspecialchars_uni($lang->relatives_families_filter_welcomes) . '</label>'
            . $welcomes_select . '</div>';
        foreach ($filter_groups as $group_id => $entry) {
            $field_id = 'relatives_filter_attr_' . $group_id;
            $select = '<select id="' . $field_id . '" name="attr[' . $group_id . ']">'
                . '<option value="">' . htmlspecialchars_uni($lang->relatives_families_filter_all) . '</option>';
            foreach ($entry['options'] as $option) {
                $selected = ($filter_attributes[$group_id] ?? 0) === (int)$option['id'] ? ' selected="selected"' : '';
                $select .= '<option value="' . (int)$option['id'] . '"' . $selected . '>' . htmlspecialchars_uni($option['label']) . '</option>';
            }
            $select .= '</select>';
            $filter_fields .= '<div class="relatives_families_filter_field">'
                . '<label for="' . $field_id . '">' . htmlspecialchars_uni($entry['group']['label']) . '</label>'
                . $select . '</div>';
        }
        $filter_html = '<form action="relatives.php" method="get" class="relatives_families_filter">'
            . '<input type="hidden" name="action" value="' . htmlspecialchars_uni($page_action) . '" />'
            . $filter_fields
            . '<div class="relatives_families_filter_actions">'
            . '<input type="submit" class="button" value="' . htmlspecialchars_uni($lang->relatives_families_filter_submit) . '" />'
            . ($is_filtered ? ' <input type="submit" class="button" form="relatives_filter_reset_form" value="' . htmlspecialchars_uni($lang->relatives_families_filter_reset) . '" />' : '')
            . '</div></form>'
            . ($is_filtered ? relatives_nav_form($page_url, 'relatives_filter_reset_form') : '');
    }

    // Volle Mitgliedschaft (auch Geschwister, Nachkommen, Partner*innen), wie im Baum.
    $member_lists = Relatives_Graph::family_member_lists();
    $persons = Relatives_Kinship::world()['persons'];

    $rows = '';
    foreach ($families as $family) {
        $relatives_family_id = (int)$family['id'];
        $relatives_family_name = htmlspecialchars_uni((string)$family['name']);
        $relatives_family_color = htmlspecialchars_uni(Relatives_Families::sanitize_color((string)$family['color']));
        // Die Uebersicht zeigt nur einen Anriss; die volle Vorstellung steht auf der
        // Familienseite (action=family).
        $family_url = relatives_family_url($relatives_family_id, $read_only);
        $relatives_family_url = htmlspecialchars_uni($family_url);
        // Echter Button wie Bearbeiten/Loeschen daneben (conventions.md §9).
        $relatives_family_more = relatives_nav_button($family_url, $lang->relatives_family_open_page);
        $relatives_family_teaser = htmlspecialchars_uni(relatives_text_teaser(relatives_family_description_plain((string)$family['description']),RELATIVES_FAMILY_TEASER_LENGTH));
        $relatives_family_closed = Relatives_Families::welcomes_new_members($family)
            ? ''
            : '<div class="relatives_family_card_closed">' . htmlspecialchars_uni($lang->relatives_family_closed_badge) . '</div>';
        $relatives_family_attributes = relatives_family_attributes_html($family_attributes[$relatives_family_id] ?? []);
        $counts = relatives_family_member_counts($member_lists[$relatives_family_id] ?? [], $persons);
        $relatives_family_members_text = htmlspecialchars_uni($lang->sprintf(
            $lang->relatives_families_members_played,
            $counts['total'],
            $counts['played']
        ));
        $relatives_family_actions = $read_only ? '' : relatives_family_manage_actions($family);
        eval('$rows .= "' . $templates->get('relatives_families_row') . '";');
    }

    $empty = '';
    if ($rows === '') {
        $empty = '<p class="relatives_hint">'
            . ($total_families > 0 ? $lang->relatives_families_filter_no_match : $lang->relatives_families_empty)
            . '</p>';
    }

    return ['filter' => $filter_html, 'rows' => $rows, 'empty' => $empty];
}

/** username/usergroup/displaygroup je uid, eine Abfrage. */
function relatives_get_users_bulk(array $uids): array
{
    global $db;
    $uids = array_values(array_filter(array_map('intval', $uids), static function ($uid) {
        return $uid > 0;
    }));
    if (!$uids) {
        return [];
    }
    $users = [];
    $query = $db->simple_select('users', 'uid, username, usergroup, displaygroup', 'uid IN (' . implode(',', $uids) . ')');
    while ($row = $db->fetch_array($query)) {
        $users[(int)$row['uid']] = $row;
    }
    return $users;
}

/** Existiert ein globales oder theme-eigenes Template dieses Namens? */
function relatives_template_exists(string $title): bool
{
    global $db, $theme;
    if ($title === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $title)) {
        return false;
    }
    $sids = [-2, -1];
    if (isset($theme['templateset'])) {
        $sids[] = (int)$theme['templateset'];
    }
    $row = $db->fetch_array($db->simple_select(
        'templates',
        'tid',
        "title = '" . $db->escape_string($title) . "' AND sid IN (" . implode(',', $sids) . ")",
        ['limit' => 1]
    ));
    return (bool)$row;
}

if ($action === 'families') {
    add_breadcrumb($lang->relatives_families_title, 'relatives.php?action=families');

    $overview = relatives_render_family_overview('families', false);
    $relatives_families_filter = $overview['filter'];
    $relatives_family_rows = $overview['rows'];
    $relatives_families_empty = $overview['empty'];

    $relatives_nav = relatives_nav($my_uid, 'families', 'relatives.php?action=family_add', $lang->relatives_family_add, 'relatives_icon_family_add');
    eval('$page = "' . $templates->get('relatives_families') . '";');
    output_page($page);
    exit;
}

// --- action: family_list (oeffentliche Liste, reines Ansehen) ---------------------------

if ($action === 'family_list') {
    add_breadcrumb($lang->relatives_family_list_title, 'relatives.php?action=family_list');

    $overview = relatives_render_family_overview('family_list', true);
    $relatives_families_filter = $overview['filter'];
    $relatives_family_rows = $overview['rows'];
    $relatives_families_empty = $overview['empty'];
    eval('$relatives_family_list_content = "' . $templates->get('relatives_family_list_content') . '";');

    // Optional in den Rahmen der forumseigenen Listen einhaengen (z. B. listen.php mit den
    // Templates "listen" + "listen_nav"). Der Inhalt steht dem Rahmen unter beiden
    // Variablennamen zur Verfuegung, damit bestehende Listen-Templates unveraendert passen.
    $layout_template = trim((string)($mybb->settings['relatives_family_list_layout_template'] ?? ''));
    $nav_template = trim((string)($mybb->settings['relatives_family_list_nav_template'] ?? ''));
    if ($layout_template !== '' && relatives_template_exists($layout_template)) {
        $headline = $lang->relatives_family_list_title;
        $listen_content = $relatives_family_list_content;
        $listen_nav = '';
        if ($nav_template !== '' && relatives_template_exists($nav_template)) {
            eval('$listen_nav = "' . $templates->get($nav_template) . '";');
        }
        $relatives_list_nav = $listen_nav;
        eval('$page = "' . $templates->get($layout_template) . '";');
    } else {
        eval('$page = "' . $templates->get('relatives_family_list') . '";');
    }
    output_page($page);
    exit;
}

// --- action: family_join (POST) -------------------------------------------------------

// Ordnet den eigenen Charakter oder eine bearbeitbare Person einer Familie zu -- dieselbe
// Kante wie der Abschnitt "Familie" im Personenformular (Mitgliedschaft ohne Grad bzw.
// Kind-Kante bei "als Eltern anzeigen").
if ($action === 'family_join') {
    relatives_verify_post();
    $family = Relatives_Families::get_by_id($mybb->get_input('id', MyBB::INPUT_INT));
    if ($family === null) {
        error($lang->relatives_error_invalid_family);
    }
    $family_id = (int)$family['id'];
    $back_url = relatives_family_url($family_id, $mybb->get_input('from') !== 'families');

    $person_id = $mybb->get_input('person_id', MyBB::INPUT_INT);
    if ($person_id <= 0) {
        // Eigener Charakter: Knoten bei Bedarf anlegen.
        $person_id = Relatives_Graph::account_person_id($my_uid);
    }
    $person = $person_id > 0 ? Relatives_Database::get_person($person_id) : null;
    if ($person === null || !Relatives_Graph::can_edit_person($person_id, $my_uid, $person)) {
        error_no_permission();
    }

    $type_id = null;
    $concept = null;
    if ($mybb->get_input('family_as_parent', MyBB::INPUT_INT) === 1) {
        $label_gender = Relatives_RelationshipTypes::map_person_gender_to_label_gender((int)$person['gender']);
        $type = Relatives_RelationshipTypes::find_by_concept_and_gender('child', $label_gender)
            ?? Relatives_RelationshipTypes::find_by_concept_and_gender('child', Relatives_RelationshipTypes::LABEL_NEUTRAL);
        if ($type === null) {
            error($lang->relatives_error_invalid_relationship_type);
        }
        $type_id = (int)$type['id'];
        $concept = (string)$type['concept_key'];
    }

    if (Relatives_Graph::find_existing_statement($person_id, null, $family_id, $concept, null) !== null) {
        redirect($back_url, $lang->relatives_family_join_exists);
    }

    $relation = [
        'relationship_type_id'      => $type_id,
        'relationship_custom_label' => null,
        'related_to_person_id'      => null,
        'related_to_family_id'      => $family_id,
        'is_secret'                 => 0,
        'is_primary'                => 0,
    ];
    foreach (Relatives_Graph::closed_family_violations($person_id, [$relation], false) as $violation) {
        if (!relatives_can_manage_family($violation['family'])) {
            error($lang->sprintf(
                $lang->relatives_error_family_closed,
                htmlspecialchars_uni((string)$violation['family']['name']),
                htmlspecialchars_uni((string)$violation['person']['name'])
            ));
        }
    }

    $relation['person_id'] = $person_id;
    // Ohne andere Verbindung positioniert die Familie die Person im Baum.
    $relation['is_primary'] = Relatives_Database::get_primary_relation($person_id) === null ? 1 : 0;
    $relation['created_by'] = $my_uid;
    Relatives_Database::add_relation($relation);
    Relatives_Graph::reset_family_memberships();
    Relatives_Kinship::recompute_all();

    redirect($back_url, $lang->sprintf($lang->relatives_family_joined, htmlspecialchars_uni((string)$person['name']), htmlspecialchars_uni((string)$family['name'])));
}

// --- action: family (Familienseite: Vorstellung, Mitglieder, Aktionen) -----------------

if ($action === 'family') {
    $family = Relatives_Families::get_by_id($mybb->get_input('id', MyBB::INPUT_INT));
    if ($family === null) {
        error($lang->relatives_error_invalid_family);
    }
    $in_usercp = $my_uid > 0 && $mybb->get_input('from') === 'families';

    $relatives_family_id = (int)$family['id'];
    $relatives_family_name = htmlspecialchars_uni((string)$family['name']);
    $relatives_family_color = htmlspecialchars_uni(Relatives_Families::sanitize_color((string)$family['color']));
    $relatives_family_description = relatives_family_description_html((string)$family['description']);
    $relatives_family_closed = Relatives_Families::welcomes_new_members($family)
        ? ''
        : '<div class="relatives_family_card_closed">' . htmlspecialchars_uni($lang->relatives_family_closed_badge) . '</div>';
    $relatives_family_attributes = relatives_family_attributes_html(
        Relatives_Attributes::get_family_attributes_bulk([$relatives_family_id])[$relatives_family_id] ?? []
    );
    $member_ids = Relatives_Graph::family_member_lists()[$relatives_family_id] ?? [];
    $persons = Relatives_Kinship::world()['persons'];
    $counts = relatives_family_member_counts($member_ids, $persons);
    $relatives_family_members_text = htmlspecialchars_uni($lang->sprintf(
        $lang->relatives_families_members_played,
        $counts['total'],
        $counts['played']
    ));
    $relatives_family_groups = relatives_family_member_groups_html($member_ids, $persons);
    if ($relatives_family_groups === '') {
        $relatives_family_groups = '<p class="relatives_hint">' . $lang->relatives_family_no_members . '</p>';
    }
    $relatives_family_join = relatives_family_join_form($family, $member_ids, $in_usercp);
    $relatives_family_actions = $my_uid > 0 ? relatives_family_manage_actions($family) : '';
    $relatives_family_back_url = $in_usercp ? 'relatives.php?action=families' : 'relatives.php?action=family_list';

    eval('$relatives_family_page_content = "' . $templates->get('relatives_family_page_content') . '";');

    if ($in_usercp) {
        add_breadcrumb($lang->relatives_families_title, 'relatives.php?action=families');
        add_breadcrumb((string)$family['name'], relatives_family_url($relatives_family_id, false));
        $relatives_nav = relatives_nav($my_uid, 'families', 'relatives.php?action=family_add', $lang->relatives_family_add, 'relatives_icon_family_add');
        eval('$page = "' . $templates->get('relatives_family_page') . '";');
        output_page($page);
        exit;
    }

    add_breadcrumb($lang->relatives_family_list_title, 'relatives.php?action=family_list');
    add_breadcrumb((string)$family['name'], relatives_family_url($relatives_family_id, true));
    // Gleicher Rahmen wie die oeffentliche Familienliste (optional forumseigenes Listen-Template).
    $relatives_family_list_content = $relatives_family_page_content;
    $layout_template = trim((string)($mybb->settings['relatives_family_list_layout_template'] ?? ''));
    $nav_template = trim((string)($mybb->settings['relatives_family_list_nav_template'] ?? ''));
    if ($layout_template !== '' && relatives_template_exists($layout_template)) {
        $headline = (string)$family['name'];
        $listen_content = $relatives_family_list_content;
        $listen_nav = '';
        if ($nav_template !== '' && relatives_template_exists($nav_template)) {
            eval('$listen_nav = "' . $templates->get($nav_template) . '";');
        }
        $relatives_list_nav = $listen_nav;
        eval('$page = "' . $templates->get($layout_template) . '";');
    } else {
        eval('$page = "' . $templates->get('relatives_family_list') . '";');
    }
    output_page($page);
    exit;
}

// --- action: tree ------------------------------------------------------------------------

if ($action === 'tree') {
    $uid = $mybb->get_input('uid', MyBB::INPUT_INT);
    if ($uid <= 0) {
        $uid = $my_uid;
    }
    if ($uid <= 0) {
        // Gast ohne uid: es gibt keinen "eigenen" Baum.
        error_no_permission();
    }

    // Aufgeklappte Randknoten ("expand=12,45"), begrenzt gegen uebergrosse Anfragen.
    $expand = array_slice(array_values(array_filter(array_map('intval', explode(',', $mybb->get_input('expand'))), static function ($value) {
        return $value > 0;
    })), 0, 50);

    if ($mybb->get_input('ajax')) {
        relatives_json_response(Relatives_TreeBuilder::build($uid, $my_uid, $expand));
    }

    $target_user = ($uid === $my_uid) ? $mybb->user : get_user($uid);
    if (!$target_user || !isset($target_user['uid'])) {
        error($lang->relatives_error_user_not_found);
    }

    // Popup: der Baum allein auf einer Seite (Theme-CSS ja, Forenheader/-footer nein),
    // dessen Link man weitergeben kann -- auch an Gaeste. Fremde sehen ihn dort nur an,
    // auch das Team, das im normalen Baum sonst mitbearbeiten darf. Die Serverseite prueft Aenderungen
    // ohnehin eigenstaendig; readOnly blendet nur die Bearbeiten-Wege im Skript aus.
    $is_popup = $mybb->get_input('popup', MyBB::INPUT_INT) === 1;
    $is_read_only = $is_popup && $uid !== $my_uid;
    // Only your own tree counts as "your" section; someone else's tree is not.
    $relatives_nav = relatives_nav($uid, $uid === $my_uid ? 'tree' : '');
    $relatives_tree_share_url = htmlspecialchars_uni($mybb->settings['bburl'] . '/relatives.php?action=tree&uid=' . $uid . '&popup=1');
    // The embedded view only zooms with Ctrl + wheel (plain wheel scrolls the page); the
    // popup has nothing else to scroll and keeps zooming on the plain wheel.
    if ($is_read_only) {
        $relatives_tree_hint = $lang->relatives_tree_hint_read_only;
    } else {
        $relatives_tree_hint = $is_popup ? $lang->relatives_tree_hint_popup : $lang->relatives_tree_hint;
    }

    add_breadcrumb($lang->relatives_view_tree, 'relatives.php?action=tree');

    // Cache-Busting fuer relatives-tree.js: die Datei wird als reine statische
    // <script src>-Referenz eingebunden (kein MyBB-Stylesheet-Mechanismus wie bei den
    // CSS-Dateien), Browser koennen sie sonst beliebig lange nach einem Deploy
    // zwischenspeichern.
    $relatives_tree_js_version = (int)(@filemtime(MYBB_ROOT . 'inc/plugins/relatives/assets/relatives-tree.js') ?: TIME_NOW);

    $relatives_tree_title = sprintf($lang->relatives_tree_title_for, htmlspecialchars_uni($target_user['username']));
    $relatives_tree_json = relatives_json_for_script(Relatives_TreeBuilder::build($uid, $my_uid, $expand));
    $relatives_relationship_types_json = relatives_json_for_script(Relatives_RelationshipTypes::get_active_json_payload());
    $relatives_attribute_groups_json = relatives_json_for_script(Relatives_Attributes::get_options_grouped(true));
    $relatives_tree_config_json = relatives_json_for_script([
        'rootUid'   => $uid,
        'viewerUid' => $my_uid,
        // Knoten des eigenen Charakters: "zu meinem Charakter" im Panel meint ihn.
        'viewerPersonId' => Relatives_Graph::find_account_person_id($my_uid),
        'expand'    => $expand,
        'postCode'  => $mybb->post_code,
        'popup'     => $is_popup,
        'readOnly'  => $is_read_only,
        'inplayYear' => Relatives_Database::inplay_year(),
    ]);
    $relatives_tree_lang_json = relatives_json_for_script([
        'add_person'               => $lang->relatives_add_person,
        'add_relative_of'          => $lang->relatives_add_relative_of,
        'add_relative_of_note'     => $lang->relatives_add_relative_of_note,
        'edit_person'               => $lang->relatives_edit_person_title,
        'field_name'                => $lang->relatives_field_name,
        'field_gender'               => $lang->relatives_field_gender,
        'field_relationship'        => $lang->relatives_field_relationship,
        'field_relationship_custom' => $lang->relatives_field_relationship_custom,
        'field_age'                  => $lang->relatives_field_age,
        'field_birth_year'           => $lang->relatives_field_birth_year,
        'field_job'                  => $lang->relatives_field_job,
        'quick_add_more_hint'        => $lang->relatives_quick_add_more_hint,
        'field_origin'               => $lang->relatives_field_origin,
        'field_description'         => $lang->relatives_field_description,
        'field_relationship_description' => $lang->relatives_field_relationship_description,
        'field_is_wanted'            => $lang->relatives_field_is_wanted,
        'field_wanted_thread_link'   => $lang->relatives_field_wanted_thread_link,
        'added_existing'             => $lang->relatives_added_existing,
        'field_related_to'           => $lang->relatives_field_related_to,
        'related_to_self'            => relatives_self_anchor_label(Relatives_Graph::account_person_id($my_uid)),
        'related_to_hint'            => $lang->relatives_field_related_to_hint,
        'relation_sentence'          => $lang->relatives_relation_sentence,
        'relation_sentence_subject'  => $lang->relatives_relation_sentence_subject,
        'relation_sentence_self'     => $lang->relatives_relation_sentence_self,
        'field_attributes'          => $lang->relatives_field_attributes,
        'relationship_other'        => $lang->relatives_relationship_other,
        'relationship_unspecified'  => $lang->relatives_relationship_unspecified,
        'gender_any'                 => $lang->relatives_gender_any,
        'gender_male'                => $lang->relatives_gender_male,
        'gender_female'              => $lang->relatives_gender_female,
        'gender_diverse'             => $lang->relatives_gender_diverse,
        'save'                        => $lang->relatives_save,
        'save_failed'                => $lang->relatives_error_generic,
        'delete'                      => $lang->relatives_delete,
        'confirm_delete'             => $lang->relatives_confirm_delete,
        'confirm_delete_npc'         => $lang->relatives_confirm_delete_npc,
        'deleted'                    => $lang->relatives_deleted,
        'cancel'                      => $lang->relatives_cancel,
        'node_you'                    => $lang->relatives_tree_node_you,
        'node_account'               => $lang->relatives_tree_node_account,
        'toggle_branch'              => $lang->relatives_tree_toggle_branch,
        'badge_wanted'               => $lang->relatives_badge_wanted,
        'badge_linked'               => $lang->relatives_badge_link_confirmed,
        'badge_link_pending'         => $lang->relatives_badge_link_requested,
        'node_family' => $lang->relatives_tree_node_family,
        'family_members' => $lang->relatives_tree_family_members,
        'connections' => $lang->relatives_connections,
        'relation_delete' => $lang->relatives_incoming_delete,
        'relation_delete_confirm' => $lang->relatives_incoming_confirm_delete,
        'relation_of_word' => $lang->relatives_relation_of_word,
        'deceased' => $lang->relatives_deceased,
        'field_is_deceased' => $lang->relatives_field_is_deceased,
        'field_death_note' => $lang->relatives_field_death_note,
        'families_link' => $lang->relatives_families_title,
        'show_more' => $lang->relatives_tree_show_more,
        'derived' => $lang->relatives_tree_derived,
        'node_character' => $lang->relatives_tree_node_character,
        'placeholder_parent' => $lang->relatives_tree_placeholder_parent,
        'placeholder_grandparent' => $lang->relatives_tree_placeholder_grandparent,
        'placeholder_great_grandparent' => $lang->relatives_tree_placeholder_great_grandparent,
        'placeholder_ancestor' => $lang->relatives_tree_placeholder_ancestor,
        'placeholder_partner' => $lang->relatives_tree_placeholder_partner,
        'placeholder_and' => $lang->relatives_tree_placeholder_and,
        'placeholder_badge' => $lang->relatives_tree_placeholder_badge,
        'placeholder_note' => $lang->relatives_tree_placeholder_note,
        'placeholder_add' => $lang->relatives_tree_placeholder_add,
        'adopt' => $lang->relatives_tree_adopt,
        'adopt_note' => $lang->relatives_tree_adopt_note,
        'already_owner' => $lang->relatives_error_already_owner,
        'wheel_hint' => $lang->relatives_tree_wheel_hint,
        'attribute_none' => $lang->relatives_family_attribute_none,
        'wanted_placeholder' => rtrim($mybb->settings['bburl'], '/') . '/showthread.php?tid=123',
        'confirm' => str_replace('{1}', '%s', $lang->relatives_confirm_title_named),
        'empty_own' => $lang->relatives_tree_empty_own,
        'empty_other' => $lang->relatives_tree_empty_other,
    ]);

    eval('$relatives_tree_toolbar = "' . $templates->get('relatives_tree_toolbar') . '";');
    eval('$relatives_tree_scripts = "' . $templates->get('relatives_tree_scripts') . '";');
    eval('$page = "' . $templates->get($is_popup ? 'relatives_tree_popup' : 'relatives_tree') . '";');
    output_page($page);
    exit;
}

// --- action: list (Default) ---------------------------------------------------------------

$persons = Relatives_Database::get_persons_by_owner($my_uid);
$relationship_labels = relatives_get_relationship_labels($persons);
$my_delete_requests = Relatives_DeleteRequests::get_pending_by_requester($my_uid);
$open_delete_requests = Relatives_DeleteRequests::get_open_for_voter($my_uid);
$open_delete_person_ids = array_flip(array_map('intval', array_column($open_delete_requests, 'person_id')));
$relatives_rows = '';
$person_details = Relatives_Database::person_details(array_column($persons, 'id'));
foreach ($persons as $person) {
    $relatives_row_id = (int)$person['id'];
    $relatives_row_name = htmlspecialchars_uni($person['name'])
        . ((int)($person['is_deceased'] ?? 0) === 1 ? ' <span class="relatives_deceased_mark" title="' . htmlspecialchars_uni($lang->relatives_deceased) . '">&dagger;</span>' : '');
    if (isset($person_details[$relatives_row_id]['job'])) {
        $relatives_row_name .= '<div class="relatives_row_job">' . htmlspecialchars_uni($person_details[$relatives_row_id]['job']) . '</div>';
    }
    $relatives_row_relationship = htmlspecialchars_uni($relationship_labels[$relatives_row_id]);
    $relatives_row_age = Relatives_Database::age_label($person);
    $relatives_row_age = $relatives_row_age !== '' ? htmlspecialchars_uni($relatives_row_age) : '-';
    $relatives_row_confirm_title = htmlspecialchars_uni($lang->sprintf($lang->relatives_confirm_title_named, $person['name']));
    $relatives_row_confirm_message = htmlspecialchars_uni(Relatives_DeleteRequests::is_npc($person)
        ? $lang->relatives_confirm_delete_npc
        : $lang->relatives_confirm_delete);
    $relatives_row_delete_status = '';
    if (isset($my_delete_requests[$relatives_row_id])) {
        $state = $my_delete_requests[$relatives_row_id];
        $relatives_row_delete_status = '<span class="relatives_badge relatives_badge_delete_requested">'
            . htmlspecialchars_uni($lang->sprintf($lang->relatives_badge_delete_requested, $state['confirmed'], $state['total']))
            . '</span>';
    } elseif (isset($open_delete_person_ids[$relatives_row_id])) {
        $relatives_row_delete_status = '<span class="relatives_badge relatives_badge_delete_requested">'
            . htmlspecialchars_uni($lang->relatives_badge_delete_vote_open) . '</span>';
    }
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

$relatives_empty_notice = '';
$relatives_table_class = '';
if (empty($persons)) {
    $relatives_table_class = ' relatives_table_empty';
    // Empty state with a way forward instead of a dead end.
    $relatives_empty_notice = '<div class="relatives_empty_state">'
        . '<p>' . htmlspecialchars_uni($lang->relatives_no_persons_yet) . '</p>'
        . '<p class="relatives_empty_state_actions">'
        . relatives_nav_button('relatives.php?action=add', '+ ' . $lang->relatives_no_persons_add, 'relatives_nav_primary') . ' '
        . relatives_nav_button('relatives.php?action=tree', $lang->relatives_no_persons_tree)
        . '</p></div>';
}

$relatives_link_requests_box = '';
$pending = Relatives_Database::get_persons_by_linked_uid($my_uid);
$pending = array_filter($pending, static function ($person) {
    return (int)$person['link_status'] === 1;
});
if (!empty($pending)) {
    $relatives_link_requests_rows = '';
    $requesters = relatives_get_users_bulk(array_column($pending, 'link_requested_by'));
    foreach ($pending as $person) {
        $relatives_lr_id = (int)$person['id'];
        // Who asks, for which entry and in which role -- without that, accept/decline is a blind guess.
        $requester_uid = (int)($person['link_requested_by'] ?? 0);
        $requester = $requesters[$requester_uid] ?? null;
        $requester_html = $requester
            ? build_profile_link(format_name(htmlspecialchars_uni($requester['username']), (int)$requester['usergroup'], (int)$requester['displaygroup']), $requester_uid)
            : htmlspecialchars_uni($lang->relatives_link_request_unknown_user);
        $relation_text = $requester ? relatives_get_relation($relatives_lr_id, $requester_uid)['text'] : '';
        $relatives_lr_text = $relation_text !== ''
            ? $lang->sprintf($lang->relatives_link_request_text_relation, $requester_html, htmlspecialchars_uni($person['name']), htmlspecialchars_uni($relation_text))
            : $lang->sprintf($lang->relatives_link_request_text, $requester_html, htmlspecialchars_uni($person['name']));
        $relatives_lr_tree_link = $requester
            ? '<a class="relatives_link_request_tree" href="relatives.php?action=tree&amp;uid=' . $requester_uid . '">' . htmlspecialchars_uni($lang->relatives_link_request_view_tree) . '</a>'
            : '';
        eval('$relatives_lr_row = "' . $templates->get('relatives_link_requests_row') . '";');
        $relatives_link_requests_rows .= $relatives_lr_row;
    }
    eval('$relatives_link_requests_box = "' . $templates->get('relatives_link_requests') . '";');
}

$relatives_delete_requests_box = '';
if (!empty($open_delete_requests)) {
    $relatives_delete_requests_rows = '';
    $requesters = relatives_get_users_bulk(array_column($open_delete_requests, 'requested_by'));
    foreach ($open_delete_requests as $request) {
        $relatives_dr_id = (int)$request['id'];
        $requester_uid = (int)$request['requested_by'];
        $requester = $requesters[$requester_uid] ?? null;
        $requester_html = $requester
            ? build_profile_link(format_name(htmlspecialchars_uni($requester['username']), (int)$requester['usergroup'], (int)$requester['displaygroup']), $requester_uid)
            : htmlspecialchars_uni($lang->relatives_link_request_unknown_user);
        $relatives_dr_text = $lang->sprintf($lang->relatives_delete_request_text, $requester_html, htmlspecialchars_uni($request['person_name']));
        $relatives_dr_date = htmlspecialchars_uni($lang->sprintf($lang->relatives_delete_request_date, my_date('normal', (int)$request['created_at'])));
        $relatives_dr_confirm_title = htmlspecialchars_uni($lang->sprintf($lang->relatives_confirm_title_named, $request['person_name']));
        $relatives_dr_confirm_message = htmlspecialchars_uni($lang->relatives_delete_vote_confirm_message);
        eval('$relatives_dr_row = "' . $templates->get('relatives_delete_requests_row') . '";');
        $relatives_delete_requests_rows .= $relatives_dr_row;
    }
    eval('$relatives_delete_requests_box = "' . $templates->get('relatives_delete_requests') . '";');
}

// Verbindungsanfragen: an mich (bestaetigen/ablehnen) und von mir (zurueckziehen).
$relatives_relation_requests_box = '';
$incoming_requests = Relatives_RelationRequests::get_open_for_target($my_uid);
$outgoing_requests = Relatives_RelationRequests::get_open_by_requester($my_uid);
if ($incoming_requests || $outgoing_requests) {
    $request_users = relatives_get_users_bulk(array_merge(
        array_column($incoming_requests, 'requested_by'),
        array_column($outgoing_requests, 'target_uid')
    ));
    $user_html = static function (int $uid) use ($request_users, $lang): string {
        $user = $request_users[$uid] ?? null;
        return $user
            ? build_profile_link(format_name(htmlspecialchars_uni($user['username']), (int)$user['usergroup'], (int)$user['displaygroup']), $uid)
            : htmlspecialchars_uni($lang->relatives_link_request_unknown_user);
    };
    $relatives_relation_requests_rows = '';
    foreach (array_merge($incoming_requests, $outgoing_requests) as $request) {
        $relatives_rr_id = (int)$request['id'];
        $incoming = in_array($request, $incoming_requests, true);
        $statement = htmlspecialchars_uni(Relatives_RelationRequests::describe($request));
        $relatives_rr_text = $incoming
            ? $lang->sprintf($lang->relatives_relation_request_incoming, $user_html((int)$request['requested_by']), $statement)
            : $lang->sprintf($lang->relatives_relation_request_outgoing, $statement, $user_html((int)$request['target_uid']));
        if ((int)$request['replaces_relation_id'] > 0) {
            $relatives_rr_text .= ' <span class="relatives_badge">' . htmlspecialchars_uni($lang->relatives_relation_request_change) . '</span>';
        }
        $relatives_rr_date = htmlspecialchars_uni($lang->sprintf($lang->relatives_delete_request_date, my_date('normal', (int)$request['created_at'])));
        $answers = $incoming
            ? ['accept' => $lang->relatives_relation_request_accept, 'decline' => $lang->relatives_relation_request_decline]
            : ['cancel' => $lang->relatives_relation_request_cancel];
        $relatives_rr_actions = '';
        foreach ($answers as $answer => $label) {
            $relatives_rr_actions .= '<form action="relatives.php?action=relation_request" method="post" class="relatives_inline_form">'
                . '<input type="hidden" name="id" value="' . $relatives_rr_id . '" />'
                . '<input type="hidden" name="answer" value="' . $answer . '" />'
                . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
                . '<input type="submit" class="button" value="' . htmlspecialchars_uni($label) . '" />'
                . '</form> ';
        }
        eval('$relatives_rr_row = "' . $templates->get('relatives_relation_requests_row') . '";');
        $relatives_relation_requests_rows .= $relatives_rr_row;
    }
    eval('$relatives_relation_requests_box = "' . $templates->get('relatives_relation_requests') . '";');
}

add_breadcrumb($lang->relatives_my_persons_title, 'relatives.php?action=list');

$relatives_nav = relatives_nav($my_uid, 'list', 'relatives.php?action=add', $lang->relatives_add_person);
eval('$page = "' . $templates->get('relatives_list') . '";');
output_page($page);

// --- lokale Hilfsfunktionen (nur fuer diese Datei) -----------------------------------------

/**
 * Rendert den "Mit Account verknuepfen"-Abschnitt am Ende des Bearbeiten-Formulars.
 */
function relatives_render_link_section(array $person): string
{
    global $lang, $mybb, $templates;

    $relatives_link_person_id = (int)$person['id'];
    $status = (int)$person['link_status'];

    // Linked/pending: name the account, so users see who they are waiting for.
    $linked_user = (int)$person['linked_uid'] > 0 ? get_user((int)$person['linked_uid']) : null;
    $linked_name = $linked_user
        ? build_profile_link(htmlspecialchars_uni($linked_user['username']), (int)$linked_user['uid'])
        : '#' . (int)$person['linked_uid'];

    if ($status === 2) {
        $relatives_link_section_body = '<p class="relatives_link_state relatives_link_state_confirmed">'
            . $lang->sprintf($lang->relatives_link_state_confirmed, $linked_name) . '</p>';
    } elseif ($status === 1) {
        $relatives_link_section_body = '<p class="relatives_link_state relatives_link_state_pending">'
            . $lang->sprintf($lang->relatives_link_state_pending, $linked_name) . '</p>';
    } else {
        eval('$relatives_link_section_body = "' . $templates->get('relatives_link_request_form') . '";');
    }

    eval('$section = "' . $templates->get('relatives_link_section') . '";');
    return $section;
}
