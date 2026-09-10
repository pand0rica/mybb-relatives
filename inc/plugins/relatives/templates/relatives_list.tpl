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
<div class="relatives_page">
	<div class="relatives_page_header">
		<h2>{$lang->relatives_my_persons_title}</h2>
		<a class="button" href="relatives.php?action=add">{$lang->relatives_add_person}</a>
		<a class="button" href="relatives.php?action=tree">{$lang->relatives_view_tree}</a>
	</div>

	{$relatives_link_requests_box}

	<table class="relatives_table" cellspacing="0" cellpadding="4" width="100%">
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
</div>
</td>
</tr>
</table>
{$footer}
</body>
</html>
