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
| `relatives_persons` | Stammdaten einer Person – gilt für alle Besitzer*innen (eine Person kann mehreren Charakteren gehören) | `id, name, gender (tinyint: -1 egal, 0 männlich, 1 weiblich, 2 divers), age (int, nullable), origin (varchar, nullable – Quelle je nach `relatives_origin_mode`), avatar (varchar, nullable – Avatarperson als Freitext, keine Bild-URL), description (text, nullable – Beschreibung der Person), is_wanted (tinyint(1) default 0), wanted_thread_link (varchar, nullable), linked_uid (int, nullable), link_status (tinyint: 0 keine, 1 angefragt, 2 bestätigt), link_requested_by (int, nullable), link_confirmed_at (int, nullable), legacy_id (int, nullable), legacy_source (varchar, nullable: 'relatives'\|'wanted'), created_at (int), updated_at (int)` |
| `relatives_person_owners` | Zuordnung Person ↔ Besitzer*in (n:m) mit allem, was nur aus Sicht eines Charakters gilt. **Seit v2.1 ohne die drei Beziehungsspalten** (→ `relatives_relations`), dafür mit dem Verwandtschafts-Cache `computed_*` (siehe „Verwandtschaftsgraph (v2.1)") | `id, person_id (FK relatives_persons), owner_uid, relationship_type_id (FK relatives_relationship_types, nullable), relationship_custom_label (varchar, nullable – Pflicht wenn Typ „Sonstiges"), related_to_person_id (int, nullable, FK relatives_persons.id – Bezugsperson im Baum dieses Charakters, z.B. „Geschwister der Mutter"; muss eine Person derselben Besitzer*in sein; wird beim Entfernen der Bezugsperson auf NULL gesetzt), description (text, nullable – eigener Beziehungstext „Eure Beziehung"), legacy_id (int, nullable – alte *_status.id), created_at, updated_at`; UNIQUE (person_id, owner_uid) |
| `relatives_relationship_types` | ACP-verwaltete, **genderspezifische** Verwandtschaftsgrade | `id, concept_key (varchar – gruppiert männliche/weibliche/neutrale Variante desselben Verhältnisses, z.B. 'parent','sibling','child','grandparent','aunt_uncle','cousin','spouse','ex_spouse','other'), gender (tinyint: 0 männlich-Label, 1 weiblich-Label, 2 neutrales Label), label (varchar), generation_offset (int: -2 Großeltern, -1 Eltern/Onkel-Tante, 0 Geschwister/Ehepartner/Cousin, +1 Kinder, +2 Enkel), sort_order (int), is_active (tinyint(1) default 1)` |
| `relatives_attribute_groups` | ACP-verwaltete Attribut-Kategorien (z.B. Haarfarbe, bei Familien z.B. Blutstatus) | `id, label, applies_to (person/family/both), field_type (select/text, text nur bei reinem family), is_multiple (nur Personen: 0 = genau ein Wert/Dropdown, 1 = mehrere/Checkboxen; serverseitig in `set_person_attributes()` erzwungen, Gruppen mit vorhandenen Mehrfachwerten werden beim Upgrade/Legacy-Import per `flag_multi_value_groups()` auf 1 gesetzt), sort_order, is_active` |
| `relatives_attribute_options` | Werte je Gruppe | `id, group_id (FK), label, sort_order, is_active` |
| `relatives_person_attributes` | Zuordnung Person↔Attributwert (n:m) | `id, person_id (FK relatives_persons), group_id (FK), option_id (FK relatives_attribute_options)` |
| `relatives_family_attributes` | Attributwert je Familie und Gruppe | `id, family_id, group_id, option_id (bei Vorauswahl), value (bei Freitext, max. 255)`, UNIQUE (family_id, group_id) |
| `relatives_delete_requests` | Löschantrag für einen NPC (`Relatives_DeleteRequests`) | `id, person_id, person_name (Snapshot für die Abschluss-Benachrichtigung), requested_by, status (0 offen, 1 gelöscht, 2 abgelehnt, 3 abgebrochen), created_at, resolved_at` |
| `relatives_delete_votes` | Antwort je betroffener Besitzer*in | `id, request_id, uid, vote (0 offen, 1 zugestimmt, 2 abgelehnt), answered_at`; UNIQUE (request_id, uid) |
| `relatives_relation_requests` | Verbindungsanfrage zu einem fremden gespielten Charakter (`Relatives_RelationRequests`) | `id, person_id, related_to_person_id, related_to_family_id, relationship_type_id, relationship_custom_label, is_secret` (0/'' statt NULL), `replaces_relation_id` (0 = neue Kante), `requested_by, target_uid, status (0 offen, 1 bestätigt, 2 abgelehnt, 3 abgebrochen), created_at, resolved_at` |
| `relatives_persons.birth_year` | Geburtsjahr (int, nullable); Alter = `relatives_inplay_year` − Geburtsjahr (`Relatives_Database::computed_age()`), sonst Altspalte `age` | |

**Neu in v2.1** (Migration über `Relatives_Installer::upgrade_schema()`, idempotent bei jedem Aktivieren):

| Tabelle / Spalte | Zweck |
|---|---|
| `relatives_relations` | Kantentabelle, **einzige Wahrheit** über Beziehungen: `id, owner_uid, person_id, related_to_person_id (NULL = relativ zu dir), related_to_family_id (ODER Familien-Kasten), relationship_type_id, relationship_custom_label, is_secret, is_primary (genau eine je owner+person, normalize_primary()), sort_order, created_at, updated_at`. Beliebig viele Zeilen je Person. |
| `relatives_families` | Familien-Kästen, forumsweit geteilt: `id, name, color (#rrggbb, sanitize_color()), description, welcomes_new_members (TINYINT, Default 1), created_by, created_at, updated_at` |
| `relatives_persons.is_deceased`, `.death_note` | Verstorben-Flag + optionaler Freitext (Jahr/Datum) |
| `relatives_person_owners.computed_concept_key/_label/_up/_down/_category/_at` | Cache „wie steht die Person zu diesem Charakter" (`Relatives_Kinship`) |
| `relatives_relationship_types.line_style` | `solid`/`dashed`/`dotted`/`dashdot` – Linienart im Stammbaum |

Die alten Aliasse `relationship_type_id`, `relationship_custom_label`, `related_to_person_id` liefert
`Relatives_Database::owner_select_sql()` weiterhin – per LEFT JOIN auf die Primärkante.
`add_owner()`/`update_owner()` akzeptieren die alten Keys als Shim und schreiben sie in die Primärkante
(dadurch laufen Legacy-Migration und `StructureInference` unverändert).

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
- **Popup-Ansicht** (`&popup=1`, Button „Stammbaum Popup" in der gemeinsamen Navigationsleiste `relatives_nav` aller UserCP-Seiten – Meine Verwandten · Familien · Stammbaum ansehen (aktiver Bereich per `relatives_nav_active` + `aria-current` markiert), als Tab-Leiste (`relatives_nav_tab`), rechts die Seitenaktion als Icon-Button (`relatives_nav_action`, Templates `relatives_icon_person_add` / `relatives_icon_family_add`, Label als Tooltip + aria-label) und ein Pop-out-Icon (Inline-SVG, Tooltip „Stammbaum als Popup öffnen"); Helfer `relatives_nav($uid, $active, …)`): Template
  `relatives_tree_popup` mit `{$headerinclude}` (Theme-CSS), aber ohne Forenheader, UserCP-Nav
  und Footer; der Viewport füllt das Fenster. Der Link ist teilbar („Link kopieren"). Für
  alle außer dem/der Baum-Besitzer*in schreibgeschützt (`config.readOnly`, auch fürs Team);
  Klick auf verknüpfte Charaktere bleibt im Popup. **Gäst*innen dürfen das Popup sehen,
  inklusive geheimer Verbindungen (bestätigte User-Entscheidung)** – es ist die einzige
  Ausnahme von der Login-Pflicht in `relatives.php` (nur `action=tree&popup=1` samt
  AJAX-Nachladen, Gruppenrecht „Forum ansehen" gilt weiter). Toolbar/Legende und Script-Block liegen als Teil-Templates
  `relatives_tree_toolbar` / `relatives_tree_scripts` vor, die beide Ansichten teilen.
- Server berechnet ein Generationen-Layout aus `generation_offset` (Graph-Traversal ab dem
  Wurzel-Charakter); Rendering als HTML/CSS mit SVG-Konnektoren **ohne externe
  JS-Libraries** (Projektregel „keine externen Libraries"), vanilla JS für Pan/Zoom (eingebettet: Zoom nur mit Strg/⌘ + Mausrad bzw. Trackpad-Pinch, normales Scrollen scrollt die Seite und blendet einen Hinweis ein; im Popup zoomt das Mausrad direkt; enthält der Baum nur den Wurzelknoten, erscheint ein Leerzustands-Hinweis und der eigene Kasten pulsiert) und
  Ein-/Ausklappen von Ästen.
- **Familienstruktur statt roher Kanten:** `Relatives_TreeBuilder::build_structure()` leitet
  aus dem `concept_key` jeder Relation (Person X ist „Ehemann/Mutter/Bruder/Sohn …" von
  Anker A) eine echte genealogische Struktur ab und liefert sie zusätzlich zu `nodes`/`edges`
  als `partners`, `families` (`parents` + `children`) und `links` aus:
  - Partner-Konzepte (`spouse`, `ex_spouse`, `partner`) → Partner-Paar.
  - Eltern-/Kind-Konzepte → Eltern-Menge des Kindes; der Partner eines Elternteils gilt
    ebenfalls als Elternteil (gleiche Annahme wie die `COMPOSE`-Tabelle: „Mamas Ehemann" =
    dein Vater).
  - Geschwister-Konzepte → Geschwistergruppe, die sich die Eltern-Menge teilt; ohne bekannte
    Eltern entsteht ein reiner Geschwisterbalken ohne Elternabgang.
  - Alles ohne eindeutige Abstammung (`aunt_uncle`, `cousin`, `grandparent`, freie
    Bezeichnung) sowie bestätigte Account-Verknüpfungen → `links`, im Baum **gestrichelt**
    gezeichnet, damit sie nicht als Eltern-Kind-Linie missverstanden werden – aber nur, wenn
    die Platzhalter-Auflösung (nächster Punkt) sie nicht abbilden kann.
  - **Platzhalter statt Direktstrich (bestätigte User-Entscheidung):** Tante/Onkel,
    Nichte/Neffe, Cousin*e (inkl. Groß-/Großcousin*e), Schwiegereltern/-kind, Schwager*in,
    Halb- und Stiefgeschwister laufen über die genealogisch nötigen Zwischenpersonen.
    `Relatives_TreePlaceholders` (Aufruf in `TreeBuilder::structure_with_placeholders()`)
    löst jede solche Aussage nach dem ersten Strukturdurchlauf in synthetische
    Eltern-/Geschwister-/Partner-Kanten auf; fehlt die Zwischenperson, entsteht ein Knoten
    `type = 'placeholder'` („Elternteil von Jolina", „Partner*in von Ava"). Danach wird die
    Struktur neu gebaut. **Nur Anzeige, nie gespeichert** – ist die echte Person eingetragen,
    ist die Aussage schon durch die Struktur abgedeckt und der Platzhalter entfällt.
    - **Mehrdeutig wird nicht geraten:** Hat Jolina Mutter UND Vater (oder nur einen bekannten
      Elternteil – dann kommt ein Platzhalter-Elternteil dazu), steht ein neutraler
      „Großelternteil von Jolina" eine Generation höher; seine Stichleitung endet auf der
      Paarlinie der Eltern (`families[].either`, im Frontend `bottom` = Kastenmitte). Eigene
      bekannte Eltern der Tante bekommen den Abgang direkt. Panel-Ableitungen („Schwester von")
      über Platzhalter-Familien entfallen.
    - Schwiegereltern: über die einzige Partner*in bzw. Platzhalter-Partner*in (`no_coparent`:
      wird nicht Elternteil der Kinder). Schwager*in nur, wenn genau eine Seite eine
      Partner*in hat. Halb-/Stiefgeschwister hängen als Zweitfamilie (`group` `half`/`step`,
      `positions` = positioniert, wird aber als `family_secondary` am Eltern-Balken gezeichnet).
    - **Aussagen werden zusammen gelesen** (vor der Einzelauflösung): Halb-/Stiefgeschwister
      zuerst (ihr Elternpaar zählt danach als bekannte Herkunft – Mischa, Halbbruder von
      Jolina UND Cousin von Louisa, bekommt keine zweite, erfundene Herkunft); gegenseitige
      Tanten („Smilla Tante von Marjatta", „Vilma Tante von Thore", jeweils Elternteil des
      anderen Kindes) werden Geschwister; mehrere Tante/Cousin*e-Aussagen derselben Person,
      deren mögliche Geschwistergruppen sich auf genau eine schneiden, setzen die Person bzw.
      ihren Platzhalter-Elternteil direkt in diese Gruppe (`resolve_mutual_aunts()`,
      `resolve_combined_joins()`).
    - Bleiben lose Linie: Groß-/Urgroßeltern und Enkel (bewusst nicht im Umfang), freie
      Bezeichnungen, `distant_*`, widersprüchliche Generationen, Familien-Kästen auf dem Weg,
      mehrere Partner*innen, Schwager*in ohne erkennbare Richtung.
    - Tests ohne MyBB: `php tests/cli-tree-placeholders.php`.
  Unbekannte, im ACP selbst angelegte `concept_key`s fallen auf den `generation_offset`
  zurück (-1 = Elternteil, +1 = Kind, sonst lose Verbindung).
- **Layout:** Partner (bzw. gemeinsame Eltern einer Familie) bilden eine „Einheit", die nie
  auseinandergerissen wird; Eltern werden über ihrem Kinderblock zentriert. Die Reihenfolge
  je Generation ist paarbewusst (`TreeBuilder::sequence_generation()`/`append_family()`):
  Familien, die über ein Paar verbunden sind, stehen als Kette nebeneinander – „Mamas
  Geschwister | Mama, Papa | Papas Geschwister" –, angeheiratete Partner*innen stehen außen.
  So endet jeder Geschwisterbalken an der Paar-Nahtstelle; Balken zweier Familien laufen
  nicht übereinander. Partnerketten (Cathalain – Caiomhe – Simon – Milena) stehen immer als
  Kette (`chain_order()`); lose Verbindungen derselben Zeile (Freund*innen …) stehen direkt
  neben ihrem Anker, der Anker am Rand seines Geschwisterblocks
  (`place_floating_beside_anchor()`).
  **Kreuzungsminimierung** (`TreeBuilder::reduce_crossings()`): Einheiten ohne eigene Eltern
  (Familien-Kästen, Stammeltern) probieren jeden Platz ihrer Zeile; danach werden nur an
  Kreuzungen beteiligte Einheiten verschoben (±`CROSSING_WINDOW` Plätze, auch mit Neuordnung
  aller Generationen darunter) oder gespiegelt – übernommen wird nur, was Kreuzungen spart,
  höchstens `CROSSING_BUDGET` Proben (feste Anzahl statt Zeit: derselbe Baum sieht immer
  gleich aus). Gezählt wird in `count_crossings()` genau wie `buildConnectorPaths()`
  zeichnet – die `DRAW_*`-Konstanten und die Höhenstufen-Regel müssen zu
  `relatives-tree.js` passen. Überschneiden sich Balken einer Zeile, versetzt das Frontend
  sie in der Höhe (`assignBusLanes()`): je Gruppe sich überschneidender Balken die
  Stapelreihenfolge mit den wenigsten Kreuzungen (Teilmengen-DP bis `LANE_ORDER_LIMIT`
  Balken, sonst schmal zuerst). Danach rückt `straighten_single_children()` Einzelkinder genau
  unter ihren Elternstiel (Eltern oder Kind verschieben, notfalls samt Nachbarn; nur ohne neue
  Kreuzung); bleibt ein Versatz bis `STRAIGHT_TOLERANCE` (32 px), zeichnet das Frontend
  trotzdem eine gerade Linie statt eines Knicks. Umgesetzt als
  iterative Relaxation (vereinfachtes Sugiyama-Verfahren, 6 Durchläufe) statt Rekursion –
  kann bei widersprüchlichen Nutzereingaben nicht entarten. `y` bleibt die Generation, `x`
  ist ein Float in Knotenbreiten; der Wurzel-Account liegt immer bei `x = 0`.
- **Linien-Grammatik im Frontend** (`relatives-tree.js`, ein einziges SVG, rechtwinklig):
  Partnerlinie waagerecht zwischen den Kästen · ein Abgang aus der Paarmitte (bzw. aus der
  Unterkante eines einzelnen Elternteils) auf einen gemeinsamen Geschwisterbalken · von dort
  je eine Stichleitung nach unten zu jedem Kind. `NODE_WIDTH`/`NODE_HEIGHT` in der JS-Datei
  müssen zu `--relatives-node-width`/`-height` in `relatives-tree.css` passen – die
  Ansatzpunkte der Linien werden daraus gerechnet, nicht per DOM-Messung.
  Gemeinsame Eltern eines Kindes werden auch ohne Partner-Relation mit einer Partnerlinie
  verbunden (`TreeBuilder::with_coparent_partners()`, Altdaten kennen nur Mutter/Vater je Kind).
  Lose (gestrichelte) Verbindungen innerhalb einer Zeile laufen über den Kästen
  (`SAME_ROW_LANE`), nicht auf Kastenhöhe quer durch andere Kästen. `settle_generation()`
  löst Überlappungen immer auf – auch in Generationen, die nur lose Knoten enthalten.
- Klick auf einen **eigenen** Knoten (self oder Person, owner_uid = eingeloggter User) →
  Aktionsmenü im Seitenpanel (kein Seitenwechsel): **Bearbeiten** / **Löschen** (beides nur
  bei Personen-Knoten, per AJAX ohne Reload) / **Person hinzufügen** (bzw. „Verwandte/n von
  X" bei einem Personen-Knoten). Es gibt bewusst **keine** dauerhaft sichtbaren „+"-Ghosts
  mehr – die haben den Baum zugemüllt (frühere Version, siehe Git-Historie).
- Klick auf einen **verlinkten, bestätigten** fremden Knoten → Navigation in dessen Stammbaum.
- Klick auf einen fremden, nicht-eigenen Knoten ohne bestätigte Verknüpfung → Nur-Lese-Panel.
- **„Person hinzufügen" bei einer Person X (`related_to_person_id` = X)**: Der im Formular
  gewählte Verwandtschaftsgrad wird **relativ zu X** interpretiert, nicht relativ zu dir
  selbst – man macht die Eingabe „aus Sicht des Verwandten": Klick auf Mama, Typ „Ehemann"
  wählen heißt „das ist Mamas Ehemann". Die **Bezugsperson** ist in beiden Formularen wählbar (klassisches Formular und
  Baum-Panel): eigene Personen außer der Person selbst und allen, deren Bezugskette zu ihr
  führt (`Relatives_Database::is_valid_related_to()`, serverseitig geprüft). Beim Bearbeiten
  wird sie nur übernommen, wenn das Formular `related_to_submitted=1` mitschickt – Aufrufer
  ohne das Feld löschen eine bestehende Verknüpfung nicht (`prepare_owner_row()`).
  Generation wird entsprechend relativ zur Bezugsperson berechnet (nicht relativ zu dir),
  iterativ aufgelöst in `Relatives_TreeBuilder::traverse_uid()` (Ketten/Reihenfolge in
  `get_persons_by_owner()` sind nicht garantiert dependency-sortiert).
- **Anzeige-Label wird aus deiner Sicht zurückkomponiert**
  (`Relatives_RelationshipTypes::compose_concept()` + `COMPOSE`-Tabelle in
  `RelationshipTypes.php`): „Mamas Ehemann" (Typ Ehemann, relativ zu Mama = Mutter) wird im
  Baum als **„Vater"** angezeigt, nicht als „Ehemann". Die Komposition läuft über die
  **komplette Bezugskette** (`TreeBuilder::traverse_uid()`): „Schwester von Papa", wobei Papa
  „Ehemann von Mama" ist, wird zu Elternteil→Tante; „Bruder von Tante", wobei Tante
  „Schwester von Mama" ist, wird zu „Onkel". Hinterlegt sind die gängigen Kombinationen mit
  naheliegender Lesart (u.a. Elternteil+Ehepartner→Elternteil, Elternteil+Geschwister→
  Tante/Onkel, Elternteil+Elternteil→Großelternteil, Elternteil+Kind→Geschwister,
  Geschwister+Kind→Nichte/Neffe, Geschwister+Geschwister→Geschwister,
  Tante/Onkel+Kind→Cousin, Tante/Onkel+Geschwister→Tante/Onkel, Kind+Kind→Enkelkind,
  Großelternteil+Ehepartner→Großelternteil, Ehepartner+Kind→Stiefkind). Unbekannte
  Kombinationen (z.B. Groß-Tante/-Onkel – dafür gibt es keine eigenen Konzepte in
  `RelationshipTypes::COMMON_DEFAULTS`) werden **nicht geraten**, sondern zeigen
  „<gewählter Typ> von <Bezugsperson>" (z.B. „Schwager von Papa") als ehrlichen Klartext-
  Fallback. `relationship_type_id` selbst bleibt dabei unverändert der roh gewählte Typ
  (relativ zur Bezugsperson) – das komponierte Label ist ein reines Anzeige-Feld
  (`display_relationship_label` im Tree-JSON), nicht persistiert. Dieselbe Komposition
  (`Relatives_RelationshipTypes::display_labels()`) nutzen Liste und Profil-Gesuche.
- Zusätzlich existiert eine einfache Listenansicht (`action=list`) als barrierearme Fallback-
  Übersicht der eigenen Personen (kein Ersatz, sondern Ergänzung zum Baum).
- **Sichtbarkeit:** Stammbäume sind für alle eingeloggten Nutzer:innen öffentlich einsehbar
  (bestätigte User-Entscheidung, keine Opt-out-Einstellung geplant).

## Gemeinsames Geflecht (v2.2)

**Grundprinzip (bestätigte User-Anforderung):** Das Stammbaum-Geflecht gehört niemandem. Jede
Aussage „X ist <Grad> von Y" steht genau einmal in `relatives_relations` und gilt in allen
Stammbäumen; jede Person hat genau einen Eintrag. Man fügt etwas hinzu und verbindet den eigenen
Charakter damit. Wo die Abschnitte v2.1/„Mehrere Besitzer*innen" unten noch „pro Charakter"
sagen, gilt für Verbindungen jetzt dieser Abschnitt.

- **Charakter-Knoten:** Jeder gespielte Charakter ist selbst eine Person mit bestätigtem
  `linked_uid` (`Relatives_Graph::account_person_id()` legt sie bei Bedarf an bzw. verknüpft eine
  gleichnamige). „Zu deinem Charakter" im Formular (Anker `0`) ist eine normale Kante zu diesem
  Knoten. `relatives_relations.owner_uid` ist nach der Zusammenführung 0 und wird nicht mehr
  gelesen; wer eine Kante angelegt hat, steht in `created_by`.
- **`relatives_person_owners`** heißt nur noch „mein Charakter ist mit dieser Person verbunden":
  steuert Liste, Rechte, Beziehungstext und den Verwandtschafts-Cache. „Löschen" trägt einen
  gespielten Charakter nur aus der eigenen Liste aus. Bei einem **NPC** stellt es einen
  Löschantrag (`Relatives_DeleteRequests`): Steht er in keiner anderen Liste, wird er sofort
  komplett gelöscht (samt Verbindungen). Sonst müssen alle anderen Besitzer*innen zustimmen
  (MyAlerts `relatives_delete_request`, Box „Offene Löschanträge“ unter „Meine Verwandten“);
  dann wird er komplett gelöscht und die antragstellende Person bekommt
  `relatives_delete_completed`. Ein „Behalten“ lässt den Antrag platzen: der NPC bleibt, die
  antragstellende Person wird ausgetragen (`relatives_delete_declined`). Keine Frist; das Team
  entscheidet hängende Anträge im ACP-Tab „Löschanträge“. Wer die Person während eines offenen
  Antrags übernimmt, wird beim Abschluss nachträglich gefragt.
- **Rechte (`Relatives_Graph::can_edit_person()`):** nicht bespielte Person → alle, die sie in
  ihrer Liste haben **oder deren Charakter derselben Familie angehört**; gespielter Charakter →
  nur sein Account; Team (`canmodcp`/`cancp`) → alles. Familienmitglieder
  (`Relatives_Graph::family_memberships()`): direkt am Familien-Kasten hängende Personen, deren
  Geschwister (nicht Adoptiv-/Stiefgeschwister), alle Nachkommen davon sowie die Partner*innen
  all dieser Personen (von Partner*innen aus wird nicht weitergesucht).
- **Familien ohne Grad:** Wählt man im Formular eine Familie als Bezug, entfällt der
  Verwandtschaftsgrad. Ohne Haken wird eine reine Mitgliedschaft gespeichert (Kante zum Kasten
  ohne `relationship_type_id` und ohne Bezeichnung; im Baum gestrichelt, Label „Mitglied“, für
  die Generation zählt sie wie ein Kind des Kastens). „Familie als Eltern anzeigen“ speichert
  eine Kind-Kante (Grad „Kind“ passend zum Geschlecht; eine vorhandene Kind-Variante wie
  Adoptivkind bleibt erhalten) – der Kasten steht dann an der Stelle der Eltern. Fremde Personen außerhalb davon lassen sich im
  Baum per „In meine Liste übernehmen“ (`action=adopt`) bearbeitbar machen.
  Verbindungen darf ändern, wer eine der beiden Personen bearbeiten darf. Der Baum liefert pro
  Knoten `can_edit` für die ansehende Person.
- **Keine Doppel-/Widerspruchsaussagen:** `Relatives_Graph::find_existing_statement()` vergleicht
  beide Richtungen (`canonical_key()`: „Delia Mutter von Louisa" = „Louisa Tochter von Delia").
  Gleiche Aussage wird nicht doppelt gespeichert, eine andere wird mit Hinweis abgelehnt.
- **Abgeleitete Verbindungen (nicht gespeichert):** Wer dieselben Eltern, denselben
  Familien-Kasten oder dieselbe Geschwistergruppe hat, ist Geschwisterteil – ein neuer „Bruder von
  Oliver" ist sofort Bruder von Delia und Matthew. `TreeBuilder::attach_node_relations()` markiert
  solche Panel-Einträge mit `derived`; die Verwandtschaftsrechnung erkennt sie ohnehin über den
  Graphen. Bewusst nicht gespeichert, damit Korrekturen an Olivers Familie sofort überall stimmen
  (bestätigte User-Entscheidung).
- **Baumumfang:** `TreeBuilder::build($root_uid, $viewer_uid, $expand)` geht ab dem Charakter-Knoten
  mit Schritt-Budget (`MAX_STEPS = 6`, Partner-Kante kostet 3, lose Kante 2) durchs Geflecht.
  Knoten, hinter denen es weitergeht, tragen `has_more` und einen „…"-Knopf; aufgeklappte Knoten
  (`expand=<ids>`) bekommen `EXPAND_STEPS = 3` neues Budget. Verwandtschafts-Labels im Baum gelten
  immer aus Sicht des gezeigten Charakters.
- **Robust gegen widersprüchliche Listen (v2.2.1, Befund Testforum):** Migrierte Listen enthalten
  Fehlannahmen (z. B. „Damien ist Sohn von Delia", obwohl Damien Megans Bruder ist). Deshalb:
  - Baum-Umfang: lose Kanten (Tante, Cousine, freie Bezeichnung) werden nur an der Wurzel oder an
    aufgeklappten Knoten weiterverfolgt – sonst zog „Ravena ist Tante von Kjartan" dessen ganze
    Familie in fremde Bäume.
  - Gezeichnet werden lose Verbindungen nur für Knoten ohne Familie/Paar, eine je Knoten
    (`TreeBuilder::needed_links()`); fürs Layout rücken solche Knoten in den Geschwisterblock
    ihres Ankers (`with_floating_neighbours()`). Seit den Platzhaltern betrifft das nur noch
    Aussagen, die `Relatives_TreePlaceholders` nicht auflösen kann.
  - Geschwister teilen Eltern nur bei vollen Geschwistern (`sibling`), und nur als Ergänzung für
    Geschwister ohne eigene Eltern; steht die Wurzel in der Gruppe, gelten ihre Eltern. Partner*in
    eines Elternteils wird nur ergänzt, solange das Kind weniger als zwei Eltern hat.
  - `Kinship`: höchstens 2ⁿ Vorfahren je Ebene (`ancestor_slot_free()`); einzige*r Partner*in
    eines alleinigen Elternteils zählt als zweiter Elternteil, aber nur nach unten (`inferred`).
  - Ohne benennbaren Weg zur Wurzel: Kategorie `via` – gespeichert werden Grad +
    `computed_via_person_id`, der Satz „Adoptivvater von Louisa Griffin" entsteht erst bei der
    Anzeige (`Kinship::display_label()`), damit der Cache nicht sprachabhängig ist.
  - Layout: Eltern werden über dem Mittel aller ihrer Kinderblöcke zentriert, Kinder unter der
    Mitte ihrer tatsächlichen Eltern (nicht der ganzen Einheit); danach entfernt
    `close_empty_columns()` senkrechte Leerstreifen, die in keiner Generation belegt sind. Im
    Frontend bekommen Geschwister ohne sichtbare Eltern nur dort einen Balken, wo sie beieinander
    stehen.
- **Geratene Zuordnungen der Alt-Migration (v2.2.1, Befund Testforum):** `StructureInference`
  ließ bei *offenen* Graden Eindeutigkeit genügen – in Jolinas Liste stand nur Delia als Tante,
  also wurden alle „Cousins“ zu Delias Kindern. Seitdem gilt `GUESS_RULES` (Cousin*e → Kind einer
  Tante/eines Onkels, Nichte/Neffe → Kind eines Geschwisters, Enkel → Kind eines Kindes,
  Schwiegerkind, Schwager über Geschwister): dort zählen nur Fakten oder ein Nachname, der
  zwischen **mehreren** Kandidat*innen unterscheidet. Sichere Zuordnungen bleiben („Onkel
  väterlicherseits“ → Bruder des einzigen Vaters).
  Für schon migrierte Daten spielt `replay_legacy_guesses()` die Ableitung mit den alten Regeln nach;
  `Relatives_Graph::revert_legacy_guesses($dry_run, $owner_uid)` macht noch unveränderte
  Rateergebnisse wieder zur Originalaussage („Christopher Lancaster ist Cousin von Jolina“).
  ACP → Geflecht listet sie mit Knopf. Testforum: Jolinas 5 zurückgenommen (User-Entscheidung),
  8 weitere aus den Listen von Abel Solomon und Marjatta Heikkinen stehen noch zur Prüfung.
- **Personen zusammenführen:** `Relatives_Graph::plan_merge_person()`/`merge_person()` hängen
  Verbindungen um, verwerfen Duplikate, legen Widersprüche (auch einen dritten leiblichen
  Elternteil) in die Konfliktliste und übernehmen die Alt-ID (`legacy_id`) für die Jobliste.
- **Cache:** geteiltes Geflecht → jede Änderung kann überall nachwirken. `Relatives_Kinship::
  recompute_all()` rechnet alles (Geflecht einmal geladen) und schreibt nur geänderte Werte.
- **Konto-Verknüpfung bestätigt:** hatte der Account schon einen automatischen Knoten, wird er per
  `Relatives_Graph::merge_person()` in die bestätigte Person überführt.
- **Zusammenführung (ACP → Tools → Verwandtschaften → Geflecht):** Vorschau ohne Schreibzugriff,
  dann `Relatives_Graph::merge()`: (1) Personen mit Account-Namen werden dessen Knoten, (2)
  gleichnamige Personen werden zusammengefasst, (3) Charaktere mit Liste bekommen einen Knoten,
  (4) Kanten werden globalisiert – Aussagen, die direkt am eigenen Charakter hingen, gewinnen,
  identische werden zusammengelegt, Widersprüche landen in `relatives_relation_conflicts`
  (ACP: übernehmen/verwerfen), (5) eine Hauptverbindung je Person, (6) Setting
  `relatives_graph_merged` + Cache neu. Bis dahin sperrt `relatives.php` das Frontend.
  Ausnahme Neuinstallation ohne Alt-Tabellen: `Relatives_Installer::install()` setzt das Flag
  sofort (nichts zusammenzuführen); Upgrades laufen nie durch `install()`.
  Testforum-Stand vor der Umstellung: 265 Personen, 382 Verbindungen, 32 Account-Verknüpfungen,
  16 neue Knoten, 4 doppelte Namen, 26 identische Aussagen, 0 Widersprüche.

## Team-Feedback Oktober 2026 (bestätigte User-Entscheidungen)

Zwei Teamies haben das Plugin auf dem Testforum ausprobiert; daraus folgten diese Regeln:

- **Bestätigungspflicht für fremde gespielte Charaktere.** Eine Kante, die einen gespielten
  Charakter einer anderen Spieler*in berührt (`Relatives_RelationRequests::confirming_uid()`), wird
  beim Anlegen oder Ändern nicht gespeichert, sondern als Anfrage in `relatives_relation_requests`
  abgelegt (`split()` im add/edit-Handler, `relation_edit`). Unveränderte Kanten bleiben, eine
  geänderte alte Kante bleibt bis zur Bestätigung stehen (`replaces_relation_id`). Bestätigen
  legt die Kante an und trägt die Gegenperson in die Liste der bestätigenden Person ein
  (Fall Norah/Lenja: Lenja stand vorher nie in Norahs Liste). MyAlerts
  `relatives_relation_request/_accepted/_declined`, Box „Offene Verbindungsanfragen" unter
  „Meine Verwandten", Zähler im Nav-Badge. Accounts derselben Spieler*in (Account-Switcher,
  `users.as_uid`) gelten als eine Person. Bestehende/migrierte Kanten bleiben aktiv; das Team
  ist nicht ausgenommen.
- **Trennen geht immer sofort, von beiden Seiten** (`action=relation_delete`, Recht
  `Relatives_Graph::can_edit_relation()`): „×" im Baum-Panel (`relations[].relation_id/can_edit`)
  und Abschnitt „Von anderen eingetragene Verbindungen" im Personenformular
  (`relatives_render_incoming_relations()`), dort auch Grad ändern (Fall Nick/Sienna: Nicks
  Kante zu Sienna ließ sich vorher nur von Nicks Account aus ändern).
- **Freie Bezeichnungen nie im Stammbaum.** `TreeBuilder::is_non_kin()` (freie Bezeichnung ohne
  Grad, Konzept `other`) wird in `collect()` übersprungen – Freund*innen/Clique aus der alten
  `wanted`-Tabelle stehen nur noch in der Liste. Die Profil-Gesuche (`wanted_nodes()`) zeigen
  dadurch nur Verwandte; nicht-verwandte Gesuche liefert später die Anschluss-Suchmaschine über
  den Provider-Hook `relatives_profile_wanted_cards`.
- **„Geheim" blendet nichts aus** und heißt deshalb „Inplay geheim (blasse Linie)" mit
  Erklärtext. Eine Ausblenden-Option gibt es bewusst nicht.
- **Adoptiv- und Stiefgeschwister ziehen keine Familie mit** (`Graph::memberships_from()`,
  `MEMBERSHIP_EXCLUDED_SIBLINGS`); Halbgeschwister, (Adoptiv-)Kinder und Partner*innen schon.
- **Familien:** eigener Formularabschnitt „Familie" (intern weiter `relations[i][anchor]=f:<id>`),
  Familienseite `action=family` (Gast-Zugriff wie `family_list`) mit Mitgliedergruppen und
  „Zu dieser Familie hinzufügen" (`action=family_join`, gleiche Prüfung geschlossener Familien).
  Übersicht: zwei Familien pro Reihe, „X Personen, davon Y bespielt", Anriss statt voller
  Beschreibung.
- **Geburtsjahr statt Alter:** Setting `relatives_inplay_year` (leer = altes Feld „Alter");
  ACP-Knopf „Alter in Geburtsjahre umrechnen" (Tab Verwandtschaftsgrade). `relatives_search_persons()`
  liefert `age` berechnet weiter (Vertrag unverändert) plus `birth_year`.
- **Beruf kommt aus der Jobliste**, nicht aus einem eigenen Feld: Provider-Hook
  `relatives_collect_person_details` (Anzeige in Baum-Panel, Liste, Gesuchskarte). Der Listener
  gehört ins Jobliste-Repo.
- **Attribute** (Haar-/Augenfarbe unnötig, Blutstatus sinnvoll) sind reine ACP-Konfiguration.

## Verwandtschaftsgraph (v2.1)

- **Mehrere Verbindungen je Person.** Das Formular (`relatives_form.tpl`) hat ein Wiederholfeld
  „Verbindungen": je Zeile Bezugsperson **oder** Familien-Kasten (ein Auswahlfeld, Werte `p:<id>` /
  `f:<id>` / `0`), Grad bzw. freie Bezeichnung, „geheim", „Hauptverbindung". Validiert in
  `relatives_collect_relations()` (`relatives.php`). Die Hauptverbindung positioniert die Person
  im Layout. Beispiel Louisa: `child` von Delia (geheim), `child` von Morgan (geheim),
  `adoptive_child` von zwei Adoptiveltern.
- **Bearbeiten im Baum = volles Formular.** „Person bearbeiten“ im Baum öffnet dasselbe Formular
  wie unter „Meine Verwandten“ (`action=edit&return=tree` bzw. `return=tree_popup`): alle
  Verbindungen als feste Aussagen, nicht aus Sicht des eigenen Charakters. Das Baum-Panel dient
  nur noch dem Schnell-Hinzufügen (eine Verbindung); der Server ersetzt bei einem
  Einzelfeld-Submit ohnehin nie mehrere bestehende Kanten.
- **Verwandtschaft zu dir: `Relatives_Kinship`** (ersetzt `display_labels()`/`COMPOSE` als
  Anzeige-Quelle). Baut aus `relatives_relations` einen Graphen (`structure_from()`, Familien-Kästen
  wirken als Elternknoten) und breitet von dir aus genealogische Koordinaten `(up, down)` aus
  (`blood_degrees()`: abwärts zu Kindern, aufwärts zu Eltern, seitwärts zu Geschwistern, bis
  stabil). Nur Blutskanten zählen für Koordinaten (`adoptive_*`, `step_*`, `foster_*` nicht); die
  kürzeste Koordinate gewinnt. Direkt an dir eingetragene Grade gelten immer.
- **Begrenzung** (`concept_for_degree()`): exakt benannt bis Urgroßeltern/Urenkel (3,0)/(0,3) und
  seitlich bis Großonkel (3,1), Großnichte (1,3), Großcousin*e (3,3). Darüber hinaus
  `distant_ancestor` („entfernte*r Vorfahr*in"), `distant_descendant`, sonst `distant_relative`
  („entfernte*r Verwandte*r"). Der Stammbaum zeigt die genaue Struktur trotzdem grafisch.
- **Cache:** `Relatives_Kinship::recompute_for_person()` nach jedem Speichern (alle Besitzer*innen der
  Person), `recompute_owner()` nach Löschen/Link-Bestätigung, `recompute_all()` per ACP-Knopf
  „Verwandtschaften neu berechnen" (Tab Verwandtschaftsgrade). **Nach dem Update auf v2.1 einmal
  ausführen.**
- **Neue Konzepte** in `COMMON_DEFAULTS` (ACP-Knopf „Standard-Grade anlegen" legt fehlende an):
  `great_aunt_uncle`, `great_niece_nephew`, `second_cousin`, `foster_parent`, `foster_child`,
  `distant_ancestor`, `distant_descendant`, `distant_relative`. Die `distant_*`-Grade sind im
  Formular nicht wählbar.
- **Linienarten:** aus `line_style` des Grades. Trennung = Grad von `spouse` auf `ex_spouse`
  umstellen, die Linie wird gestrichelt. Geheime Kanten blass (`is-secret`), Zweit-/Adoptivfamilien
  in eigener Farbe (`family_secondary`). Jede Verbindung ist ein eigener `<path>` mit `data-keys`;
  Überfahren eines Kastens hebt genau seine Verbindungen hervor.
- **Adoption im Baum herleitbar:** `adoptive_sibling` ohne eigene Eltern im Baum wird in
  `build_structure()` zum leiblichen Kind der Adoptiveltern des anderen (bzw. umgekehrt zum
  Adoptivkind von dessen leiblichen Eltern) – keine losen Striche mehr. Zweitfamilie mit denselben
  Eltern hängt im Frontend am Balken der Primärfamilie, nur ihr eigenes Stück in `family_secondary`.
  Layout: Zweit-Eltern ohne Herkunftsfamilie rücken neben die Primäreltern
  (`place_beside_coparents()`), das Kind an den zugewandten Rand seines Geschwisterblocks
  (`align_secondary_children()`), Angeheiratete stehen außen neben ihrer Partner*in
  (`orient_unit()`).
- **Klartext-Verbindungen:** `TreeBuilder::attach_node_relations()` hängt an jeden Knoten
  `relations[]` inkl. Gegenrichtung (`Kinship::inverse_concept()`): „Bruder von Delia, Jonathan ·
  Ehemann von Ava · Vater von Ethan" im Panel, Einträge springen zum Knoten.
- **Familien-Kästen:** Knotentyp `family` (Farbbalken, eine Generation über dem höchsten Kind).
  Übersicht `relatives.php?action=families`, anlegen `family_add` (gleichnamige wird
  wiederverwendet), bearbeiten/löschen `family_edit`/`family_delete` nur durch die anlegende Person
  oder das Team (`relatives_can_manage_family()`).
- **„Freut sich über neue Mitglieder“** (`relatives_families.welcomes_new_members`, Checkbox im
  Familienformular, Standard an): Ist es aus, nimmt die Familie keine weiteren **gespielten**
  Charaktere auf – neue Spieler*innen können nur noch NPCs übernehmen, die bereits im Stammbaum
  stehen (Verknüpfung/`account_person_id()` erzeugt keine neuen Kanten). Prüfung beim Speichern
  von Verbindungen (`relatives.php` add/edit): `Relatives_Graph::closed_family_violations()`
  rechnet die volle Mitgliedschaft (`memberships_from()`) mit den neuen Kanten durch und meldet
  jeden gespielten Charakter, der dadurch neu Mitglied würde – direkt am Kasten, als Kind,
  Geschwister oder Partner*in eines Mitglieds, auch über eine neu angelegte NPC („Delia ist meine
  Mutter“). NPCs dürfen weiterhin ergänzt werden. Ausgenommen: wer die Familie verwalten darf
  (`relatives_can_manage_family()`). Übersicht zeigt das Badge „Keine neuen Mitglieder“.
- **Familien-Attribute:** ACP → Attribut-Gruppen, „Gilt für: Familien“ (oder „Personen und Familien“ – dann in beiden Formularen, immer Vorauswahl), Feldtyp Vorauswahl
  (Dropdown, eine Option) oder Freitext. Stehen im Familienformular unter „Optional“ (nur wenn es
  aktive Familien-Gruppen gibt) und auf der Familienkarte der Übersicht. Wird eine gleichnamige
  Familie wiederverwendet, bleiben ihre Attribute unangetastet.
- **Öffentliche Familienliste** (`relatives.php?action=family_list`, reines Ansehen): gleiche Karten
  und Filter wie die UserCP-Seite (`relatives_render_family_overview($action, $read_only)`), aber
  ohne Bearbeiten/Löschen; gespielte Charaktere als formatierter Profil-Link. Gäste nur mit Setting
  `relatives_family_list_guests` (+ „Forum ansehen“). Optional im Rahmen der forumseigenen Listen:
  Settings `relatives_family_list_layout_template` (z. B. `listen`) und
  `relatives_family_list_nav_template` (z. B. `listen_nav`); der Rahmen bekommt `$listen_content`/
  `$relatives_family_list_content`, `$listen_nav`/`$relatives_list_nav`, `$headline`. Ohne Setting:
  Plugin-Template `relatives_family_list`, Inhalt in `relatives_family_list_content`.
- **Filter der Familienübersicht** (`action=families`, GET, verlinkbar): `welcomes=1|0` (neue
  Mitglieder erlaubt/nicht erlaubt) und `attr[<group_id>]=<option_id>` je Familien-Attributgruppe
  mit Vorauswahl (Freitextgruppen nicht filterbar), UND-verknüpft; Werte werden gegen die aktiven
  Optionen der Gruppe geprüft (`Relatives_Attributes::get_family_option_ids_bulk()`).
- **Verstorben:** Kreuz-Badge + gedämpfter Kasten im Baum, `†` in der Liste, Todesjahr als Zusatz.
- **Tests ohne MyBB:** `php tests/cli-kinship.php` prüft Graphaufbau, Koordinaten und Begrenzung.

## Mehrere Besitzer*innen & gleichnamige Personen
- Eine Person kann mehreren Charakteren gehören (wie im Altplugin, z.B. eine Mutter bei allen
  Geschwister-Charakteren). Stammdaten (`relatives_persons`) sind geteilt; Verwandtschaftsgrad,
  Bezugsperson und Beziehungstext liegen pro Charakter in `relatives_person_owners`.
- **Gleichnamige Person beim Hinzufügen:** Existiert bereits eine Person mit exakt diesem Namen
  (Groß-/Kleinschreibung und äußere Leerzeichen egal, Akzente zählen –
  `Relatives_Database::find_person_by_name()`), wird **kein** neuer Eintrag angelegt, sondern
  der Account als weitere*r Besitzer*in eingetragen. Bereits hinterlegte Stammdaten werden
  nicht überschrieben, nur leere Felder ergänzt; für die Grad-Validierung zählt das
  hinterlegte Geschlecht der Person (außer „egal"). So können auch Personen anderer
  Nutzer*innen im eigenen Stammbaum stehen. Bei mehreren Treffern gewinnt die älteste Person.
- **Bearbeiten:** jede*r Besitzer*in darf die Stammdaten ändern (Hinweis im Formular, wenn die
  Person geteilt ist). **Löschen** entfernt nur die eigene Zuordnung
  (`Relatives_Database::remove_owner()`); die Person selbst wird erst gelöscht, wenn niemand
  sie mehr in der Liste hat.
- Im Stammbaum trägt jeder Personen-Knoten `owner_uid` = der Account, über dessen Baum er
  aufgebaut wurde; bearbeiten lässt er sich nur dort (sonst würde man den Grad einer anderen
  Person überschreiben). `owner_count` steuert den Hinweis auf geteilte Personen.

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
| `relatives_tree_link_profile` | yesno (default 1) | Stammbaum-Link im Profil – Variable `{$relatives_tree_link}` in `member_profile` |
| `relatives_tree_link_postbit` | yesno (default 0) | Stammbaum-Link im Postbit – Variable `{$post['relatives_tree_link']}` in `postbit`/`postbit_classic` |
| `relatives_tree_link_memberlist` | yesno (default 0) | Stammbaum-Link in der Mitgliederliste – Variable `{$user['relatives_tree_link']}` in `memberlist_user` |
| `relatives_inplay_year` | text (default leer) | Aktuelles Inplay-Jahr; gesetzt = Geburtsjahr statt Alter im Formular, Alter wird berechnet |
| `relatives_family_list_guests` | yesno (default 1) | Öffentliche Familienliste (`action=family_list`) auch für Gäst*innen; „Forum ansehen" gilt zusätzlich |
| `relatives_family_list_layout_template` | text (default leer) | Optionales Rahmen-Template für die Familienliste (z. B. `listen`) |
| `relatives_family_list_nav_template` | text (default leer) | Optionales Navigations-Template (z. B. `listen_nav`), nur zusammen mit dem Rahmen-Template |
| `relatives_legacy_migrated` | text (intern) | Zeitstempel der letzten Legacy-Migration, vom Migrations-Tool gesetzt |
| `relatives_graph_merged` | text (intern) | Zeitstempel der Geflecht-Zusammenführung; bis dahin sperrt `relatives.php` das Frontend |

Settings werden in `install()` angelegt und in `uninstall()` entfernt; `activate()` rüstet nur
fehlende nach (`debugging.md` #21).

## Hooks

| Hook | Handler | Zweck |
|---|---|---|
| `member_profile_end` | `relatives_handle_member_profile` | Rendert „Gesucht"-Mini-Karten (`is_wanted=1`) aller Personen im Stammbaum-Ausschnitt des Charakters (`Relatives_TreeBuilder::wanted_nodes()`, Fallback vor der Zusammenführung: eigene Liste) in `{$relatives_wanted_box}` (beim Aktivieren automatisch in `member_profile` vor `{$signature}` eingefügt) und setzt `$relatives_tree_link` (Setting `relatives_tree_link_profile`); **responsives CSS statt der alten hartcodierten Theme-tid-Breiten-Sonderfälle** |
| `postbit`, `postbit_prev` | `relatives_handle_postbit` | Setzt `$post['relatives_tree_link']` (Setting `relatives_tree_link_postbit`) |
| `memberlist_user` | `relatives_handle_memberlist_user` | Setzt `$user['relatives_tree_link']` (Setting `relatives_tree_link_memberlist`) |
| `usercp_menu` | `relatives_handle_usercp_menu` | Eigener, sauberer UserCP-Menüpunkt „Verwandtschaften" (ersetzt die alte zusammengewürfelte 3-Link-Nav) |
| `admin_tools_permissions` | `relatives_admin_permissions` | Registriert `tools → relatives` als Admin-Recht (sonst hätten alle Admins Zugriff, ohne Einschränkungsmöglichkeit) |
| `admin_config_settings_begin` | `relatives_admin_settings_lang` | Lädt die Admin-Sprachdatei, damit MyBB die Setting-Titel über `setting_<name>`/`_desc`/`setting_group_relatives` übersetzt; die deutschen Texte in der DB sind nur Fallback |
| `global_start` / `xmlhttp` | `relatives_register_alert_formatter` | Registriert den MyAlerts-Formatter (nur wenn MyAlerts installiert ist) |
| `datahandler_user_delete_start` | `relatives_handle_user_delete` | Lässt offene Löschanträge des Users platzen und streicht seine offenen Antworten (kann fremde Anträge abschließen); entfernt den gelöschten User als Besitzer*in aller seiner Personen (Personen ohne weitere Besitzer*innen werden gelöscht); setzt bei allen Zeilen, die diesen User als `linked_uid` referenzieren, `linked_uid`/`link_status` zurück |

## Eigene Hooks / API (andere Plugins docken hier an)

**Bereitgestellte Listener** (nur wirksam, wenn Jobliste installiert ist – reines
Provider-Hook-Muster, kein Code-Pfad in Relatives hängt davon ab):
```php
$plugins->add_hook('jobliste_collect_entities', 'relatives_provide_jobliste_entities');
$plugins->add_hook('jobliste_resolve_entity_label', 'relatives_resolve_jobliste_entity_label');
```
Vertrag (siehe `jobliste`-Repo `PROJECT_CONTEXT.md`): `relatives_provide_jobliste_entities()`
hängt an `$args['entities']` Zeilen `['entity_type' => 'relative', 'entity_id' => person_id,
'label' => sanitized name]` für alle Personen in der Liste von `$args['uid']` an (geteilte Personen erscheinen bei allen Besitzer*innen).
Kaputte/fremde Aufrufer werden ignoriert, Escaping bleibt bei Jobliste.

**Eigene Provider-Hooks (seit Team-Feedback Oktober 2026):**
- `relatives_collect_person_details` – `$args = ['person_ids' => int[], 'linked_uids' => [person_id => uid], 'details' => []]`
  (`linked_uids` = bestätigt verknüpfte Accounts gespielter Charaktere); ein
  Listener setzt `$args['details'][person_id]['job']` (reiner Text). Relatives verwirft unbekannte
  Personen/Keys, kürzt auf 255 Zeichen und escapet beim Rendern (`Relatives_Database::person_details()`).
- `relatives_profile_wanted_cards` – `$args = ['uid' => int, 'cards' => '', 'count' => 0]`; ein
  Listener hängt fertig escaptes Karten-HTML an `cards` an und erhöht `count`.

**Öffentliche Adapter-API** (für `anschluss suchmaschine`, statt direktem Tabellenzugriff –
Muster aus `conventions.md` §7 „Fremde Tabellen nur über einen Adapter"):
```php
relatives_search_persons(array $filters): array
```
Filter-Keys (alle optional): `gender`, `relationship_type_id`, `attribute_option_ids` (array),
`is_wanted` (bool, Default true für den Suchmaschinen-Use-Case), `age_min`, `age_max`, `query`
(Textsuche Name/Description). Rückgabe: eine Zeile je Person (`id, name, gender, age, origin, avatar, description, is_wanted,
wanted_thread_link`) plus `owners` = Liste `['owner_uid', 'relationship_type_id',
'relationship_custom_label']`; `relationship_type_id` trifft, sobald eine Zuordnung diesen Grad
hat. Sanitisiert, aber **nicht** HTML-escaped (Escaping macht der Konsument beim Rendern). Diese Funktion ist die einzige erlaubte
Zugriffsart auf `relatives_*`-Daten für Fremdplugins – zusammen mit der folgenden Funktion (v2.1):

```php
relatives_get_relation(int $person_id, int $uid): array
// ['concept_key', 'label', 'category', 'up', 'down', 'owner_name', 'text']
// text = "Onkel von Megan Fawley" (Sprach-Key relatives_relation_of), leer wenn unbekannt
```
Liest den Cache aus `relatives_person_owners.computed_*` (rechnet einmalig nach, wenn er leer ist).
`category` ∈ `exact`, `distant_ancestor`, `distant_descendant`, `distant_relative`, `custom`, `unknown`.
Der Provider-Hook `relatives_provide_jobliste_entities()` liefert zusätzlich `relation_label`,
`relatives_resolve_jobliste_entity_label()` füllt – wenn Jobliste es anfragt – `relations[]` mit
`['uid', 'relation_label']` je Besitzer*in (Info-Icon in der Jobliste-Gesamtübersicht), die
Such-API in `owners[]` zusätzlich `computed_label`, `computed_concept_key`, `computed_category`.

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
- Alle Aktionen erfordern Login (`$mybb->user['uid'] > 0`). Ausnahmen (nur lesend, Gruppenrecht
  „Forum ansehen" gilt weiter): Stammbaum-Popup `action=tree&popup=1` und – per Setting
  `relatives_family_list_guests` – die öffentliche Familienliste `action=family_list`.
- Schreibende Aktionen nur per POST + `verify_post_check()` (`relatives_verify_post()` bzw.
  `request_method`-Prüfung im Formular-Handler).
- Personen bearbeiten: `Relatives_Graph::can_edit_person()` (siehe „Gemeinsames Geflecht");
  aus der eigenen Liste entfernen: nur Besitzer*innen (`Relatives_Database::is_owner()`, fixt den
  Alt-IDOR-Bug, bei dem jede eingeloggte Person fremde Einträge per ID bearbeiten/löschen konnte).
- Familien-Kästen ändern/löschen: anlegende Person oder Team (`relatives_can_manage_family()`).
- Stammbaum ansehen: alle eingeloggten Nutzer*innen, für jeden `uid` (öffentlich, siehe oben).
- Verwandtschaftsgrade/Attribut-Gruppen/Migrations-Tool: nur über ACP (Admin-Rechte).
- Link-Bestätigung: nur der per `linked_uid` referenzierte Account selbst darf annehmen/ablehnen.
- Verbindungsanfragen: bestätigen/ablehnen nur die angefragte Spieler*in (`target_uid` bzw. ein
  Account derselben Spieler*in), zurückziehen nur die anfragende. Einzelne Verbindung trennen oder
  ändern: wer eine der beiden Personen bearbeiten darf (`Relatives_Graph::can_edit_relation()`).

## Migration (Legacy → Neu)
Quellen (`table_exists()`-Guard vor Zugriff): `mybb_relatives`, `mybb_wanted` (Personen),
`mybb_relatives_status`, `mybb_wanted_status` (Zuordnung Person ↔ Besitzer*in: `owner_uid,
status, beziehung`), `mybb_relatives_options` (Grade + Attribut-Werte). Re-Run-Schutz über
Setting `relatives_legacy_migrated` (erneuter Lauf nur mit ACP-Bestätigung) sowie `legacy_id`
auf Personen- und Zuordnungsebene. Der ACP-Tab zeigt vor dem Lauf eine **Vorschau**
(`Relatives_Migration::preview()`, ohne Schreibzugriff): Zuordnung jedes alten Grades mit
Nutzungszahl und die Kennzahlen übernehmbarer/übersprungener Personen und Zuordnungen.
Wiederholbare Testläufe im Testforum: `tests/cli-migration.php` (reset → run → verify, schreibende
Befehle nur mit `$config['relatives_allow_migration_reset']` + `--yes`).

1. **Grade:** `relatives_options` (`option_name='status'`) → bestehende Standard-Grade über
   `Relatives_Migration::STATUS_BASE_MAP` (Grundbegriff → `concept_key` + Label-Geschlecht).
   Zusätze „mütterlicherseits"/„väterlicherseits" und „(Stief-, Adoptiv-, angeheiratet,...)"
   werden vorher entfernt – diese Details gehen bewusst verloren (bestätigte
   User-Entscheidung). **Ausnahme „(entfernt)"** (seit 2026-09-24, User-Entscheidung): solche
   Grade werden zu `distant_relative` („entfernte*r Verwandte*r", Geschlecht aus dem
   Grundbegriff) statt zum Grundbegriff – „Cousine (entfernt)" war im Altplugin keine echte
   Cousine, sondern weitläufige Verwandtschaft ohne bekannten Weg. Es gibt **keinen Freitext-Fallback**: ist ein
   genutzter alter Grad nicht zugeordnet, bricht die Migration vor dem ersten Schreibzugriff ab.
   Fehlende Konzepte wurden stattdessen in `RelationshipTypes::COMMON_DEFAULTS` ergänzt
   (`half_sibling`, `adoptive_sibling`, `sibling_in_law`, `parent_in_law`, `child_in_law`,
   `fiance`, `great_grandparent`, `great_grandchild`); die Migration legt fehlende
   Standard-Grade per `create_common_defaults()` selbst an. „NPC" → ohne Grad.
2. **Attribute:** `relatives_options` (Gruppen `haarfarbe`, `augenfarbe`, `ethnik`, `blutstatus`)
   → `relatives_attribute_groups` + `relatives_attribute_options`; die gleichnamigen Spalten der
   Altpersonen (Options-ids, ≤ 0 = Platzhalter keine Angabe/egal/Sonstiges) →
   `relatives_person_attributes`.
3. **Personen:** jede Altperson mit mindestens einer gültigen Zuordnung wird genau **eine**
   `relatives_persons`-Zeile (`legacy_id` bleibt eindeutig, `legacy_source` = `'relatives'` bzw.
   `'wanted'` – Pflicht für die Jobliste-Migration). `name, geschlecht → gender, age` (0 → NULL),
   `herkunft → origin` (Platzhalter 0/-1/-2 → NULL), `avatar` (Avatarperson),
   `gesuchstext → description`, `gesucht → is_wanted`, `link → wanted_thread_link`.
   **`beruf` wird NICHT migriert** (Aufgabe der Jobliste-Migration). Personen ohne
   existierenden Besitzer*innen-Account werden nicht übernommen (bleiben im Backup).
4. **Zuordnungen:** jede gültige `*_status`-Zeile → `relatives_person_owners`
   (`beziehung → description`, `legacy_id` = alte Status-id). `relatives_status.status` → Grad aus
   Schritt 1; `wanted_status.status` (Freitext) → `relationship_custom_label`. Übersprungen:
   Zuordnungen zu gelöschten Accounts oder nicht mehr existierenden Personen sowie doppelte
   (Person, Besitzer*in)-Paare (älteste gewinnt). Der Grad landet in einer **eigenen
   Listen-Kante je Zuordnung** (`Relatives_Database::add_list_relation()`: `owner_uid` gesetzt,
   ohne Bezugsperson = relativ zum Charakter) – **nicht** über `add_owner()`, dessen
   Primärkante je Person nur einmal existiert: Dieselbe Altperson stand oft in mehreren Listen
   (Caiomhe Sayre: bei Kayley Mutter, bei Ciaran Tante), jede weitere Liste hätte die Aussage
   der vorigen überschrieben (Befund Testforum: 126 verlorene Aussagen). Erst
   `Relatives_Graph::merge()` macht daraus das gemeinsame Geflecht.
5. **Struktur ableiten** (`Relatives_StructureInference::apply_to_migrated_data()`): Das
   Altplugin speicherte alles nur relativ zum Charakter. Für Zuordnungen ohne Bezugsperson wird
   eine gesucht und der Grad relativ zu ihr umgeschrieben, sodass die Komposition wieder das
   alte Konzept ergibt („Onkel mütterlicherseits" → „Bruder" von Mutter; „Tante (angeheiratet)"
   → „Ehefrau" eines Onkels; „Cousine" → „Tochter" eines Onkels). Belege in dieser Rangfolge:
   (1) Fakten aus den Listen **aller** Alt-Charaktere, auch gelöschter (Mutter + Vater einer
   Liste = Paar, Mutter + Bruder = Elternteil/Kind, Geschwister untereinander; ein Account mit
   dem Namen einer Person gilt als diese Person), (2) Seite und „angeheiratet" aus dem alten
   Label, „(entfernt)" wird nie zugeordnet, (3) Eindeutigkeit, (4) Nachname als Tie-Breaker.
   Eine Entscheidung pro Runde, schwache Belege erst, wenn keine mögliche Bezugsperson mehr
   offen ist. Nicht Eindeutiges bleibt lose verbunden. Nur Zuordnungen ohne Bezugsperson werden
   angefasst (idempotent).
6. Abschlussbericht (im ACP nach dem Lauf, nicht persistiert) mit allen Zählern plus Liste der
   `concept_key`s ohne neutrale (`gender=2`) Variante.

## What NOT to change
- `relatives_person_owners` nicht wieder in `relatives_persons` zurückfalten (eine Spalte
  `owner_uid`) – eine Person gehört bewusst mehreren Charakteren (bestätigte User-Anforderung),
  Grad/Bezugsperson/Beziehungstext sind pro Charakter verschieden.
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
- `relatives_relations` nicht wieder in `relatives_person_owners` zurückfalten (eine Spalte
  `related_to_person_id`) – Mehrfach-Abstammung (leibliche + Adoptiveltern) braucht mehrere Kanten
  je Person (bestätigte User-Anforderung).
- Keinen zweiten Status wie „getrennt/geschieden" neben dem Grad einführen – die Linienart hängt
  bewusst am Grad (`ex_spouse`), damit es nur eine Stelle gibt (bestätigte User-Entscheidung).
- Die Verwandtschaft zu dir nicht wieder über `COMPOSE`-Ketten berechnen – nur die
  Koordinaten-Rechnung in `Relatives_Kinship` kennt Entfernung und Mehrfach-Abstammung.
- Familien-Kästen nicht pro Charakter duplizieren – sie sind forumsweit geteilt (bestätigte
  User-Entscheidung, Grundlage der Familien-Übersichtsseite).
- Verbindungen nicht wieder pro Charakter speichern (`owner_uid` auswerten) – das Geflecht gehört
  niemandem, jede Person und jede Aussage existiert genau einmal (bestätigte User-Anforderung v2.2).
- Abgeleitete Geschwister-/Elternverbindungen nicht als echte Kanten speichern – sie werden bei
  jeder Anzeige aus dem Geflecht erkannt (bestätigte User-Entscheidung v2.2).
- Gespielte Charaktere nicht als separaten `self`-Sonderfall neben dem Geflecht führen – sie sind
  Personen-Knoten mit bestätigtem `linked_uid`.
- Offene Verbindungsanfragen nicht als Status-Spalte in `relatives_relations` führen – alle Leser
  der Kantentabelle (Kinship, TreeBuilder, Graph) sollen nur gültige Kanten sehen (bestätigte
  User-Entscheidung Oktober 2026).
- Freie Bezeichnungen (Freund*innen …) nicht wieder in den Stammbaum zeichnen und „Geheim" nicht zu
  einem Ausblenden umbauen (bestätigte User-Entscheidungen Oktober 2026).
