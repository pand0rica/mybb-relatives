<?php
/**
 * Verwandtschaften & Stammbaum - ACP-Sprachdatei (Deutsch, Du-Form)
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

// Pluginname + Beschreibung
$l['relatives'] = "Verwandtschaften & Stammbaum";
$l['relatives_acp_title'] = "Verwandtschaften & Stammbaum";

// Tabs
$l['relatives_tab_relationship_types'] = "Verwandtschaftsgrade";
$l['relatives_tab_desc_relationship_types'] = "Verwaltet die genderspezifischen Verwandtschaftsgrade (Mutter/Vater, Geschwister usw.), die im Formular zur Auswahl stehen.";
$l['relatives_tab_attributes'] = "Attribut-Gruppen";
$l['relatives_tab_desc_attributes'] = "Verwaltet Attribut-Gruppen und ihre Optionen (z. B. Haarfarbe, Augenfarbe), die einer Person zugeordnet werden können.";
$l['relatives_tab_origin'] = "Herkunft";
$l['relatives_tab_desc_origin'] = "Legt fest, ob das Feld \"Herkunft\" ein Freitextfeld ist oder auf einem bestehenden Profilfeld basiert.";
$l['relatives_tab_migration'] = "Migration";
$l['relatives_tab_desc_migration'] = "Importiert Daten aus dem alten relatives-/wanted-Plugin.";

// Gemeinsame Formularfelder
$l['relatives_acp_field_label'] = "Bezeichnung";
$l['relatives_acp_field_sort_order'] = "Sortierung";
$l['relatives_acp_field_is_active'] = "Aktiv";
$l['relatives_field_gender'] = "Geschlecht";
$l['relatives_field_origin'] = "Herkunft";

// Verwandtschaftsgrade
$l['relatives_acp_add_relationship_type'] = "Verwandtschaftsgrad hinzufügen";
$l['relatives_acp_edit_relationship_type'] = "Verwandtschaftsgrad bearbeiten";
$l['relatives_acp_field_concept_key'] = "Konzept-Schlüssel";
$l['relatives_acp_field_concept_key_desc'] = "Gruppiert die männliche/weibliche/neutrale Variante derselben Verwandtschaft (z. B. 'parent', 'sibling'). Für alle drei Geschlechter derselben Verwandtschaft denselben Konzept-Schlüssel verwenden.";
$l['relatives_acp_field_generation_offset'] = "Generationen-Abstand";
$l['relatives_acp_generation_grandparent'] = "Großeltern (-2)";
$l['relatives_acp_generation_parent'] = "Eltern / Onkel-Tante (-1)";
$l['relatives_acp_generation_same'] = "Gleiche Generation (0)";
$l['relatives_acp_generation_child'] = "Kinder (+1)";
$l['relatives_acp_generation_grandchild'] = "Enkel (+2)";
$l['relatives_acp_relationship_types_overview'] = "Verwandtschaftsgrade, gruppiert nach Konzept";
$l['relatives_acp_col_concept_key'] = "Konzept";
$l['relatives_acp_no_relationship_types'] = "Noch keine Verwandtschaftsgrade angelegt.";
$l['relatives_gender_male'] = "männlich";
$l['relatives_gender_female'] = "weiblich";
$l['relatives_gender_diverse_neutral'] = "divers";
$l['relatives_acp_missing'] = "fehlt";

// Attribut-Gruppen
$l['relatives_acp_add_group'] = "Gruppe hinzufügen";
$l['relatives_acp_edit_group'] = "Gruppe bearbeiten";
$l['relatives_acp_add_option'] = "Option hinzufügen";
$l['relatives_acp_edit_option'] = "Option bearbeiten";
$l['relatives_acp_col_label'] = "Bezeichnung";
$l['relatives_acp_col_actions'] = "Aktionen";
$l['relatives_acp_no_groups'] = "Noch keine Attribut-Gruppen angelegt.";
$l['relatives_acp_no_options'] = "Noch keine Optionen in dieser Gruppe.";

// Herkunft
$l['relatives_acp_origin_intro'] = "Lege fest, ob Mitglieder eine freie Herkunft eintippen oder aus der Optionsliste eines bestehenden Profilfelds auswählen.";
$l['relatives_acp_origin_mode_freetext'] = "Freitext";
$l['relatives_acp_origin_mode_profilefield'] = "Profilfeld";
$l['relatives_acp_field_origin_fid'] = "Profilfeld-ID (fid)";
$l['relatives_acp_field_origin_fid_desc'] = "Die fid des MyBB-Profilfelds, dessen Optionsliste als Herkunft verwendet wird. Nur relevant, wenn oben \"Profilfeld\" gewählt ist.";
$l['relatives_acp_error_invalid_fid'] = "Diese Profilfeld-ID existiert nicht.";

// Migration
$l['relatives_acp_migration_intro'] = "Importiert Daten aus den Tabellen des alten relatives-/wanted-Plugins. Diesen Lauf nur einmal pro Forum ausführen.";
$l['relatives_acp_migration_table_found'] = "gefunden";
$l['relatives_acp_migration_table_missing'] = "nicht gefunden";
$l['relatives_acp_migration_run'] = "Migration starten";
$l['relatives_acp_migration_confirm_rerun'] = "Mir ist bewusst, dass dieses Forum bereits migriert wurde, und ich möchte den Lauf trotzdem erneut ausführen.";
$l['relatives_acp_migration_rerun_warning'] = "Dieses Forum wurde bereits migriert. Ein erneuter Lauf ist unkritisch, da bereits importierte Zeilen über ihre Legacy-ID übersprungen werden - bitte trotzdem unten bestätigen, wenn wirklich fortgefahren werden soll.";
$l['relatives_acp_migration_already_done'] = "Dieses Forum wurde bereits migriert. Setze das Bestätigungshäkchen, um den Lauf erneut auszuführen.";
$l['relatives_acp_migration_no_tables'] = "Keine der alten Tabellen (relatives, wanted, relatives_options) wurde gefunden.";
$l['relatives_acp_migration_report_summary'] = "%d Verwandtschaftsgrade, %d Attribut-Gruppen und %d Attribut-Optionen angelegt. %d Einträge aus \"relatives\" und %d Einträge aus \"wanted\" migriert. %d Zeilen wurden übersprungen (ungültige/unvollständige Daten).";
$l['relatives_acp_migration_missing_neutral_title'] = "Für folgende Konzept-Schlüssel fehlt noch eine geschlechtsneutrale Bezeichnung. Bitte ergänzen, damit der Divers-/Egal-Filter dafür funktioniert:";

// Allgemeine ACP-Aktionen
$l['relatives_acp_edit'] = "Bearbeiten";
$l['relatives_acp_delete'] = "Löschen";
$l['relatives_acp_confirm_delete'] = "Diesen Eintrag wirklich löschen?";
$l['relatives_acp_saved'] = "Erfolgreich gespeichert.";
$l['relatives_acp_deleted'] = "Erfolgreich gelöscht.";
$l['relatives_acp_not_found'] = "Eintrag nicht gefunden.";
$l['relatives_acp_error_required_fields'] = "Bitte alle Pflichtfelder ausfüllen.";
$l['relatives_acp_inactive'] = "inaktiv";
