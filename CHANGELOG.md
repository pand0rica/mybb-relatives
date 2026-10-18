# Changelog

All notable changes to this plugin are documented here. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/).

## [2.2.0] – unreleased

Complete rewrite of the legacy *relatives* / *wanted* plugin (v1.0). First public release of the
new code base; 2.0 and 2.1 were internal development stages and are summarised here.

### Added
- One person table and one form for relatives and other connections (friends, rivals …) – replaces
  the two separate legacy tables `relatives` and `wanted`.
- Gender-specific relationship types managed in the ACP (concept key + male/female/diverse
  label, generation offset, line style); the relationship dropdown filters by the chosen gender.
  Button *Create default relationship types*.
- Attribute groups for persons and families (preset dropdown/checkboxes or free text).
- Interactive family tree without external libraries: pan/zoom, unfolding branches, per-box
  action menu, plain-text connection panel, gender colours, deceased marker, placeholders for
  missing intermediate persons, right-angle family-tree connectors.
- Shareable read-only popup view of a tree (also for guests) and optional *Family tree* links in
  profile, postbit and member list.
- Shared family network: every person and every statement exists once for the whole forum;
  sibling and parent connections are derived instead of stored; duplicate/contradicting
  statements are rejected.
- Several connections per person (biological and adoptive parents, partners …), secret
  connections, main connection, free-text connections.
- Computed relation to the character (`Relatives_Kinship`), limited to named degrees up to
  great-grandparents/second cousins, otherwise *distant relative*.
- Family boxes with colour, description (MyCode and HTML allowed), attributes, "welcomes new members" switch, overview
  with filters and a public read-only family list (embeddable into existing list templates).
- Account links with confirmation by the linked member (sent as a regular PM, so MyAlerts /
  Account Switcher react).
- ACP migration from the legacy plugin with preview, mapping of every legacy label, structure
  inference (e.g. "uncle on the mother's side" → brother of the mother) and re-run protection.
- ACP *Shared network* page: merge preview, merge, conflict list, taking back legacy guesses.
- Public API `relatives_search_persons()` and `relatives_get_relation()`, provider hooks for the
  *jobliste* plugin (`jobliste_resolve_entity_label` also returns who added a person and in which
  degree, and the confirmed linked account so jobliste can show the character instead of a
  duplicate relative).
- User CP menu item in the *Miscellaneous* box with its own icon; shared tab navigation on all
  plugin pages.
- Deletion requests for NPCs: deleting an NPC asks everyone else who has it in their list
  (optional MyAlerts notifications, open requests under *My relatives* with a counter in the
  navigation). Once all agree, the NPC is deleted completely and the requester is notified; one
  *Keep* cancels the request and removes the requester from the NPC's list. NPCs nobody else
  lists are deleted right away. ACP tab *Deletion requests* to decide stuck requests.
- Feedback after deleting in the family tree (status message instead of a silent reload).
- Connection requests: adding or changing a connection to a character another member plays
  creates a request instead of an edge. The player confirms or declines it (MyAlerts, box
  *Open connection requests* under *My relatives*, counter in the navigation); after confirming,
  the other person also appears in their list. Accounts of the same player (Account Switcher)
  need no confirmation.
- Connections can be removed from either side right away: **×** in the tree panel and the new
  section *Connections added by others* in the person form, which also changes the degree of a
  connection stored at the other person.
- Family page (`action=family`) with full description, attributes and members grouped into
  played characters, wanted and other members; **Add to this family** for your own character or
  persons from your list.
- Separate *Family* section in the person form (families are no longer hidden among the
  connection anchors).
- Setting *Current in-play year*: persons get a year of birth and the age is calculated from it
  everywhere (tree, list, profile, search API); ACP button to convert stored ages once. For
  deceased persons with a year of death the age at death is shown.
- Provider hooks `relatives_collect_person_details` (occupation, e.g. from *jobliste*, shown in
  tree panel, list and wanted cards) and `relatives_profile_wanted_cards` (extra wanted cards
  from other plugins).
- Person form hooks `relatives_person_form_fields`, `relatives_person_validate` and
  `relatives_person_saved` so other plugins (jobliste) can show and edit fields such as the
  occupation in the person form.

### Changed
- The profile wanted box lists every wanted person in the character's family tree (not only the
  character's own list), closest relatives first, as compact mini cards (initial,
  relation, age, collapsible description, wanted-thread link) with a counter. Responsive grid
  instead of hard-coded widths per theme; themable via `--relatives-wanted-color` and
  `--relatives-wanted-description-height`.
- `{$relatives_wanted_box}` is inserted on activation into `member_profile`, per template set before
  `{$signature}`, fallback `{$footer}`, only where it is still missing (manual placement is
  kept); removed on deactivation.
- Relationship types and attribute values are no longer seeded with forum-specific example data.
- Connections with a free label (friends, clique, rivals …) are no longer drawn in the family
  tree; they stay in the list. The profile wanted box therefore only shows wanted relatives.
- Adoptive and step siblings no longer carry family membership over (an adoptive brother does
  not make someone part of the other family); half siblings still do.
- The *Secret* option is now called *Secret in-play (faded line)* with an explanation – it only
  fades the line and never hides anything.
- Person form: optional details reordered (description, year of birth, deceased + date, origin,
  wanted + thread link, attributes, your relationship); the date of death and the thread link
  only appear when their checkbox is ticked – also in the tree quick-add form.
- The face claim (*Avatarperson*) is no longer entered or shown; stored values are kept.
- Family overview: two families per row, *X persons, Y of them played*, a short teaser instead
  of the full scrolling description and a link to the family page.
- All frontend actions (edit, delete, cancel, accept/decline, reset filter, empty-state buttons)
  are real theme buttons (`input.button`); mixed links, `a.button` and link-styled buttons with
  `|` separators are gone.
- On the family pages the popup icon in the navigation opens the public family list instead of
  the family tree.

### Fixed
- Editing a played character (e.g. by staff) now works from that character's point of view:
  related persons come from its own tree, there is no "to your character" option, and a hint
  points to the connections stored with the other person.
- Birth parents of an adopted child now stand side by side with a partner line in the tree; a
  person with three or more partners keeps the partners they share children with directly
  beside them, and a partner line that has to pass another box is drawn above it.
- Any logged-in user could edit or delete other users' entries by changing the ID in the URL
  (legacy IDOR). Every write action now checks ownership/edit rights.
- Tree data silently broke because the JSON inside `<script>` blocks was HTML-escaped.
- User CP menu item stayed invisible: `usercp_menu()` evaluates `usercp_nav_misc` in its own
  scope, so a hook-filled global was never visible – the row is now baked statically into the
  template, and `usercp.css` is attached to `relatives.php`.
- Settings fell back to their defaults on every deactivate/activate cycle – settings now belong to
  install/uninstall only.
- Fatal error in the ACP forms caused by non-existent Form API methods.
- Tooltip of the *Family tree* link showed a literal "%s" instead of the character name.
- The "relative to your character" option in the relationship form showed a literal
  "%s (your character)" instead of the character name (`$lang->sprintf()` expects `{1}`).
- Family tree lines crossed each other in many trees (156 crossings over the 46 trees of the
  test forum, now 4, all in trees with contradicting data):
  - family boxes and ancestors without parents in the tree (e.g. the *Nott* box of a
    character's mother) were always placed at the end of their row;
  - statements were resolved one at a time, so "half-brother of Jolina" plus "cousin of
    Louisa", two cousin statements or two mutual "aunt of" statements invented extra
    placeholder ancestors whose lines ran across the tree – they are now read together;
  - friends and other loose connections of the same row were placed after the whole sibling
    block, so their line crossed every sibling;
  - partners of a chain (A – B – C – D) could be separated by other boxes;
  - overlapping sibling bars were stacked left to right instead of in the order that avoids
    crossings.
  The layout now also moves and mirrors the families involved in a crossing and keeps the
  arrangement with the fewest crossings (deterministic, same tree always looks the same).
- An only child standing a few pixels beside its parents' stem got a small jog in its line
  (down, sideways, down), which looked like a drawing error (Sophie below the *Nott* box).
  The layout now shifts the parent or the child (with its neighbours if needed) exactly
  below the stem, and offsets of up to 32 px are drawn as one straight line.
- The User CP menu icon was tiled and overlapped the label in themes that do not style
  `usercp_nav_item`; deactivating now also removes rows baked by older versions.
- A fresh installation without legacy data was locked ("network not merged yet") until an admin
  ran a merge on empty tables.
- The ACP page is registered as an admin permission (*Tools & Maintenance → Can manage
  Relatives & Family Tree?*); before, every administrator had access and it could not be
  restricted.
- Setting titles and descriptions are translatable (English and German) instead of fixed German
  text.
- ACP pages and plugin templates no longer use inline `style`/`onclick` attributes (exceptions:
  the per-family colour variable and the popup handler of the *Family tree* link, which also
  appears on profile/postbit/member list pages without the plugin script); the ACP delete
  confirmation no longer breaks on language strings containing an apostrophe.
- Deleting a user account left all of its relatives data behind: the
  `datahandler_user_delete_start` handler read `$userhandler->user['uid']`, which
  `UserDataHandler::delete_user()` never sets, and always returned early. It now iterates over
  `$userhandler->delete_uids` (also covers bulk deletions in the ACP), removes each deleted user as
  owner of their persons, cancels their open deletion requests/votes and resets every
  `linked_uid` reference to them.
- Accepting a request that changes an existing connection (e.g. *mother* → *father*) marked the
  request as accepted but left the old degree in place: every form save deleted and re-inserted
  all connections of a person, so the remembered connection id was gone, and the fallback treated
  *mother* and *father* as the same statement. Saving now keeps the rows (and ids) of unchanged
  connections, and accepting updates the current connection to the same anchor – also when only
  the gender-specific degree, free label or secret flag differs.

### Security
- All data-changing frontend actions (delete, adopt, link request/accept/decline, family delete)
  now require POST in addition to `my_post_key`.
- User-supplied wanted-thread links are restricted to http(s) and relative URLs (blocks
  `javascript:` URLs).
