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
- Family boxes with colour, description, attributes, "welcomes new members" switch, overview
  with filters and a public read-only family list (embeddable into existing list templates).
- Account links with confirmation by the linked member (sent as a regular PM, so MyAlerts /
  Account Switcher react).
- ACP migration from the legacy plugin with preview, mapping of every legacy label, structure
  inference (e.g. "uncle on the mother's side" → brother of the mother) and re-run protection.
- ACP *Shared network* page: merge preview, merge, conflict list, taking back legacy guesses.
- Public API `relatives_search_persons()` and `relatives_get_relation()`, provider hooks for the
  *jobliste* plugin.
- User CP menu item in the *Miscellaneous* box with its own icon; shared tab navigation on all
  plugin pages.

### Changed
- Wanted cards on the profile use responsive CSS instead of hard-coded widths per theme.
- Relationship types and attribute values are no longer seeded with forum-specific example data.

### Fixed
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
- A fresh installation without legacy data was locked ("network not merged yet") until an admin
  ran a merge on empty tables.
- The ACP page is registered as an admin permission (*Tools & Maintenance → Can manage
  Relatives & Family Tree?*); before, every administrator had access and it could not be
  restricted.
- Setting titles and descriptions are translatable (English and German) instead of fixed German
  text.
- ACP pages and plugin templates no longer use inline `style`/`onclick` attributes; the ACP delete
  confirmation no longer breaks on language strings containing an apostrophe.

### Security
- All data-changing frontend actions (delete, adopt, link request/accept/decline, family delete)
  now require POST in addition to `my_post_key`.
- User-supplied wanted-thread links are restricted to http(s) and relative URLs (blocks
  `javascript:` URLs).
