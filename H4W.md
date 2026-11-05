# h4w-Anpassungen

Dieser Branch ist `main` **plus** die Anpassungen für das h4w-Forum. Regeln: Dev-Kit
`standards/conventions.md` §12 – Neuerungen zuerst auf `main`, dann `main` → `h4w` mergen,
nie umgekehrt. Deploy ins h4w-Test-/Live-Forum von diesem Branch.

## Anpassungen

| Bereich | Dateien | Zweck |
|---|---|---|
| CSS-Variablen des h4w-Themes | `inc/plugins/relatives/assets/relatives-tree.css`, `inc/plugins/relatives/assets/relatives.css` | Farben/Größen als `var(--h4w-…, Fallback)` (73 Stellen), damit das h4w-Theme sie zentral steuert. Auf `main` stehen die Fallback-Werte direkt. |
| UserCP-Menü in der RPG-Kategorie | `inc/plugins/relatives/core/Installer.php` | Der UserCP-Menüpunkt „Verwandtschaften & Stammbaum“ steht in der Kategorie **RPG** (Template `usercp_nav_rpg_chara`, Gruppen-Templates). `install_usercp_nav_misc_link()` hängt ihn nicht mehr in `usercp_nav_misc` („Sonstiges“), sondern entfernt nur Zeilen älterer Versionen. Link: `relatives.php?action=list`, Label `{$lang->relatives_usercp_menu_item}`. |
