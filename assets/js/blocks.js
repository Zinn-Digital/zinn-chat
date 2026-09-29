/*!
 * Zinn® Chat blocks for the block editor (and every builder built on it, incl. Page Builder Sandwich).
 * No build step: plain wp.* globals. Each block is rendered by the server, so the editor preview is
 * exactly what visitors get. Neil Lock — CEO, Zinn Digital® Ltd — GPL-2.0-or-later.
 */
(function (wp, data) {
	'use strict';
	if (!wp || !data) { return; }
	var el = wp.element.createElement;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;
	Object.keys(data).forEach(function (slug) {
		var def = data[slug];
		wp.blocks.registerBlockType('zinn-chat/' + slug, {
			apiVersion: 2,
			title: def.title,
			icon: def.icon,
			category: 'widgets',
			keywords: ['chat', 'support', 'ticket'],
			edit: function (props) {
				var controls = Object.keys(def.atts).map(function (name) {
					return el(TextControl, {
						key: name,
						label: def.atts[name].label,
						value: props.attributes[name] || '',
						onChange: function (v) { var o = {}; o[name] = v; props.setAttributes(o); }
					});
				});
				return el('div', useBlockProps(),
					controls.length ? el(InspectorControls, null, el(PanelBody, { title: __('Settings', 'zinn-chat') }, controls)) : null,
					el(ServerSideRender, { block: 'zinn-chat/' + slug, attributes: props.attributes })
				);
			},
			save: function () { return null; }
		});
	});
}(window.wp, window.zinnChatBlocks));
