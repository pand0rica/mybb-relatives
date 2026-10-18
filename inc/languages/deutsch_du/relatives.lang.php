<?php
/**
 * Verwandtschaften & Stammbaum - Frontend-Sprachdatei (Deutsch, Du-Form)
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

$l['relatives'] = "Verwandtschaften & Stammbaum";
$l['relatives_disabled'] = "Diese Funktion ist derzeit deaktiviert.";

// Navigation / Titel
$l['relatives_my_persons_title'] = "Meine Verwandten";
$l['relatives_add_person'] = "Person hinzufügen";
$l['relatives_add_relative_of'] = "Verwandte*n von %s hinzufügen";
$l['relatives_add_relative_of_note'] = "Der Verwandtschaftsgrad wird aus Sicht von %s gewählt (z. B. „Bruder“ heißt: Bruder von %s). Der Baum zeigt dir automatisch, was das aus deiner eigenen Sicht bedeutet (z. B. „Onkel“).";
$l['relatives_edit_person_title'] = "Person bearbeiten";
$l['relatives_view_tree'] = "Stammbaum ansehen";
$l['relatives_usercp_menu_item'] = "Verwandtschaften & Stammbaum";
$l['relatives_tree_title_for'] = "Stammbaum von %s";
$l['relatives_tree_reset_view'] = "Ansicht zurücksetzen";
$l['relatives_tree_zoom_in'] = "Vergrößern";
$l['relatives_tree_zoom_out'] = "Verkleinern";
$l['relatives_tree_hint'] = "Zum Verschieben ziehen, zum Zoomen Strg (Mac: ⌘) + Mausrad oder die Knöpfe +/− nutzen. Klick auf einen Kasten öffnet die passenden Aktionen.";
$l['relatives_tree_hint_popup'] = "Zum Verschieben ziehen, zum Zoomen scrollen. Klick auf einen Kasten öffnet die passenden Aktionen.";
$l['relatives_tree_wheel_hint'] = "Zum Zoomen Strg (Mac: ⌘) gedrückt halten und scrollen";
$l['relatives_tree_empty_own'] = "Dein Stammbaum ist noch leer. Klick auf deinen Kasten, um die erste Person hinzuzufügen.";
$l['relatives_tree_empty_other'] = "In diesem Stammbaum ist noch niemand eingetragen.";
$l['relatives_tree_popup'] = "Stammbaum als Popup öffnen";
$l['relatives_tree_link'] = "Stammbaum";
$l['relatives_tree_copy_link'] = "Link kopieren";
$l['relatives_tree_link_copied'] = "Link kopiert";
$l['relatives_tree_hint_read_only'] = "Zum Verschieben ziehen, zum Zoomen scrollen. Klick auf einen Kasten zeigt die Details.";
$l['relatives_tree_legend_self'] = "Du";
$l['relatives_tree_node_you'] = "Du";
$l['relatives_tree_node_account'] = "Account";
$l['relatives_tree_toggle_branch'] = "Ast ein-/ausklappen";

// Liste
$l['relatives_col_name'] = "Name";
$l['relatives_col_relationship'] = "Verwandtschaft";
$l['relatives_col_age'] = "Alter";
$l['relatives_col_status'] = "Status";
$l['relatives_col_actions'] = "Aktionen";
$l['relatives_no_persons_yet'] = "Du hast noch keine Verwandten angelegt. Leg die erste Person über das Formular an – oder direkt im Stammbaum, indem du auf deinen eigenen Kasten klickst.";
$l['relatives_no_persons_add'] = "Erste Person anlegen";
$l['relatives_no_persons_tree'] = "Im Stammbaum anlegen";
$l['relatives_edit'] = "Bearbeiten";
$l['relatives_delete'] = "Löschen";
$l['relatives_confirm_delete'] = 'Diese Person aus deiner Liste entfernen? Im gemeinsamen Stammbaum bleibt sie mit ihren Verbindungen erhalten.';
$l['relatives_cancel'] = "Abbrechen";
$l['relatives_save'] = "Speichern";

// Flash-Meldungen
$l['relatives_added'] = "Person hinzugefügt.";
$l['relatives_saved'] = "Änderungen gespeichert.";
$l['relatives_deleted'] = "Person aus deiner Liste entfernt.";
$l['relatives_added_existing'] = 'Eine Person mit diesem Namen gab es schon – sie steht jetzt auch in deiner Liste. Vorhandene Angaben wurden nicht überschrieben, deine Verbindungen wurden ergänzt.';
$l['relatives_shared_note'] = 'Weitere Charaktere mit dieser Person: %d. Alle Angaben und Verbindungen gelten im gemeinsamen Stammbaum für alle.';

// Formularfelder
$l['relatives_field_name'] = "Name";
$l['relatives_field_gender'] = "Geschlecht";
$l['relatives_field_relationship'] = "Verwandtschaftsgrad";
$l['relatives_field_relationship_custom'] = "Freie Bezeichnung";
$l['relatives_field_age'] = "Alter";
$l['relatives_field_birth_year'] = "Geburtsjahr";
$l['relatives_field_job'] = "Beruf";
$l['relatives_quick_add_more_hint'] = "Weitere Angaben wie den Beruf trägst du nach dem Speichern über „Person bearbeiten“ ein.";
$l['relatives_birth_year_age'] = "= {1} Jahre (Inplay-Jahr {2})";
$l['relatives_age_born'] = "{1} (geb. {2})";
$l['relatives_error_invalid_birth_year'] = "Bitte gib ein gültiges Geburtsjahr ein (nicht nach dem aktuellen Inplay-Jahr).";
$l['relatives_field_origin'] = "Herkunft";
$l['relatives_field_avatar'] = "Avatarperson";
$l['relatives_field_description'] = "Beschreibung der Person";
$l['relatives_field_relationship_description'] = "Eure Beziehung";
$l['relatives_field_related_to'] = "Bezugsperson";
$l['relatives_field_related_to_hint'] = "Der Verwandtschaftsgrad gilt relativ zu dieser Person: „Bruder“ mit Bezugsperson „Mutter“ ergibt im Stammbaum „Onkel“. Ohne Bezugsperson gilt er relativ zu dir.";
$l['relatives_related_to_self'] = '– zu deinem Charakter –';
$l['relatives_related_to_self_named'] = '{1} (dein Charakter)';
$l['relatives_relation_sentence'] = 'Bedeutet: {1} ist {2} von {3}.';
$l['relatives_relation_sentence_subject'] = 'Diese Person';
$l['relatives_relation_sentence_self'] = 'deinem Charakter';
$l['relatives_error_invalid_related_to'] = "Diese Bezugsperson kann nicht gewählt werden: Sie ist nicht in deiner Liste oder würde eine Schleife ergeben.";
$l['relatives_field_is_wanted'] = "Als gesucht markieren";
$l['relatives_field_wanted_thread_link'] = "Link zum Gesuchs-Thread";
$l['relatives_field_attributes'] = "Attribute";
$l['relatives_field_link_username'] = "Benutzername, mit dem diese Person verknüpft werden soll";

$l['relatives_gender_any'] = "egal";
$l['relatives_gender_male'] = "männlich";
$l['relatives_gender_female'] = "weiblich";
$l['relatives_gender_diverse'] = "divers";
$l['relatives_relationship_other'] = "Sonstige Verbindung";
$l['relatives_relationship_unspecified'] = "nicht angegeben";
$l['relatives_relationship_missing_neutral'] = "Für diese Verwandtschaft ist noch keine neutrale Bezeichnung hinterlegt. Bitte ein Team-Mitglied ansprechen.";
$l['relatives_origin_none'] = "- keine -";

$l['relatives_badge_wanted'] = "Gesucht";
$l['relatives_badge_link_requested'] = "Verknüpfung angefragt";
$l['relatives_badge_link_confirmed'] = "Verknüpft";

// Validierungsfehler
$l['relatives_error_name_required'] = "Bitte gib einen Namen ein.";
$l['relatives_error_custom_label_required'] = "Bitte gib eine freie Bezeichnung ein oder wähle einen Verwandtschaftsgrad aus der Liste.";
$l['relatives_error_invalid_relationship_type'] = "Der gewählte Verwandtschaftsgrad ist ungültig.";
$l['relatives_error_relationship_gender_mismatch'] = "Der gewählte Verwandtschaftsgrad passt nicht zum gewählten Geschlecht.";
$l['relatives_error_existing_gender_mismatch'] = "Eine Person mit diesem Namen gibt es bereits mit einem anderen Geschlecht. Bitte wähle einen dazu passenden Verwandtschaftsgrad.";
$l['relatives_error_already_owner'] = "Diese Person ist bereits in deiner Liste.";
$l['relatives_error_invalid_age'] = "Bitte gib ein gültiges Alter ein.";
$l['relatives_error_invalid_origin'] = "Bitte wähle eine gültige Herkunft aus.";
$l['relatives_error_limit_reached'] = "Du hast die maximale Anzahl an Verwandten erreicht.";
$l['relatives_error_username_required'] = "Bitte gib einen Benutzernamen ein.";
$l['relatives_error_user_not_found'] = "Dieser Account wurde nicht gefunden.";
$l['relatives_error_generic'] = "Etwas ist schiefgelaufen. Bitte versuch es erneut.";

// Gesucht-Box (Profil)
$l['relatives_wanted_box_title'] = "Gesucht";
$l['relatives_wanted_thread_link_text'] = "Zum Gesuchs-Thread";
$l['relatives_wanted_more'] = "Mehr";
$l['relatives_wanted_age'] = "{1} Jahre";

// Verknüpfungs-Workflow
$l['relatives_pending_link_requests'] = "Offene Verknüpfungsanfragen";
$l['relatives_link_request_text'] = '{1} möchte den Eintrag <strong>„{2}“</strong> aus dem eigenen Stammbaum mit deinem Account verknüpfen.';
$l['relatives_link_request_text_relation'] = '{1} möchte den Eintrag <strong>„{2}“</strong> ({3}) mit deinem Account verknüpfen.';
$l['relatives_link_request_unknown_user'] = "Ein*e unbekannte*r Nutzer*in";
$l['relatives_link_request_view_tree'] = "Stammbaum der anfragenden Person ansehen";
$l['relatives_link_accept'] = "Annehmen";
$l['relatives_link_decline'] = "Ablehnen";
$l['relatives_link_requested'] = "Verknüpfungsanfrage gesendet.";
$l['relatives_link_updated'] = "Verknüpfungsstatus aktualisiert.";
$l['relatives_link_section_title'] = "Mit einem Account verknüpfen";
$l['relatives_link_section_hint'] = "Wird diese Person im Forum als eigener Charakter gespielt? Dann verknüpfe den Eintrag mit dem Account. Der Account bekommt eine PN und muss zustimmen – danach sind eure Stammbäume verbunden, und ein Klick auf den Kasten führt in den Stammbaum dieses Charakters.";
$l['relatives_link_state_confirmed'] = 'Verknüpft mit {1}.';
$l['relatives_link_state_pending'] = 'Anfrage an {1} gesendet – wartet auf Bestätigung.';
$l['relatives_link_search_user'] = "Benutzernamen suchen …";
$l['relatives_confirm_title_named'] = '„{1}“ löschen?';
$l['relatives_link_send_request'] = "Verknüpfungsanfrage senden";

// PM bei Verknüpfungsanfrage (siehe LinkManager)
$l['relatives_pm_link_request_subject'] = "Verknüpfungsanfrage: %s";
$l['relatives_pm_link_request_message'] = "Jemand möchte den Verwandten-Eintrag \"%s\" mit deinem Account verknüpfen, damit eure Stammbäume verbunden werden.\n\nDu kannst diese Anfrage hier annehmen oder ablehnen:\n%s";

// v2.1: Verbindungen, Familien, Verstorben, Verwandtschafts-Cache
$l['relatives_relation_of'] = '{1} von {2}';
$l['relatives_relation_of_word'] = 'von';
$l['relatives_connections'] = 'Verbindungen';
$l['relatives_deceased'] = 'Verstorben';
$l['relatives_tree_node_family'] = 'Familie';
$l['relatives_tree_legend_family'] = 'Familie';
$l['relatives_tree_legend_deceased'] = 'Verstorben';
$l['relatives_tree_legend_line_solid'] = 'Leiblich / verheiratet';
$l['relatives_tree_legend_line_dashed'] = 'Adoptiert / Pflege / getrennt';
$l['relatives_tree_legend_line_dotted'] = 'Stief / angeheiratet / verlobt';
$l['relatives_tree_legend_line_secret'] = 'Inplay geheime Verbindung';
$l['relatives_tree_legend_non_kin'] = 'Freund*innen, Bekannte & Co. stehen nur in der Liste, nicht im Stammbaum.';
$l['relatives_field_is_deceased'] = 'Verstorben';
$l['relatives_field_death_note'] = 'Todesjahr oder -datum';
$l['relatives_field_relations'] = 'Verbindungen';
$l['relatives_field_relations_hint'] = 'Der Grad gilt immer relativ zur gewählten Bezugsperson bzw. Familie: „Bruder“ mit Bezugsperson „Delia“ heißt „Bruder von Delia“. Du kannst mehrere Verbindungen anlegen, z. B. leibliche Eltern und Adoptiveltern. Die Hauptverbindung bestimmt, wo die Person im Stammbaum steht.';
$l['relatives_form_section_optional'] = 'Optional';
$l['relatives_relation_anchor'] = 'Bezugsperson oder Familie';
$l['relatives_relation_anchor_choose'] = '– Bezugsperson wählen –';
$l['relatives_error_anchor_required'] = 'Bitte wähle für jede Verbindung eine Bezugsperson. Ein gespielter Charakter kann nicht mit sich selbst verbunden werden.';
$l['relatives_incoming_below'] = 'Weitere Verbindungen sind bei der jeweils anderen Person gespeichert – du findest sie unten unter „Von anderen eingetragene Verbindungen“.';
$l['relatives_relation_add'] = 'Weitere Verbindung hinzufügen';
$l['relatives_relation_remove'] = 'Entfernen';
$l['relatives_relation_primary'] = 'Hauptverbindung';
$l['relatives_relation_secret'] = 'Inplay geheim (blasse Linie)';
$l['relatives_relation_secret_desc'] = 'Die Linie wird im Stammbaum blass gezeichnet, z. B. für eine Abstammung, von der die Figuren nichts wissen. Die Verbindung bleibt für alle sichtbar.';
$l['relatives_relation_family_as_parent'] = 'Familie als Eltern anzeigen';
$l['relatives_relation_family_hint'] = 'Bei einer Familie brauchst du keinen Verwandtschaftsgrad. Mit Haken steht der Familien-Kasten im Stammbaum an der Stelle der Eltern, sonst gehört die Person einfach zur Familie.';
$l['relatives_relation_sentence_family'] = 'Bedeutet: {1} gehört zu {3}.';
$l['relatives_relation_sentence_family_parent'] = 'Bedeutet: {1} gehört zu {3}, der Kasten steht an der Stelle der Eltern.';
$l['relatives_field_families'] = 'Familie';
$l['relatives_field_families_hint'] = 'Gehört die Person zu einer Familie aus der Familienübersicht? Dann wähle sie hier aus. Einen Verwandtschaftsgrad brauchst du dafür nicht.';
$l['relatives_field_families_none'] = 'Es gibt noch keine Familien. Du kannst unter „Familien“ eine anlegen.';
$l['relatives_family_row_add'] = 'Familie zuordnen';
$l['relatives_family_row_family'] = 'Familie';
$l['relatives_family_row_choose'] = '– Familie wählen –';
$l['relatives_family_member'] = 'Mitglied';
$l['relatives_anchor_group_persons'] = 'Personen';
$l['relatives_families_title'] = 'Familien';
$l['relatives_families_hint'] = 'Familien-Kästen stehen im Stammbaum an der Stelle der Eltern. So kannst du eine Geschwistergruppe zusammenfassen, ohne einzelne Vorfahren anzulegen. Familien gelten forumsweit für alle Stammbäume. Personen ordnest du im Personenformular unter „Familie“ oder auf der Familienseite über „Zu dieser Familie hinzufügen“ zu.';
$l['relatives_families_empty'] = 'Es wurden noch keine Familien angelegt.';
$l['relatives_families_filter_welcomes'] = 'Neue Mitglieder';
$l['relatives_families_filter_welcomes_yes'] = 'erlaubt';
$l['relatives_families_filter_welcomes_no'] = 'nicht erlaubt';
$l['relatives_families_filter_all'] = '– alle –';
$l['relatives_families_filter_submit'] = 'Filtern';
$l['relatives_families_filter_reset'] = 'Filter zurücksetzen';
$l['relatives_families_filter_no_match'] = 'Keine Familie passt zu diesem Filter.';
$l['relatives_family_list_title'] = 'Familien';
$l['relatives_family_list_open'] = 'Öffentliche Familienliste öffnen';
$l['relatives_family_list_intro'] = 'Hier siehst du alle Familien des Forums mit ihren Mitgliedern. Über den Filter findest du zum Beispiel Familien, die sich über neue Mitglieder freuen.';
$l['relatives_families_members_played'] = '{1} Personen, davon {2} bespielt';
$l['relatives_family_open_page'] = 'Zur Familienseite';
$l['relatives_family_back'] = 'Zurück zur Familienübersicht';
$l['relatives_family_group_played'] = 'Bespielte Charaktere';
$l['relatives_family_group_wanted'] = 'Gesucht';
$l['relatives_family_group_npc'] = 'Weitere Familienmitglieder';
$l['relatives_family_member_tree'] = 'Stammbaum';
$l['relatives_family_no_members'] = 'Dieser Familie gehört noch niemand an.';
$l['relatives_family_join_title'] = 'Zu dieser Familie hinzufügen';
$l['relatives_family_join_hint'] = 'Wähle deinen Charakter oder eine Person aus deiner Liste. Weitere Verbindungen (z. B. Eltern, Geschwister) trägst du wie gewohnt im Personenformular ein.';
$l['relatives_family_join_person'] = 'Person';
$l['relatives_family_join_submit'] = 'Hinzufügen';
$l['relatives_family_join_closed_hint'] = 'Diese Familie nimmt keine weiteren bespielten Charaktere auf. NPCs kannst du trotzdem zuordnen.';
$l['relatives_family_join_exists'] = 'Diese Person gehört bereits zu der Familie.';
$l['relatives_family_joined'] = '{1} gehört jetzt zur Familie {2}.';
$l['relatives_tree_family_members'] = 'Mitglieder';
$l['relatives_family_add'] = 'Familie anlegen';
$l['relatives_family_added'] = 'Die Familie wurde angelegt.';
$l['relatives_family_deleted'] = 'Die Familie wurde gelöscht.';
$l['relatives_family_confirm_delete'] = 'Familie wirklich löschen? Sie verschwindet aus allen Stammbäumen, die Personen bleiben erhalten.';
$l['relatives_family_edit_title'] = 'Familie bearbeiten';
$l['relatives_family_form_hint'] = 'Gibt es bereits eine Familie mit diesem Namen, wird sie wiederverwendet statt doppelt angelegt. Bearbeiten können die Person, die sie angelegt hat, das Team und alle bespielten Charaktere, die zur Familie gehören; löschen nur die Person, die sie angelegt hat, und das Team.';
$l['relatives_field_family_name'] = 'Name (z. B. „Potter“)';
$l['relatives_field_family_color'] = 'Farbe';
$l['relatives_field_family_description'] = 'Beschreibung';
$l['relatives_field_family_welcomes'] = 'Die Familie freut sich über neue Mitglieder';
$l['relatives_field_family_welcomes_hint'] = 'Ohne Haken können keine weiteren Charaktere mehr zur Familie hinzugefügt werden. Neue Spieler*innen können dann nur noch NPCs übernehmen, die bereits in einem Stammbaum der Familie stehen.';
$l['relatives_family_closed_badge'] = 'Keine neuen Mitglieder';
$l['relatives_family_attribute_none'] = '– keine Angabe –';
$l['relatives_error_family_closed'] = 'Die Familie {1} freut sich derzeit nicht über neue Mitglieder. {2} kann ihr nur beitreten, indem eine NPC übernommen wird, die bereits in ihrem Stammbaum steht.';
$l['relatives_error_family_name_required'] = 'Bitte gib einen Namen für die Familie an.';
$l['relatives_error_invalid_family'] = 'Diese Familie existiert nicht (mehr).';

// v2.2: gemeinsames Geflecht
$l['relatives_graph_not_merged'] = 'Der Stammbaum wird gerade auf ein gemeinsames Geflecht umgestellt. Bitte versuche es später noch einmal.';
$l['relatives_error_relation_conflict'] = 'Zwischen dieser Person und {1} ist bereits „{2}“ eingetragen. Passe die bestehende Verbindung an, statt eine widersprüchliche anzulegen.';
$l['relatives_tree_show_more'] = 'Weitere Verwandte anzeigen';
$l['relatives_tree_expand_all'] = 'Alle ausklappen';
$l['relatives_tree_collapse_all'] = 'Standardansicht';
$l['relatives_tree_truncated'] = 'Der Stammbaum ist sehr groß – es werden nur die nächsten Verwandten gezeigt.';
$l['relatives_tree_derived'] = 'abgeleitet (nicht eingetragen, sondern über gemeinsame Eltern erkannt)';
$l['relatives_tree_node_character'] = 'Charakter';
$l['relatives_tree_placeholder_parent'] = 'Elternteil von %s';
$l['relatives_tree_placeholder_grandparent'] = 'Großelternteil von %s';
$l['relatives_tree_placeholder_great_grandparent'] = 'Urgroßelternteil von %s';
$l['relatives_tree_placeholder_ancestor'] = 'Vorfahr*in von %s';
$l['relatives_tree_placeholder_partner'] = 'Partner*in von %s';
$l['relatives_tree_placeholder_and'] = ' und ';
$l['relatives_tree_placeholder_badge'] = 'Platzhalter';
$l['relatives_tree_placeholder_note'] = 'Diese Person ist nicht eingetragen. Der Platzhalter zeigt nur, über wen die Verbindung läuft, und verschwindet, sobald die echte Person eingetragen ist.';
$l['relatives_tree_placeholder_add'] = 'Diese Person eintragen';
$l['relatives_tree_adopt'] = 'In meine Liste übernehmen';
$l['relatives_tree_adopt_note'] = 'Diese Person hat ein*e andere*r Spieler*in eingetragen. Übernimm sie in deine Liste, um sie bearbeiten zu können – die Angaben bleiben für alle gleich.';
$l['relatives_adopted'] = 'Die Person steht jetzt auch in deiner Liste.';

// Löschanträge für NPCs
$l['relatives_confirm_delete_npc'] = 'Diese Person komplett aus dem Stammbaum löschen? Alle anderen, die sie in ihrer Liste haben, müssen zustimmen. Erst dann wird sie mit allen Verbindungen gelöscht. Hat sie niemand sonst in der Liste, wird sie sofort gelöscht.';
$l['relatives_deleted_completely'] = 'Die Person wurde komplett aus dem Stammbaum gelöscht.';
$l['relatives_delete_requested_one'] = 'Löschantrag gestellt. Eine weitere Person muss noch zustimmen, danach wird der NPC komplett gelöscht und du bekommst Bescheid.';
$l['relatives_delete_requested'] = 'Löschantrag gestellt. {1} weitere Personen müssen noch zustimmen, danach wird der NPC komplett gelöscht und du bekommst Bescheid.';
$l['relatives_error_delete_pending'] = 'Für diese Person läuft bereits ein Löschantrag. Offene Anträge findest du unter „Meine Verwandten“.';
$l['relatives_error_delete_vote'] = 'Dieser Löschantrag ist nicht mehr offen oder betrifft dich nicht.';
$l['relatives_pending_delete_requests'] = 'Offene Löschanträge';
$l['relatives_pending_delete_requests_hint'] = 'Diese Personen stehen auch in deiner Liste. Sie werden erst komplett gelöscht, wenn alle zugestimmt haben. Lehnst du ab, bleibt die Person erhalten.';
$l['relatives_delete_request_text'] = '{1} möchte <strong>{2}</strong> komplett aus dem Stammbaum löschen.';
$l['relatives_delete_request_date'] = 'Beantragt am {1}';
$l['relatives_delete_vote_confirm'] = 'Löschen zustimmen';
$l['relatives_delete_vote_decline'] = 'Behalten';
$l['relatives_delete_vote_confirm_message'] = 'Zustimmen, dass diese Person mit allen Verbindungen aus dem Stammbaum gelöscht wird? Das lässt sich nicht rückgängig machen.';
$l['relatives_delete_vote_confirmed'] = 'Danke, deine Zustimmung ist gespeichert. Die Person wird gelöscht, sobald alle zugestimmt haben.';
$l['relatives_delete_vote_completed'] = 'Alle haben zugestimmt, die Person wurde komplett aus dem Stammbaum gelöscht.';
$l['relatives_delete_vote_declined'] = 'Die Person bleibt erhalten. Der Löschantrag wurde abgelehnt.';
$l['relatives_badge_delete_requested'] = 'Löschung beantragt ({1}/{2} Zustimmungen)';
$l['relatives_badge_delete_vote_open'] = 'Löschantrag offen';
$l['relatives_nav_open_delete_requests'] = 'Offene Löschanträge: {1}';
$l['relatives_nav_open_requests'] = 'Offene Anfragen an dich: {1}';
$l['relatives_relation_statement'] = '{1} ist {2} von {3}';
$l['relatives_relation_statement_family'] = '{1} gehört zur Familie {2}';
$l['relatives_relation_requests_title'] = 'Offene Verbindungsanfragen';
$l['relatives_relation_requests_hint'] = 'Verbindungen zu einem bespielten Charakter gelten erst, wenn dessen Spieler*in sie bestätigt. Bis dahin erscheinen sie in keinem Stammbaum.';
$l['relatives_relation_request_incoming'] = '{1} möchte eintragen: <strong>{2}</strong>';
$l['relatives_relation_request_outgoing'] = 'Deine Anfrage <strong>{1}</strong> wartet auf {2}';
$l['relatives_relation_request_change'] = 'Änderung';
$l['relatives_relation_request_accept'] = 'Bestätigen';
$l['relatives_relation_request_decline'] = 'Ablehnen';
$l['relatives_relation_request_cancel'] = 'Zurückziehen';
$l['relatives_relation_request_accepted'] = 'Die Verbindung wurde bestätigt und eingetragen.';
$l['relatives_relation_request_declined'] = 'Die Verbindung wurde abgelehnt.';
$l['relatives_relation_request_cancelled'] = 'Die Anfrage wurde zurückgezogen.';
$l['relatives_relation_request_conflict'] = 'Zwischen den beiden Personen ist inzwischen etwas anderes eingetragen. Die Anfrage wurde deshalb verworfen.';
$l['relatives_relation_requests_created'] = '{1} Verbindung(en) zu bespielten Charakteren warten auf Bestätigung durch deren Spieler*in.';
$l['relatives_relation_deleted'] = 'Die Verbindung wurde getrennt.';
$l['relatives_incoming_title'] = 'Von anderen eingetragene Verbindungen';
$l['relatives_incoming_hint'] = 'Diese Verbindungen wurden bei der jeweils anderen Person eingetragen. Du kannst sie hier ändern oder trennen. Betrifft eine Änderung einen bespielten Charakter, muss dessen Spieler*in sie erst bestätigen.';
$l['relatives_incoming_pending'] = 'Warten noch auf Bestätigung:';
$l['relatives_incoming_change'] = 'Ändern';
$l['relatives_incoming_delete'] = 'Trennen';
$l['relatives_incoming_confirm_delete'] = 'Verbindung wirklich trennen? Die Personen bleiben erhalten.';
