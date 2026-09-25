/**
 * relatives-tree.js - Verwandtschaften & Stammbaum
 *
 * Vanilla-JS-Renderer fuer den interaktiven Stammbaum: Pan/Zoom, Ein-/Ausklappen von
 * Aesten, Klick-Interaktionen (eigener Knoten -> Inline-Edit, leerer Slot -> gefuehrtes
 * Klick-Add-Interface, verlinkter bestaetigter fremder Knoten -> Navigation). Keine
 * externen Libraries (Projektregel).
 *
 * Die Verbindungslinien folgen der Grammatik einer klassischen Ahnentafel und werden
 * als ein einziges SVG gezeichnet (rechtwinklig, pixelgenau, auch beim Zoomen scharf):
 *   - Partner:      waagerechte Linie zwischen den beiden Kaesten
 *   - Eltern/Kind:  ein Abgang aus der Paarmitte (bzw. aus der Unterkante eines
 *                   einzelnen Elternteils) auf einen gemeinsamen Geschwisterbalken,
 *                   von dort je eine Stichleitung nach unten zu jedem Kind
 *   - Geschwister ohne bekannte Eltern: nur der Geschwisterbalken
 *   - sonstige/Account-Verbindungen: dieselbe rechtwinklige Fuehrung, aber gestrichelt
 * Grundlage sind die vom Server gelieferten Felder families/partners/links (siehe
 * Relatives_TreeBuilder) -- edges bleibt die Anlege-Struktur fuer das Ein-/Ausklappen.
 */
(function () {
	"use strict";

	var SVG_NS = "http://www.w3.org/2000/svg";

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

	// Muessen zu den Kastenmassen in relatives-tree.css passen -- die Konnektoren docken
	// rechnerisch an den Kastenraendern an, nicht per DOM-Messung (das waere pro Render
	// ein Reflow je Knoten).
	var NODE_WIDTH = 170;
	var NODE_HEIGHT = 58;
	var H_SPACING = 188;
	var V_SPACING = 140;
	var HALF_W = NODE_WIDTH / 2;
	var HALF_H = NODE_HEIGHT / 2;
	var BUS_OFFSET = 32;   // Hoehe des Geschwisterbalkens ueber der Kastenoberkante
	var BUS_LANE_STEP = 12; // Versatz je Hoehenstufe, wenn sich Balken einer Zeile ueberschneiden
	var BUS_LANE_GAP = 24;  // Mindestabstand, ab dem zwei Balken dieselbe Hoehe teilen duerfen
	var LOOSE_LANE = 26;   // Versatz loser Verbindungen, damit sie nicht auf einem Balken liegen
	var SAME_ROW_LANE = 14; // Hoehe loser Verbindungen innerhalb einer Zeile ueber der Kastenoberkante
	var MIN_ZOOM = 0.3;
	var MAX_ZOOM = 2;

	var viewport = document.getElementById("relatives-tree-viewport");
	var canvas = document.getElementById("relatives-tree-canvas");
	var panel = document.getElementById("relatives-tree-panel");
	var backdrop = document.getElementById("relatives-tree-backdrop");

	if (!viewport || !canvas) { return; }

	var connectorLayer = document.createElementNS(SVG_NS, "svg");
	connectorLayer.setAttribute("class", "relatives_tree_connectors");
	connectorLayer.setAttribute("shape-rendering", "crispEdges");

	var nodeLayer = document.createElement("div");
	nodeLayer.className = "relatives_tree_nodes";

	var state = {
		nodesByKey: {},
		edges: treeData.edges || [],
		collapsed: {},
		// Personen-ids aufgeklappter Randknoten -- der Server liefert deren Umgebung mit.
		expand: (config.expand || []).slice(),
		pan: { x: 0, y: 0 },
		zoom: 1,
	};

	function indexNodes() {
		state.nodesByKey = {};
		(treeData.nodes || []).forEach(function (node) {
			// Popup eines fremden Baums: nur ansehen. Ohne can_edit fuehren alle Klickwege
			// (Aktionsmenue, Platzhalter-Eintragen) ins Nur-Lesen-Panel.
			if (config.readOnly) { node.can_edit = false; }
			state.nodesByKey[node.key] = node;
		});
		state.edges = treeData.edges || [];
	}
	indexNodes();

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

	// --- Geometrie ---------------------------------------------------------------------

	function nodePixel(node) {
		return { x: node.x * H_SPACING, y: node.y * V_SPACING };
	}

	function pixelOf(key) {
		var node = state.nodesByKey[key];
		return node ? nodePixel(node) : null;
	}

	// Ursprung der SVG-Flaeche (wird vor jedem Zeichnen aus den Baumgrenzen gesetzt) --
	// Konnektor-Koordinaten sind relativ zur linken oberen Ecke des SVG.
	var connectorOrigin = { x: 0, y: 0 };

	function cx(value) {
		// Halbe Pixel: sonst liegt eine 1px-Linie auf der Pixelgrenze und wird unscharf.
		return Math.round(value - connectorOrigin.x) + 0.5;
	}

	function cy(value) {
		return Math.round(value - connectorOrigin.y) + 0.5;
	}

	// --- Verbindungslinien --------------------------------------------------------------

	function buildConnectorPaths(visible) {
		var paths = [];

		// meta: { style: "solid"|"dashed"|"dotted"|"dashdot", secret: bool, keys: [nodeKey, ...] }
		// keys sind die beteiligten Knoten -- daran haengt die Hervorhebung beim
		// Ueberfahren eines Kastens ("welche Linien gehoeren zu Oliver?").
		function add(d, kind, meta) {
			if (!d) { return; }
			meta = meta || {};
			paths.push({
				d: d,
				kind: kind,
				style: meta.style || "solid",
				secret: !!meta.secret,
				keys: meta.keys || []
			});
		}

		// Partnerlinie: waagerecht zwischen den beiden Kastenraendern. Die Linienart
		// kommt aus dem Verwandtschaftsgrad -- aus "Ehefrau" wird "Ex-Frau" und die
		// durchgezogene Linie damit automatisch eine gestrichelte.
		(treeData.partners || []).forEach(function (pair) {
			if (!visible[pair.a] || !visible[pair.b]) { return; }
			var a = pixelOf(pair.a);
			var b = pixelOf(pair.b);
			if (!a || !b) { return; }
			var meta = { style: pair.style, secret: pair.secret, keys: [pair.a, pair.b] };
			if (a.y !== b.y) { add(elbowPath(a, b, LOOSE_LANE), "loose", meta); return; }
			var left = Math.min(a.x, b.x) + HALF_W;
			var right = Math.max(a.x, b.x) - HALF_W;
			if (right - left > 1) {
				add("M" + cx(left) + " " + cy(a.y) + "H" + cx(right), "partner", meta);
			}
		});

		// Familien: Abgang aus der Paarmitte -> Geschwisterbalken -> Stichleitungen.
		// Erst alle Balken sammeln, dann Hoehen vergeben: zwei Familien derselben Zeile,
		// deren Balken sich waagerecht ueberschneiden, duerfen nie auf derselben Hoehe
		// liegen -- sonst sieht es aus, als waeren alle Kaesten Geschwister.
		var buses = [];
		var busByParents = {};
		// Primaerfamilien zuerst: eine Zweitfamilie mit denselben Eltern (Adoptivkind neben
		// den leiblichen Kindern der Adoptiveltern) haengt sich an deren Balken an.
		var orderedFamilies = (treeData.families || []).filter(function (family) { return family.primary !== false; })
			.concat((treeData.families || []).filter(function (family) { return family.primary === false; }));
		orderedFamilies.forEach(function (family) {
			var parents = (family.parents || []).filter(function (key) { return visible[key] && pixelOf(key); });
			// Adoptiv-/Zweitfamilien werden andersfarbig gezeichnet, damit die
			// leibliche Linie erkennbar bleibt.
			var familyKind = family.primary === false ? "family_secondary" : "family";
			var rows = {};
			var rowKeys = {};
			// Heimliche Kinder in einer sonst offenen Familie: nur ihre Stichleitung ist geheim.
			var secretChildren = family.secret_children || [];
			var secretXs = {};
			(family.children || []).forEach(function (key) {
				if (!visible[key]) { return; }
				var point = pixelOf(key);
				if (!point) { return; }
				(rows[point.y] = rows[point.y] || []).push(point.x);
				(rowKeys[point.y] = rowKeys[point.y] || []).push(key);
				if (secretChildren.indexOf(key) !== -1) {
					(secretXs[point.y] = secretXs[point.y] || []).push(point.x);
				}
			});

			// Neutraler Platzhalter ("Grosselternteil von Jolina"): die Stichleitung endet
			// auf der Paarlinie eines Elternpaars -- eine*r von beiden ist Kind dieser Familie.
			var eitherStubs = {};
			(family.either || []).forEach(function (group) {
				var points = group.map(function (key) { return visible[key] ? pixelOf(key) : null; });
				if (!points.length || points.some(function (p) { return !p; })) { return; }
				if (points.some(function (p) { return p.y !== points[0].y; })) { return; }
				var xs = points.map(function (p) { return p.x; });
				var midX = (Math.min.apply(null, xs) + Math.max.apply(null, xs)) / 2;
				var y = points[0].y;
				(rows[y] = rows[y] || []).push(midX);
				rowKeys[y] = (rowKeys[y] || []).concat(group);
				(eitherStubs[y] = eitherStubs[y] || []).push(midX);
			});

			Object.keys(rows).forEach(function (rowKey) {
				var childXs = rows[rowKey].sort(function (a, b) { return a - b; });
				var childY = parseFloat(rowKey);
				var childTop = childY - HALF_H;
				var meta = {
					style: family.style,
					secret: family.secret,
					keys: parents.concat(rowKeys[rowKey] || [])
				};


				var originX = null;
				var originY = null;
				if (parents.length === 1) {
					var single = pixelOf(parents[0]);
					originX = single.x;
					originY = single.y + HALF_H;
				} else if (parents.length > 1) {
					var xs = parents.map(function (key) { return pixelOf(key).x; });
					var ys = parents.map(function (key) { return pixelOf(key).y; });
					// Mitte der Paarlinie: der Abgang laeuft durch die Luecke zwischen
					// den Elternkaesten nach unten, nicht durch einen der Kaesten.
					originX = (Math.min.apply(null, xs) + Math.max.apply(null, xs)) / 2;
					originY = Math.max.apply(null, ys);
				}

				if (originY !== null && originY >= childTop - 4) {
					// Eltern stehen (durch abweichende generation_offsets) nicht wirklich
					// oberhalb: dann lieber gar keinen Abgang zeichnen als eine Linie,
					// die nach oben laeuft.
					originX = null;
					originY = null;
				}

				// Kein sichtbarer Elternteil und nur ein sichtbares Kind: es gibt nichts
				// zu verbinden -- sonst bliebe ein Linienstummel ueber dem Kasten stehen
				// (passiert, sobald ein Ast eingeklappt ist).
				if (originX === null && childXs.length < 2) { return; }

				var eitherRow = eitherStubs[rowKey] || [];
				var secretRow = secretXs[rowKey] || [];
				var secretMeta = { style: meta.style, secret: true, keys: meta.keys };
				var stubs = childXs.map(function (x) {
					return {
						x: x, kind: familyKind,
						meta: secretRow.indexOf(x) !== -1 ? secretMeta : meta,
						bottom: eitherRow.indexOf(x) !== -1 ? childY : childTop
					};
				});

				// Dieselben Eltern wie ein bereits gesammelter Balken dieser Zeile: das
				// Adoptivkind haengt am Balken der leiblichen Kinder, nur sein eigenes Stueck
				// ist andersartig. So liest man "Kind dieser Eltern, aber adoptiert" direkt ab,
				// statt zweier Abgaenge aus derselben Paarmitte.
				var parentsKey = originX !== null ? parents.slice().sort().join("|") + "@" + rowKey : null;
				var shared = parentsKey !== null ? busByParents[parentsKey] : null;
				if (shared && familyKind === "family_secondary") {
					stubs.forEach(function (stub) {
						shared.stubs.push(stub);
						shared.left = Math.min(shared.left, stub.x);
						shared.right = Math.max(shared.right, stub.x);
					});
					return;
				}

				var busLeft = childXs[0];
				var busRight = childXs[childXs.length - 1];
				if (originX !== null) {
					busLeft = Math.min(busLeft, originX);
					busRight = Math.max(busRight, originX);
				}
				var bus = {
					row: childY, childTop: childTop, stubs: stubs,
					originX: originX, originY: originY,
					left: busLeft, right: busRight,
					// Eigene Spanne der Familie; was eine angehaengte Zweitfamilie darueber
					// hinaus braucht, wird in deren Linienart verlaengert.
					coreLeft: busLeft, coreRight: busRight,
					lane: 0, kind: familyKind, meta: meta,
				};
				buses.push(bus);
				if (parentsKey !== null && !busByParents[parentsKey]) { busByParents[parentsKey] = bus; }
			});
		});

		assignBusLanes(buses);

		buses.forEach(function (bus) {
			// Einzelkind genau unter der Paarmitte: eine durchgehende Linie statt
			// eines Balkens mit Laenge null.
			if (isStraightBus(bus)) {
				add("M" + cx(bus.originX) + " " + cy(bus.originY) + "V" + cy(bus.stubs[0].bottom), bus.stubs[0].kind, bus.stubs[0].meta);
				return;
			}

			var laneY = bus.childTop - BUS_OFFSET - bus.lane * BUS_LANE_STEP;
			var busY = bus.originY === null ? laneY : Math.max(bus.originY + 8, laneY);

			if (bus.originX !== null) {
				add("M" + cx(bus.originX) + " " + cy(bus.originY) + "V" + cy(busY), bus.kind, bus.meta);
			}
			if (bus.coreRight - bus.coreLeft > 1) {
				add("M" + cx(bus.coreLeft) + " " + cy(busY) + "H" + cx(bus.coreRight), bus.kind, bus.meta);
			}
			bus.stubs.forEach(function (stub) {
				if (stub.x < bus.coreLeft - 1) {
					add("M" + cx(stub.x) + " " + cy(busY) + "H" + cx(bus.coreLeft), stub.kind, stub.meta);
				} else if (stub.x > bus.coreRight + 1) {
					add("M" + cx(bus.coreRight) + " " + cy(busY) + "H" + cx(stub.x), stub.kind, stub.meta);
				}
				add("M" + cx(stub.x) + " " + cy(busY) + "V" + cy(stub.bottom), stub.kind, stub.meta);
			});
		});

		// Sonstige/Account-Verbindungen: gleiche rechtwinklige Fuehrung, gestrichelt.
		(treeData.links || []).forEach(function (link) {
			if (!visible[link.from] || !visible[link.to]) { return; }
			var from = pixelOf(link.from);
			var to = pixelOf(link.to);
			if (!from || !to) { return; }
			add(elbowPath(from, to, LOOSE_LANE), link.kind === "account" ? "account" : "loose", {
				style: link.style, secret: link.secret, keys: [link.from, link.to]
			});
		});

		return paths;
	}

	/**
	 * Vergibt je Zeile Hoehenstufen fuer die Geschwisterbalken (Intervall-Faerbung, von
	 * links nach rechts): ein Balken bekommt die niedrigste Stufe, deren letzter Balken
	 * links genug Abstand laesst. Bei einem sauberen Layout bleibt alles auf Stufe 0 --
	 * das ist nur das Sicherheitsnetz fuer verschraenkte Familien.
	 */
	function isStraightBus(bus) {
		return bus.originX !== null && bus.stubs.length === 1 && Math.abs(bus.stubs[0].x - bus.originX) < 1;
	}

	function assignBusLanes(buses) {
		var rows = {};
		buses.forEach(function (bus) {
			// Eine gerade Linie ohne Balken belegt keine Hoehenstufe.
			if (isStraightBus(bus)) { return; }
			(rows[bus.row] = rows[bus.row] || []).push(bus);
		});
		Object.keys(rows).forEach(function (rowKey) {
			var laneEnds = [];
			rows[rowKey]
				.sort(function (a, b) { return a.left - b.left; })
				.forEach(function (bus) {
					var lane = 0;
					while (lane < laneEnds.length && laneEnds[lane] >= bus.left - BUS_LANE_GAP) { lane++; }
					bus.lane = lane;
					laneEnds[lane] = bus.right;
				});
		});
	}

	/**
	 * Rechtwinklige Verbindung zwischen zwei Kaesten (waagerecht bzw. runter-quer-runter).
	 * $lane verschiebt die Querachse, damit lose Verbindungen nicht genau auf einem
	 * Geschwisterbalken zu liegen kommen und mit ihm verwechselt werden.
	 */
	function elbowPath(a, b, lane) {
		lane = lane || 0;
		if (a.y === b.y) {
			// Gleiche Zeile: ueber den Kaesten fuehren (zwischen Kastenoberkante und
			// Geschwisterbalken) statt auf Kastenhoehe quer durch alle Kaesten dazwischen.
			if (Math.abs(a.x - b.x) <= 1) { return null; }
			var laneY = a.y - HALF_H - SAME_ROW_LANE;
			return "M" + cx(a.x) + " " + cy(a.y - HALF_H)
				+ "V" + cy(laneY)
				+ "H" + cx(b.x)
				+ "V" + cy(b.y - HALF_H);
		}
		var top = a.y < b.y ? a : b;
		var bottom = a.y < b.y ? b : a;
		var midY = (top.y + HALF_H + bottom.y - HALF_H) / 2 + lane;
		return "M" + cx(top.x) + " " + cy(top.y + HALF_H)
			+ "V" + cy(midY)
			+ "H" + cx(bottom.x)
			+ "V" + cy(bottom.y - HALF_H);
	}

	// --- Rendering ---------------------------------------------------------------------

	function render() {
		var visible = visibleKeys();
		var visibleList = Object.keys(visible).filter(function (key) { return state.nodesByKey[key]; });

		nodeLayer.innerHTML = "";
		while (connectorLayer.firstChild) { connectorLayer.removeChild(connectorLayer.firstChild); }

		if (!visibleList.length) {
			applyTransform();
			return;
		}

		// SVG-Flaeche auf die Ausdehnung des Baums legen (Koordinaten im SVG sind
		// relativ zu dessen linker oberer Ecke, deshalb der Offset).
		var bounds = { minX: Infinity, maxX: -Infinity, minY: Infinity, maxY: -Infinity };
		visibleList.forEach(function (key) {
			var point = nodePixel(state.nodesByKey[key]);
			bounds.minX = Math.min(bounds.minX, point.x - HALF_W);
			bounds.maxX = Math.max(bounds.maxX, point.x + HALF_W);
			bounds.minY = Math.min(bounds.minY, point.y - HALF_H);
			bounds.maxY = Math.max(bounds.maxY, point.y + HALF_H);
		});
		var margin = BUS_OFFSET + 10;
		var width = Math.ceil((bounds.maxX - bounds.minX) + margin * 2);
		var height = Math.ceil((bounds.maxY - bounds.minY) + margin * 2);

		connectorOrigin = { x: bounds.minX - margin, y: bounds.minY - margin };
		connectorLayer.setAttribute("width", width);
		connectorLayer.setAttribute("height", height);
		connectorLayer.setAttribute("viewBox", "0 0 " + width + " " + height);
		connectorLayer.style.left = connectorOrigin.x + "px";
		connectorLayer.style.top = connectorOrigin.y + "px";

		// Eine <path> je Verbindung statt einer gesammelten pro Linienart: nur so
		// laesst sich beim Ueberfahren eines Kastens genau dessen Verbindungen
		// hervorheben. Baeume dieser Groessenordnung vertragen das problemlos.
		buildConnectorPaths(visible).forEach(function (item) {
			var path = document.createElementNS(SVG_NS, "path");
			var classes = "relatives_tree_connector relatives_tree_connector_" + item.kind
				+ " is-style-" + item.style;
			if (item.secret) { classes += " is-secret"; }
			path.setAttribute("class", classes);
			path.setAttribute("d", item.d);
			if (item.keys.length) { path.setAttribute("data-keys", item.keys.join(" ")); }
			connectorLayer.appendChild(path);
		});

		visibleList.forEach(function (key) {
			nodeLayer.appendChild(buildNode(state.nodesByKey[key]));
		});

		renderEmptyState();
		applyTransform();
	}

	// A tree that only holds the root card gives no clue what to do next: say it, and
	// on your own tree point at your card (it opens the "add person" menu).
	var emptyNotice = null;
	function renderEmptyState() {
		var onlyRoot = !(treeData.nodes || []).some(function (n) { return n.type !== "self"; });
		var own = !config.readOnly && config.viewerUid > 0 && config.rootUid === config.viewerUid;
		if (!onlyRoot) {
			if (emptyNotice) { emptyNotice.style.display = "none"; }
			return;
		}
		if (!emptyNotice) {
			emptyNotice = document.createElement("div");
			emptyNotice.className = "relatives_tree_empty";
			emptyNotice.setAttribute("role", "status");
			viewport.appendChild(emptyNotice);
		}
		emptyNotice.textContent = own
			? t("empty_own", "Dein Stammbaum ist noch leer. Klick auf deinen Kasten, um die erste Person hinzuzufügen.")
			: t("empty_other", "In diesem Stammbaum ist noch niemand eingetragen.");
		emptyNotice.style.display = "";
		if (own) {
			var selfEl = nodeLayer.querySelector(".relatives-node-self");
			if (selfEl) { selfEl.classList.add("relatives-node-pulse"); }
		}
	}

	function applyTransform() {
		canvas.style.transform = "translate(" + state.pan.x + "px," + state.pan.y + "px) scale(" + state.zoom + ")";
	}

	function relationshipLabel(node) {
		// Der serverseitig berechnete Wert ist bereits aus Sicht des Baum-Besitzers
		// gerechnet UND begrenzt ("entfernte*r Verwandte*r" statt einer erfundenen
		// Bezeichnung) -- er hat deshalb Vorrang.
		if (node.computed_label) { return node.computed_label; }
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

	function badge(text, modifier) {
		var el = document.createElement("span");
		el.className = "relatives_tree_node_badge relatives_tree_node_badge_" + modifier;
		el.textContent = text;
		return el;
	}

	function buildNode(node) {
		var point = nodePixel(node);
		var el = document.createElement("div");
		el.className = "relatives_tree_node";
		el.setAttribute("data-key", node.key);
		el.style.left = point.x + "px";
		el.style.top = point.y + "px";
		el.setAttribute("role", "button");
		el.setAttribute("tabindex", "0");

		var body = document.createElement("div");
		body.className = "relatives_tree_node_body";
		el.appendChild(body);

		var nameEl = document.createElement("span");
		nameEl.className = "relatives_tree_node_name";
		body.appendChild(nameEl);

		var action;

		if (node.type === "family") {
			// Familien-Kasten: steht an der Stelle der Eltern und fasst eine
			// Geschwistergruppe zusammen, ohne dass einzelne Vorfahren noetig waeren.
			el.classList.add("relatives_tree_node_family");
			if (node.color) { el.style.setProperty("--relatives-family-color", node.color); }
			nameEl.textContent = node.name;

			var familyRole = document.createElement("span");
			familyRole.className = "relatives_tree_node_relationship";
			familyRole.textContent = t("node_family", "Familie");
			body.appendChild(familyRole);

			action = function () { openFamilyPanel(node); };
		} else if (node.type === "placeholder") {
			// Nicht eingetragene Zwischenperson (nur Anzeige): ueber sie laeuft die Verbindung
			// zu Tante, Cousine oder Schwiegereltern, statt eines Direktstrichs.
			el.classList.add("relatives_tree_node_placeholder");
			nameEl.textContent = placeholderLabel(node);
			el.title = nameEl.textContent;

			var placeholderRole = document.createElement("span");
			placeholderRole.className = "relatives_tree_node_relationship";
			placeholderRole.textContent = t("placeholder_badge", "Platzhalter");
			body.appendChild(placeholderRole);

			action = function () { openPlaceholderPanel(node); };
		} else if (node.type === "self") {
			// Wurzel: der Charakter, dessen Baum gezeigt wird (selbst ein Knoten im Geflecht).
			el.classList.add("relatives-node-account");
			el.classList.add("relatives-node-self");
			nameEl.textContent = node.username || node.name || ("#" + node.uid);

			var roleEl = document.createElement("span");
			roleEl.className = "relatives_tree_node_relationship";
			roleEl.textContent = node.uid === config.viewerUid
				? t("node_you", "Du")
				: t("node_character", "Charakter");
			body.appendChild(roleEl);

			action = function () {
				if (node.can_edit) {
					openActionMenu(node);
				} else {
					openReadOnlyPanel(node);
				}
			};
		} else {
			var gender = mapGender(node.gender);
			el.classList.add(gender === 0 ? "relatives-gender-male" : (gender === 1 ? "relatives-gender-female" : "relatives-gender-diverse"));
			if (node.is_wanted) { el.classList.add("relatives-node-wanted"); }
			if (node.link_status === 2) { el.classList.add("relatives-node-linked-confirmed"); }
			if (node.link_status === 1) { el.classList.add("relatives-node-linked-pending"); }
			if (node.can_edit) { el.classList.add("relatives-node-own"); }
			if (node.is_deceased) { el.classList.add("relatives-node-deceased"); }

			nameEl.textContent = node.name;

			var relEl = document.createElement("span");
			relEl.className = "relatives_tree_node_relationship";
			relEl.textContent = relationshipLabel(node);
			if (node.is_deceased && node.death_note) {
				relEl.textContent += " · † " + node.death_note;
			}
			body.appendChild(relEl);

			var badges = document.createElement("div");
			badges.className = "relatives_tree_node_badges";
			if (node.is_deceased) { badges.appendChild(badge("†", "deceased")); }
			if (node.is_wanted) { badges.appendChild(badge(t("badge_wanted", "Gesucht"), "wanted")); }
			if (node.link_status === 2) { badges.appendChild(badge(t("badge_linked", "Verknüpft"), "linked")); }
			if (node.link_status === 1) { badges.appendChild(badge(t("badge_link_pending", "Angefragt"), "pending")); }
			if (badges.childNodes.length) { el.appendChild(badges); }

			action = function () {
				if (node.can_edit) {
					openActionMenu(node);
				} else if (node.link_status === 2 && node.linked_uid) {
					// Gespielter Charakter: sein eigener Stammbaum.
					window.location.href = "relatives.php?action=tree&uid=" + encodeURIComponent(node.linked_uid)
						+ (config.popup ? "&popup=1" : "");
				} else {
					openReadOnlyPanel(node);
				}
			};
		}

		// Randknoten: hinter ihm geht das gemeinsame Geflecht weiter.
		if (node.has_more && node.person_id && state.expand.indexOf(node.person_id) === -1) {
			var more = document.createElement("button");
			more.type = "button";
			more.className = "relatives_tree_node_more";
			more.title = t("show_more", "Weitere Verwandte anzeigen");
			more.setAttribute("aria-label", more.title);
			more.textContent = "…";
			more.addEventListener("click", function (ev) {
				ev.stopPropagation();
				state.expand.push(node.person_id);
				reloadTree();
			});
			el.appendChild(more);
		}

		// Ein-/Ausklappen nur dort, wo unterhalb ueberhaupt etwas haengt.
		if (childrenOf(node.key).length) {
			var toggle = document.createElement("button");
			toggle.type = "button";
			toggle.className = "relatives_tree_node_toggle";
			toggle.setAttribute("aria-label", t("toggle_branch", "Ast ein-/ausklappen"));
			toggle.textContent = state.collapsed[node.key] ? "+" : "−";
			toggle.addEventListener("click", function (ev) {
				ev.stopPropagation();
				state.collapsed[node.key] = !state.collapsed[node.key];
				render();
			});
			el.appendChild(toggle);
			if (state.collapsed[node.key]) { el.classList.add("relatives-node-collapsed"); }
		}

		// Ueberfahren/Fokussieren hebt genau die Verbindungen dieses Kastens hervor --
		// das ist die eigentliche Antwort auf "woran sehe ich, dass Oliver der Bruder
		// von Delia und der Vater von Ethan ist".
		el.addEventListener("mouseenter", function () { highlightNode(node.key); });
		el.addEventListener("mouseleave", clearHighlight);
		el.addEventListener("focus", function () { highlightNode(node.key); });
		el.addEventListener("blur", clearHighlight);

		el.addEventListener("click", function () {
			if (suppressClick) { return; }
			action();
		});
		el.addEventListener("keydown", function (ev) {
			if (ev.key === "Enter" || ev.key === " ") {
				ev.preventDefault();
				action();
			}
		});

		return el;
	}

	// --- Pan & Zoom --------------------------------------------------------------------

	var dragging = false;
	var suppressClick = false;
	var dragStart = { x: 0, y: 0 };
	var panStart = { x: 0, y: 0 };

	function viewportCenterOffset(clientX, clientY) {
		var rect = viewport.getBoundingClientRect();
		return { x: clientX - rect.left - rect.width / 2, y: clientY - rect.top - rect.height / 2 };
	}

	// Pointer- statt Maus-Events: deckt Maus, Touch und Stift mit einem Codepfad ab.
	// Bewusst ohne setPointerCapture -- die Kaesten muessen ihr click-Event behalten;
	// ein versehentliches Oeffnen nach dem Ziehen verhindert stattdessen suppressClick.
	// Two fingers = pinch zoom around their midpoint. Active pointers are tracked by id;
	// while a pinch runs, one-finger panning is paused so the view does not jump.
	var pointers = {};
	var pinch = null;

	function pointerCount() { return Object.keys(pointers).length; }

	function pinchMetrics() {
		var ids = Object.keys(pointers);
		var a = pointers[ids[0]];
		var b = pointers[ids[1]];
		return {
			dist: Math.max(1, Math.hypot(a.x - b.x, a.y - b.y)),
			mid: { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }
		};
	}

	viewport.addEventListener("pointerdown", function (ev) {
		if (ev.pointerType === "mouse" && ev.button !== 0) { return; }
		if (ev.target.closest && ev.target.closest(".relatives_tree_node_toggle")) { return; }
		if (ev.pointerType !== "mouse") {
			pointers[ev.pointerId] = { x: ev.clientX, y: ev.clientY };
			if (pointerCount() === 2) {
				var start = pinchMetrics();
				pinch = { dist: start.dist, zoom: state.zoom };
				dragging = false;
				suppressClick = true;
				return;
			}
			if (pointerCount() > 2) { return; }
		}
		dragging = true;
		suppressClick = false;
		viewport.classList.add("relatives-dragging");
		dragStart = { x: ev.clientX, y: ev.clientY };
		panStart = { x: state.pan.x, y: state.pan.y };
	});

	window.addEventListener("pointermove", function (ev) {
		if (pointers[ev.pointerId]) {
			pointers[ev.pointerId] = { x: ev.clientX, y: ev.clientY };
		}
		if (pinch && pointerCount() === 2) {
			var now = pinchMetrics();
			zoomTo(pinch.zoom * (now.dist / pinch.dist), viewportCenterOffset(now.mid.x, now.mid.y));
			return;
		}
		if (!dragging) { return; }
		var dx = ev.clientX - dragStart.x;
		var dy = ev.clientY - dragStart.y;
		if (!suppressClick && (Math.abs(dx) > 4 || Math.abs(dy) > 4)) { suppressClick = true; }
		if (!suppressClick) { return; }
		state.pan.x = panStart.x + dx;
		state.pan.y = panStart.y + dy;
		applyTransform();
	});

	function endDrag() {
		if (!dragging) { return; }
		dragging = false;
		viewport.classList.remove("relatives-dragging");
		// Erst im naechsten Tick freigeben: das abschliessende click-Event eines
		// Zieh-Vorgangs wuerde sonst noch das Aktionsmenue oeffnen.
		window.setTimeout(function () { suppressClick = false; }, 0);
	}
	function endPointer(ev) {
		delete pointers[ev.pointerId];
		if (pinch) {
			// Pinch over: stay put until all fingers are lifted, no stray pan afterwards.
			if (pointerCount() < 2) { pinch = null; }
			dragging = false;
			viewport.classList.remove("relatives-dragging");
			window.setTimeout(function () { suppressClick = false; }, 0);
			return;
		}
		endDrag();
	}
	window.addEventListener("pointerup", endPointer);
	window.addEventListener("pointercancel", endPointer);

	function zoomTo(nextZoom, focus) {
		nextZoom = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, nextZoom));
		if (focus) {
			// Punkt unter dem Mauszeiger bleibt beim Zoomen an Ort und Stelle.
			var pointX = (focus.x - state.pan.x) / state.zoom;
			var pointY = (focus.y - state.pan.y) / state.zoom;
			state.pan.x = focus.x - pointX * nextZoom;
			state.pan.y = focus.y - pointY * nextZoom;
		}
		state.zoom = nextZoom;
		applyTransform();
	}

	// Embedded in a forum page the plain wheel must keep scrolling the page -- otherwise
	// the tree traps the reader. Zoom there needs Ctrl/Cmd (trackpad pinch arrives as
	// ctrlKey wheel events, so pinching works too). The popup has nothing else to
	// scroll and zooms on the plain wheel.
	var wheelHint = null;
	var wheelHintTimer = null;
	function showWheelHint() {
		if (!wheelHint) {
			wheelHint = document.createElement("div");
			wheelHint.className = "relatives_tree_wheel_hint";
			wheelHint.setAttribute("aria-hidden", "true");
			wheelHint.textContent = t("wheel_hint", "Zum Zoomen Strg gedrückt halten und scrollen");
			viewport.appendChild(wheelHint);
		}
		wheelHint.classList.add("is-visible");
		window.clearTimeout(wheelHintTimer);
		wheelHintTimer = window.setTimeout(function () { wheelHint.classList.remove("is-visible"); }, 1400);
	}

	viewport.addEventListener("wheel", function (ev) {
		if (!config.popup && !ev.ctrlKey && !ev.metaKey) {
			showWheelHint();
			return;
		}
		ev.preventDefault();
		var factor = ev.deltaY > 0 ? 0.9 : 1.1;
		zoomTo(state.zoom * factor, viewportCenterOffset(ev.clientX, ev.clientY));
	}, { passive: false });

	/** Baum vollstaendig und mittig in den Viewport legen. */
	function fitToView() {
		var visible = visibleKeys();
		var keys = Object.keys(visible).filter(function (key) { return state.nodesByKey[key]; });
		if (!keys.length) {
			state.zoom = 1;
			state.pan = { x: 0, y: 0 };
			applyTransform();
			return;
		}
		var minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
		keys.forEach(function (key) {
			var point = nodePixel(state.nodesByKey[key]);
			minX = Math.min(minX, point.x - HALF_W);
			maxX = Math.max(maxX, point.x + HALF_W);
			minY = Math.min(minY, point.y - HALF_H);
			maxY = Math.max(maxY, point.y + HALF_H);
		});
		var padding = 48;
		var zoom = Math.min(
			(viewport.clientWidth - padding) / Math.max(1, maxX - minX),
			(viewport.clientHeight - padding) / Math.max(1, maxY - minY)
		);
		state.zoom = Math.min(1, Math.max(MIN_ZOOM, zoom));
		state.pan.x = -((minX + maxX) / 2) * state.zoom;
		state.pan.y = -((minY + maxY) / 2) * state.zoom;
		applyTransform();
	}

	function bindToolbar() {
		var zoomIn = document.getElementById("relatives-tree-zoom-in");
		var zoomOut = document.getElementById("relatives-tree-zoom-out");
		var zoomReset = document.getElementById("relatives-tree-zoom-reset");
		if (zoomIn) { zoomIn.addEventListener("click", function () { zoomTo(state.zoom + 0.15); }); }
		if (zoomOut) { zoomOut.addEventListener("click", function () { zoomTo(state.zoom - 0.15); }); }
		if (zoomReset) { zoomReset.addEventListener("click", fitToView); }

		// Popup: teilbaren Link in die Zwischenablage (im Popup-Fenster fehlt oft die Adresszeile).
		var copyLink = document.getElementById("relatives-tree-copy-link");
		if (copyLink) {
			copyLink.addEventListener("click", function () {
				var url = copyLink.getAttribute("data-url") || window.location.href;
				var label = copyLink.textContent;
				var done = function () {
					copyLink.textContent = copyLink.getAttribute("data-copied") || label;
					window.setTimeout(function () { copyLink.textContent = label; }, 2000);
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(url).then(done, function () { window.prompt("", url); });
				} else {
					window.prompt("", url);
				}
			});
		}
	}

	// --- Panel -------------------------------------------------------------------------

	function openPanel() {
		panel.hidden = false;
		if (backdrop) { backdrop.hidden = false; }
	}

	function closePanel() {
		panel.hidden = true;
		if (backdrop) { backdrop.hidden = true; }
	}

	function panelHeader(titleText) {
		var head = document.createElement("div");
		head.className = "relatives_tree_panel_header";

		var heading = document.createElement("h3");
		heading.textContent = titleText;
		head.appendChild(heading);

		var btn = document.createElement("button");
		btn.type = "button";
		btn.className = "relatives_tree_panel_close";
		btn.setAttribute("aria-label", t("cancel", "Abbrechen"));
		btn.textContent = "×";
		btn.addEventListener("click", closePanel);
		head.appendChild(btn);

		return head;
	}

	if (backdrop) { backdrop.addEventListener("click", closePanel); }
	document.addEventListener("keydown", function (ev) {
		if (ev.key === "Escape" && panel && !panel.hidden) { closePanel(); }
	});

	// --- Aktionsmenue (Klick auf einen Knoten: Bearbeiten / Loeschen / Hinzufuegen) -----

	/** "Verwandte*n von <Name> hinzufuegen" */
	function addRelativeLabel(name) {
		return t("add_relative_of", "Verwandte*n von %s hinzufügen").split("%s").join(name || "");
	}

	function menuButton(label, onClick, modifier) {
		var btn = document.createElement("button");
		btn.type = "button";
		btn.className = "relatives_tree_menu_button" + (modifier ? " " + modifier : "");
		btn.textContent = label;
		btn.addEventListener("click", onClick);
		return btn;
	}

	function openActionMenu(node) {
		if (!panel) { return; }
		panel.innerHTML = "";
		var title = node.type === "self" ? (node.username || ("#" + node.uid)) : node.name;
		panel.appendChild(panelHeader(title));

		var menu = document.createElement("div");
		menu.className = "relatives_tree_action_menu";

		// Bearbeiten laeuft immer ueber dasselbe Formular wie unter "Meine Verwandten":
		// alle Verbindungen als feste Aussagen ("X ist Bruder von Y"), nicht aus Sicht
		// des eigenen Charakters. Danach geht es zurueck in den Baum.
		if (node.person_id) {
			menu.appendChild(menuButton(t("edit_person", "Person bearbeiten"), function () {
				window.location.href = fullEditUrl(node.person_id);
			}));
		}

		// "Zu meinem Charakter" braucht keinen Anker; alles andere haengt am geklickten Knoten.
		var isOwnCharacter = node.person_id && node.person_id === config.viewerPersonId;
		var addLabel = isOwnCharacter || !node.person_id
			? t("add_person", "Person hinzufuegen")
			: addRelativeLabel(node.name || node.username);
		menu.appendChild(menuButton(addLabel, function () {
			openPersonForm(isOwnCharacter || !node.person_id ? null : node);
		}));

		// "Loeschen" entfernt nur aus der eigenen Liste; im Geflecht bleibt die Person.
		if (node.type === "person" && node.in_viewer_list) {
			menu.appendChild(menuButton(t("delete", "Loeschen"), function () {
				var question = t("confirm_delete", "Diese Person wirklich loeschen?");
				if (!window.RelativesConfirm) {
					if (window.confirm(question)) { deletePersonAjax(node.id); }
					return;
				}
				// Same dialog as on the list pages (relatives.js) instead of the native confirm().
				closePanel();
				window.RelativesConfirm.open({
					title: t("confirm", "%s löschen?").split("%s").join(node.name || ""),
					message: question,
					ok: t("delete", "Löschen"),
					cancel: t("cancel", "Abbrechen")
				}, function () { deletePersonAjax(node.id); });
			}, "relatives_tree_menu_button_danger"));
		}

		panel.appendChild(menu);
		openPanel();
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
					closePanel();
					reloadTree();
				} else {
					window.alert((data && data.error) || t("save_failed", "Fehlgeschlagen."));
				}
			})
			.catch(function () {
				window.alert(t("save_failed", "Fehlgeschlagen."));
			});
	}

	// --- Panel: read-only Info ------------------------------------------------------------

	// --- Hervorhebung ------------------------------------------------------------------

	function highlightNode(key) {
		connectorLayer.classList.add("is-dimmed");
		var related = {};
		var paths = connectorLayer.querySelectorAll("path[data-keys]");
		for (var i = 0; i < paths.length; i++) {
			var keys = (paths[i].getAttribute("data-keys") || "").split(" ");
			if (keys.indexOf(key) === -1) { continue; }
			paths[i].classList.add("is-highlighted");
			keys.forEach(function (other) { related[other] = true; });
		}
		nodeLayer.classList.add("is-dimmed");
		var nodes = nodeLayer.querySelectorAll("[data-key]");
		for (var j = 0; j < nodes.length; j++) {
			var nodeKey = nodes[j].getAttribute("data-key");
			if (nodeKey === key || related[nodeKey]) { nodes[j].classList.add("is-highlighted"); }
		}
	}

	function clearHighlight() {
		connectorLayer.classList.remove("is-dimmed");
		nodeLayer.classList.remove("is-dimmed");
		var highlighted = canvas.querySelectorAll(".is-highlighted");
		for (var i = 0; i < highlighted.length; i++) {
			highlighted[i].classList.remove("is-highlighted");
		}
	}

	/**
	 * Klartextliste der Verbindungen eines Knotens, nach Art gruppiert:
	 * "Bruder von Delia, Jonathan, Ravena · Ehemann von Ava · Vater von Ethan".
	 * Die Eintraege sind klickbar und springen zum jeweiligen Kasten.
	 */
	function relationsBlock(node) {
		if (!node.relations || !node.relations.length) { return null; }

		var byLabel = {};
		var order = [];
		node.relations.forEach(function (relation) {
			if (!relation.other_name) { return; }
			if (!byLabel[relation.label]) {
				byLabel[relation.label] = [];
				order.push(relation.label);
			}
			byLabel[relation.label].push(relation);
		});
		if (!order.length) { return null; }

		var wrap = document.createElement("div");
		wrap.className = "relatives_tree_relations";

		var heading = document.createElement("h4");
		heading.textContent = t("connections", "Verbindungen");
		wrap.appendChild(heading);

		var list = document.createElement("ul");
		order.forEach(function (label) {
			var item = document.createElement("li");
			var strong = document.createElement("strong");
			strong.textContent = label + " " + t("relation_of_word", "von") + " ";
			item.appendChild(strong);

			byLabel[label].forEach(function (relation, index) {
				if (index > 0) { item.appendChild(document.createTextNode(", ")); }
				var link = document.createElement("button");
				link.type = "button";
				link.className = "relatives_tree_relation_link";
				link.textContent = relation.other_name;
				if (relation.secret) { link.classList.add("is-secret"); }
				if (relation.derived) {
					// Nicht eingetragen, sondern aus gemeinsamen Eltern/Geschwistergruppe erkannt.
					link.classList.add("is-derived");
					link.title = t("derived", "abgeleitet");
				}
				link.addEventListener("click", function () {
					closePanel();
					focusNode(relation.other_key);
				});
				item.appendChild(link);
			});
			list.appendChild(item);
		});
		wrap.appendChild(list);
		return wrap;
	}

	/** Scrollt/zentriert einen Knoten und hebt ihn kurz hervor. */
	function focusNode(key) {
		var node = state.nodesByKey[key];
		if (!node) { return; }
		var point = nodePixel(node);
		state.pan = { x: -point.x * state.zoom, y: -point.y * state.zoom };
		applyTransform();
		highlightNode(key);
		window.setTimeout(clearHighlight, 2000);
	}

	function openFamilyPanel(node) {
		if (!panel) { return; }
		panel.innerHTML = "";
		panel.appendChild(panelHeader(node.name));

		var lead = document.createElement("p");
		lead.className = "relatives_tree_panel_lead";
		lead.textContent = t("node_family", "Familie");
		panel.appendChild(lead);

		if (node.description) {
			var desc = document.createElement("p");
			desc.textContent = node.description;
			panel.appendChild(desc);
		}

		// Mitglieder statt Verbindungen: "Elternteil von Oisin" sagt bei einem Kasten wenig,
		// und reine Mitglieder haetten dort gar keinen Eintrag.
		if (node.members && node.members.length) {
			var wrap = document.createElement("div");
			wrap.className = "relatives_tree_relations";
			var heading = document.createElement("h4");
			heading.textContent = t("family_members", "Mitglieder") + " (" + node.members.length + ")";
			wrap.appendChild(heading);
			var list = document.createElement("ul");
			node.members.forEach(function (member) {
				var item = document.createElement("li");
				if (state.nodesByKey[member.key]) {
					var link = document.createElement("button");
					link.type = "button";
					link.className = "relatives_tree_relation_link";
					link.textContent = member.name;
					link.addEventListener("click", function () {
						closePanel();
						focusNode(member.key);
					});
					item.appendChild(link);
				} else {
					item.textContent = member.name;
				}
				list.appendChild(item);
			});
			wrap.appendChild(list);
			panel.appendChild(wrap);
		}

		openPanel();
	}

	/** "Elternteil von Jolina", "Grosselternteil von Jolina und Christopher", "Partner*in von Ava". */
	function placeholderLabel(node) {
		var names = (node.placeholder_of || []).map(function (key) {
			var other = state.nodesByKey[key];
			return other ? (other.name || other.username || "") : "";
		}).filter(function (name) { return name !== ""; });
		var depth = node.placeholder_depth || 1;
		var template;
		if (node.placeholder_role === "partner") {
			template = t("placeholder_partner", "Partner*in von %s");
		} else if (depth >= 4) {
			template = t("placeholder_ancestor", "Vorfahr*in von %s");
		} else if (depth === 3) {
			template = t("placeholder_great_grandparent", "Urgroßelternteil von %s");
		} else if (depth === 2) {
			template = t("placeholder_grandparent", "Großelternteil von %s");
		} else {
			template = t("placeholder_parent", "Elternteil von %s");
		}
		return template.split("%s").join(names.join(t("placeholder_and", " und ")));
	}

	function openPlaceholderPanel(node) {
		if (!panel) { return; }
		panel.innerHTML = "";
		panel.appendChild(panelHeader(placeholderLabel(node)));

		var note = document.createElement("p");
		note.className = "relatives_tree_panel_note";
		note.textContent = t("placeholder_note", "Diese Person ist nicht eingetragen.");
		panel.appendChild(note);

		// Eintragen nur, wo der Bezug eindeutig ist: Elternteil bzw. Partner*in genau einer
		// Person, die man bearbeiten darf. Den Grad waehlt man im Formular.
		var anchors = (node.placeholder_of || []).map(function (key) { return state.nodesByKey[key]; })
			.filter(function (other) { return other && other.person_id; });
		if (anchors.length === 1 && anchors[0].can_edit && (node.placeholder_depth || 1) === 1) {
			var anchor = anchors[0];
			var menu = document.createElement("div");
			menu.className = "relatives_tree_action_menu";
			menu.appendChild(menuButton(t("placeholder_add", "Diese Person eintragen"), function () {
				openPersonForm(anchor.person_id === config.viewerPersonId ? null : anchor);
			}));
			panel.appendChild(menu);
		}

		openPanel();
	}

	function openReadOnlyPanel(node) {
		if (!panel) { return; }
		panel.innerHTML = "";
		panel.appendChild(panelHeader(node.name));

		var rel = document.createElement("p");
		rel.className = "relatives_tree_panel_lead";
		rel.textContent = relationshipLabel(node);
		panel.appendChild(rel);

		if (node.is_deceased) {
			var deceased = document.createElement("p");
			deceased.className = "relatives_tree_panel_note";
			deceased.textContent = "† " + t("deceased", "Verstorben")
				+ (node.death_note ? " (" + node.death_note + ")" : "");
			panel.appendChild(deceased);
		}

		if (node.age !== null && node.age !== undefined && node.age !== "") {
			var age = document.createElement("p");
			age.textContent = t("field_age", "Alter") + ": " + node.age;
			panel.appendChild(age);
		}
		if (node.origin) {
			var origin = document.createElement("p");
			origin.textContent = t("field_origin", "Herkunft") + ": " + node.origin;
			panel.appendChild(origin);
		}
		if (node.avatar) {
			var avatar = document.createElement("p");
			avatar.textContent = t("field_avatar", "Avatarperson") + ": " + node.avatar;
			panel.appendChild(avatar);
		}
		if (node.relationship_description) {
			var relDesc = document.createElement("p");
			relDesc.textContent = node.relationship_description;
			panel.appendChild(relDesc);
		}
		if (node.description) {
			var desc = document.createElement("p");
			desc.textContent = node.description;
			panel.appendChild(desc);
		}

		var connections = relationsBlock(node);
		if (connections) { panel.appendChild(connections); }

		var actions = readOnlyActions(node);
		if (actions) { panel.appendChild(actions); }

		openPanel();
	}

	/**
	 * Wege aus dem Nur-Lesen-Panel fuer eingeloggte Besucher*innen des eigenen oder
	 * eines fremden Baums (nicht im weitergegebenen Popup): eine von anderen
	 * eingetragene Person in die eigene Liste uebernehmen (danach bearbeitbar) oder
	 * eigene Verwandte an sie haengen.
	 */
	function readOnlyActions(node) {
		if (config.readOnly || !config.viewerUid || !node.person_id) { return null; }

		var wrap = document.createElement("div");
		var menu = document.createElement("div");
		menu.className = "relatives_tree_action_menu";

		var isAccount = node.link_status === 2 && node.linked_uid;
		if (!isAccount && !node.in_viewer_list) {
			var note = document.createElement("p");
			note.className = "relatives_tree_panel_note";
			note.textContent = t("adopt_note", "Diese Person hat ein*e andere*r Spieler*in eingetragen.");
			wrap.appendChild(note);
			menu.appendChild(menuButton(t("adopt", "In meine Liste übernehmen"), function () {
				adoptPersonAjax(node);
			}));
		}

		if (node.person_id !== config.viewerPersonId) {
			menu.appendChild(menuButton(addRelativeLabel(node.name), function () {
				openPersonForm(node);
			}));
		}

		if (!menu.childNodes.length) { return null; }
		wrap.appendChild(menu);
		return wrap;
	}

	function adoptPersonAjax(node) {
		var payload = new FormData();
		payload.append("my_post_key", config.postCode || "");
		payload.append("ajax", "1");
		payload.append("id", node.person_id);

		fetch("relatives.php?action=adopt", { method: "POST", body: payload, credentials: "same-origin" })
			.then(function (resp) { return resp.json(); })
			.then(function (data) {
				if (data && data.success) {
					// Neu laden und direkt das Aktionsmenue der nun bearbeitbaren Person oeffnen.
					reloadTree(function () {
						var fresh = state.nodesByKey[node.key];
						if (fresh && fresh.can_edit) { openActionMenu(fresh); } else { closePanel(); }
					});
				} else {
					window.alert((data && data.error) || t("save_failed", "Fehlgeschlagen."));
				}
			})
			.catch(function () {
				window.alert(t("save_failed", "Fehlgeschlagen."));
			});
	}

	// --- Panel: Add / Edit (gefuehrtes Klick-Add-Interface) -----------------------------

	function buildAttributeFieldset(selectedOptionIds) {
		var wrap = document.createElement("div");
		var isSelected = function (optId) {
			return !!selectedOptionIds && selectedOptionIds.map(String).indexOf(String(optId)) !== -1;
		};
		attributeGroups.forEach(function (group) {
			if (!(group.options || []).length) { return; }
			var fs = document.createElement("fieldset");
			fs.className = "relatives_tree_attribute_group";
			var legend = document.createElement("legend");
			legend.textContent = group.group.label;
			fs.appendChild(legend);
			// Single-value groups (hair colour & co.) as a dropdown, like the full form.
			if (!parseInt(group.group.is_multiple, 10)) {
				var select = document.createElement("select");
				select.name = "attribute_option_ids[]";
				var none = document.createElement("option");
				none.value = "";
				none.textContent = t("attribute_none", "– keine Angabe –");
				select.appendChild(none);
				group.options.forEach(function (opt) {
					var option = document.createElement("option");
					option.value = opt.id;
					option.textContent = opt.label;
					if (isSelected(opt.id)) { option.selected = true; }
					select.appendChild(option);
				});
				fs.appendChild(select);
				wrap.appendChild(fs);
				return;
			}
			(group.options || []).forEach(function (opt) {
				var label = document.createElement("label");
				var cb = document.createElement("input");
				cb.type = "checkbox";
				cb.name = "attribute_option_ids[]";
				cb.value = opt.id;
				if (selectedOptionIds && selectedOptionIds.map(String).indexOf(String(opt.id)) !== -1) {
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
		relationshipTypes
			.filter(function (rtype) { return rtype.gender === labelGender; })
			.sort(function (a, b) { return a.label.localeCompare(b.label, "de"); })
			.forEach(function (rtype) {
				var opt = document.createElement("option");
				opt.value = rtype.id;
				opt.textContent = rtype.label;
				select.appendChild(opt);
			});
		if (selectedTypeId) { select.value = String(selectedTypeId); }
	}

	/** Fuehrt die Bezugskette ab startNode (einschliesslich) ueber die Person needleId? */
	function chainContains(startNode, needleId) {
		var current = startNode;
		for (var steps = 0; current && steps < 50; steps++) {
			if (current.id === needleId) { return true; }
			current = current.related_to_person_id ? state.nodesByKey["person:" + current.related_to_person_id] : null;
		}
		return false;
	}

	function fullEditUrl(personId) {
		return "relatives.php?action=edit&id=" + encodeURIComponent(personId)
			+ "&return=" + (config.popup ? "tree_popup" : "tree");
	}

	/**
	 * Schnell-Hinzufuegen im Baum (eine Verbindung). Bearbeiten laeuft ueber das
	 * vollstaendige Formular (fullEditUrl()).
	 */
	function openPersonForm(relatedToNode) {
		if (!panel) { return; }
		panel.innerHTML = "";

		panel.appendChild(panelHeader(t("add_person", "Person hinzufuegen")));

		if (relatedToNode) {
			var hint = document.createElement("p");
			hint.className = "relatives_tree_panel_lead";
			hint.textContent = addRelativeLabel(relatedToNode.name);
			panel.appendChild(hint);

			var hintDetail = document.createElement("p");
			hintDetail.className = "relatives_tree_panel_note";
			hintDetail.textContent = t(
				"add_relative_of_note",
				"Der Verwandtschaftsgrad wird aus Sicht von %s gewaehlt. Der Baum zeigt dir automatisch, was das aus deiner eigenen Sicht bedeutet."
			).split("%s").join(relatedToNode.name);
			panel.appendChild(hintDetail);
		}

		var form = document.createElement("form");

		var nameInput = labeledInput(form, "text", t("field_name", "Name"), "");
		var genderSelect = labeledSelect(form, t("field_gender", "Geschlecht"), [
			["-1", t("gender_any", "egal")],
			["0", t("gender_male", "maennlich")],
			["1", t("gender_female", "weiblich")],
			["2", t("gender_diverse", "divers")],
		], "-1");

		// Bezugsperson: der Grad gilt relativ zu ihr (leer = zu deinem Charakter). Im
		// gemeinsamen Geflecht steht jede Person des sichtbaren Baums zur Auswahl -- auch
		// solche, die nicht in deiner Liste stehen.
		var relatedOptions = [["", t("related_to_self", "– zu deinem Charakter –")]];
		(treeData.nodes || [])
			.filter(function (other) {
				return other.person_id && other.person_id !== config.viewerPersonId;
			})
			.sort(function (a, b) { return String(a.name).localeCompare(String(b.name), "de"); })
			.forEach(function (other) {
				var label = relationshipLabel(other);
				relatedOptions.push([String(other.person_id), other.name + (label ? " (" + label + ")" : "")]);
			});
		var relatedSelected = relatedToNode ? String(relatedToNode.person_id || relatedToNode.id) : "";
		var relatedSelect = labeledSelect(form, t("field_related_to", "Bezugsperson"), relatedOptions, relatedSelected);
		var relatedHint = document.createElement("p");
		relatedHint.className = "relatives_tree_panel_note";
		relatedHint.textContent = t("related_to_hint", "");
		relatedSelect.parentNode.appendChild(relatedHint);

		var relWrap = document.createElement("div");
		relWrap.className = "relatives_field";
		var relLabel = document.createElement("label");
		relLabel.textContent = t("field_relationship", "Verwandtschaftsgrad");
		relWrap.appendChild(relLabel);
		var relSelect = document.createElement("select");
		relWrap.appendChild(relSelect);
		form.appendChild(relWrap);

		var customLabelInput = labeledInput(form, "text", t("field_relationship_custom", "Freie Bezeichnung"), "");
		var relDescTextarea = labeledTextarea(form, t("field_relationship_description", "Eure Beziehung"), "");

		rebuildRelationshipOptions(relSelect, genderSelect.value, null);

		// "Finian Hari ist Sohn von Rian MacCrea." -- spells out the direction of the
		// connection, which the bare "Verwandtschaftsgrad" field does not.
		var sentence = document.createElement("p");
		sentence.className = "relatives_tree_panel_note relatives_relation_sentence";
		relWrap.appendChild(sentence);
		function selfName() {
			var own = (treeData.nodes || []).filter(function (other) {
				return config.viewerPersonId && other.person_id === config.viewerPersonId;
			})[0];
			return own ? own.name : t("relation_sentence_self", "deinem Charakter");
		}
		function updateSentence() {
			var degree = relSelect.value !== ""
				? relSelect.options[relSelect.selectedIndex].textContent
				: customLabelInput.value.trim();
			if (!degree) {
				sentence.style.display = "none";
				return;
			}
			var target = relatedSelect.value
				? ((treeData.nodes || []).filter(function (other) {
					return String(other.person_id) === relatedSelect.value;
				})[0] || {}).name || ""
				: selfName();
			sentence.textContent = t("relation_sentence", "Bedeutet: {1} ist {2} von {3}.")
				.split("{1}").join(nameInput.value.trim() || t("relation_sentence_subject", "Diese Person"))
				.split("{2}").join(degree)
				.split("{3}").join(target);
			sentence.style.display = "";
		}
		[relSelect, relatedSelect].forEach(function (el) { el.addEventListener("change", updateSentence); });
		[nameInput, customLabelInput].forEach(function (el) { el.addEventListener("input", updateSentence); });

		genderSelect.addEventListener("change", function () {
			rebuildRelationshipOptions(relSelect, genderSelect.value, null);
			updateSentence();
		});
		updateSentence();

		var ageInput = labeledInput(form, "number", t("field_age", "Alter"), "");
		var originInput = labeledInput(form, "text", t("field_origin", "Herkunft"), "");
		var avatarInput = labeledInput(form, "text", t("field_avatar", "Avatarperson"), "");
		var descTextarea = labeledTextarea(form, t("field_description", "Beschreibung"), "");
		var wantedCheckbox = labeledCheckbox(form, t("field_is_wanted", "Als gesucht markieren"), false);
		var wantedLinkInput = labeledInput(form, "url", t("field_wanted_thread_link", "Link zum Gesuchs-Thread"), "");
		wantedLinkInput.placeholder = t("wanted_placeholder", "");
		var deceasedCheckbox = labeledCheckbox(form, t("field_is_deceased", "Verstorben"), false);
		var deathNoteInput = labeledInput(form, "text", t("field_death_note", "Todesjahr oder -datum (optional)"), "");

		if (attributeGroups.length) {
			var attrLabel = document.createElement("label");
			attrLabel.className = "relatives_tree_form_label";
			attrLabel.textContent = t("field_attributes", "Attribute");
			form.appendChild(attrLabel);
			form.appendChild(buildAttributeFieldset([]));
		}

		var submit = document.createElement("button");
		submit.type = "submit";
		submit.className = "relatives_tree_menu_button relatives_tree_menu_button_primary";
		submit.textContent = t("save", "Speichern");
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
			payload.append("relationship_description", relDescTextarea.value);
			payload.append("avatar", avatarInput.value);
			payload.append("wanted_thread_link", wantedLinkInput.value);
			if (wantedCheckbox.checked) {
				payload.append("is_wanted", "1");
			}
			if (deceasedCheckbox.checked) {
				payload.append("is_deceased", "1");
				payload.append("death_note", deathNoteInput.value);
			}
			payload.append("related_to_person_id", relatedSelect.value || "0");

			var checked = form.querySelectorAll("input[name='attribute_option_ids[]']:checked, select[name='attribute_option_ids[]']");
			for (var i = 0; i < checked.length; i++) {
				if (checked[i].value !== "") { payload.append("attribute_option_ids[]", checked[i].value); }
			}

			submit.disabled = true;
			fetch("relatives.php?action=add", { method: "POST", body: payload, credentials: "same-origin" })
				.then(function (resp) { return resp.json(); })
				.then(function (data) {
					if (data && data.success) {
						closePanel();
						reloadTree();
						if (data.joined_existing) {
							alert(t("added_existing", ""));
						}
					} else {
						submit.disabled = false;
						alert((data && data.error) || t("save_failed", "Speichern fehlgeschlagen."));
					}
				})
				.catch(function () {
					submit.disabled = false;
					alert(t("save_failed", "Speichern fehlgeschlagen."));
				});
		});

		panel.appendChild(form);
		openPanel();
		nameInput.focus();
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

	function labeledCheckbox(form, labelText, checked) {
		var wrap = document.createElement("div");
		wrap.className = "relatives_field";
		var label = document.createElement("label");
		var checkbox = document.createElement("input");
		checkbox.type = "checkbox";
		checkbox.checked = !!checked;
		label.appendChild(checkbox);
		label.appendChild(document.createTextNode(" " + labelText));
		wrap.appendChild(label);
		form.appendChild(wrap);
		return checkbox;
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

	function reloadTree(onDone) {
		var url = "relatives.php?action=tree&ajax=1&uid=" + encodeURIComponent(config.rootUid)
			+ (config.popup ? "&popup=1" : "")
			+ (state.expand.length ? "&expand=" + encodeURIComponent(state.expand.join(",")) : "");
		fetch(url, { credentials: "same-origin" })
			.then(function (resp) { return resp.json(); })
			.then(function (data) {
				if (!data) { return; }
				treeData = data;
				indexNodes();
				render();
				if (onDone) { onDone(); }
			});
	}

	canvas.appendChild(connectorLayer);
	canvas.appendChild(nodeLayer);
	bindToolbar();
	render();
	fitToView();

	var resizeTimer = null;
	window.addEventListener("resize", function () {
		window.clearTimeout(resizeTimer);
		resizeTimer = window.setTimeout(applyTransform, 150);
	});
})();
