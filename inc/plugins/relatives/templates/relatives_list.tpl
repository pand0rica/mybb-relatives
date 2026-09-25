<html>
<head>
<title>{$mybb->settings['bbname']} - {$lang->relatives_my_persons_title}</title>
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
	<td class="thead"><strong>{$lang->relatives_my_persons_title}</strong></td>
</tr>
<tr>
	<td class="trow1">
		{$relatives_nav}

		{$relatives_link_requests_box}

		<table class="relatives_table{$relatives_table_class}" cellspacing="0" cellpadding="4" width="100%">
			<thead>
				<tr>
					<th>{$lang->relatives_col_name}</th>
					<th>{$lang->relatives_col_relationship}</th>
					<th>{$lang->relatives_col_age}</th>
					<th>{$lang->relatives_col_status}</th>
					<th>{$lang->relatives_col_actions}</th>
				</tr>
			</thead>
			<tbody>
				{$relatives_rows}
			</tbody>
		</table>
		{$relatives_empty_notice}
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
