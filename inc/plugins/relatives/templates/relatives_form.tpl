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
				data-persons-label="{$lang->relatives_anchor_group_persons}"></div>
			<button type="button" class="button" id="relatives-relation-add">{$lang->relatives_relation_add}</button>
		</div>

		<div class="relatives_field relatives_field_families">
			<label>{$lang->relatives_field_families}</label>
			<p class="relatives_hint">{$lang->relatives_field_families_hint}</p>
			<p class="relatives_hint" id="relatives-families-empty" hidden>{$lang->relatives_field_families_none}</p>
			<div id="relatives-families"
				data-sentence-family="{$lang->relatives_relation_sentence_family}"
				data-sentence-family-parent="{$lang->relatives_relation_sentence_family_parent}"
				data-sentence-subject="{$lang->relatives_relation_sentence_subject}"></div>
			<button type="button" class="button" id="relatives-family-add">{$lang->relatives_family_row_add}</button>
		</div>

		<h3 class="relatives_form_section">{$lang->relatives_form_section_optional}</h3>

		<div class="relatives_field">
			<label for="relatives_relationship_description">{$lang->relatives_field_relationship_description}</label>
			<textarea id="relatives_relationship_description" name="relationship_description" rows="4">{$relatives_form_relationship_description}</textarea>
		</div>

		<div class="relatives_field">
			{$relatives_form_age_field}
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

	{$relatives_form_incoming}

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

<template id="relatives-family-row-template">
	<fieldset class="relatives_relation_row relatives_family_row">
		<div class="relatives_field">
			<label>{$lang->relatives_family_row_family}</label>
			<select data-role="family"><option value="">{$lang->relatives_family_row_choose}</option></select>
		</div>
		<div class="relatives_field">
			<label><input type="checkbox" value="1" data-role="as-parent" /> {$lang->relatives_relation_family_as_parent}</label>
			<p class="relatives_hint">{$lang->relatives_relation_family_hint}</p>
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
	var familyContainer = document.getElementById("relatives-families");
	var familyTemplate = document.getElementById("relatives-family-row-template");
	var familyAddButton = document.getElementById("relatives-family-add");
	var familiesEmpty = document.getElementById("relatives-families-empty");
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

	function toggleCustom(row) {
		var typeSelect = row.querySelector('[data-role="type"]');
		row.querySelector('[data-role="custom-wrap"]').style.display = typeSelect.value === "" ? "" : "none";
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

	// Familien haben einen eigenen Abschnitt; die Kante bleibt dieselbe wie frueher
	// (relations[i][anchor] = "f:<id>"), nur ohne Grad.
	function updateFamilySentence(row) {
		var sentence = row.querySelector('[data-role="sentence"]');
		var select = row.querySelector('[data-role="family"]');
		if (!select.value) {
			sentence.textContent = "";
			sentence.style.display = "none";
			return;
		}
		var asParent = row.querySelector('[data-role="as-parent"]').checked;
		var subject = (nameInput && nameInput.value.trim()) || familyContainer.getAttribute("data-sentence-subject") || "";
		sentence.textContent = (familyContainer.getAttribute(asParent ? "data-sentence-family-parent" : "data-sentence-family") || "")
			.split("{1}").join(subject)
			.split("{3}").join(personNames[select.value] || "");
		sentence.style.display = "";
	}

	function updateAllSentences() {
		var rows = container.querySelectorAll(".relatives_relation_row");
		for (var i = 0; i < rows.length; i++) { updateSentence(rows[i]); }
		if (familyContainer) {
			var familyRows = familyContainer.querySelectorAll(".relatives_family_row");
			for (var j = 0; j < familyRows.length; j++) { updateFamilySentence(familyRows[j]); }
		}
	}

	function addFamilyRow(data) {
		data = data || {};
		var index = counter++;
		var row = familyTemplate.content.firstElementChild.cloneNode(true);

		var select = row.querySelector('[data-role="family"]');
		var asParent = row.querySelector('[data-role="as-parent"]');
		var primary = row.querySelector('[data-role="primary"]');
		var secret = row.querySelector('[data-role="secret"]');
		var remove = row.querySelector('[data-role="remove"]');

		families.forEach(function (family) {
			var opt = document.createElement("option");
			opt.value = "f:" + family.id;
			opt.textContent = family.name;
			select.appendChild(opt);
		});
		select.name = "relations[" + index + "][anchor]";
		asParent.name = "relations[" + index + "][family_as_parent]";
		secret.name = "relations[" + index + "][is_secret]";
		primary.value = index;
		// Gespeicherte Kind-Variante (z. B. Adoptivkind) mitschicken -- der Server
		// behaelt sie bei "als Eltern anzeigen", sonst gilt "Kind".
		if (data.type_id) {
			var typeInput = document.createElement("input");
			typeInput.type = "hidden";
			typeInput.name = "relations[" + index + "][relationship_type_id]";
			typeInput.value = String(data.type_id);
			row.appendChild(typeInput);
		}

		select.value = data.anchor || "";
		if (select.value !== (data.anchor || "")) { select.value = ""; }
		asParent.checked = !!data.as_parent;
		secret.checked = !!data.secret;
		primary.checked = !!data.primary;

		select.addEventListener("change", function () { updateFamilySentence(row); });
		asParent.addEventListener("change", function () { updateFamilySentence(row); });
		remove.addEventListener("click", function () {
			var wasPrimary = primary.checked;
			row.parentNode.removeChild(row);
			if (wasPrimary) { ensurePrimary(); }
		});

		familyContainer.appendChild(row);
		updateFamilySentence(row);
		ensurePrimary();
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
		var radios = document.querySelectorAll('#relatives-relations [data-role="primary"], #relatives-families [data-role="primary"]');
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
		var existingPersons = existing.filter(function (item) { return String(item.anchor || "").indexOf("f:") !== 0; });
		var existingFamilies = existing.filter(function (item) { return String(item.anchor || "").indexOf("f:") === 0; });
		if (existingPersons.length) {
			existingPersons.forEach(addRow);
		} else if (!existingFamilies.length) {
			addRow({ anchor: "0", primary: true });
		}
		if (familyContainer && familyTemplate) {
			existingFamilies.forEach(addFamilyRow);
			if (!families.length) {
				if (familyAddButton) { familyAddButton.hidden = true; }
				if (familiesEmpty) { familiesEmpty.hidden = false; }
			} else if (familyAddButton) {
				familyAddButton.addEventListener("click", function () { addFamilyRow({}); });
			}
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

	// "= 34 Jahre" neben dem Geburtsjahr (Inplay-Jahr aus den Einstellungen).
	var birthYearInput = document.getElementById("relatives_birth_year");
	var birthYearAge = document.getElementById("relatives_birth_year_age");
	function updateBirthYearAge() {
		if (!birthYearInput || !birthYearAge) { return; }
		var year = parseInt(birthYearInput.value, 10);
		var inplay = parseInt(birthYearInput.getAttribute("data-inplay-year"), 10);
		birthYearAge.textContent = isNaN(year) || isNaN(inplay) || year > inplay
			? ""
			: (birthYearAge.getAttribute("data-template") || "").split("{1}").join(String(inplay - year)).split("{2}").join(String(inplay));
	}
	if (birthYearInput) { birthYearInput.addEventListener("input", updateBirthYearAge); updateBirthYearAge(); }

	if (wantedCheckbox) { wantedCheckbox.addEventListener("change", function () { toggleWrap(wantedCheckbox, wantedWrap); }); }
	if (deceasedCheckbox) { deceasedCheckbox.addEventListener("change", function () { toggleWrap(deceasedCheckbox, deathWrap); }); }
	toggleWrap(wantedCheckbox, wantedWrap);
	toggleWrap(deceasedCheckbox, deathWrap);
})();
</script>
<script src="inc/plugins/relatives/assets/relatives.js?v={$relatives_js_version}"></script>
{$footer}
</body>
</html>
