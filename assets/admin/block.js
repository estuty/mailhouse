/**
 * The Newsletter form block.
 *
 * Written against wp.element.createElement rather than JSX, so the plugin
 * needs no build step -- what is in this file is what the editor runs.
 * Rendering happens in PHP, so the form on the front end is the same one the
 * shortcode produces.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'mailhouse/form', {
		edit: function ( props ) {
			var attributes = props.attributes;

			function set( key ) {
				return function ( value ) {
					var update = {};

					update[ key ] = value;
					props.setAttributes( update );
				};
			}

			var inspector = el(
				blockEditor.InspectorControls,
				{},
				el(
					components.PanelBody,
					{ title: __( 'Form', 'mailhouse' ) },
					el( components.TextControl, {
						label: __( 'Heading', 'mailhouse' ),
						value: attributes.title,
						onChange: set( 'title' )
					} ),
					el( components.TextareaControl, {
						label: __( 'Description', 'mailhouse' ),
						value: attributes.description,
						onChange: set( 'description' )
					} ),
					el( components.TextControl, {
						label: __( 'Button label', 'mailhouse' ),
						placeholder: __( 'Subscribe', 'mailhouse' ),
						value: attributes.button,
						onChange: set( 'button' )
					} ),
					el( components.SelectControl, {
						label: __( 'Name fields', 'mailhouse' ),
						value: attributes.name,
						options: [
							{ label: __( 'Email only', 'mailhouse' ), value: 'none' },
							{ label: __( 'First name', 'mailhouse' ), value: 'first' },
							{ label: __( 'First and last name', 'mailhouse' ), value: 'full' }
						],
						onChange: set( 'name' )
					} ),
					el( components.SelectControl, {
						label: __( 'Layout', 'mailhouse' ),
						value: attributes.layout,
						options: [
							{ label: __( 'Stacked', 'mailhouse' ), value: 'stacked' },
							{ label: __( 'Inline', 'mailhouse' ), value: 'inline' }
						],
						onChange: set( 'layout' )
					} ),
					el( components.TextControl, {
						label: __( 'Tags to add', 'mailhouse' ),
						help: __( 'Comma separated. Tags that do not exist yet are created.', 'mailhouse' ),
						value: attributes.tags,
						onChange: set( 'tags' )
					} )
				)
			);

			// A representative preview rather than a server render: the real
			// form carries a signed payload and a time trap, neither of which
			// belongs in the editor.
			var preview = el(
				'div',
				blockEditor.useBlockProps ? blockEditor.useBlockProps( { className: 'mailhouse-block-preview' } ) : { className: 'mailhouse-block-preview' },
				attributes.title ? el( 'h2', {}, attributes.title ) : null,
				attributes.description ? el( 'p', {}, attributes.description ) : null,
				attributes.name !== 'none' ? el( 'p', {}, el( 'input', { type: 'text', placeholder: __( 'First name', 'mailhouse' ), disabled: true } ) ) : null,
				attributes.name === 'full' ? el( 'p', {}, el( 'input', { type: 'text', placeholder: __( 'Last name', 'mailhouse' ), disabled: true } ) ) : null,
				el( 'p', {}, el( 'input', { type: 'email', placeholder: __( 'Email address', 'mailhouse' ), disabled: true } ) ),
				el( 'p', {}, el( 'button', { type: 'button', className: 'button', disabled: true }, attributes.button || __( 'Subscribe', 'mailhouse' ) ) )
			);

			return el( element.Fragment, {}, inspector, preview );
		},

		save: function () {
			return null;
		}
	} );
}(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
) );
