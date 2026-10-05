<html>
<head>
<title>{$mybb->settings['bbname']} - {$relatives_family_name}</title>
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

	{$relatives_family_page_content}
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
