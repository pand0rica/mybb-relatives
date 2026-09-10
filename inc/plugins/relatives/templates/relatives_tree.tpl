<html>
<head>
<title>{$mybb->settings['bbname']} - {$relatives_tree_title}</title>
{$headerinclude}
</head>
<body>
{$header}
<table width="100%" border="0" align="center">
<tr>
{$usercpnav}
<td valign="top">
<div class="relatives_tree_page">
	<div class="relatives_page_header">
		<h2>{$relatives_tree_title}</h2>
		<a class="button" href="relatives.php?action=list">{$lang->relatives_my_persons_title}</a>
	</div>

	<div id="relatives-tree-toolbar" class="relatives_tree_toolbar">
		<button type="button" id="relatives-tree-zoom-in">+</button>
		<button type="button" id="relatives-tree-zoom-out">-</button>
		<button type="button" id="relatives-tree-zoom-reset">{$lang->relatives_tree_reset_view}</button>
	</div>

	<div id="relatives-tree-viewport" class="relatives_tree_viewport">
		<div id="relatives-tree-canvas" class="relatives_tree_canvas"></div>
	</div>

	<div id="relatives-tree-panel" class="relatives_tree_panel" style="display:none;"></div>
</div>
</td>
</tr>
</table>

<script type="application/json" id="relatives-tree-data">{$relatives_tree_json}</script>
<script type="application/json" id="relatives-relationship-types-data">{$relatives_relationship_types_json}</script>
<script type="application/json" id="relatives-attribute-groups-data">{$relatives_attribute_groups_json}</script>
<script type="application/json" id="relatives-tree-config">{$relatives_tree_config_json}</script>
<script type="application/json" id="relatives-tree-lang">{$relatives_tree_lang_json}</script>
<script src="inc/plugins/relatives/assets/relatives-tree.js?v={$relatives_tree_js_version}"></script>
{$footer}
</body>
</html>
