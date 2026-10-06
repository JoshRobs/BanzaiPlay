/**
 * The BanzaiPlay Game block, editor side.
 *
 * Plain JavaScript against the wp.* globals — there is no build step. The
 * front end is rendered in PHP (see Block::render), so save() returns null.
 * The editor shows a placeholder card, not the game: a 50 MB build loading
 * inside the editor would be no help to anyone.
 */
(function (wp) {
	"use strict";

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var blockEditor = wp.blockEditor;
	var components = wp.components;
	var data = window.banzaiPlayBlockData || { games: [], adminUrl: "" };

	function findGame(slug) {
		for (var i = 0; i < data.games.length; i++) {
			if (data.games[i].slug === slug) {
				return data.games[i];
			}
		}

		return null;
	}

	function gameOptions() {
		var options = [{ value: "", label: __("Select a game…", "banzaiplay") }];

		data.games.forEach(function (game) {
			var label = game.name;

			if (game.status !== "ready") {
				label = sprintf("%s (%s)", game.name, game.statusLabel);
			} else if (!game.active) {
				label = sprintf("%s (%s)", game.name, __("Inactive", "banzaiplay"));
			}

			options.push({ value: game.slug, label: label });
		});

		return options;
	}

	/** The size the player will have, as Embed::size() works it out. */
	function playerSize(game, width, height) {
		if (width && height) {
			return [width, height];
		}

		if (width) {
			return [width, Math.round((width * game.height) / game.width)];
		}

		if (height) {
			return [Math.round((height * game.width) / game.height), height];
		}

		return [game.width, game.height];
	}

	function numberControl(props, key, label, help) {
		return el(components.TextControl, {
			type: "number",
			min: 0,
			label: label,
			help: help,
			value: props.attributes[key] ? String(props.attributes[key]) : "",
			onChange: function (value) {
				var update = {};
				update[key] = Math.max(0, parseInt(value, 10) || 0);
				props.setAttributes(update);
			},
		});
	}

	function Edit(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var game = findGame(attributes.game);
		var blockProps = blockEditor.useBlockProps({ className: "banzaiplay-block" });

		var picker = el(components.SelectControl, {
			label: __("Game", "banzaiplay"),
			value: attributes.game,
			options: gameOptions(),
			onChange: function (value) {
				setAttributes({ game: value });
			},
		});

		var inspector = el(
			blockEditor.InspectorControls,
			null,
			el(
				components.PanelBody,
				{ title: __("Game settings", "banzaiplay") },
				picker,
				numberControl(props, "width", __("Width (px)", "banzaiplay"), game ? sprintf(__("Leave empty for the game's own: %d.", "banzaiplay"), game.width) : ""),
				numberControl(props, "height", __("Height (px)", "banzaiplay"), __("Give one side and the other keeps the game's aspect ratio.", "banzaiplay")),
				el(components.SelectControl, {
					label: __("Start", "banzaiplay"),
					value: attributes.start || "",
					options: [
						{
							value: "",
							label: game
								? sprintf(__("Game's setting (%s)", "banzaiplay"), game.startsOnLoad ? __("with the page", "banzaiplay") : __("on Play", "banzaiplay"))
								: __("Game's setting", "banzaiplay"),
						},
						{ value: "click", label: __("When the visitor presses Play", "banzaiplay") },
						{ value: "load", label: __("As soon as the page loads", "banzaiplay") },
					],
					help: __("Starting with the page downloads the game for every visitor, and browsers may keep its sound off until the visitor clicks it.", "banzaiplay"),
					onChange: function (value) {
						setAttributes({ start: value });
					},
				}),
				el(components.ToggleControl, {
					label: __("Fullscreen button", "banzaiplay"),
					checked: attributes.fullscreen !== false,
					onChange: function (value) {
						setAttributes({ fullscreen: value });
					},
				})
			)
		);

		var body;

		if (!data.games.length) {
			body = el(
				"p",
				null,
				__("No games uploaded yet.", "banzaiplay"),
				data.adminUrl ? " " : null,
				data.adminUrl ? el("a", { href: data.adminUrl, target: "_blank", rel: "noopener" }, __("Add one in BanzaiPlay", "banzaiplay")) : null
			);
		} else if (!game) {
			body = picker;
		} else {
			var size = playerSize(game, attributes.width, attributes.height);

			body = el(
				"div",
				{ className: "banzaiplay-block-card", style: { aspectRatio: size[0] + " / " + size[1], maxWidth: size[0] + "px" } },
				el(
					"div",
					{ className: "banzaiplay-block-card-inner" },
					el("span", { className: "banzaiplay-block-play", "aria-hidden": "true" }, "▶"),
					el("strong", { className: "banzaiplay-block-title" }, game.name),
					game.description ? el("span", { className: "banzaiplay-block-description" }, game.description) : null,
					el(
						"span",
						{ className: "banzaiplay-block-meta" },
						el("span", { className: "banzaiplay-block-engine banzaiplay-block-engine-" + game.engine }, game.engineLabel),
						el("span", null, size[0] + " × " + size[1]),
						(attributes.start ? attributes.start === "load" : game.startsOnLoad) ? el("span", null, __("Starts with the page", "banzaiplay")) : null
					),
					game.status !== "ready" ? el("span", { className: "banzaiplay-block-warning" }, game.statusLabel) : null,
					game.status === "ready" && !game.active
						? el("span", { className: "banzaiplay-block-warning" }, __("Inactive — visitors see nothing until it is activated.", "banzaiplay"))
						: null
				)
			);
		}

		return el(
			"div",
			blockProps,
			inspector,
			game
				? body
				: el(components.Placeholder, { icon: "games", label: __("BanzaiPlay Game", "banzaiplay") }, body)
		);
	}

	wp.blocks.registerBlockType("banzaiplay/game", {
		edit: Edit,
		save: function () {
			return null;
		},
	});
})(window.wp);
