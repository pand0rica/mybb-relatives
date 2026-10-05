<html>
<head>
<title>{$mybb->settings['bbname']} - {$relatives_family_form_title}</title>
{$headerinclude}
</head>
<body>
{$header}
<table width="100%" border="0" align="center">
<tr>
{$usercpnav}
<td valign="top">
<form action="relatives.php?action={$relatives_family_form_action}&amp;id={$relatives_family_form_id}" method="post">
<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
<table border="0" cellspacing="{$theme['borderwidth']}" cellpadding="{$theme['tablespace']}" class="tborder relatives_page">
<tr>
	<td class="thead"><strong>{$relatives_family_form_title}</strong></td>
</tr>
<tr>
	<td class="trow1">
	{$relatives_nav}

	<p class="relatives_intro">{$lang->relatives_family_form_hint}</p>

	<div class="relatives_field">
		<label for="relatives_family_name">{$lang->relatives_field_family_name}</label>
		<input type="text" class="textbox" id="relatives_family_name" name="name" maxlength="100" value="{$relatives_family_form_name}" required="required" />
	</div>

	<div class="relatives_field">
		<label for="relatives_family_color">{$lang->relatives_field_family_color}</label>
		<input type="color" id="relatives_family_color" name="color" value="{$relatives_family_form_color}" />
	</div>

	<div class="relatives_field">
		<label for="relatives_family_description">{$lang->relatives_field_family_description}</label>
		<textarea id="relatives_family_description" name="description" rows="5" cols="60">{$relatives_family_form_description}</textarea>
	</div>

	<div class="relatives_field">
		<input type="hidden" name="welcomes_new_members" value="0" />
		<label><input type="checkbox" name="welcomes_new_members" value="1"{$relatives_family_form_welcomes_checked} /> {$lang->relatives_field_family_welcomes}</label>
		<p class="relatives_hint">{$lang->relatives_field_family_welcomes_hint}</p>
	</div>

	{$relatives_family_form_attributes}
	</td>
</tr>
<tr>
	<td class="tfoot" align="center">
		<input type="submit" class="button" value="{$lang->relatives_save}" />
		<input type="submit" class="button" form="relatives_cancel_form" value="{$lang->relatives_cancel}" />
	</td>
</tr>
</table>
</form>
{$relatives_cancel_form}
</td>
</tr>
</table>
{$footer}
</body>
</html>
