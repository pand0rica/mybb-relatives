# Verwandtschaften & Stammbaum – Project Context

## Identität
- **Name:** Verwandtschaften & Stammbaum
- **Codename / Dateiname:** `relatives` (Präfix: `relatives`)
- **Version:** 2.0.0 (Rework von Altplugin „relatives" v1.0)
- **Autor:** pand0rica
- **Kurzbeschreibung:** Verwaltet pro Charakter (= MyBB-Account) Verwandte/verbundene Personen
  mit strukturierten Zusatzinfos, zeigt offene „Gesuche" im Profil an und bietet einen
  interaktiven, klickbaren Stammbaum, der sich über Account-Verknüpfungen mit den Stammbäumen
  anderer Nutzer:innen verbinden lässt.
- **MyBB:** 1.8.x · **PHP:** 7.4 + 8.x

## Zweck & Zielgruppe
RPG-Charakterforum (h4w-rpg.de): Spieler:innen sollen für ihren Charakter Familienmitglieder/
sonstige nahestehende Personen anlegen, mit Aussehen/Beziehungsdetails beschreiben und bei Bedarf
als „gesucht" markieren (mit Link zum Gesuchs-Thread). Neu gegenüber dem Altplugin: ein
optisch ansprechender, interaktiver Stammbaum, der Personen-Einträge optional mit echten
Accounts verknüpft (nach gegenseitiger Bestätigung) und so über Account-Grenzen hinweg
durchklickbar wird.

## Ablösung von zwei Alt-Konzepten
Das Altplugin führte `relatives` (mit Verwandtschaftsgrad-Dropdown) und `wanted` (mit freiem
Beziehungstext, für Freunde/Feinde/Liebschaften) als **zwei separate, strukturell fast
identische Tabellen/Seiten**. Das war laut Audit die Hauptquelle der vom Nutzer genannten
Unübersichtlichkeit. Im Neubau: **eine** Tabelle `relatives_persons`, ein Formular. Wer keinen
formalen Verwandtschaftsgrad will, wählt den Typ „Sonstige Verbindung" und trägt einen freien
`relationship_custom_label`-Text ein (deckt die alten `wanted`-Fälle ab).

**Nicht im Scope dieses Plugins:** die alte `anschluss.php`-Suchfunktion wird als eigenständiges
Plugin `anschluss suchmaschine` gebaut (siehe „Abhängigkeiten" unten), nicht hier.

## Architektur
- **Variante:** Klassen-/Core-Split (Begründung: mehrere zusammenhängende Tabellen, ein
  Tree-Layout-Algorithmus, ein mehrstufiger Link/Bestätigungs-Workflow mit nativem PM-Versand,
  ein Migrations-Tool und eine öffentliche Such-API für ein Fremdplugin – vergleichbar mit
  RPG Maps/Blacklist, siehe Dev-Kit `conventions.md` §2).
- **Dateilayout** (kanonisches Layout siehe Dev-Kit `conventions.md` §1):
  ```
  relatives.php                                    # Frontend-Root (MyBB-Wurzelverzeichnis)
  inc/plugins/relatives.php                        # Lifecycle + Hook-Registrierung
  inc/plugins/relatives/
      core/
          Database.php          # Datenzugriff relatives_persons + Lookups (Repository)
          RelationshipTypes.php # CRUD + Gender-Filterung für relatives_relationship_types
          Attributes.php        # CRUD für Attribut-Gruppen/-Optionen + Zuordnung
          TreeBuilder.php       # Baut Generationen-Layout aus relatives_persons-Graph
          LinkManager.php       # Verknüpfungs-/Bestätigungs-Workflow (inkl. PM-Versand)
          SearchApi.php         # relatives_search_persons() – öffentliche Adapter-API
          Installer.php         # install/uninstall/activate/deactivate-Logik
          Migration.php         # Legacy-Import aus mybb_relatives/wanted/relatives_options
      assets/relatives.css, relatives-tree.css, relatives-tree.js
      templates/*.tpl
      modules/admin/            # ACP-Tab-Handler (relationship-types, attributes, migration)
  admin/modules/tools/relatives.php                # ACP-Einstiegspunkt
  inc/languages/english/relatives.lang.php (+ admin/)
  inc/languages/deutsch_du/relatives.lang.php (+ admin/)
  ```

## Datenbank-Tabellen

| Tabelle (`TABLE_PREFIX .`) | Zweck | Wichtige Spalten |
|---|---|---|
| `relatives_persons` | Ein Verwandter/eine verbundene Person eines Charakters | `id, owner_uid, name, gender (tinyint: -1 egal, 0 männlich, 1 weiblich, 2 divers), relationship_type_id (FK relatives_relationship_types, nullable), relationship_custom_label (varchar, nullable – Pflicht wenn Typ „Sonstiges"), age (int, nullable), origin (varchar, nullable – Quelle je nach `relatives_origin_mode`), avatar (varchar, nullable), description (text, nullable), is_wanted (tinyint(1) default 0), wanted_thread_link (varchar, nullable), linked_uid (int, nullable), link_status (tinyint: 0 keine, 1 angefragt, 2 bestätigt), link_requested_by (int, nullable), link_confirmed_at (int, nullable), legacy_id (int, nullable), legacy_source (varchar, nullable: 'relatives'\|'wanted'), created_at (int), updated_at (int)` |
| `relatives_relationship_types` | ACP-verwaltete, **genderspezifische** Verwandtschaftsgrade | `id, concept_key (varchar – gruppiert männliche/weibliche/neutrale Variante desselben Verhältnisses, z.B. 'parent','sibling','child','grandparent','aunt_uncle','cousin','spouse','ex_spouse','other'), gender (tinyint: 0 männlich-Label, 1 weiblich-Label, 2 neutrales Label), label (varchar), generation_offset (int: -2 Großeltern, -1 Eltern/Onkel-Tante, 0 Geschwister/Ehepartner/Cousin, +1 Kinder, +2 Enkel), sort_order (int), is_active (tinyint(1) default 1)` |
| `relatives_attribute_groups` | ACP-verwaltete Attribut-Kategorien (z.B. Haarfarbe) | `id, label, sort_order, is_active` |
| `relatives_attribute_options` | Werte je Gruppe | `id, group_id (FK), label, sort_order, is_active` |
| `relatives_person_attributes` | Zuordnung Person↔Attributwert (n:m) | `id, person_id (FK relatives_persons), group_id (FK), option_id (FK relatives_attribute_options)` |

**Bewusst kein Seed-Data-Insert in `_install()`** – alle Lookup-Tabellen starten leer und werden
entweder über die ACP oder über das Migrations-Tool befüllt (das Altplugin hatte hier nur
per-SQL-pflegbare, forumsspezifische Harry-Potter-Beispieldaten, die nicht Teil eines öffentlich
weitergegebenen Plugins sein sollen).

## Formular-Logik: genderabhängige Verwandtschaftsgrad-Auswahl
Im Add/Edit-Formular UND im Klick-Add-Interface am Stammbaum wird **zuerst** das Geschlecht der
Person gewählt. Das Verwandtschaftsgrad-Dropdown wird daraufhin (rein clientseitig, aus einem
serverseitig mitgelieferten JSON-Payload aller aktiven `relatives_relationship_types`-Zeilen)
auf die zum gewählten Geschlecht passenden `gender`-Zeilen gefiltert:
- Geschlecht „männlich" (0) → nur `gender=0`-Labels.
- Geschlecht „weiblich" (1) → nur `gender=1`-Labels.
- Geschlecht „divers" (2) oder „egal" (-1) → nur `gender=2` (neutrale Labels).
- Existiert für ein `concept_key` keine neutrale Variante, erscheint das Konzept in dieser
  Auswahl nicht; stattdessen ein Hinweistext „Für [Konzept] ist noch keine neutrale Bezeichnung
  hinterlegt" mit einem ACP-Link für Team-Mitglieder (kein harter Fehler).
- Immer verfügbar, unabhängig vom Geschlecht: Typ „Sonstige Verbindung" (freier
  `relationship_custom_label`).

## Interaktiver Stammbaum
- Eigene Hauptseite (`relatives.php?action=tree`, Parameter `uid` für fremde Bäume).
- Server berechnet ein Generationen-Layout aus `generation_offset` (Graph-Traversal ab dem
  Wurzel-Charakter); Rendering als HTML/CSS (+ optional SVG-Konnektoren) **ohne externe
  JS-Libraries** (Projektregel „keine externen Libraries"), vanilla JS für Pan/Zoom und
  Ein-/Ausklappen von Ästen.
- Klick auf einen **eigenen** Knoten (owner_uid = eingeloggter User) → Inline-Bearbeiten-Panel
  direkt im Baum (kein Seitenwechsel).
- Klick auf einen **leeren Beziehungs-Slot** neben einem Knoten (z.B. „+ Mutter hinzufügen") →
  geführtes Klick-Add-Interface (Schritt 1 Geschlecht, Schritt 2 gefilterter
  Verwandtschaftsgrad, Schritt 3 restliche Felder), das denselben Save-Endpunkt wie das
  klassische Formular nutzt.
- Klick auf einen **verlinkten, bestätigten** fremden Knoten → Navigation in dessen Stammbaum.
- Zusätzlich existiert eine einfache Listenansicht (`action=list`) als barrierearme Fallback-
  Übersicht der eigenen Personen (kein Ersatz, sondern Ergänzung zum Baum).
- **Sichtbarkeit:** Stammbäume sind für alle eingeloggten Nutzer:innen öffentlich einsehbar
  (bestätigte User-Entscheidung, keine Opt-out-Einstellung geplant).

## Konto-Verknüpfung & Bestätigungs-Workflow
- Eine `relatives_persons`-Zeile kann optional mit einem echten Account (`linked_uid`) verknüpft
  werden, um Stammbäume zu verbinden.
- **Bestätigungspflicht:** `link_status` startet bei `1` (angefragt); der verlinkte Account
  erhält eine PM über den **nativen PM-Flow** (nicht `send_pm()`, siehe globale `CLAUDE.md`
  „Sending PMs from a plugin" – `PMDataHandler` direkt nutzen und `private_do_send_end` selbst
  feuern, damit MyAlerts/Account-Switcher reagieren). Erst nach Zustimmung wird `link_status=2`
  gesetzt und `link_confirmed_at` befüllt; erst dann ist die Verbindung im Stammbaum aktiv/
  navigierbar.
- Ablehnung setzt `linked_uid`/`link_status` zurück auf „keine".
- Wird ein Link bestätigt, sollte `is_wanted` serverseitig automatisch auf `0` gesetzt werden
  (Rolle ist „besetzt").

## Settings (settinggroup `relatives`)

| Setting | optionscode | Zweck |
|---|---|---|
| `relatives_enabled` | onoff (default 1) | Master-Schalter |
| `relatives_max_persons_per_user` | text/numeric (default 0) | Missbrauchsschutz, 0 = unbegrenzt |
| `relatives_origin_mode` | select (`profilefield`/`freetext`, default `freetext`) | Herkunfts-Quelle |
| `relatives_origin_profilefield_id` | text/numeric | `fid` des MyBB-Profilfelds; nur relevant wenn `relatives_origin_mode = profilefield`; ACP validiert bei Speichern per `field_exists`-artigem Check gegen `profilefields`, dass die `fid` existiert |

## Hooks

| Hook | Handler | Zweck |
|---|---|---|
| `member_profile_end` | `relatives_handle_member_profile` | Rendert „Gesucht"-Karten (`is_wanted=1`) auf der Profilseite; **responsives CSS statt der alten hartcodierten Theme-tid-Breiten-Sonderfälle** |
| `usercp_menu` | `relatives_handle_usercp_menu` | Eigener, sauberer UserCP-Menüpunkt „Verwandtschaften" (ersetzt die alte zusammengewürfelte 3-Link-Nav) |
| `datahandler_user_delete_start` | `relatives_handle_user_delete` | Löscht alle `relatives_persons`-Zeilen mit `owner_uid = gelöschter User`; setzt bei allen Zeilen, die diesen User als `linked_uid` referenzieren, `linked_uid`/`link_status` zurück |

## Eigene Hooks / API (andere Plugins docken hier an)

**Bereitgestellte Listener** (nur wirksam, wenn Jobliste installiert ist – reines
Provider-Hook-Muster, kein Code-Pfad in Relatives hängt davon ab):
```php
$plugins->add_hook('jobliste_collect_entities', 'relatives_provide_jobliste_entities');
$plugins->add_hook('jobliste_resolve_entity_label', 'relatives_resolve_jobliste_entity_label');
```
Vertrag (siehe `jobliste`-Repo `PROJECT_CONTEXT.md`): `relatives_provide_jobliste_entities()`
hängt an `$args['entities']` Zeilen `['entity_type' => 'relative', 'entity_id' => person_id,
'label' => sanitized name]` für alle `relatives_persons` mit `owner_uid = $args['uid']` an.
Kaputte/fremde Aufrufer werden ignoriert, Escaping bleibt bei Jobliste.

**Öffentliche Adapter-API** (für `anschluss suchmaschine`, statt direktem Tabellenzugriff –
Muster aus `conventions.md` §7 „Fremde Tabellen nur über einen Adapter"):
```php
relatives_search_persons(array $filters): array
```
Filter-Keys (alle optional): `gender`, `relationship_type_id`, `attribute_option_ids` (array),
`is_wanted` (bool, Default true für den Suchmaschinen-Use-Case), `age_min`, `age_max`, `query`
(Textsuche Name/Description). Rückgabe: Array sanitisierter, aber **nicht** HTML-escapeter Zeilen
(Escaping macht der Konsument beim Rendern). Diese Funktion ist die einzige erlaubte
Zugriffsart auf `relatives_*`-Daten für Fremdplugins.

## Abhängigkeiten
- Keine Pflicht-Abhängigkeiten – Plugin funktioniert komplett eigenständig.
- **Optionaler Konsument:** `jobliste` (Repo `F:\RPG\Mybb Plugins - eigene\jobliste`) – docke
  über die oben genannten `jobliste_*`-Hooks an, wenn installiert.
- **Harter Konsument:** `anschluss suchmaschine` (Repo
  `F:\RPG\Mybb Plugins - eigene\anschluss suchmaschine`) – ruft `relatives_search_persons()` auf,
  prüft vorher per Plugin-Cache, ob `relatives` aktiv ist.
- **Umsetzungsreihenfolge:** Dieses Plugin muss **zuerst** fertig umgesetzt und (auf dem
  Testforum) migriert sein, bevor Jobliste-Migration und Anschluss-Suchmaschine sinnvoll
  entwickelt/getestet werden können (Jobliste-Migration löst `relatives_persons.legacy_id`
  auf, Anschluss-Suchmaschine braucht produktive `relatives_persons`-Daten zum Testen).

## Berechtigungen
- Alle Aktionen erfordern Login (`$mybb->user['uid'] > 0`).
- Eigene Personen anlegen/bearbeiten/löschen: nur der `owner_uid` selbst (fixt den
  Alt-IDOR-Bug, bei dem jede eingeloggte Person fremde Einträge per ID bearbeiten/löschen
  konnte).
- Stammbaum ansehen: alle eingeloggten Nutzer:innen, für jeden `uid` (öffentlich, siehe oben).
- Verwandtschaftsgrade/Attribut-Gruppen/Migrations-Tool: nur über ACP (Admin-Rechte).
- Link-Bestätigung: nur der per `linked_uid` referenzierte Account selbst darf annehmen/ablehnen.

## Migration (Legacy → Neu)
Quellen (Live-DB, `table_exists()`-Guard vor Zugriff): `mybb_relatives`, `mybb_wanted`,
`mybb_relatives_options`. Re-Run-Schutz über Setting/Flag `relatives_legacy_migrated`
(Zeitstempel; erneuter Lauf erfordert explizite Bestätigung im ACP-Formular).

1. `relatives_options` (`option_name='status'`) → `relatives_relationship_types`: festes
   Mapping-Wörterbuch bekannter deutscher Verwandtschaftsbegriff-Paare (Mutter↔Vater,
   Bruder↔Schwester, Onkel↔Tante [mütterlicherseits/väterlicherseits jeweils eigenes Paar],
   Großmutter↔Großvater [je Seite], Sohn↔Tochter, Ehemann↔Ehefrau, Ex-Mann↔Ex-Frau,
   Cousin↔Cousine, Adoptiv-/Stief-Varianten je Paar) vergibt `concept_key` + `gender` +
   `generation_offset`. Nicht paarbare Werte (z.B. „NPC") erhalten `gender=2` (neutral),
   `generation_offset=0`.
2. `relatives_options` (Gruppen `haarfarbe`, `augenfarbe`, `ethnik`, `blutstatus`) →
   `relatives_attribute_groups` (eine Gruppe je `option_name`) + `relatives_attribute_options`.
3. `relatives` + `wanted` → `relatives_persons`:
   - `owner_uid, name, age, avatar` direkt übernehmen.
   - `geschlecht` → `gender` (Mapping -1/0/1 identisch, keine neue Diversität aus Altdaten
     ableitbar).
   - `relatives.status` (FK auf alte `relatives_options`) → `relationship_type_id` über die in
     Schritt 1 aufgebaute Mapping-Tabelle.
   - `wanted.status` (Freitext) → `relationship_custom_label`, `relationship_type_id = NULL`
     (Typ „Sonstiges").
   - `herkunft` → `origin` (Freitext-Übernahme unabhängig vom späteren `relatives_origin_mode`).
   - `beziehung` → `description`.
   - `gesucht` → `is_wanted`, `link` → `wanted_thread_link`.
   - **`beruf`-Spalte wird NICHT migriert** (Aufgabe der Jobliste-Migration).
   - `legacy_id = alte id`, `legacy_source = 'relatives'` bzw. `'wanted'` (Pflicht – wird von
     der Jobliste-Migration zur Auflösung von `mybb_relatives.beruf`/`mybb_wanted.beruf`
     gebraucht).
4. Abschlussbericht (im ACP nach dem Lauf angezeigt, nicht persistiert) listet alle
   `concept_key`s ohne neutrale (`gender=2`) Variante zur manuellen Nachpflege.

## What NOT to change
- Die Trennung `relationship_type_id` (strukturiert) vs. `relationship_custom_label` (frei)
  **nicht** zu einem einzigen Freitextfeld vereinfachen – das war der Auslöser der
  Altplugin-Unübersichtlichkeit (zwei Tabellen), die hier bewusst in einer Tabelle mit klarer
  Typ-Unterscheidung gelöst wurde.
- Die `gender`+`concept_key`-Doppelspalte bei `relatives_relationship_types` nicht zu einer
  flachen Label-Liste „vereinfachen" – sie ist die Grundlage der genderabhängigen
  Dropdown-Filterung (explizite User-Anforderung).
- `legacy_id`/`legacy_source` auf `relatives_persons` nicht entfernen, auch nicht nach
  erfolgreicher Migration – die Jobliste-Migration ist darauf angewiesen und kann zeitlich
  später laufen als die Relatives-Migration.
- Kein direkter Tabellenzugriff von Fremdplugins auf `relatives_*` zulassen/dokumentieren – nur
  `relatives_search_persons()` und die beiden `jobliste_*`-Provider-Hooks sind der öffentliche
  Vertrag.
- Keine Seed-/Beispieldaten (insb. keine Harry-Potter-spezifischen Werte) fest im Plugin-Code
  verankern – das Plugin wird öffentlich weitergegeben (GitHub-Release-Workflow).
