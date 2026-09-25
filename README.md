# Relatives & Family Tree

A MyBB 1.8 plugin for character-based roleplay forums. Players record the relatives and other
close persons of their character – with gender, age, origin, face claim, description and
custom attributes – mark open roles as *wanted* (with a link to the wanted thread) and explore
everything in an interactive, clickable family tree. The tree is one shared network for the
whole forum: every person and every statement ("Delia is the mother of Louisa") exists exactly
once, and confirmed account links let visitors click from one character's tree into the next.

Built for RPG forums, where family backgrounds are shared between many players and a character
sheet alone can't show who belongs to whom.

## Requirements

- MyBB 1.8.x
- PHP 7.4 or 8.x
- MySQL 5.7+ / MariaDB 10.2+ (InnoDB)
- **No dependencies.** Optional integrations: the *jobliste* plugin (job list) picks up persons
  through provider hooks, and *anschluss suchmaschine* (connection search) queries wanted roles
  through the public API. Both are separate plugins and not required.

## Installation

1. Upload the contents of this repository into your MyBB root directory (the folders `admin/`
   and `inc/` merge with the existing ones, `relatives.php` goes next to `index.php`).
2. **ACP → Configuration → Plugins** → *Verwandtschaften & Stammbaum* → **Install & Activate**.
3. **ACP → Configuration → Settings → Verwandtschaften & Stammbaum** → review the settings (see
   [Configuration](#configuration)).
4. **ACP → Tools & Maintenance → Relatives & Family Tree → Relationship types** → click
   **Create default relationship types**. The plugin ships without any relationship types, so
   the add-person form stays empty until you do this (or create your own types).
5. Optional – show the tree link and wanted roles in the profile: **ACP → Templates & Style →
   Templates → *your template set* → Member Templates → `member_profile`** and insert
   `{$relatives_wanted_box}` and `{$relatives_tree_link}` where they should appear.

Upgrading from the legacy *relatives*/*wanted* plugin (v1.x)? Follow
[Migrating from the legacy plugin](#migrating-from-the-legacy-plugin) before your users start
adding persons.

## Usage

### First steps after activation

1. Create the default relationship types (Installation step 4) and adjust labels if needed.
2. Optionally create attribute groups (hair colour, eye colour, blood status …) under
   **ACP → Tools & Maintenance → Relatives & Family Tree → Attribute groups**.
3. Decide where the "origin" of a person comes from (free text or an existing profile field)
   under **… → Origin**.
4. Tell your players where to find it: **User CP → Miscellaneous → Relatives & Family Tree**.

### For players: managing relatives

Open **User CP → Miscellaneous → Relatives & Family Tree**. The page has three tabs: *My
relatives*, *Families* and *View family tree*; the icon on the right adds a person (or family),
the pop-out icon opens your tree in its own window.

**Add a person:** click the add icon on *My relatives*.

- **Name** – if a person with exactly this name already exists in the network, no duplicate is
  created; you are connected to the existing person instead and only empty fields are filled.
- **Gender** – choose it first: the relationship dropdown then only offers the fitting labels
  (e.g. *mother* for female, *parent* for diverse/unspecified).
- **Connections** – one or more statements "this person is *&lt;relationship&gt;* of
  *&lt;person or family&gt;*". The anchor is your own character by default, but can be any person
  in your tree (e.g. *brother* of *your mother* = your uncle) or a family box. Each connection
  can be marked **secret** (drawn faintly) and one of them is the **Main connection** that
  positions the person in the tree. Choose *Other connection* and type a free label for friends,
  rivals, lovers etc.
- **Age, Origin, Face claim, Description of the person, Your relationship** – optional details;
  *Your relationship* is personal to your character, the rest is shared with everyone who has
  this person in their list.
- **Deceased** / **Year or date of death** – shows a cross and a dimmed box in the tree.
- **Mark as wanted** / **Link to the wanted thread** – the person appears as a wanted card on
  your profile.
- **Attributes** – the attribute groups configured in the ACP.

![Add person form](docs/screenshots/person-form.png)

**Edit / remove:** use the links in your list. *Remove* only takes the person off **your**
list; the person disappears from the network once nobody lists them, they are not a played
character and they have no connections left.

**Who may edit a person?** Everyone who has the person in their list, everyone whose character
belongs to the same family, the account of a played character (for its own node) and the team
(moderators/admins). Everybody else sees the person but can take them over via **Add to my
list** in the tree.

### The family tree

**User CP → Relatives & Family Tree → View family tree** (or the *Family tree* link in a
profile/postbit/member list).

- Drag to pan; zoom with **Ctrl/⌘ + mouse wheel** or pinch (in the popup the plain wheel zooms).
- Click a box to see all its connections in plain words ("Brother of Delia · Husband of Ava");
  entries jump to the respective box. Hovering a box highlights exactly its lines.
- The box menu adds a relative *of this person*, edits the person or takes them into your list.
- Boxes with **…** have more relatives behind them – click to unfold that branch.
- Played characters (confirmed account links) are clickable and open their own tree.
- Uncertain intermediate persons are shown as grey **placeholders** ("Parent of Jolina") instead
  of a misleading direct line, e.g. for aunts, cousins or in-laws whose parents nobody has
  entered yet. Placeholders are display only and vanish once the real person is added.
- Line styles come from the relationship type (e.g. dashed for ex-spouses); secret connections
  are faint, adoptive/second families get their own colour.

![Family tree](docs/screenshots/tree.png)

**Popup & sharing:** the pop-out icon opens the tree without the forum layout. The popup URL can
be shared – it is read-only for everyone except the tree owner and is visible to guests too
(guests still need the usergroup permission *Can view board*). Note: the shared popup shows
secret connections as well.

### Linking a person to a real account

When a relative is played by another member, open the person's edit form, scroll to **Link
to an account**, enter the **Username to link this person with** and submit. The other member
receives a private message and sees the request on their *My relatives* page (with a link to
your tree) and can **accept** or **decline**. Only after acceptance is the person shown as a
played character, clickable into their tree, and the *wanted* flag is cleared automatically.

The PM is sent through MyBB's regular PM flow, so MyAlerts, Account Switcher and similar
plugins are notified as usual.

### Families

**User CP → Relatives & Family Tree → Families** lists all family boxes of the forum. **Add
family** asks for a **Name**, a **Colour**, a **Description**, **This family welcomes new
members** and any family attributes (ACP). A family box appears in the tree one generation above
its members.

- In a person's connections, pick the family as anchor: without a relationship it is a plain
  membership; with **Show family as parents** the box takes the place of the parents.
- Membership covers everyone attached to the box, their siblings, all descendants and their
  partners.
- If **This family welcomes new members** is off, no further *played* characters can join the
  family – new players can only take over NPCs already in the tree. The overview shows a
  *No new members* badge.
- Only the creator of a family and the team can edit or delete it.
- The overview can be filtered by "welcomes new members" and by family attributes; filter URLs
  can be shared.

![Families overview](docs/screenshots/families.png)

**Public family list:** `relatives.php?action=family_list` shows the same cards read-only, with
played characters as formatted profile links. It can be embedded into an existing list layout
(see settings *Family list: frame template* / *navigation template*).

### For administrators: ACP pages

All pages are under **ACP → Tools & Maintenance → Relatives & Family Tree**.

- **Relationship types** – gender-specific labels grouped by a *Concept key* (e.g. `parent` with
  *Father* / *Mother* / *Parent*). Fields: **Concept key**, **Gender** (male/female/diverse
  label), **Label**, **Generation offset** (-2 grandparents … +2 grandchildren), **Line style in
  the tree**, **Sort order**, **Active**. If a concept has no diverse variant, users who pick
  *diverse/unspecified* get a hint instead of that option. **Create default relationship types**
  adds all missing standard types; **Recalculate relations** rebuilds the cached "how is this
  person related to the character" for every tree.
- **Attribute groups** – groups and their options. **Applies to** persons, families or both;
  **Field type** *Preset* (dropdown) or *Free text* (families only); **Multiple values for
  persons** switches between one value and checkboxes.
- **Origin** – free-text origin or the options of an existing profile field (enter its **Profile
  field ID (fid)**).
- **Migration** – import from the legacy plugin (see below).
- **Shared network** – preview and merge of the per-character lists into the shared network,
  the list of contradicting statements (accept/discard) and a tool to take back guesses made by
  the legacy migration.

![ACP relationship types](docs/screenshots/acp-relationship-types.png)

### Migrating from the legacy plugin

The legacy tables (`relatives`, `relatives_status`, `wanted`, `wanted_status`,
`relatives_options`) are read, never modified. **Back up your database first.**

1. Install and activate this plugin. While legacy tables exist, the frontend stays locked
   ("not merged yet") so nobody works on half-migrated data.
2. **ACP → Tools & Maintenance → Relatives & Family Tree → Migration**: check the preview (every
   old relationship label and how it will be mapped, persons/assignments that will be taken over
   or skipped), then click **Run migration**. It stops before writing anything if a used legacy
   label can't be mapped.
3. **… → Shared network**: check the preview and click **Merge network now**. Persons named like
   an account become that character's node, identical names are merged, contradictions go to a
   conflict list you can resolve afterwards. After the merge the frontend is unlocked.
4. **… → Relationship types → Recalculate relations** (the merge does this once, run it again
   after bulk corrections).

A fresh installation **without** legacy tables is unlocked immediately.

### Tips & Tricks

- Anchor connections at the closest known person: "brother of my mother" is stored as such
  and the tree works out that he is your uncle – better than "uncle of me", which leaves open
  on which side he belongs.
- Siblings are derived: a new "brother of Oliver" is automatically a sibling of Oliver's
  siblings. Don't add these connections by hand.
- Separation: switch *Spouse* to *Ex-spouse* – the line becomes dashed.
- Relations beyond great-grandparents / second cousins are shown as *distant ancestor /
  descendant / relative*; the tree still draws the exact structure.
- Template variables: `{$relatives_tree_link}` and `{$relatives_wanted_box}` (template
  `member_profile`), `{$post['relatives_tree_link']}` (`postbit`, `postbit_classic`),
  `{$user['relatives_tree_link']}` (`memberlist_user`).

## Configuration

**ACP → Configuration → Settings → Verwandtschaften & Stammbaum** (the setting titles are in
German; the English names below are translations).

- **Plugin active** – master switch. When off, `relatives.php` shows a notice; the User CP menu
  item stays visible.
- **Maximum persons per user** – abuse protection, `0` = unlimited.
- **Origin source** – *Free text* or *Profile field* (see ACP → … → Origin).
- **Origin profile field (fid)** – profile field used as option list when the source is
  *Profile field*.
- **Family list for guests** – whether guests may open the public family list.
- **Family list: frame template** (optional) – name of an existing template the family list is
  embedded into (e.g. `listen`). It receives the content as `{$listen_content}` /
  `{$relatives_family_list_content}`, the navigation as `{$listen_nav}` / `{$relatives_list_nav}`
  and the title as `{$headline}`. Empty = the plugin's own page.
- **Family list: navigation template** (optional) – template with the list navigation (e.g.
  `listen_nav`), only used together with the frame template.
- **Family tree link in profile** / **in postbit** / **in member list** – show a *Family tree*
  link (opens the popup) in the respective place; requires the template variables above.
- **Last legacy migration** and **Shared network merged** – set automatically, don't edit.

## For developers

Other plugins must not read the `relatives_*` tables directly. Public contract:

- `relatives_search_persons(array $filters): array` – search persons. Filters (all optional):
  `gender`, `relationship_type_id`, `attribute_option_ids` (array), `is_wanted` (default
  `true`), `age_min`, `age_max`, `query` (name/description). Values are sanitized but **not**
  HTML-escaped – escape when rendering.
- `relatives_get_relation(int $person_id, int $uid): array` – how a person relates to a
  character: `concept_key`, `label`, `category`, `up`, `down`, `owner_name`, `text`
  (e.g. "Uncle of Megan").
- Provider hooks for *jobliste*: `jobliste_collect_entities`, `jobliste_resolve_entity_label`.

## Uninstallation

1. **ACP → Configuration → Plugins** → *Verwandtschaften & Stammbaum* → **Deactivate**. This removes
   the templates, the stylesheets from all themes and the User CP menu item; your data and
   settings are kept.
2. **Uninstall** – drops all `relatives_*` tables and removes the settings. This deletes all
   persons, connections and families irrevocably. Legacy tables of the old plugin are not
   touched.
3. Delete the uploaded files (`relatives.php`, `inc/plugins/relatives.php`,
   `inc/plugins/relatives/`, `admin/modules/tools/relatives.php` and the `relatives.lang.php`
   files in `inc/languages/*/` and `inc/languages/*/admin/`).
4. Remove the template variables you added manually (`member_profile`, `postbit`, …).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
