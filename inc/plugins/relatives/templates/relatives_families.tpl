<html>
<head>
<title>{$mybb->settings['bbname']} - {$lang->relatives_families_title}</title>
{$headerinclude}
</head>
<body>
{$header}
<table width="100%" border="0" align="center">
<tr>
{$usercpnav}
<td valign="top">
<table border="0" cellspacing="{$theme['borderwidth']}" cellpadding="{$theme['tablespace']}" class="tborder relatives_page">
<tr>
	<td class="thead"><strong>{$lang->relatives_families_title}</strong></td>
</tr>
<tr>
	<td class="trow1">
	{$relatives_nav}

	<p class="relatives_intro">{$lang->relatives_families_hint}</p>

	{$relatives_families_filter}

	<div class="relatives_family_cards">
		{$relatives_family_rows}
	</div>
	{$relatives_families_empty}
	</td>
</tr>
</table>
</td>
</tr>
</table>
<script src="inc/plugins/relatives/assets/relatives.js?v={$relatives_js_version}"></script>
{$footer}
</body>
</html>
