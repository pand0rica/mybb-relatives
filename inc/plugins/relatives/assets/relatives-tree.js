/**
 * relatives-tree.js - Verwandtschaften & Stammbaum
 *
 * Vanilla-JS-Renderer fuer den interaktiven Stammbaum: Pan/Zoom, Ein-/Ausklappen von
 * Aesten, Klick-Interaktionen (eigener Knoten -> Inline-Edit, leerer Slot -> gefuehrtes
 * Klick-Add-Interface, verlinkter bestaetigter fremder Knoten -> Navigation). Keine
 * externen Libraries (Projektregel).
 */
(function () {
	"use strict";

	function readJson(id, fallback) {
		var el = document.getElementById(id);
		if (!el) { return fallback; }
		try {
			return JSON.parse(el.textContent || "null") || fallback;
		} catch (e) {
			return fallback;
		}
	}

	var treeData = readJson("relatives-tree-data", { nodes: [], edges: [] });
	var relationshipTypes = readJson("relatives-relationship-types-data", []);
	var attributeGroups = readJson("relatives-attribute-groups-data", []);
	var config = readJson("relatives-tree-config", {});
	var L = readJson("relatives-tree-lang", {});

	var H_SPACING = 190;
	var V_SPACING = 130;

	var viewport = document.getElementById("relatives-tree-viewport");
	var canvas = document.getElementById("relatives-tree-canvas");
	var panel = document.getElementById("relatives-tree-panel");

	if (!viewport || !canvas) { return; }

	var state = {
		nodesByKey: {},
		edges: treeData.edges || [],
		collapsed: {},
		pan: { x: 0, y: 0 },
		zoom: 1,
	};

	(treeData.nodes || []).forEach(function (node) {
		state.nodesByKey[node.key] = node;
	});

	function mapGender(personGender) {
		personGender = parseInt(personGender, 10);
		if (personGender === 0) { return 0; }
		if (personGender === 1) { return 1; }
		return 2;
	}

	function t(key, fallback) {
		return (L && L[key]) ? L[key] : fallback;
	}

	// --- Sichtbarkeit (Ein-/Ausklappen) -------------------------------------------------

	function childrenOf(key) {
		return state.edges.filter(function (e) { return e.from === key; }).map(function (e) { return e.to; });
	}

	function visibleKeys() {
		var visible = {};
		var roots = (treeData.nodes || []).filter(function (n) { return n.type === "self"; });
		var rootKeys = roots.length ? [roots[0].key] : [];
		// Root des aktuell betrachteten Baums: Selbst-Knoten mit uid == config.rootUid
		var actualRoot = null;
		(treeData.nodes || []).some(function (n) {
			if (n.type === "self" && n.uid === config.rootUid) { actualRoot = n.key; return true; }
			return false;
		});
		var startKey = actualRoot || (rootKeys.length ? rootKeys[0] : null);
		if (!startKey) { return visible; }

		var queue = [startKey];
		visible[startKey] = true;
		while (queue.length) {
			var key = queue.shift();
			if (state.collapsed[key]) { continue; }
			childrenOf(key).forEach(function (childKey) {
				if (!visible[childKey]) {
					visible[childKey] = true;
					queue.push(childKey);
				}
			});
		}
		return visible;
	}

	// --- Rendering -------------------------------------------------------------------

	function relationshipLabel(node) {
		if (node.display_relationship_label) { return node.display_relationship_label; }
		if (node.relationship_type_id) {
			for (var i = 0; i < relationshipTypes.length; i++) {
				if (relationshipTypes[i].id === node.relationship_type_id) {
					return relationshipTypes[i].label;
				}
			}
		}
		if (node.relationship_custom_label) { return node.relationship_custom_label; }
		return t("relationship_unspecified", "");
	}

	function render() {
		canvas.innerHTML = "";
		canvas.style.transform = "translate(" + state.pan.x + "px," + state.pan.y + "px) scale(" + state.zoom + ")";

		var visible = visibleKeys();

		state.edges.forEach(function (edge) {
			if (!visible[edge.from] || !visible[edge.to]) { return; }
			var from = state.nodesByKey[edge.from];
			var to = state.nodesByKey[edge.to];
			if (!from || !to) { return; }
			drawEdge(from, to);
		});

		Object.keys(visible).forEach(function (key) {
			var node = state.nodesByKey[key];
			if (!node) { return; }
			drawNode(node);
		});
	}

	function nodePixel(node) {
		return {
			x: node.x * H_SPACING,
			y: node.y * V_SPACING,
		};
	}

	function drawEdge(from, to) {
		var p1 = nodePixel(from);
		var p2 = nodePixel(to);
		var dx = p2.x - p1.x;
		var dy = p2.y - p1.y;
		var length = Math.sqrt(dx * dx + dy * dy);
		var angle = Math.atan2(dy, dx) * (180 / Math.PI);

		var el = document.createElement("div");
		el.className = "relatives_tree_edge";
		el.style.width = length + "px";
		el.style.left = p1.x + "px";
		el.style.top = p1.y + "px";
		el.style.transform = "rotate(" + angle + "deg)";
		canvas.appendChild(el);
	}

	function drawNode(node) {
		var pos = nodePixel(node);
		var el = document.createElement("div");
		el.className = "relatives_tree_node";
		el.style.left = pos.x + "px";
		el.style.top = pos.y + "px";

		if (node.type === "self") {
			el.classList.add("relatives-node-self");
			var toggle = document.createElement("span");
			toggle.textContent = state.collapsed[node.key] ? "▸ " : "▾ ";
			toggle.addEventListener("click", function (ev) {
				ev.stopPropagation();
				state.collapsed[node.key] = !state.collapsed[node.key];
				render();
			});
			el.appendChild(toggle);
			var nameSpan = document.createElement("span");
			nameSpan.textContent = node.username || ("#" + node.uid);
			el.appendChild(nameSpan);

			el.addEventListener("click", function () {
				if (node.uid !== config.rootUid) {
					window.location.href = "relatives.php?action=tree&uid=" + encodeURIComponent(node.uid);
				} else if (node.uid === config.viewerUid) {
					openActionMenu(node);
				}
			});
		} else {
			if (node.gender === 0) { el.classList.add("relatives-gender-male"); }
			else if (node.gender === 1) { el.classList.add("relatives-gender-female"); }
			else { el.classList.add("relatives-gender-diverse"); }
			if (node.is_wanted) { el.classList.add("relatives-node-wanted"); }
			if (node.link_status === 2) { el.classList.add("relatives-node-linked-confirmed"); }
			if (node.link_status === 1) { el.classList.add("relatives-node-linked-pending"); }

			var nameDiv = document.createElement("div");
			nameDiv.className = "relatives_tree_node_name";
			nameDiv.textContent = node.name;
			el.appendChild(nameDiv);

			var relDiv = document.createElement("div");
			relDiv.className = "relatives_tree_node_relationship";
			relDiv.textContent = relationshipLabel(node);
			el.appendChild(relDiv);

			el.addEventListener("click", function () {
				if (node.owner_uid === config.viewerUid) {
					openActionMenu(node);
				} else if (node.link_status === 2 && node.linked_uid) {
					window.location.href = "relatives.php?action=tree&uid=" + encodeURIComponent(node.linked_uid);
				} else {
					openReadOnlyPanel(node);
				}
			});
		}

		canvas.appendChild(el);
	}

	// --- Aktionsmenue (Klick auf einen Knoten: Bearbeiten / Loeschen / Hinzufuegen) -----

	function menuButton(label, onClick) {
		var btn = document.createElement("button");
		btn.type = "button";
		btn.className = "button";
		btn.style.display = "block";
		btn.style.width = "100%";
		btn.style.marginBottom = "6px";
		btn.textContent = label;
		btn.addEventListener("click", onClick);
		return btn;
	}

	function openActionMenu(node) {
		if (!panel) { return; }
		panel.innerHTML = "";
		panel.appendChild(closeButton());

		var h = document.createElement("h3");
		h.textContent = node.type === "self" ? (node.username || ("#" + node.uid)) : node.name;
		panel.appendChild(h);

		var menu = document.createElement("div");
		menu.className = "relatives_tree_action_menu";

		if (node.type === "person") {
			menu.appendChild(menuButton(t("edit_person", "Person bearbeiten"), function () {
				openEditPanel(node);
			}));
			menu.appendChild(menuButton(t("delete", "Loeschen"), function () {
				if (window.confirm(t("confirm_delete", "Diese Person wirklich loeschen?"))) {
					deletePersonAjax(node.id);
				}
			}));
		}

		var addLabel = node.type === "self"
			? t("add_person", "Person hinzufuegen")
			: t("add_relative_of", "Verwandte/n von") + " " + node.name;
		menu.appendChild(menuButton(addLabel, function () {
			openPersonForm(null, node.type === "person" ? node : null);
		}));

		panel.appendChild(menu);
		panel.style.display = "block";
	}

	function deletePersonAjax(id) {
		var payload = new FormData();
		payload.append("my_post_key", config.postCode || "");
		payload.append("ajax", "1");
		payload.append("id", id);

		fetch("relatives.php?action=delete", { method: "POST", body: payload, credentials: "same-origin" })
			.then(function (resp) { return resp.json(); })
			.then(function (data) {
				if (data && data.success) {
					panel.style.display = "none";
					reloadTree();
				} else {
					window.alert((data && data.error) || t("save_failed", "Fehlgeschlagen."));
				}
			})
			.catch(function () {
				window.alert(t("save_failed", "Fehlgeschlagen."));
			});
	}

	// --- Pan & Zoom --------------------------------------------------------------------

	var dragging = false;
	var dragStart = { x: 0, y: 0 };
	var panStart = { x: 0, y: 0 };

	viewport.addEventListener("mousedown", function (ev) {
		dragging = true;
		viewport.classList.add("relatives-dragging");
		dragStart = { x: ev.clientX, y: ev.clientY };
		panStart = { x: state.pan.x, y: state.pan.y };
	});
	window.addEventListener("mousemove", function (ev) {
		if (!dragging) { return; }
		state.pan.x = panStart.x + (ev.clientX - dragStart.x);
		state.pan.y = panStart.y + (ev.clientY - dragStart.y);
		render();
	});
	window.addEventListener("mouseup", function () {
		dragging = false;
		viewport.classList.remove("relatives-dragging");
	});

	viewport.addEventListener("wheel", function (ev) {
		ev.preventDefault();
		var delta = ev.deltaY > 0 ? -0.1 : 0.1;
		state.zoom = Math.min(2, Math.max(0.4, state.zoom + delta));
		render();
	}, { passive: false });

	function bindToolbar() {
		var zoomIn = document.getElementById("relatives-tree-zoom-in");
		var zoomOut = document.getElementById("relatives-tree-zoom-out");
		var zoomReset = document.getElementById("relatives-tree-zoom-reset");
		if (zoomIn) { zoomIn.addEventListener("click", function () { state.zoom = Math.min(2, state.zoom + 0.1); render(); }); }
		if (zoomOut) { zoomOut.addEventListener("click", function () { state.zoom = Math.max(0.4, state.zoom - 0.1); render(); }); }
		if (zoomReset) {
			zoomReset.addEventListener("click", function () {
				state.zoom = 1;
				state.pan = { x: 0, y: 0 };
				render();
			});
		}
	}

	// --- Panel: read-only Info ------------------------------------------------------------

	function openReadOnlyPanel(node) {
		if (!panel) { return; }
		panel.innerHTML = "";
		panel.appendChild(closeButton());

		var h = document.createElement("h3");
		h.textContent = node.name;
		panel.appendChild(h);

		var rel = document.createElement("p");
		rel.textContent = relationshipLabel(node);
		panel.appendChild(rel);

		if (node.description) {
			var desc = document.createElement("p");
			desc.textContent = node.description;
			panel.appendChild(desc);
		}

		panel.style.display = "block";
	}

	function closeButton() {
		var btn = document.createElement("button");
		btn.type = "button";
		btn.className = "relatives_tree_panel_close";
		btn.textContent = "×";
		btn.addEventListener("click", function () {
			panel.style.display = "none";
		});
		return btn;
	}

	// --- Panel: Add / Edit (gefuehrtes Klick-Add-Interface) -----------------------------

	function buildAttributeFieldset(selectedOptionIds) {
		var wrap = document.createElement("div");
		attributeGroups.forEach(function (group) {
			var fs = document.createElement("fieldset");
			var legend = document.createElement("legend");
			legend.textContent = group.group.label;
			fs.appendChild(legend);
			(group.options || []).forEach(function (opt) {
				var label = document.createElement("label");
				label.style.display = "inline-block";
				label.style.marginRight = "8px";
				var cb = document.createElement("input");
				cb.type = "checkbox";
				cb.name = "attribute_option_ids[]";
				cb.value = opt.id;
				if (selectedOptionIds && selectedOptionIds.indexOf(opt.id) !== -1) {
					cb.checked = true;
				}
				label.appendChild(cb);
				label.appendChild(document.createTextNode(" " + opt.label));
				fs.appendChild(label);
			});
			wrap.appendChild(fs);
		});
		return wrap;
	}

	function rebuildRelationshipOptions(select, genderValue, selectedTypeId) {
		var labelGender = mapGender(genderValue);
		select.innerHTML = "";
		var otherOpt = document.createElement("option");
		otherOpt.value = "";
		otherOpt.textContent = t("relationship_other", "Sonstige Verbindung");
		select.appendChild(otherOpt);
		relationshipTypes.forEach(function (rtype) {
			if (rtype.gender !== labelGender) { return; }
			var opt = document.createElement("option");
			opt.value = rtype.id;
			opt.textContent = rtype.label;
			select.appendChild(opt);
		});
		if (selectedTypeId) { select.value = String(selectedTypeId); }
	}

	function openEditPanel(node) {
		openPersonForm(node, null);
	}

	function openPersonForm(node, relatedToNode) {
		if (!panel) { return; }
		panel.innerHTML = "";
		panel.appendChild(closeButton());

		var isEdit = !!node;
		var h = document.createElement("h3");
		h.textContent = isEdit ? t("edit_person", "Person bearbeiten") : t("add_person", "Person hinzufuegen");
		panel.appendChild(h);

		if (!isEdit && relatedToNode) {
			var hint = document.createElement("p");
			hint.className = "relatives_hint";
			hint.textContent = t("add_relative_of", "Verwandte/r von") + " " + relatedToNode.name;
			panel.appendChild(hint);

			var hintDetail = document.createElement("p");
			hintDetail.className = "relatives_hint";
			hintDetail.style.fontSize = "0.85em";
			hintDetail.style.opacity = "0.8";
			hintDetail.textContent = t("add_relative_of_note", "Der Verwandtschaftsgrad wird aus deiner eigenen Sicht gewaehlt, nicht aus Sicht von %s.").replace("%s", relatedToNode.name);
			panel.appendChild(hintDetail);
		}

		var form = document.createElement("form");

		var nameInput = labeledInput(form, "text", t("field_name", "Name"), node ? node.name : "");
		var genderSelect = labeledSelect(form, t("field_gender", "Geschlecht"), [
			["-1", t("gender_any", "egal")],
			["0", t("gender_male", "maennlich")],
			["1", t("gender_female", "weiblich")],
			["2", t("gender_diverse", "divers")],
		], node ? String(node.gender) : "-1");

		var relWrap = document.createElement("div");
		relWrap.className = "relatives_field";
		var relLabel = document.createElement("label");
		relLabel.textContent = t("field_relationship", "Verwandtschaftsgrad");
		relWrap.appendChild(relLabel);
		var relSelect = document.createElement("select");
		relWrap.appendChild(relSelect);
		form.appendChild(relWrap);

		var customLabelInput = labeledInput(form, "text", t("field_relationship_custom", "Freie Bezeichnung"),
			node ? (node.relationship_custom_label || "") : "");

		rebuildRelationshipOptions(relSelect, genderSelect.value, node ? node.relationship_type_id : null);
		genderSelect.addEventListener("change", function () {
			rebuildRelationshipOptions(relSelect, genderSelect.value, null);
		});

		var ageInput = labeledInput(form, "number", t("field_age", "Alter"), node && node.age !== null ? node.age : "");
		var originInput = labeledInput(form, "text", t("field_origin", "Herkunft"), node ? (node.origin || "") : "");
		var descTextarea = labeledTextarea(form, t("field_description", "Beschreibung"), node ? (node.description || "") : "");

		var attrLabel = document.createElement("label");
		attrLabel.textContent = t("field_attributes", "Attribute");
		form.appendChild(attrLabel);
		form.appendChild(buildAttributeFieldset([]));

		var submit = document.createElement("input");
		submit.type = "submit";
		submit.className = "relatives_button";
		submit.value = t("save", "Speichern");
		form.appendChild(submit);

		form.addEventListener("submit", function (ev) {
			ev.preventDefault();
			var payload = new FormData();
			payload.append("my_post_key", config.postCode || "");
			payload.append("ajax", "1");
			payload.append("name", nameInput.value);
			payload.append("gender", genderSelect.value);
			payload.append("relationship_type_id", relSelect.value);
			payload.append("relationship_custom_label", customLabelInput.value);
			payload.append("age", ageInput.value);
			payload.append("origin", originInput.value);
			payload.append("description", descTextarea.value);
			if (!isEdit && relatedToNode) {
				payload.append("related_to_person_id", relatedToNode.id);
			}

			var checked = form.querySelectorAll("input[name='attribute_option_ids[]']:checked");
			for (var i = 0; i < checked.length; i++) {
				payload.append("attribute_option_ids[]", checked[i].value);
			}

			var url = isEdit
				? "relatives.php?action=edit&id=" + encodeURIComponent(node.id)
				: "relatives.php?action=add";

			fetch(url, { method: "POST", body: payload, credentials: "same-origin" })
				.then(function (resp) { return resp.json(); })
				.then(function (data) {
					if (data && data.success) {
						panel.style.display = "none";
						reloadTree();
					} else {
						alert((data && data.error) || t("save_failed", "Speichern fehlgeschlagen."));
					}
				})
				.catch(function () {
					alert(t("save_failed", "Speichern fehlgeschlagen."));
				});
		});

		panel.appendChild(form);
		panel.style.display = "block";
	}

	function labeledInput(form, type, labelText, value) {
		var wrap = document.createElement("div");
		wrap.className = "relatives_field";
		var label = document.createElement("label");
		label.textContent = labelText;
		wrap.appendChild(label);
		var input = document.createElement("input");
		input.type = type;
		input.value = value === null || value === undefined ? "" : value;
		wrap.appendChild(input);
		form.appendChild(wrap);
		return input;
	}

	function labeledTextarea(form, labelText, value) {
		var wrap = document.createElement("div");
		wrap.className = "relatives_field";
		var label = document.createElement("label");
		label.textContent = labelText;
		wrap.appendChild(label);
		var textarea = document.createElement("textarea");
		textarea.rows = 4;
		textarea.value = value || "";
		wrap.appendChild(textarea);
		form.appendChild(wrap);
		return textarea;
	}

	function labeledSelect(form, labelText, options, selectedValue) {
		var wrap = document.createElement("div");
		wrap.className = "relatives_field";
		var label = document.createElement("label");
		label.textContent = labelText;
		wrap.appendChild(label);
		var select = document.createElement("select");
		options.forEach(function (pair) {
			var opt = document.createElement("option");
			opt.value = pair[0];
			opt.textContent = pair[1];
			select.appendChild(opt);
		});
		select.value = selectedValue;
		wrap.appendChild(select);
		form.appendChild(wrap);
		return select;
	}

	function reloadTree() {
		var url = "relatives.php?action=tree&ajax=1&uid=" + encodeURIComponent(config.rootUid);
		fetch(url, { credentials: "same-origin" })
			.then(function (resp) { return resp.json(); })
			.then(function (data) {
				if (!data) { return; }
				treeData = data;
				state.nodesByKey = {};
				(treeData.nodes || []).forEach(function (node) {
					state.nodesByKey[node.key] = node;
				});
				state.edges = treeData.edges || [];
				render();
			});
	}

	bindToolbar();
	render();
})();
