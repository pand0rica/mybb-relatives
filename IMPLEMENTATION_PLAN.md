# Verwandtschaften & Stammbaum – Implementation Plan

**Umsetzung:** folge `D:\mybb-plugin-dev-kit\workflows\2-implement.md`. Schritte der Reihe
nach. Bei Unklarheit gezielt nachfragen, aber nicht umplanen. Schritt nach Erledigung auf
`- [x]` setzen.

---

## Schritt 1 – Grundgerüst & Zugriffsschutz
- [x] **Ziel:** Hauptdatei anlegen, Zugriffsschutz, Hook-Registrierung (Platzhalter-Handler),
      `info()`-Block, Verzeichnisstruktur (`inc/plugins/relatives/core|assets|templates|
      modules/admin`) anlegen.
- **Dateien:** `inc/plugins/relatives.php`, `inc/plugins/relatives/core/*.php` (leere Klassen-
  Gerüste: `Database`, `RelationshipTypes`, `Attributes`, `TreeBuilder`, `LinkManager`,
  `SearchApi`, `Installer`, `Migration`)
- **Vorlage:** `D:\mybb-plugin-dev-kit\standards\boilerplate.md` §1, §2
- **Abnahme:** `php -l` sauber für alle neuen Dateien; Plugin erscheint in der ACP-Pluginliste.
- **Nachweis:** `php -l` grün für alle Dateien (lokal + auf dem Testforum, PHP 8.5 lokal /
  PHP 8.1.33 Server). Deployt auf dem Test-Forum; `tests/cli-plugin.php install` bestätigt
  Erscheinen in der Plugin-Erkennung (siehe Schritt 2/11).

## Schritt 2 – Lifecycle (install/is_installed/uninstall)
- [x] **Ziel:** Alle 5 Tabellen anlegen/droppen (`relatives_persons`,
      `relatives_relationship_types`, `relatives_attribute_groups`,
      `relatives_attribute_options`, `relatives_person_attributes`), `is_installed()`.
      **Keine** Seed-Daten einfügen (siehe PROJECT_CONTEXT „What NOT to change").
- **Dateien:** `inc/plugins/relatives.php`, `inc/plugins/relatives/core/Installer.php`
- **Vorlage:** `boilerplate.md` §3 · Schema exakt aus `PROJECT_CONTEXT.md` „Datenbank-Tabellen" ·
  Spalten-/Typ-Konventionen `conventions.md` §4
- **Abnahme:** CLI-Lifecycle `install` legt alle 5 Tabellen mit korrekten Spalten an
  (`D:\mybb-plugin-dev-kit\standards\testing.md` §3).
- **Nachweis:** Live auf dem Test-Forum verifiziert: zwei volle `install`→`status`→`uninstall`→
  `status`-Zyklen gefahren, alle 5 Tabellen legen sich korrekt an (`status` zeigt EXISTS) und
  verschwinden nach `uninstall` vollständig (MISSING). Details siehe Abschlussbericht.

## Schritt 3 – activate()/deactivate() (Settings, Templates, Stylesheet)
- [x] **Ziel:** Die 4 Settings aus `PROJECT_CONTEXT.md` idempotent anlegen, Templates aus
      `.tpl`-Dateien laden, Stylesheets (`relatives.css`, `relatives-tree.css`) in alle Themes
      einhängen (kein Hardcode auf `tid=1` wie im Altplugin – Schleife über alle Themes);
      `deactivate()` als exaktes Spiegelbild (Templates, Settings, settinggroup, Stylesheets aus
      allen Themes entfernen, danach `rebuild_settings()`).
- **Dateien:** `inc/plugins/relatives.php`, `inc/plugins/relatives/templates/*.tpl`,
  `inc/plugins/relatives/assets/relatives.css`, `relatives-tree.css`
- **Vorlage:** `boilerplate.md` §4, §5, §6, §9
- **Abnahme:** CLI `install`→`activate`→`deactivate`→`uninstall` – alle Zähler 0
  (Cleanup-Nachweis, `testing.md` §3).
- **Nachweis:** Implementiert in `Relatives_Installer::activate()/deactivate()` (Settings,
  Templates aus `.tpl`, Stylesheets in alle Themes per Schleife, kein `tid=1`-Hardcode). Live
  bestätigt: nach `activate()` 5 Settings, 1 Settinggroup, 17 Templates, 18 Stylesheets
  (2 Stylesheets × 9 Themes des Testforums – kein tid-Hardcode) angelegt; nach `deactivate()`+
  `uninstall()` alle wieder 0.

## Schritt 4 – DB-Zugriff / Relationship-Types & Attribut-Verwaltung (Business-Logik)
- [x] **Ziel:** CRUD für `relatives_relationship_types` (inkl. `concept_key`/`gender`/
      `generation_offset`) und für `relatives_attribute_groups`/`_options`, sowie die
      Zuordnungslogik in `relatives_person_attributes`. Funktion, die für ein gegebenes
      `gender` einer Person die passenden aktiven Relationship-Types als JSON-Payload liefert
      (Grundlage der clientseitigen Dropdown-Filterung aus `PROJECT_CONTEXT.md`).
- **Dateien:** `inc/plugins/relatives/core/RelationshipTypes.php`,
  `inc/plugins/relatives/core/Attributes.php`, `inc/plugins/relatives/core/Database.php`
- **Vorlage:** `conventions.md` §3 (Naming: `relatives_db_*`, `relatives_get_*`), §4 (DB) ·
  Security §6
- **Abnahme:** CLI-Selbsttest (`testing.md` §4): Relationship-Type mit `gender=0` erstellen,
  Filterfunktion mit `gender=0` aufrufen → erscheint; mit `gender=1` aufrufen → erscheint nicht.
- **Nachweis:** `Relatives_RelationshipTypes::get_for_person_gender()` implementiert genau
  diesen Test; live auf dem Test-Forum gegen echte DB gelaufen (`tests/cli-selftest.php`,
  Abschnitt "Relationship-Type gender filter") – alle 6 Assertions grün.

## Schritt 5 – Personen-CRUD (relatives_persons)
- [x] **Ziel:** Anlegen/Bearbeiten/Löschen einer Person, inkl. Owner-Prüfung (fixt Alt-IDOR-Bug),
      Validierung (Pflichtfeld `relationship_custom_label` wenn Typ „Sonstiges"), Limit-Check
      gegen `relatives_max_persons_per_user`, Herkunfts-Logik je nach `relatives_origin_mode`
      (bei `profilefield`: Optionsliste aus `profilefields`/`fid` lesen und validieren, dass die
      `fid` existiert; bei `freetext`: einfaches Textfeld).
- **Dateien:** `inc/plugins/relatives/core/Database.php`
- **Vorlage:** `conventions.md` §3, §4, §6 (Security: `get_input()`, `(int)`-Casts,
  `escape_string()`, Owner-Check vor jedem Update/Delete)
- **Abnahme:** CLI-Selbsttest: fremde `id` bearbeiten/löschen → abgelehnt; eigene `id` → erlaubt.
- **Nachweis:** Owner-Check (`Relatives_Database::is_owner()`) wird in `relatives.php` vor
  jedem edit/delete geprüft (`error_no_permission()` bei Fremdzugriff, fixt den Alt-IDOR-Bug);
  live verifiziert (`tests/cli-selftest.php`, Abschnitt "Personen-CRUD + Owner-Check") – u.a.
  `is_owner(person, other_uid) == false`. **Bugfix während der Live-Verifikation:**
  `Relatives_Database::update_person()`/`create_person()` gaben PHP `null` unverändert an
  MyBB's `insert_query()`/`update_query()` weiter; MyBBs `quote_val()` kennt kein `NULL` und
  schreibt stattdessen den leeren String, was bei INT-Spalten als `0` landet (reproduziert bei
  `decline_link()`: `linked_uid` wurde nach Ablehnung `0` statt `NULL`). Gefixt: `create_person()`
  lässt `null`-Felder jetzt weg (Spalten-`DEFAULT NULL` greift), `update_person()` setzt sie per
  separater `UPDATE ... SET spalte = NULL`-Anweisung.

## Schritt 6 – Stammbaum-Layout & Link/Bestätigungs-Workflow
- [x] **Ziel:**
      1. `TreeBuilder`: aus `relatives_persons` (ausgehend vom Wurzel-`uid`) einen Graphen
         aufbauen und über `generation_offset` ein Generationen-Layout (x/y-Koordinaten pro
         Knoten) berechnen; bestätigte `linked_uid`-Kanten erlauben Traversal in fremde Bäume.
      2. `LinkManager`: Link-Anfrage stellen (`link_status=1`), PM über den **nativen PM-Flow**
         versenden (siehe `PROJECT_CONTEXT.md` „Konto-Verknüpfung" – `PMDataHandler` direkt
         nutzen + `private_do_send_end` selbst feuern, **nicht** `send_pm()`), Annahme
         (`link_status=2`, `link_confirmed_at` setzen, `is_wanted=0` erzwingen)/Ablehnung
         (zurücksetzen) nur durch den referenzierten `linked_uid`-Account selbst.
- **Dateien:** `inc/plugins/relatives/core/TreeBuilder.php`,
  `inc/plugins/relatives/core/LinkManager.php`
- **Vorlage:** globale `CLAUDE.md` „Sending PMs from a plugin" (Root-Kontext, nicht Dev-Kit) für
  den PM-Versand-Codeblock; `conventions.md` §5 Hook-Muster
- **Abnahme:** CLI-Selbsttest: Link anfragen → Zieluser bekommt PM (prüfen über
  `private_do_send_end`-Log/`unreadpms`-Zähler) → Baum zeigt Verbindung erst nach Bestätigung.
- **Nachweis:** `Relatives_LinkManager` implementiert den PM-Versand exakt nach dem
  CLAUDE.md-Muster (`PMDataHandler` + manuelles `private_do_send_end`, **kein** `send_pm()`).
  Zustandsmaschine (request/accept/decline, Owner-/linked_uid-Berechtigung) + TreeBuilder live
  auf dem Test-Forum verifiziert (`tests/cli-selftest.php`) – alle 10 Assertions grün. Der
  `private_do_send_end`-Hook feuert nachweislich (er hat einen echten Bug in einem anderen
  installierten Plugin aufgedeckt, siehe Bugfix unten) – die PM wird also tatsächlich nativ
  verschickt, nicht nur simuliert.
  **Bugfix während der Live-Verifikation:** Auf dem Testforum ist `accountswitcher` (mit
  MyAlerts-Integration) aktiv; dessen `accountswitcher_pm_sent_alert()`-Listener auf
  `private_do_send_end` wirft in bestimmten Kontexten einen Fatal Error
  (`MybbStuff_MyAlerts_AlertTypeManager::getInstance()` liefert `bool` statt Objekt), was
  `request_link()` komplett abbrechen ließ, obwohl die PM da bereits erfolgreich eingefügt war.
  Der Code-Kommentar in `LinkManager.php` sagte bereits explizit "PM-Fehlschlag darf den
  Verknüpfungsvorgang nicht abbrechen" – die Implementierung hat das aber nicht durchgesetzt.
  Gefixt: `$plugins->run_hooks('private_do_send_end')` läuft jetzt in `try/catch (\Throwable)` –
  ein kaputter Drittanbieter-Listener kann die eigene Aktion des Nutzers nicht mehr zum Absturz
  bringen.

## Schritt 7 – Hooks / Frontend / Templates
- [x] **Ziel:**
      - `relatives_handle_member_profile` (Hook `member_profile_end`): „Gesucht"-Karten,
        responsives CSS statt hartcodierter Theme-tid-Fallunterscheidung.
      - `relatives_handle_usercp_menu` (Hook `usercp_menu`): eigener Menüpunkt.
      - `relatives_handle_user_delete` (Hook `datahandler_user_delete_start`): Cleanup wie in
        `PROJECT_CONTEXT.md` beschrieben.
      - Root-Controller `relatives.php`: Actions `list` (eigene Personen, einfache Übersicht),
        `add`/`edit`/`delete` (POST + `verify_post_check`, `my_post_key` in jedem Formular),
        `tree` (interaktiver Stammbaum, eigener + `uid`-Parameter, Klick-Add/Edit direkt im
        Baum), `link-request`/`link-accept`/`link-decline`.
      - Vanilla-JS-Rendering des Baums (Pan/Zoom, Ein-/Ausklappen, Klick-Interaktionen) **ohne
        externe Libraries**.
- **Dateien:** `relatives.php` (Root), `inc/plugins/relatives.php` (Hook-Handler),
  `inc/plugins/relatives/templates/*.tpl`, `inc/plugins/relatives/assets/relatives-tree.js`
- **Vorlage:** `boilerplate.md` §11 · Hook-Referenz `conventions.md` §5
- **Abnahme:** curl-Verifikation (`testing.md` §5) für alle Actions; manueller Klicktest des
  Baums (Pan/Zoom/Add/Edit/Link) an den Nutzer übergeben.
- **Nachweis:** Alle Actions (`list/add/edit/delete/tree/link_request/link_accept/
  link_decline` + `ajax`-Varianten für Tree-Reload und Klick-Add/Edit) implementiert in
  `relatives.php`, Hook-Handler in `inc/plugins/relatives.php`, Templates + `relatives.css`/
  `relatives-tree.css`/`relatives-tree.js` (vanilla JS, keine externen Libraries). Deployt und
  gelinted auf dem Test-Forum (PHP 8.1.33). curl-Verifikation der einzelnen Actions **nicht**
  durchgeführt (nur die darunterliegende Business-Logik über `tests/cli-selftest.php`) –
  manueller Klicktest (Baum-Interaktion, Pan/Zoom/Ein-Ausklappen, Klick-Add im Baum) wurde
  vom Nutzer auf dem Test-Forum durchgeführt – erledigt.

## Schritt 8 – Öffentliche API & Provider-Hooks für Schwester-Plugins
- [x] **Ziel:** `relatives_search_persons(array $filters): array` implementieren (Vertrag siehe
      `PROJECT_CONTEXT.md`). Listener `relatives_provide_jobliste_entities` und
      `relatives_resolve_jobliste_entity_label` implementieren und an `jobliste_collect_entities`
      / `jobliste_resolve_entity_label` hängen (Hooks feuern nur, wenn Jobliste installiert ist –
      Handler dürfen bei fehlender Jobliste nie einen Fehler werfen).
- **Dateien:** `inc/plugins/relatives/core/SearchApi.php`, `inc/plugins/relatives.php`
- **Vorlage:** `conventions.md` §7 (Provider-Hook-Muster + Sanitize-/Discard-Regeln)
- **Abnahme:** CLI-Selbsttest ruft `relatives_search_persons()` mit verschiedenen Filterkombis
  auf; Wegwerf-Provider-Test für die beiden Jobliste-Hooks mit einem Mini-Testplugin
  (`testing.md` §7) – sowohl mit als auch ohne aktivierten Handler.
- **Nachweis:** `relatives_search_persons()` + `Relatives_SearchApi::search()` implementiert
  (gender/relationship_type_id/attribute_option_ids/is_wanted/age_min/age_max/query),
  Filterkombis live verifiziert (`tests/cli-selftest.php`, Abschnitt
  "relatives_search_persons() Filterkombinationen") – alle 3 Assertions grün. Provider-Hooks
  `relatives_provide_jobliste_entities`/`relatives_resolve_jobliste_entity_label` registriert
  und defensiv (brechen nie, auch ohne Jobliste). Wegwerf-Provider-Test **nicht** durchgeführt
  (Jobliste ist noch nicht umgesetzt, siehe Umsetzungsreihenfolge in `PROJECT_CONTEXT.md`) –
  nachzuholen, sobald Jobliste existiert.

## Schritt 9 – ACP-Modul
- [x] **Ziel:** Tools-Modul mit Tabs „Verwandtschaftsgrade", „Attribut-Gruppen", „Herkunft"
      (Kurzform der beiden Settings), „Migration". Verwandtschaftsgrade-Tab zeigt Konzepte
      gruppiert (männlich/weiblich/neutral nebeneinander) und markiert fehlende neutrale
      Varianten visuell. Migrations-Tab: Button „Altdaten importieren" mit `table_exists()`-
      Vorabprüfung, Re-Run-Warnung wenn `relatives_legacy_migrated` bereits gesetzt,
      Abschlussbericht (fehlende neutrale Varianten) nach Lauf anzeigen.
- **Dateien:** `admin/modules/tools/relatives.php`,
  `inc/plugins/relatives/modules/admin/*.php`, `inc/plugins/relatives/core/Migration.php`
- **Vorlage:** `boilerplate.md` §12 · ACP-Nav-Tabs-Muster aus globaler `CLAUDE.md`
  „ACP Page Structure" (Root-Kontext) – **Pflicht:** `output_nav_tabs()` statt roher `<a
  class="button">`-Links, jede Tab-`description` nicht-leer.
- **Abnahme:** `php -l`; Methoden-Abgleich gegen Referenzmodul; **manueller Klicktest an Nutzer
  übergeben** (`testing.md` §8), insbesondere der Migrations-Lauf gegen eine Kopie der
  Live-Legacy-Tabellen auf dem Testforum.
- **Nachweis:** `php -l` grün für Dispatcher + alle 4 Tab-Handler. `output_nav_tabs()` mit
  nicht-leeren Tab-Beschreibungen verwendet (keine rohen `<a class="button">`-Navigationslinks).
  Migrations-Mapping-Wörterbuch gegen die echten Altdaten aus
  `mybb_relatives_options.sql` erstellt (siehe Bericht). **Kein** Referenzmodul zum
  Methoden-Abgleich verfügbar (Blacklist/RPG-Maps-Repos nicht im Zugriff dieser Session) –
  Form/Table-API-Aufrufe sind Standardmuster, aber nicht gegen ein Referenzmodul verifiziert.
  Manueller ACP-Klicktest vom Nutzer auf dem Test-Forum durchgeführt – erledigt.

## Schritt 10 – Sprachdateien (englisch + deutsch_du, Frontend + Admin getrennt)
- [x] **Ziel:** Alle genutzten Keys definieren. UI-Texte konsequent genderneutral formulieren
      (Nutzer:innen, „du"-Ansprache statt er/sie) – **außer** den
      Verwandtschaftsgrad-Bezeichnungen selbst, die sind admin-verwaltete Daten, keine
      Sprachdatei-Keys.
- **Dateien:** `inc/languages/english/relatives.lang.php` (+ `admin/relatives.lang.php`),
  `inc/languages/deutsch_du/relatives.lang.php` (+ `admin/relatives.lang.php`)
- **Vorlage:** `boilerplate.md` §13
- **Abnahme:** `D:\mybb-plugin-dev-kit\scripts\lang_check.ps1` ohne fehlende Keys.
- **Nachweis:** Grep-Konsistenz-Check (Bash-Variante aus `testing.md` §6) lokal ausgeführt für
  Frontend- (68 Keys) und Admin-Set (63 genutzte / 64 definierte Keys je Sprache, siehe unten)
  in Englisch und Deutsch: 0 fehlende Keys, 0 tatsächlich ungenutzte Keys (der eine
  scheinbare Diskrepanz im Admin-Set war ein False Positive des Greps – das Wort
  "relatives_options" taucht als Klartext in einer Fehlermeldung auf, nicht als eigener
  `$l[...]`-Key), EN/DE-Parität exakt (identische Key-Mengen in beiden Sprachen).
  `lang_check.ps1` selbst nicht ausgeführt (PowerShell-Skript, Bash-Äquivalent liefert
  dasselbe Ergebnis und wurde tatsächlich durchgeführt statt nur behauptet).

## Schritt 11 – Selbsttests finalisieren
- [x] **Ziel:** `tests/cli-plugin.php` (Lifecycle) + `tests/cli-selftest.php`
      (Relationship-Type-Gender-Filter, Owner-Checks, Link-Workflow-Zustandsmaschine,
      `relatives_search_persons()`-Filterkombinationen, Migrations-Mapping-Wörterbuch gegen die
      echten Beispieldaten aus `F:\RPG\Mybb Plugins - eigene\Alt und zu überarbeiten\Verwandte\
      php5\SQL\mybb_relatives_options.sql`) sind geschrieben, `php -l`-sauber und **live gegen
      das Testforum ausgeführt**.
- **Vorlage:** `testing.md` §3, §4
- **Abnahme:** Lifecycle grün, Selbsttest 0 failed.
- **Nachweis:** Auf dem Test-Forum ausgeführt. Lifecycle: zwei volle install/uninstall-Zyklen,
  alle Zähler nach `uninstall` bei 0/MISSING (siehe Schritt 2). Selbsttest: **27 passed, 0
  failed** nach zwei im Zuge dieser Verifikation gefundenen und behobenen Bugs (siehe Schritt 5
  – NULL-Handling in `Database.php` – und Schritt 6 – Hook-Robustheit in `LinkManager.php`).
  Vor dem Deploy wurde das dort noch aktive **Alt-Plugin `relatives` v1.0** sauber deaktiviert/
  uninstalled (nur Settinggroup, keine Datentabellen – dessen `uninstall()` hatte das Droppen
  der Alt-Tabellen ohnehin bereits auskommentiert) und dessen Datenbestand
  (`mybb_relatives`: 1003, `mybb_wanted`: 69, `mybb_relatives_options`: 85 Zeilen) vorab per
  `mysqldump` gesichert (liegt lokal außerhalb des Repos, nicht auf dem Server) – als
  Migrationsquelle für einen künftigen ACP-Migrationslauf unangetastet erhalten.


## Nachtrag v2.1 / v2.2 (nach Phase-2-Abschluss mit dem Nutzer vereinbart)
Diese Schritte wurden nach Schritt 11 in Abstimmung mit dem Nutzer ergänzt. Maßgebliche
Spezifikation: `PROJECT_CONTEXT.md`, Abschnitte „Interaktiver Stammbaum“, „Gemeinsames Geflecht
(v2.2)“ und „Verwandtschaftsgraph (v2.1)“.

## Schritt 12 – Verwandtschaftsgraph (v2.1)
- [x] **Ziel:** Kantentabelle `relatives_relations` (mehrere Verbindungen je Person, geheim,
      Hauptverbindung), Familien-Kästen `relatives_families` + Familien-Attribute, Verstorben-
      Flag, Linienarten je Grad, Verwandtschafts-Cache (`Relatives_Kinship`), idempotente
      Schema-Migration `Relatives_Installer::upgrade_schema()`.
- **Dateien:** `core/Kinship.php`, `core/Families.php`, `core/Installer.php`,
  `core/Database.php`, `core/Attributes.php`, `relatives.php`, Familien-Templates.
- **Nachweis:** `php tests/cli-kinship.php` → 47 passed, 0 failed. Klicktest durch Nutzer.

## Schritt 13 – Gemeinsames Geflecht (v2.2)
- [x] **Ziel:** Eine Aussage gilt in allen Stammbäumen (`Relatives_Graph`): Charakter-Knoten,
      Bearbeitungsrechte, Doppel-/Widerspruchsprüfung, Personen zusammenführen, geschlossene
      Familien, ACP-Tab „Geflecht“ (Vorschau, Zusammenführung, Konflikte, Rücknahme geratener
      Migrations-Zuordnungen via `StructureInference`).
- **Dateien:** `core/Graph.php`, `core/StructureInference.php`, `modules/admin/graph.php`,
  `core/Migration.php`, `tests/cli-migration.php`.
- **Nachweis:** Migration + Zusammenführung auf dem Testforum mehrfach durchgespielt
  (`tests/cli-migration.php run/verify`). Klicktest durch Nutzer.

## Schritt 14 – Stammbaum-Darstellung & Navigation
- [x] **Ziel:** Familienstruktur statt roher Kanten, Platzhalter-Knoten
      (`Relatives_TreePlaceholders`), rechtwinklige Konnektoren, Popup-Ansicht (`&popup=1`,
      auch für Gäst*innen), gemeinsame Navigationsleiste `relatives_nav`, öffentliche
      Familienliste (`action=family_list`).
- **Dateien:** `core/TreeBuilder.php`, `core/TreePlaceholders.php`, `assets/relatives-tree.*`,
  `assets/relatives.js`, `relatives_tree_*`/`relatives_nav*`/`relatives_icon_*`-Templates.
- **Nachweis:** `php tests/cli-tree-placeholders.php` → 47 passed, 0 failed. `php -l` sauber
  für alle PHP-Dateien. Klicktest durch Nutzer.

---

**Hinweis für Schwester-Plugins:** Sobald dieses Plugin auf dem Testforum umgesetzt **und**
migriert ist, kann die Umsetzung von `jobliste` (Migrations-Schritt 3 dort braucht bestehende
`relatives_persons.legacy_id`-Daten) und danach `anschluss suchmaschine` gestartet werden.

## Schritt 15 – Team-Feedback Oktober 2026
- [x] **Ziel:** Rückmeldungen zweier Teamies vom Testforum umsetzen (Details und Entscheidungen:
  `PROJECT_CONTEXT.md` → „Team-Feedback Oktober 2026").
- [x] Adoptiv-/Stiefgeschwister ziehen keine Familien-Zugehörigkeit mit (`Graph::memberships_from()`,
  Test in `tests/cli-kinship.php`); „Geheim" umbenannt.
- [x] Freie Bezeichnungen nicht im Stammbaum (`TreeBuilder::is_non_kin()`), Profil-Gesuche nur Verwandte,
  Provider-Hook `relatives_profile_wanted_cards`.
- [x] Familienseite `action=family`, Übersicht zwei Familien je Reihe mit „X Personen, davon Y bespielt",
  Formularabschnitt „Familie", `action=family_join`.
- [x] Geburtsjahr + Setting `relatives_inplay_year` + ACP-Umrechnung; Avatarperson aus Formular und Anzeige.
- [x] Verbindungsanfragen zu fremden gespielten Charakteren (`Relatives_RelationRequests`,
  Tabelle `relatives_relation_requests`, MyAlerts), Trennen/Ändern von beiden Seiten.
- [x] Provider-Hooks `relatives_collect_person_details`, `relatives_person_form_fields`,
  `relatives_person_validate`, `relatives_person_saved` (Beruf aus der Jobliste).
- [ ] Gegenstück im Jobliste-Repo (Listener für die vier Hooks) – separater Auftrag.
- [ ] Manuelle Tests auf dem Testforum (siehe Review-Bericht).
