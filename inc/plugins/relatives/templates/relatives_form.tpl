<html>
<head>
<title>{$mybb->settings['bbname']} - {$relatives_form_title}</title>
{$headerinclude}
</head>
<body>
{$header}
<table width="100%" border="0" align="center">
<tr>
{$usercpnav}
<td valign="top">
<div class="relatives_page">
	<h2>{$relatives_form_title}</h2>
	<form action="relatives.php?action={$relatives_form_action}" method="post">
		<input type="hidden" name="id" value="{$relatives_form_id}" />
		<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />

		<div class="relatives_field">
			<label for="relatives_name">{$lang->relatives_field_name}</label>
			<input type="text" id="relatives_name" name="name" maxlength="255" value="{$relatives_form_name}" required="required" />
		</div>

		<div class="relatives_field">
			<label for="relatives_gender">{$lang->relatives_field_gender}</label>
			<select id="relatives_gender" name="gender">
				{$relatives_form_gender_options}
			</select>
		</div>

		<div class="relatives_field">
			<label for="relatives_relationship_type_id">{$lang->relatives_field_relationship}</label>
			<select id="relatives_relationship_type_id" name="relationship_type_id" data-selected="{$relatives_form_relationship_type_id}">
				<option value="">{$lang->relatives_relationship_other}</option>
			</select>
			<p class="relatives_hint" id="relatives_relationship_hint" style="display:none;">{$lang->relatives_relationship_missing_neutral}</p>
		</div>

		<div class="relatives_field" id="relatives_custom_label_wrap">
			<label for="relatives_relationship_custom_label">{$lang->relatives_field_relationship_custom}</label>
			<input type="text" id="relatives_relationship_custom_label" name="relationship_custom_label" maxlength="255" value="{$relatives_form_custom_label}" />
		</div>

		<div class="relatives_field">
			<label for="relatives_age">{$lang->relatives_field_age}</label>
			<input type="number" id="relatives_age" name="age" min="0" max="999" value="{$relatives_form_age}" />
		</div>

		<div class="relatives_field">
			<label for="relatives_origin">{$lang->relatives_field_origin}</label>
			{$relatives_form_origin_field}
		</div>

		<div class="relatives_field">
			<label for="relatives_avatar">{$lang->relatives_field_avatar}</label>
			<input type="text" id="relatives_avatar" name="avatar" maxlength="255" value="{$relatives_form_avatar}" />
		</div>

		<div class="relatives_field">
			<label for="relatives_description">{$lang->relatives_field_description}</label>
			<textarea id="relatives_description" name="description" rows="6">{$relatives_form_description}</textarea>
		</div>

		<div class="relatives_field">
			<label for="relatives_is_wanted">
				<input type="checkbox" id="relatives_is_wanted" name="is_wanted" value="1" {$relatives_form_is_wanted_checked} />
				{$lang->relatives_field_is_wanted}
			</label>
		</div>

		<div class="relatives_field" id="relatives_wanted_thread_wrap">
			<label for="relatives_wanted_thread_link">{$lang->relatives_field_wanted_thread_link}</label>
			<input type="text" id="relatives_wanted_thread_link" name="wanted_thread_link" maxlength="255" value="{$relatives_form_wanted_thread_link}" />
		</div>

		<div class="relatives_field relatives_field_attributes">
			<label>{$lang->relatives_field_attributes}</label>
			{$relatives_form_attribute_groups}
		</div>

		<div class="relatives_field">
			<input type="submit" class="button" value="{$lang->relatives_save}" />
			<a class="button" href="relatives.php?action=list">{$lang->relatives_cancel}</a>
		</div>
	</form>

	{$relatives_form_link_section}
</div>
</td>
</tr>
</table>

<script type="application/json" id="relatives-relationship-types-data">{$relatives_relationship_types_json}</script>
<script>
(function() {
	"use strict";
	var dataEl = document.getElementById("relatives-relationship-types-data");
	if (!dataEl) { return; }
	var allTypes = [];
	try { allTypes = JSON.parse(dataEl.textContent || "[]"); } catch (e) { allTypes = []; }

	var genderSelect = document.getElementById("relatives_gender");
	var typeSelect = document.getElementById("relatives_relationship_type_id");
	var customWrap = document.getElementById("relatives_custom_label_wrap");
	var wantedCheckbox = document.getElementById("relatives_is_wanted");
	var wantedWrap = document.getElementById("relatives_wanted_thread_wrap");
	var selectedTypeId = typeSelect ? typeSelect.getAttribute("data-selected") : "";

	function mapGender(personGender) {
		personGender = parseInt(personGender, 10);
		if (personGender === 0) { return 0; }
		if (personGender === 1) { return 1; }
		return 2;
	}

	function rebuildTypeOptions() {
		if (!genderSelect || !typeSelect) { return; }
		var labelGender = mapGender(genderSelect.value);
		var current = typeSelect.value;
		while (typeSelect.options.length > 1) {
			typeSelect.remove(1);
		}
		for (var i = 0; i < allTypes.length; i++) {
			if (allTypes[i].gender !== labelGender) { continue; }
			var opt = document.createElement("option");
			opt.value = allTypes[i].id;
			opt.textContent = allTypes[i].label;
			typeSelect.appendChild(opt);
		}
		if (selectedTypeId) {
			typeSelect.value = selectedTypeId;
			selectedTypeId = "";
		} else if (current) {
			typeSelect.value = current;
		}
		toggleCustomLabel();
	}

	function toggleCustomLabel() {
		if (!customWrap || !typeSelect) { return; }
		customWrap.style.display = typeSelect.value === "" ? "" : "none";
	}

	function toggleWantedThread() {
		if (!wantedWrap || !wantedCheckbox) { return; }
		wantedWrap.style.display = wantedCheckbox.checked ? "" : "none";
	}

	if (genderSelect) { genderSelect.addEventListener("change", rebuildTypeOptions); }
	if (typeSelect) { typeSelect.addEventListener("change", toggleCustomLabel); }
	if (wantedCheckbox) { wantedCheckbox.addEventListener("change", toggleWantedThread); }

	rebuildTypeOptions();
	toggleWantedThread();
})();
</script>
{$footer}
</body>
</html>
