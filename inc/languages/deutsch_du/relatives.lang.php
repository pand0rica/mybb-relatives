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
$l['relatives_add_relative_of'] = "Verwandte/n von";
$l['relatives_add_relative_of_note'] = "Der Verwandtschaftsgrad wird aus deiner eigenen Sicht gewählt, nicht aus Sicht von %s.";
$l['relatives_edit_person_title'] = "Person bearbeiten";
$l['relatives_view_tree'] = "Stammbaum ansehen";
$l['relatives_usercp_menu_item'] = "Verwandtschaften & Stammbaum";
$l['relatives_tree_title_for'] = "Stammbaum von %s";
$l['relatives_tree_reset_view'] = "Ansicht zurücksetzen";

// Liste
$l['relatives_col_name'] = "Name";
$l['relatives_col_relationship'] = "Verwandtschaft";
$l['relatives_col_age'] = "Alter";
$l['relatives_col_status'] = "Status";
$l['relatives_col_actions'] = "Aktionen";
$l['relatives_no_persons_yet'] = "Du hast noch keine Verwandten angelegt.";
$l['relatives_edit'] = "Bearbeiten";
$l['relatives_delete'] = "Löschen";
$l['relatives_confirm_delete'] = "Diese Person wirklich löschen?";
$l['relatives_cancel'] = "Abbrechen";
$l['relatives_save'] = "Speichern";

// Flash-Meldungen
$l['relatives_added'] = "Person hinzugefügt.";
$l['relatives_saved'] = "Änderungen gespeichert.";
$l['relatives_deleted'] = "Person gelöscht.";

// Formularfelder
$l['relatives_field_name'] = "Name";
$l['relatives_field_gender'] = "Geschlecht";
$l['relatives_field_relationship'] = "Verwandtschaftsgrad";
$l['relatives_field_relationship_custom'] = "Freie Bezeichnung";
$l['relatives_field_age'] = "Alter";
$l['relatives_field_origin'] = "Herkunft";
$l['relatives_field_avatar'] = "Avatar (Bild-URL)";
$l['relatives_field_description'] = "Beschreibung";
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
$l['relatives_error_invalid_age'] = "Bitte gib ein gültiges Alter ein.";
$l['relatives_error_invalid_origin'] = "Bitte wähle eine gültige Herkunft aus.";
$l['relatives_error_limit_reached'] = "Du hast die maximale Anzahl an Verwandten erreicht.";
$l['relatives_error_username_required'] = "Bitte gib einen Benutzernamen ein.";
$l['relatives_error_user_not_found'] = "Dieser Account wurde nicht gefunden.";
$l['relatives_error_generic'] = "Etwas ist schiefgelaufen. Bitte versuch es erneut.";

// Gesucht-Box (Profil)
$l['relatives_wanted_box_title'] = "Gesucht";
$l['relatives_wanted_thread_link_text'] = "Zum Gesuchs-Thread";

// Verknüpfungs-Workflow
$l['relatives_pending_link_requests'] = "Offene Verknüpfungsanfragen";
$l['relatives_link_request_text'] = "Jemand möchte einen Verwandten-Eintrag mit deinem Account verknüpfen.";
$l['relatives_link_accept'] = "Annehmen";
$l['relatives_link_decline'] = "Ablehnen";
$l['relatives_link_requested'] = "Verknüpfungsanfrage gesendet.";
$l['relatives_link_updated'] = "Verknüpfungsstatus aktualisiert.";
$l['relatives_link_pending'] = "Verknüpfungsanfrage wartet auf Bestätigung.";
$l['relatives_link_confirmed_with'] = "Verknüpft mit Account:";
$l['relatives_link_send_request'] = "Verknüpfungsanfrage senden";

// PM bei Verknüpfungsanfrage (siehe LinkManager)
$l['relatives_pm_link_request_subject'] = "Verknüpfungsanfrage: %s";
$l['relatives_pm_link_request_message'] = "Jemand möchte den Verwandten-Eintrag \"%s\" mit deinem Account verknüpfen, damit eure Stammbäume verbunden werden.\n\nDu kannst diese Anfrage hier annehmen oder ablehnen:\n%s";
