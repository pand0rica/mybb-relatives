# Verwandtschaften & Stammbaum – Implementation Plan

**Umsetzung:** folge `D:\mybb-plugin-dev-kit\workflows\2-implement.md`. Schritte der Reihe
nach. Bei Unklarheit gezielt nachfragen, aber nicht umplanen. Schritt nach Erledigung auf
`- [x]` setzen.

---

## Schritt 1 – Grundgerüst & Zugriffsschutz
- [ ] **Ziel:** Hauptdatei anlegen, Zugriffsschutz, Hook-Registrierung (Platzhalter-Handler),
      `info()`-Block, Verzeichnisstruktur (`inc/plugins/relatives/core|assets|templates|
      modules/admin`) anlegen.
- **Dateien:** `inc/plugins/relatives.php`, `inc/plugins/relatives/core/*.php` (leere Klassen-
  Gerüste: `Database`, `RelationshipTypes`, `Attributes`, `TreeBuilder`, `LinkManager`,
  `SearchApi`, `Installer`, `Migration`)
- **Vorlage:** `D:\mybb-plugin-dev-kit\standards\boilerplate.md` §1, §2
- **Abnahme:** `php -l` sauber für alle neuen Dateien; Plugin erscheint in der ACP-Pluginliste.

## Schritt 2 – Lifecycle (install/is_installed/uninstall)
- [ ] **Ziel:** Alle 5 Tabellen anlegen/droppen (`relatives_persons`,
      `relatives_relationship_types`, `relatives_attribute_groups`,
      `relatives_attribute_options`, `relatives_person_attributes`), `is_installed()`.
      **Keine** Seed-Daten einfügen (siehe PROJECT_CONTEXT „What NOT to change").
- **Dateien:** `inc/plugins/relatives.php`, `inc/plugins/relatives/core/Installer.php`
- **Vorlage:** `boilerplate.md` §3 · Schema exakt aus `PROJECT_CONTEXT.md` „Datenbank-Tabellen" ·
  Spalten-/Typ-Konventionen `conventions.md` §4
- **Abnahme:** CLI-Lifecycle `install` legt alle 5 Tabellen mit korrekten Spalten an
  (`D:\mybb-plugin-dev-kit\standards\testing.md` §3).

## Schritt 3 – activate()/deactivate() (Settings, Templates, Stylesheet)
- [ ] **Ziel:** Die 4 Settings aus `PROJECT_CONTEXT.md` idempotent anlegen, Templates aus
      `.tpl`-Dateien laden, Stylesheets (`relatives.css`, `relatives-tree.css`) in alle Themes
      einhängen (kein Hardcode auf `tid=1` wie im Altplugin – Schleife über alle Themes);
      `deactivate()` als exaktes Spiegelbild (Templates, Settings, settinggroup, Stylesheets aus
      allen Themes entfernen, danach `rebuild_settings()`).
- **Dateien:** `inc/plugins/relatives.php`, `inc/plugins/relatives/templates/*.tpl`,
  `inc/plugins/relatives/assets/relatives.css`, `relatives-tree.css`
- **Vorlage:** `boilerplate.md` §4, §5, §6, §9
- **Abnahme:** CLI `install`→`activate`→`deactivate`→`uninstall` – alle Zähler 0
  (Cleanup-Nachweis, `testing.md` §3).

## Schritt 4 – DB-Zugriff / Relationship-Types & Attribut-Verwaltung (Business-Logik)
- [ ] **Ziel:** CRUD für `relatives_relationship_types` (inkl. `concept_key`/`gender`/
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

## Schritt 5 – Personen-CRUD (relatives_persons)
- [ ] **Ziel:** Anlegen/Bearbeiten/Löschen einer Person, inkl. Owner-Prüfung (fixt Alt-IDOR-Bug),
      Validierung (Pflichtfeld `relationship_custom_label` wenn Typ „Sonstiges"), Limit-Check
      gegen `relatives_max_persons_per_user`, Herkunfts-Logik je nach `relatives_origin_mode`
      (bei `profilefield`: Optionsliste aus `profilefields`/`fid` lesen und validieren, dass die
      `fid` existiert; bei `freetext`: einfaches Textfeld).
- **Dateien:** `inc/plugins/relatives/core/Database.php`
- **Vorlage:** `conventions.md` §3, §4, §6 (Security: `get_input()`, `(int)`-Casts,
  `escape_string()`, Owner-Check vor jedem Update/Delete)
- **Abnahme:** CLI-Selbsttest: fremde `id` bearbeiten/löschen → abgelehnt; eigene `id` → erlaubt.

## Schritt 6 – Stammbaum-Layout & Link/Bestätigungs-Workflow
- [ ] **Ziel:**
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

## Schritt 7 – Hooks / Frontend / Templates
- [ ] **Ziel:**
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

## Schritt 8 – Öffentliche API & Provider-Hooks für Schwester-Plugins
- [ ] **Ziel:** `relatives_search_persons(array $filters): array` implementieren (Vertrag siehe
      `PROJECT_CONTEXT.md`). Listener `relatives_provide_jobliste_entities` und
      `relatives_resolve_jobliste_entity_label` implementieren und an `jobliste_collect_entities`
      / `jobliste_resolve_entity_label` hängen (Hooks feuern nur, wenn Jobliste installiert ist –
      Handler dürfen bei fehlender Jobliste nie einen Fehler werfen).
- **Dateien:** `inc/plugins/relatives/core/SearchApi.php`, `inc/plugins/relatives.php`
- **Vorlage:** `conventions.md` §7 (Provider-Hook-Muster + Sanitize-/Discard-Regeln)
- **Abnahme:** CLI-Selbsttest ruft `relatives_search_persons()` mit verschiedenen Filterkombis
  auf; Wegwerf-Provider-Test für die beiden Jobliste-Hooks mit einem Mini-Testplugin
  (`testing.md` §7) – sowohl mit als auch ohne aktivierten Handler.

## Schritt 9 – ACP-Modul
- [ ] **Ziel:** Tools-Modul mit Tabs „Verwandtschaftsgrade", „Attribut-Gruppen", „Herkunft"
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

## Schritt 10 – Sprachdateien (englisch + deutsch_du, Frontend + Admin getrennt)
- [ ] **Ziel:** Alle genutzten Keys definieren. UI-Texte konsequent genderneutral formulieren
      (Nutzer:innen, „du"-Ansprache statt er/sie) – **außer** den
      Verwandtschaftsgrad-Bezeichnungen selbst, die sind admin-verwaltete Daten, keine
      Sprachdatei-Keys.
- **Dateien:** `inc/languages/english/relatives.lang.php` (+ `admin/relatives.lang.php`),
  `inc/languages/deutsch_du/relatives.lang.php` (+ `admin/relatives.lang.php`)
- **Vorlage:** `boilerplate.md` §13
- **Abnahme:** `D:\mybb-plugin-dev-kit\scripts\lang_check.ps1` ohne fehlende Keys.

## Schritt 11 – Selbsttests finalisieren
- [ ] **Ziel:** `tests/cli-plugin.php` (Lifecycle) + `tests/cli-selftest.php` (Relationship-Type-
      Gender-Filter, Owner-Checks, Link-Workflow-Zustandsmaschine, `relatives_search_persons()`-
      Filterkombinationen, Migrations-Mapping-Wörterbuch gegen die echten Beispieldaten aus
      `F:\RPG\Mybb Plugins - eigene\Alt und zu überarbeiten\Verwandte\php5\SQL\
      mybb_relatives_options.sql`).
- **Vorlage:** `testing.md` §3, §4
- **Abnahme:** Lifecycle grün, Selbsttest 0 failed.

---

**Hinweis für Schwester-Plugins:** Sobald dieses Plugin auf dem Testforum umgesetzt **und**
migriert ist, kann die Umsetzung von `jobliste` (Migrations-Schritt 3 dort braucht bestehende
`relatives_persons.legacy_id`-Daten) und danach `anschluss suchmaschine` gestartet werden.
