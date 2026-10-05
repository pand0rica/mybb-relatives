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
<table border="0" cellspacing="{$theme['borderwidth']}" cellpadding="{$theme['tablespace']}" class="tborder relatives_page">
<tr>
	<td class="thead"><strong>{$relatives_form_title}</strong></td>
</tr>
<tr>
	<td class="trow1">
	{$relatives_nav}

	<form action="relatives.php?action={$relatives_form_action}" method="post">
		<input type="hidden" name="id" value="{$relatives_form_id}" />
		<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
		<input type="hidden" name="return" value="{$relatives_form_return}" />
		{$relatives_form_shared_note}

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

		<div class="relatives_field relatives_field_relations">
			<label>{$lang->relatives_field_relations}</label>
			<p class="relatives_hint">{$lang->relatives_field_relations_hint}</p>
			<p class="relatives_hint" id="relatives_relationship_hint" hidden>{$lang->relatives_relationship_missing_neutral}</p>
			<div id="relatives-relations"
				data-self-label="{$relatives_form_self_label}"
				data-self-name="{$relatives_form_self_name}"
				data-sentence="{$lang->relatives_relation_sentence}"
				data-sentence-subject="{$lang->relatives_relation_sentence_subject}"
				data-sentence-family="{$lang->relatives_relation_sentence_family}"
				data-sentence-family-parent="{$lang->relatives_relation_sentence_family_parent}"
				data-persons-label="{$lang->relatives_anchor_group_persons}"
				data-families-label="{$lang->relatives_families_title}"></div>
			<button type="button" class="button" id="relatives-relation-add">{$lang->relatives_relation_add}</button>
		</div>

		<h3 class="relatives_form_section">{$lang->relatives_form_section_optional}</h3>

		<div class="relatives_field">
			<label for="relatives_relationship_description">{$lang->relatives_field_relationship_description}</label>
			<textarea id="relatives_relationship_description" name="relationship_description" rows="4">{$relatives_form_relationship_description}</textarea>
		</div>

		<div class="relatives_field">
			<label for="relatives_age">{$lang->relatives_field_age}</label>
			<input type="number" id="relatives_age" name="age" min="0" max="999" value="{$relatives_form_age}" />
		</div>

		<div class="relatives_field">
			<label for="relatives_is_deceased">
				<input type="checkbox" id="relatives_is_deceased" name="is_deceased" value="1" {$relatives_form_is_deceased_checked} />
				{$lang->relatives_field_is_deceased}
			</label>
		</div>

		<div class="relatives_field" id="relatives_death_note_wrap">
			<label for="relatives_death_note">{$lang->relatives_field_death_note}</label>
			<input type="text" id="relatives_death_note" name="death_note" maxlength="64" value="{$relatives_form_death_note}" />
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
			<input type="url" id="relatives_wanted_thread_link" name="wanted_thread_link" maxlength="255" value="{$relatives_form_wanted_thread_link}" placeholder="{$relatives_form_wanted_placeholder}" />
		</div>

		{$relatives_form_attribute_groups}

		<div class="relatives_form_actions">
			<input type="submit" class="button" value="{$lang->relatives_save}" />
			<input type="submit" class="button" form="relatives_cancel_form" value="{$lang->relatives_cancel}" />
		</div>
	</form>
	{$relatives_cancel_form}

	{$relatives_form_link_section}
	</td>
</tr>
</table>
</td>
</tr>
</table>

<template id="relatives-relation-row-template">
	<fieldset class="relatives_relation_row">
		<div class="relatives_field">
			<label>{$lang->relatives_relation_anchor}</label>
			<select data-role="anchor"></select>
		</div>
		<div class="relatives_field" data-role="family-wrap">
			<label><input type="checkbox" value="1" data-role="as-parent" /> {$lang->relatives_relation_family_as_parent}</label>
			<p class="relatives_hint">{$lang->relatives_relation_family_hint}</p>
		</div>
		<div class="relatives_field" data-role="type-wrap">
			<label>{$lang->relatives_field_relationship}</label>
			<select data-role="type"><option value="">{$lang->relatives_relationship_other}</option></select>
		</div>
		<div class="relatives_field" data-role="custom-wrap">
			<label>{$lang->relatives_field_relationship_custom}</label>
			<input type="text" maxlength="255" data-role="custom" />
		</div>
		<p class="relatives_relation_sentence" data-role="sentence"></p>
		<div class="relatives_relation_flags">
			<label><input type="radio" name="relations_primary" data-role="primary" /> {$lang->relatives_relation_primary}</label>
			<label title="{$lang->relatives_relation_secret_desc}"><input type="checkbox" value="1" data-role="secret" /> {$lang->relatives_relation_secret}</label>
			<button type="button" class="button" data-role="remove">{$lang->relatives_relation_remove}</button>
		</div>
	</fieldset>
</template>

<script type="application/json" id="relatives-relationship-types-data">{$relatives_relationship_types_json}</script>
<script type="application/json" id="relatives-form-persons-data">{$relatives_form_persons_json}</script>
<script type="application/json" id="relatives-form-families-data">{$relatives_form_families_json}</script>
<script type="application/json" id="relatives-form-relations-data">{$relatives_form_relations_json}</script>
<script>
(function() {
	"use strict";

	function readJson(id) {
		var el = document.getElementById(id);
		if (!el) { return []; }
		try { return JSON.parse(el.textContent || "[]") || []; } catch (e) { return []; }
	}

	var allTypes = readJson("relatives-relationship-types-data");
	var persons = readJson("relatives-form-persons-data");
	var families = readJson("relatives-form-families-data");
	var existing = readJson("relatives-form-relations-data");

	var genderSelect = document.getElementById("relatives_gender");
	var container = document.getElementById("relatives-relations");
	var rowTemplate = document.getElementById("relatives-relation-row-template");
	var addButton = document.getElementById("relatives-relation-add");
	var wantedCheckbox = document.getElementById("relatives_is_wanted");
	var wantedWrap = document.getElementById("relatives_wanted_thread_wrap");
	var deceasedCheckbox = document.getElementById("relatives_is_deceased");
	var deathWrap = document.getElementById("relatives_death_note_wrap");

	// Laufende Nummer statt Position: entfernte Zeilen hinterlassen Luecken, die
	// Hauptverbindung verweist trotzdem eindeutig auf ihre Zeile.
	var counter = 0;

	function mapGender(personGender) {
		personGender = parseInt(personGender, 10);
		if (personGender === 0) { return 0; }
		if (personGender === 1) { return 1; }
		return 2;
	}

	function fillAnchor(select, value) {
		var self = document.createElement("option");
		self.value = "0";
		self.textContent = container.getAttribute("data-self-label") || "";
		select.appendChild(self);

		if (persons.length) {
			var personGroup = document.createElement("optgroup");
			personGroup.label = container.getAttribute("data-persons-label") || "";
			persons.forEach(function (person) {
				var opt = document.createElement("option");
				opt.value = "p:" + person.id;
				opt.textContent = person.label ? person.name + " (" + person.label + ")" : person.name;
				personGroup.appendChild(opt);
			});
			select.appendChild(personGroup);
		}
		if (families.length) {
			var familyGroup = document.createElement("optgroup");
			familyGroup.label = container.getAttribute("data-families-label") || "";
			families.forEach(function (family) {
				var opt = document.createElement("option");
				opt.value = "f:" + family.id;
				opt.textContent = family.name;
				familyGroup.appendChild(opt);
			});
			select.appendChild(familyGroup);
		}
		select.value = value || "0";
		if (select.value === "") { select.value = "0"; }
	}

	function fillTypes(row) {
		var typeSelect = row.querySelector('[data-role="type"]');
		var labelGender = mapGender(genderSelect ? genderSelect.value : -1);
		var current = typeSelect.getAttribute("data-selected") || typeSelect.value;
		typeSelect.removeAttribute("data-selected");
		while (typeSelect.options.length > 1) { typeSelect.remove(1); }

		allTypes
			.filter(function (type) { return type.gender === labelGender; })
			// Sammelbegriffe ("entfernte*r Verwandte*r") vergibt die Berechnung selbst.
			.filter(function (type) { return type.concept_key.indexOf("distant_") !== 0; })
			.sort(function (a, b) { return a.label.localeCompare(b.label, "de"); })
			.forEach(function (type) {
				var opt = document.createElement("option");
				opt.value = type.id;
				opt.textContent = type.label;
				typeSelect.appendChild(opt);
			});

		typeSelect.value = current;
		if (typeSelect.value !== String(current)) { typeSelect.value = ""; }
		toggleCustom(row);
	}

	function isFamilyRow(row) {
		return row.querySelector('[data-role="anchor"]').value.indexOf("f:") === 0;
	}

	// Familie: kein Grad, nur "als Eltern anzeigen" -- Grad und freie Bezeichnung
	// verschwinden (der Server leitet die Kante selbst ab).
	function toggleCustom(row) {
		var typeSelect = row.querySelector('[data-role="type"]');
		var family = isFamilyRow(row);
		row.querySelector('[data-role="family-wrap"]').style.display = family ? "" : "none";
		row.querySelector('[data-role="type-wrap"]').style.display = family ? "none" : "";
		row.querySelector('[data-role="custom-wrap"]').style.display = !family && typeSelect.value === "" ? "" : "none";
	}

	// "Finian Hari ist Sohn von Rian MacCrea." -- makes the direction of each connection
	// explicit; without it, users cannot tell whether to pick "Vater" or "Sohn".
	var nameInput = document.getElementById("relatives_name");
	var personNames = {};
	persons.forEach(function (person) { personNames["p:" + person.id] = person.name; });
	families.forEach(function (family) { personNames["f:" + family.id] = family.name; });

	function updateSentence(row) {
		var sentence = row.querySelector('[data-role="sentence"]');
		var typeSelect = row.querySelector('[data-role="type"]');
		var anchor = row.querySelector('[data-role="anchor"]');
		var subject = (nameInput && nameInput.value.trim()) || container.getAttribute("data-sentence-subject") || "";
		if (isFamilyRow(row)) {
			var asParent = row.querySelector('[data-role="as-parent"]').checked;
			sentence.textContent = (container.getAttribute(asParent ? "data-sentence-family-parent" : "data-sentence-family") || "")
				.split("{1}").join(subject)
				.split("{3}").join(personNames[anchor.value] || "");
			sentence.style.display = "";
			return;
		}
		var degree = typeSelect.value !== ""
			? typeSelect.options[typeSelect.selectedIndex].textContent
			: row.querySelector('[data-role="custom"]').value.trim();
		if (!degree) {
			sentence.textContent = "";
			sentence.style.display = "none";
			return;
		}
		var target = anchor.value === "0"
			? (container.getAttribute("data-self-name") || "")
			: (personNames[anchor.value] || "");
		sentence.textContent = (container.getAttribute("data-sentence") || "")
			.split("{1}").join(subject)
			.split("{2}").join(degree)
			.split("{3}").join(target);
		sentence.style.display = "";
	}

	function updateAllSentences() {
		var rows = container.querySelectorAll(".relatives_relation_row");
		for (var i = 0; i < rows.length; i++) { updateSentence(rows[i]); }
	}

	function addRow(data) {
		data = data || {};
		var index = counter++;
		var row = rowTemplate.content.firstElementChild.cloneNode(true);

		var anchor = row.querySelector('[data-role="anchor"]');
		var typeSelect = row.querySelector('[data-role="type"]');
		var custom = row.querySelector('[data-role="custom"]');
		var primary = row.querySelector('[data-role="primary"]');
		var secret = row.querySelector('[data-role="secret"]');
		var asParent = row.querySelector('[data-role="as-parent"]');
		var remove = row.querySelector('[data-role="remove"]');

		anchor.name = "relations[" + index + "][anchor]";
		typeSelect.name = "relations[" + index + "][relationship_type_id]";
		custom.name = "relations[" + index + "][relationship_custom_label]";
		secret.name = "relations[" + index + "][is_secret]";
		asParent.name = "relations[" + index + "][family_as_parent]";
		primary.value = index;

		fillAnchor(anchor, data.anchor);
		typeSelect.setAttribute("data-selected", data.type_id ? String(data.type_id) : "");
		custom.value = data.custom_label || "";
		secret.checked = !!data.secret;
		asParent.checked = !!data.as_parent;
		primary.checked = !!data.primary;

		typeSelect.addEventListener("change", function () { toggleCustom(row); updateSentence(row); });
		anchor.addEventListener("change", function () { toggleCustom(row); updateSentence(row); });
		asParent.addEventListener("change", function () { updateSentence(row); });
		custom.addEventListener("input", function () { updateSentence(row); });
		remove.addEventListener("click", function () {
			var wasPrimary = primary.checked;
			row.parentNode.removeChild(row);
			if (wasPrimary) { ensurePrimary(); }
		});

		container.appendChild(row);
		fillTypes(row);
		updateSentence(row);
		ensurePrimary();
	}

	function ensurePrimary() {
		var radios = container.querySelectorAll('[data-role="primary"]');
		for (var i = 0; i < radios.length; i++) {
			if (radios[i].checked) { return; }
		}
		if (radios.length) { radios[0].checked = true; }
	}

	function toggleWrap(checkbox, wrap) {
		if (!checkbox || !wrap) { return; }
		wrap.style.display = checkbox.checked ? "" : "none";
	}

	if (container && rowTemplate) {
		if (existing.length) {
			existing.forEach(addRow);
		} else {
			addRow({ anchor: "0", primary: true });
		}
		if (addButton) { addButton.addEventListener("click", function () { addRow({ anchor: "0" }); }); }
		if (genderSelect) {
			genderSelect.addEventListener("change", function () {
				var rows = container.querySelectorAll(".relatives_relation_row");
				for (var i = 0; i < rows.length; i++) { fillTypes(rows[i]); updateSentence(rows[i]); }
			});
		}
		if (nameInput) { nameInput.addEventListener("input", updateAllSentences); }
	}

	if (wantedCheckbox) { wantedCheckbox.addEventListener("change", function () { toggleWrap(wantedCheckbox, wantedWrap); }); }
	if (deceasedCheckbox) { deceasedCheckbox.addEventListener("change", function () { toggleWrap(deceasedCheckbox, deathWrap); }); }
	toggleWrap(wantedCheckbox, wantedWrap);
	toggleWrap(deceasedCheckbox, deathWrap);
})();
</script>
{$footer}
</body>
</html>
