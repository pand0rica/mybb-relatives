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
$l['relatives_tab_desc_attributes'] = "Verwaltet Attribut-Gruppen und ihre Optionen, die einer Person (z. B. Haarfarbe) oder einer Familie (z. B. Blutstatus) zugeordnet werden können.";
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
$l['relatives_acp_actions_title'] = "Aktionen";
$l['relatives_acp_add_relationship_type'] = "Verwandtschaftsgrad hinzufügen";
$l['relatives_acp_add_relationship_type_desc'] = "Legt einen einzelnen neuen Verwandtschaftsgrad an, z. B. eine fehlende Geschlechtsvariante zu einem bestehenden Konzept.";
$l['relatives_acp_seed_defaults_button'] = "Standard-Verwandtschaftsgrade anlegen";
$l['relatives_acp_seed_defaults_desc'] = "Legt die üblichen Verwandtschaftsarten (Eltern, Geschwister, Kinder, Großeltern, Enkel, Onkel/Tante, Nichte/Neffe, Cousin/Cousine, Ehepartner, Ex-Partner, Stief- und Adoptivvarianten) jeweils in allen drei Geschlechtsvarianten an. Bereits vorhandene Kombinationen aus Konzept und Geschlecht werden übersprungen, nichts wird dupliziert oder verändert.";
$l['relatives_acp_seed_defaults_done'] = "%d Verwandtschaftsgrad(e) angelegt.";
$l['relatives_acp_seed_defaults_nothing'] = "Alle Standard-Verwandtschaftsgrade waren bereits vorhanden, es wurde nichts angelegt.";
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
$l['relatives_acp_add_group_desc'] = "Legt eine neue Attribut-Gruppe an, z. B. \"Haarfarbe\" oder \"Augenfarbe\".";
$l['relatives_acp_edit_group'] = "Gruppe bearbeiten";
$l['relatives_acp_field_applies_to'] = "Gilt für";
$l['relatives_acp_field_applies_to_desc'] = "Personen-Attribute stehen im Formular einer Person, Familien-Attribute (z. B. \"Blutstatus\") im Familienformular unter \"Optional\". Mit \"Personen und Familien\" erscheint die Gruppe in beiden Formularen.";
$l['relatives_acp_applies_person'] = "Personen";
$l['relatives_acp_applies_family'] = "Familien";
$l['relatives_acp_applies_both'] = "Personen und Familien";
$l['relatives_acp_error_text_family_only'] = "Freitext ist nur möglich, wenn die Gruppe ausschließlich für Familien gilt. Wähle bei \"Gilt für\" \"Familien\" oder stelle den Feldtyp auf \"Vorauswahl\".";
$l['relatives_acp_field_field_type'] = "Feldtyp";
$l['relatives_acp_field_field_type_desc'] = "Vorauswahl: Die Nutzer*innen wählen eine der Optionen dieser Gruppe. Freitext: Sie tragen den Wert selbst ein. Freitext gibt es nur, wenn die Gruppe ausschließlich für Familien gilt.";
$l['relatives_acp_field_type_select'] = "Vorauswahl";
$l['relatives_acp_field_type_text'] = "Freitext";
$l['relatives_acp_field_is_multiple'] = "Mehrfachauswahl bei Personen";
$l['relatives_acp_field_is_multiple_desc'] = "Nein: Eine Person hat genau einen Wert dieser Gruppe (Dropdown, z. B. Haarfarbe). Ja: Eine Person kann mehrere Werte haben (Checkboxen, z. B. Fähigkeiten). Bei Familien gibt es immer genau einen Wert.";
$l['relatives_acp_multiple_note'] = "Mehrfachauswahl";
$l['relatives_acp_text_group_no_options'] = "Freitextfeld – diese Gruppe braucht keine Optionen.";
$l['relatives_acp_add_option'] = "Option hinzufügen";
$l['relatives_acp_edit_option'] = "Option bearbeiten";
$l['relatives_acp_col_label'] = "Bezeichnung";
$l['relatives_acp_col_actions'] = 'Aktionen';
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
$l['relatives_acp_migration_no_tables'] = "Keine verwertbaren Altdaten gefunden (benötigt relatives + relatives_status und/oder wanted + wanted_status).";
$l['relatives_acp_migration_unmapped_error'] = "Migration abgebrochen, bevor etwas verändert wurde: Diese alten Verwandtschaftsgrade werden genutzt, haben aber noch keine Zuordnung. Bitte das Mapping in Relatives_Migration::STATUS_BASE_MAP ergänzen und erneut starten.";
$l['relatives_acp_migration_report_title'] = "Migrationsbericht";
$l['relatives_acp_migration_report_types'] = "Angelegte Verwandtschaftsgrade";
$l['relatives_acp_migration_report_groups'] = "Angelegte Attribut-Gruppen";
$l['relatives_acp_migration_report_options'] = "Angelegte Attribut-Optionen";
$l['relatives_acp_migration_report_persons_relatives'] = "Übernommene Personen aus \"relatives\"";
$l['relatives_acp_migration_report_persons_wanted'] = "Übernommene Personen aus \"wanted\"";
$l['relatives_acp_migration_report_links'] = "Übernommene Besitzer*innen-Zuordnungen";
$l['relatives_acp_migration_report_attributes'] = "Zugeordnete Attributwerte";
$l['relatives_acp_migration_report_without_relationship'] = "Zuordnungen ohne Verwandtschaftsgrad";
$l['relatives_acp_migration_report_skipped_no_owner'] = "Übersprungene Personen (kein existierender Besitzer*innen-Account mehr)";
$l['relatives_acp_migration_report_skipped_invalid'] = "Übersprungene Personen (kein Name)";
$l['relatives_acp_migration_report_skipped_missing_user'] = "Übersprungene Zuordnungen (Account gelöscht)";
$l['relatives_acp_migration_report_skipped_missing_person'] = "Übersprungene Zuordnungen (Person existiert nicht mehr)";
$l['relatives_acp_migration_report_skipped_duplicate'] = "Übersprungene Zuordnungen (doppelt)";
$l['relatives_acp_migration_report_structure_anchored'] = "Abgeleitete Bezugspersonen (Stammbaum-Struktur)";
$l['relatives_acp_migration_report_structure_ambiguous'] = "Bezugsperson nicht eindeutig (bleibt lose verbunden)";
$l['relatives_acp_migration_report_structure_unresolved'] = "Keine passende Bezugsperson vorhanden (bleibt lose verbunden)";
$l['relatives_acp_migration_preview_stats_title'] = "Vorschau: Was übernommen wird";
$l['relatives_acp_migration_preview_persons_with_owner'] = "Personen mit mindestens einem existierenden Besitzer*innen-Account";
$l['relatives_acp_migration_preview_persons_without_owner'] = "Personen ohne existierenden Besitzer*innen-Account (werden nicht übernommen)";
$l['relatives_acp_migration_preview_links_valid'] = "Zu übernehmende Besitzer*innen-Zuordnungen";
$l['relatives_acp_migration_preview_links_invalid_status'] = "Zuordnungen mit ungültigem alten Verwandtschaftsgrad (werden ohne Grad übernommen)";
$l['relatives_acp_migration_preview_mapping_title'] = "Vorschau: Zuordnung der alten Verwandtschaftsgrade";
$l['relatives_acp_migration_col_item'] = "Posten";
$l['relatives_acp_migration_col_count'] = "Anzahl";
$l['relatives_acp_migration_col_legacy'] = "Alter Verwandtschaftsgrad";
$l['relatives_acp_migration_col_target'] = "Neuer Verwandtschaftsgrad";
$l['relatives_acp_migration_col_concept'] = "Konzept";
$l['relatives_acp_migration_col_links'] = "Zuordnungen";
$l['relatives_acp_migration_no_grade'] = "ohne Verwandtschaftsgrad";
$l['relatives_acp_migration_unmapped_marker'] = "keine Zuordnung";
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

// v2.1: Verbindungen, Familien, Verstorben, Verwandtschafts-Cache
$l['relatives_acp_field_line_style'] = 'Linienart im Stammbaum';
$l['relatives_acp_field_line_style_desc'] = 'So wird diese Verbindung im Stammbaum gezeichnet. Getrennte Paare bekommen einen eigenen Grad (z. B. Ex-Frau) mit gestrichelter Linie.';
$l['relatives_acp_line_style_solid'] = 'Durchgezogen';
$l['relatives_acp_line_style_dashed'] = 'Gestrichelt';
$l['relatives_acp_line_style_dotted'] = 'Gepunktet';
$l['relatives_acp_line_style_dashdot'] = 'Strichpunkt';
$l['relatives_acp_recompute_button'] = 'Verwandtschaften neu berechnen';
$l['relatives_acp_recompute_desc'] = 'Berechnet für alle Stammbäume neu, wie jede Person zum jeweiligen Charakter steht (z. B. für die Jobliste). Nötig nach dem Update und nach Änderungen an den Verwandtschaftsgraden.';
$l['relatives_acp_recompute_done'] = 'Verwandtschaft für {1} Zuordnungen neu berechnet.';
$l['setting_relatives_inplay_year'] = 'Aktuelles Inplay-Jahr';
$l['setting_relatives_inplay_year_desc'] = 'Jahr, in dem das Inplay gerade spielt. Ist es gesetzt, tragt ihr bei Personen ein Geburtsjahr ein und das Alter wird daraus berechnet (auch bei allen Verwandten immer aktuell). Leer = altes Feld „Alter“. Bestehende Alter rechnet ihr unter Werkzeuge → Verwandtschaften → Verwandtschaftsgrade → „Alter in Geburtsjahre umrechnen“ um.';
$l['relatives_acp_convert_ages_button'] = 'Alter in Geburtsjahre umrechnen';
$l['relatives_acp_convert_ages_desc'] = 'Einmalig nach dem Setzen des Inplay-Jahres: Personen mit fest eingetragenem Alter bekommen das Geburtsjahr Inplay-Jahr − Alter. Personen, die schon ein Geburtsjahr haben, bleiben unverändert.';
$l['relatives_acp_convert_ages_done'] = '{1} Alter mit Inplay-Jahr {2} in Geburtsjahre umgerechnet.';
$l['relatives_acp_convert_ages_no_year'] = 'Bitte zuerst unter Einstellungen → Verwandtschaften das aktuelle Inplay-Jahr eintragen.';

// v2.2: gemeinsames Geflecht
$l['relatives_relation_of'] = '{1} von {2}';
$l['relatives_tab_graph'] = 'Geflecht';
$l['relatives_tab_desc_graph'] = 'Führt die früheren Listen je Charakter zu einem gemeinsamen Stammbaum-Geflecht zusammen und zeigt Widersprüche.';
$l['relatives_acp_graph_intro'] = 'Seit Version 2.2 gehören Verbindungen niemandem mehr: Jede Aussage steht genau einmal im gemeinsamen Geflecht, jeder gespielte Charakter ist selbst ein Knoten. Die Zusammenführung verknüpft Personen mit gleichnamigen Accounts, fasst gleichnamige Personen zusammen, legt identische Aussagen zusammen und sammelt Widersprüche unten zur Prüfung. Bis sie gelaufen ist, ist der Stammbaum im Forum gesperrt.';
$l['relatives_acp_graph_merged_at'] = 'Zusammengeführt: {1}';
$l['relatives_acp_graph_not_merged'] = 'Noch nicht zusammengeführt – der Stammbaum ist im Forum bis dahin gesperrt.';
$l['relatives_acp_graph_preview_title'] = 'Vorschau (es wird noch nichts geändert)';
$l['relatives_acp_graph_preview_account_links'] = 'Personen, die mit dem gleichnamigen Account verknüpft werden';
$l['relatives_acp_graph_preview_duplicates'] = 'Gleichnamige Personen, die zu einer zusammengefasst werden';
$l['relatives_acp_graph_preview_new_nodes'] = 'Charaktere, die einen eigenen Knoten bekommen';
$l['relatives_acp_graph_preview_identical'] = 'Identische Aussagen, die zusammengelegt werden';
$l['relatives_acp_graph_preview_conflicts'] = 'Widersprüchliche Aussagen (landen in der Konfliktliste)';
$l['relatives_acp_graph_duplicates_hint'] = 'Stehen hier Platzhalter-Namen wie „Tante“, benenne die Personen vorher um – sonst werden verschiedene Menschen zu einem Eintrag.';
$l['relatives_acp_graph_col_person'] = 'Person';
$l['relatives_acp_graph_col_account'] = 'Account';
$l['relatives_acp_graph_col_ids'] = 'IDs';
$l['relatives_acp_graph_col_kept'] = 'Behaltene Aussage';
$l['relatives_acp_graph_col_discarded'] = 'Verworfene Aussage';
$l['relatives_acp_graph_col_source'] = 'Eingetragen von';
$l['relatives_acp_graph_run'] = 'Geflecht jetzt zusammenführen';
$l['relatives_acp_graph_rerun'] = 'Erneut zusammenführen';
$l['relatives_acp_graph_conflicts_title'] = 'Konflikte';
$l['relatives_acp_graph_no_conflicts'] = 'Keine offenen Konflikte.';
$l['relatives_acp_graph_accept'] = 'Übernehmen';
$l['relatives_acp_graph_discard'] = 'Verwerfen';
$l['relatives_acp_graph_conflict_accepted'] = 'Die Aussage wurde übernommen.';
$l['relatives_acp_graph_conflict_discarded'] = 'Die Aussage wurde verworfen.';
$l['relatives_acp_graph_report_title'] = 'Ergebnis der Zusammenführung';
$l['relatives_acp_graph_report_account_links'] = 'Personen mit Account verknüpft';
$l['relatives_acp_graph_report_persons_merged'] = 'Gleichnamige Personen zusammengefasst';
$l['relatives_acp_graph_report_account_nodes_created'] = 'Neue Charakter-Knoten';
$l['relatives_acp_graph_report_relations_kept'] = 'Übernommene Verbindungen';
$l['relatives_acp_graph_report_identical_removed'] = 'Identische Aussagen zusammengelegt';
$l['relatives_acp_graph_report_conflicts'] = 'Widersprüche in der Konfliktliste';
$l['relatives_acp_graph_report_self_removed'] = 'Bedeutungslose Selbstbezüge entfernt';
$l['relatives_acp_graph_guesses_title'] = 'Geratene Zuordnungen aus der Alt-Migration';
$l['relatives_acp_graph_guesses_desc'] = 'Diese Verbindungen hat die Alt-Migration nur geraten, weil in einer Liste genau eine mögliche Bezugsperson stand (z. B. „Cousin“ → Kind der einzigen eingetragenen Tante). Im gemeinsamen Geflecht gelten sie für alle Stammbäume. Beim Zurücknehmen wird daraus wieder die ursprüngliche Aussage aus der alten Liste.';
$l['relatives_acp_graph_col_guess'] = 'Geratene Verbindung';
$l['relatives_acp_graph_col_original'] = 'Wird wieder zu';
$l['relatives_acp_graph_no_guesses'] = 'Keine geratenen Zuordnungen mehr vorhanden.';
$l['relatives_acp_graph_guesses_button'] = 'Geratene Zuordnungen zurücknehmen';
$l['relatives_acp_graph_guesses_reverted'] = '{1} geratene Zuordnungen wurden zurückgenommen.';

// Admin-Berechtigung (ACP -> Administrator-Berechtigungen -> Werkzeuge & Wartung)
$l['relatives_acp_permission'] = 'Darf Verwandtschaften & Stammbaum verwalten?';

// Einstellungen: MyBB ersetzt die gespeicherten Titel durch setting_<name> / setting_<name>_desc
$l['setting_group_relatives'] = 'Verwandtschaften & Stammbaum';
$l['setting_group_relatives_desc'] = 'Einstellungen für das Plugin Verwandtschaften & Stammbaum.';
$l['setting_relatives_origin_mode_freetext'] = 'Freitext';
$l['setting_relatives_origin_mode_profilefield'] = 'Profilfeld';
$l['setting_relatives_enabled'] = 'Plugin aktiv';
$l['setting_relatives_enabled_desc'] = 'Schaltet das Plugin komplett ein/aus (Hauptschalter).';
$l['setting_relatives_max_persons_per_user'] = 'Maximale Personen pro Nutzer*in';
$l['setting_relatives_max_persons_per_user_desc'] = 'Missbrauchsschutz. 0 = unbegrenzt.';
$l['setting_relatives_origin_mode'] = 'Herkunfts-Quelle';
$l['setting_relatives_origin_mode_desc'] = 'Woher stammt die „Herkunft“ einer Person: freies Textfeld oder ein bestehendes Profilfeld.';
$l['setting_relatives_origin_profilefield_id'] = 'Herkunfts-Profilfeld (fid)';
$l['setting_relatives_origin_profilefield_id_desc'] = 'fid des MyBB-Profilfelds, das als Herkunfts-Optionsliste dient. Nur relevant, wenn „Herkunfts-Quelle“ = Profilfeld.';
$l['setting_relatives_legacy_migrated'] = 'Letzte Legacy-Migration (Unix-Zeitstempel)';
$l['setting_relatives_legacy_migrated_desc'] = 'Wird automatisch vom Migrations-Tool gesetzt. 0 = noch nie migriert. Nicht manuell ändern.';
$l['setting_relatives_graph_merged'] = 'Gemeinsames Geflecht zusammengeführt (Unix-Zeitstempel)';
$l['setting_relatives_graph_merged_desc'] = 'Wird automatisch gesetzt, sobald die früheren Einzel-Listen im ACP zu einem gemeinsamen Stammbaum-Geflecht zusammengeführt wurden. 0 = noch nicht. Nicht manuell ändern.';
$l['setting_relatives_family_list_guests'] = 'Familienliste für Gäst*innen';
$l['setting_relatives_family_list_guests_desc'] = 'Dürfen Gäst*innen die öffentliche Familienliste (relatives.php?action=family_list) sehen? Die Gruppenberechtigung „Forum ansehen“ gilt zusätzlich.';
$l['setting_relatives_family_list_layout_template'] = 'Familienliste: Rahmen-Template (optional)';
$l['setting_relatives_family_list_layout_template_desc'] = 'Name eines vorhandenen Templates, in das die Familienliste eingebettet wird, z. B. der Rahmen eurer übrigen Listen („listen“). Es erhält den Inhalt als {$listen_content} bzw. {$relatives_family_list_content}, die Navigation als {$listen_nav} bzw. {$relatives_list_nav} und den Titel als {$headline}. Leer = eigene Seite des Plugins.';
$l['setting_relatives_family_list_nav_template'] = 'Familienliste: Navigations-Template (optional)';
$l['setting_relatives_family_list_nav_template_desc'] = 'Name eines vorhandenen Templates mit der Listen-Navigation, z. B. „listen_nav“. Nur zusammen mit dem Rahmen-Template wirksam.';
$l['setting_relatives_tree_link_profile'] = 'Stammbaum-Link im Profil';
$l['setting_relatives_tree_link_profile_desc'] = 'Zeigt im Profil einen Link zum Stammbaum des Charakters. Template-Variable {$relatives_tree_link} im Template member_profile.';
$l['setting_relatives_tree_link_postbit'] = 'Stammbaum-Link im Postbit';
$l['setting_relatives_tree_link_postbit_desc'] = 'Zeigt im Postbit einen Link zum Stammbaum der Autor*in. Template-Variable {$post[\'relatives_tree_link\']} in den Templates postbit und postbit_classic.';
$l['setting_relatives_tree_link_memberlist'] = 'Stammbaum-Link in der Mitgliederliste';
$l['setting_relatives_tree_link_memberlist_desc'] = 'Zeigt in der Mitgliederliste je Mitglied einen Link zum Stammbaum. Template-Variable {$user[\'relatives_tree_link\']} im Template memberlist_user.';

// Löschanträge
$l['relatives_tab_delete_requests'] = 'Löschanträge';
$l['relatives_tab_desc_delete_requests'] = 'Offene Anträge, einen NPC komplett aus dem Stammbaum zu löschen. Es gibt keine Frist: Hängt ein Antrag, könnt ihr ihn hier sofort ausführen oder verwerfen.';
$l['relatives_acp_delete_request_person'] = 'Person';
$l['relatives_acp_delete_request_by'] = 'Beantragt von';
$l['relatives_acp_delete_request_date'] = 'Beantragt am';
$l['relatives_acp_delete_request_state'] = 'Zustimmungen';
$l['relatives_acp_delete_request_waiting'] = 'Wartet auf';
$l['relatives_acp_delete_requests_none'] = 'Keine offenen Löschanträge.';
$l['relatives_acp_delete_request_do_delete'] = 'Jetzt löschen';
$l['relatives_acp_delete_request_do_discard'] = 'Verwerfen';
$l['relatives_acp_delete_request_confirm_delete'] = 'Die Person jetzt mit allen Verbindungen aus dem Stammbaum löschen, ohne auf die übrigen Zustimmungen zu warten? Das lässt sich nicht rückgängig machen.';
$l['relatives_acp_delete_request_confirm_discard'] = 'Den Antrag verwerfen? Die Person bleibt erhalten, die antragstellende Person wird aus ihrer Liste ausgetragen.';
$l['relatives_acp_delete_request_deleted'] = 'Die Person wurde komplett gelöscht.';
$l['relatives_acp_delete_request_discarded'] = 'Der Löschantrag wurde verworfen.';
$l['relatives_acp_delete_request_error'] = 'Dieser Löschantrag ist nicht mehr offen.';
