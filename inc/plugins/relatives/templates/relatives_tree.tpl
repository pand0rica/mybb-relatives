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
<table border="0" cellspacing="{$theme['borderwidth']}" cellpadding="{$theme['tablespace']}" class="tborder relatives_tree_page">
<tr>
	<td class="thead"><strong>{$relatives_tree_title}</strong></td>
</tr>
<tr>
	<td class="trow1">
	{$relatives_nav}

	{$relatives_tree_toolbar}

	<div id="relatives-tree-viewport" class="relatives_tree_viewport">
		<div id="relatives-tree-canvas" class="relatives_tree_canvas"></div>
	</div>

	<p class="relatives_tree_hint">{$relatives_tree_hint}</p>

	<div id="relatives-tree-backdrop" class="relatives_tree_backdrop" hidden></div>
	<div id="relatives-tree-panel" class="relatives_tree_panel" role="dialog" aria-modal="true" hidden></div>
	</td>
</tr>
</table>
</td>
</tr>
</table>

{$relatives_tree_scripts}
{$footer}
</body>
</html>
