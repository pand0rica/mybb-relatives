# h4w-Anpassungen

Dieser Branch ist `main` **plus** die Anpassungen für das h4w-Forum. Regeln: Dev-Kit
`standards/conventions.md` §12 – Neuerungen zuerst auf `main`, dann `main` → `h4w` mergen,
nie umgekehrt. Deploy ins h4w-Test-/Live-Forum von diesem Branch.

## Anpassungen

| Bereich | Dateien | Zweck |
|---|---|---|
| CSS-Variablen des h4w-Themes | `inc/plugins/relatives/assets/relatives-tree.css`, `inc/plugins/relatives/assets/relatives.css` | Farben/Größen als `var(--h4w-…, Fallback)` (73 Stellen), damit das h4w-Theme sie zentral steuert. Auf `main` stehen die Fallback-Werte direkt. |
