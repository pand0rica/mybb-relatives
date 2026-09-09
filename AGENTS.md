# Verwandtschaften & Stammbaum – Agent Instructions

Dieses Repo wird mit dem **MyBB Plugin Dev Kit** entwickelt. Bevor du hier etwas tust:

1. Lies zuerst das Dev-Kit: **`D:\mybb-plugin-dev-kit\AGENTS.md`** und halte dich an alle dort
   verlinkten Standards. Das Kit ist die kanonische Quelle für Konventionen, Boilerplate,
   Debugging und Testing.
2. Lies dann in diesem Repo: `PROJECT_CONTEXT.md` (Kontext) und – in der Umsetzungsphase –
   `IMPLEMENTATION_PLAN.md` (die Arbeitsschritte).

Das Dev-Kit wird **nicht** in dieses Repo kopiert; es wird per absolutem Pfad referenziert.

## Sprache
Antworten Deutsch, Code/Bezeichner Englisch. PHP 7.4 + 8.x. Keine externen Libraries.

## Schwester-Plugins
Dieses Plugin ist Teil einer 3-Plugin-Familie (Rework von zwei Altplugins):
- `F:\RPG\Mybb Plugins - eigene\jobliste` – optionaler Konsument dieses Plugins (Provider-Hooks).
- `F:\RPG\Mybb Plugins - eigene\anschluss suchmaschine` – harter Konsument dieses Plugins
  (nutzt die öffentliche API `relatives_search_persons()`).

Dieses Plugin (relatives) muss **zuerst** umgesetzt und migriert werden, bevor die beiden
anderen sinnvoll entwickelt/migriert werden können (siehe „Abhängigkeiten" in
`PROJECT_CONTEXT.md`).

## Phasen (der Nutzer löst sie einzeln aus)

| Nutzer sagt | Phase | Folge dieser Kit-Datei |
|---|---|---|
| „umsetzen / implementieren" | Umsetzung | `D:\mybb-plugin-dev-kit\workflows\2-implement.md` + `IMPLEMENTATION_PLAN.md` |
| „reviewe das Projekt" | Review | `D:\mybb-plugin-dev-kit\workflows\3-review.md` |
| „release das Projekt" | Release | `D:\mybb-plugin-dev-kit\workflows\4-release.md` |

## Die 5 goldenen Regeln (Kurzform, Details im Kit)
Keine Core-Edits · `TABLE_PREFIX` · `$mybb->get_input()` · POST + `verify_post_check()` ·
alles mit Präfix `relatives`. Ausgabe `htmlspecialchars_uni()`, SQL `escape_string()`/`(int)`.
