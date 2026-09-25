<html>
<head>
<title>{$mybb->settings['bbname']} - {$relatives_tree_title}</title>
{$headerinclude}
</head>
<body class="relatives_tree_popup_body">
<div class="relatives_tree_page relatives_tree_popup">
	<div class="relatives_tree_popup_header">
		<strong class="relatives_tree_popup_title">{$relatives_tree_title}</strong>
		<div class="relatives_tree_popup_actions">
			<button type="button" id="relatives-tree-copy-link" class="button" data-url="{$relatives_tree_share_url}" data-copied="{$lang->relatives_tree_link_copied}">{$lang->relatives_tree_copy_link}</button>
		</div>
	</div>

	{$relatives_tree_toolbar}

	<div id="relatives-tree-viewport" class="relatives_tree_viewport">
		<div id="relatives-tree-canvas" class="relatives_tree_canvas"></div>
	</div>

	<p class="relatives_tree_hint">{$relatives_tree_hint}</p>

	<div id="relatives-tree-backdrop" class="relatives_tree_backdrop" hidden></div>
	<div id="relatives-tree-panel" class="relatives_tree_panel" role="dialog" aria-modal="true" hidden></div>
</div>

{$relatives_tree_scripts}
</body>
</html>
