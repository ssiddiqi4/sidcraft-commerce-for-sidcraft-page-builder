/**
 * Sidcraft Commerce — event blocks for the block editor (server-rendered).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! window.sidccofoBlocks ) {
		return;
	}
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var be = wp.blockEditor;
	var c = wp.components;
	var ServerSideRender = wp.serverSideRender;
	var data = window.sidccofoBlocks;
	var i18n = data.i18n || {};

	function control( key, def, value, set ) {
		var label = def.label || key;
		var change = function ( v ) {
			var next = {};
			next[ key ] = v;
			set( next );
		};
		if ( key === 'event_id' ) {
			var opts = [ { value: '0', label: i18n.current } ].concat( ( data.events || [] ).map( function ( e ) {
				return { value: String( e.id ), label: e.title + ' (#' + e.id + ')' };
			} ) );
			return el( c.SelectControl, { key: key, label: i18n.event, value: String( value || 0 ), options: opts, onChange: function ( v ) {
				change( parseInt( v, 10 ) || 0 );
			} } );
		}
		switch ( def.type ) {
			case 'switch':
				return el( c.ToggleControl, { key: key, label: label, checked: !! value, onChange: change } );
			case 'number':
				return el( c.TextControl, { key: key, label: label, type: 'number', min: def.min, max: def.max, value: value === undefined ? '' : value, onChange: function ( v ) {
					change( v === '' ? 0 : Number( v ) );
				} } );
			case 'select':
				return el( c.SelectControl, { key: key, label: label, value: value, options: Object.keys( def.options || {} ).map( function ( k ) {
					return { value: k, label: def.options[ k ] };
				} ), onChange: change } );
			case 'color':
				return el( 'div', { key: key, className: 'components-base-control' },
					el( 'p', {}, label ),
					el( c.ColorPalette, { value: value || undefined, onChange: function ( v ) {
						change( v || '' );
					} } )
				);
			default:
				return el( c.TextControl, { key: key, label: label, value: value || '', onChange: change } );
		}
	}

	data.blocks.forEach( function ( block ) {
		var attributes = { className: { type: 'string', default: '' } };
		Object.keys( block.controls ).forEach( function ( key ) {
			var def = block.controls[ key ];
			attributes[ key ] = {
				type: def.type === 'number' ? 'number' : ( def.type === 'switch' ? 'boolean' : 'string' ),
				default: def.type === 'switch' ? !! def.default : ( def.type === 'number' ? Number( def.default || 0 ) : String( def.default == null ? '' : def.default ) ),
			};
		} );

		wp.blocks.registerBlockType( block.name, {
			apiVersion: 3,
			title: block.title,
			icon: block.icon || 'tickets-alt',
			category: 'sidcraft-commerce',
			keywords: block.keywords || [],
			attributes: attributes,
			supports: { html: false, align: [ 'wide', 'full' ] },
			edit: function ( props ) {
				var blockProps = be.useBlockProps ? be.useBlockProps() : {};
				var content = [];
				var style = [];
				Object.keys( block.controls ).forEach( function ( key ) {
					var def = block.controls[ key ];
					( def.section === 'style' ? style : content ).push( control( key, def, props.attributes[ key ], props.setAttributes ) );
				} );
				return el( Fragment, {},
					el( be.InspectorControls, {},
						el( c.PanelBody, { title: i18n.content, initialOpen: true }, content ),
						style.length ? el( c.PanelBody, { title: i18n.style, initialOpen: false }, style ) : null
					),
					el( 'div', blockProps,
						el( c.Disabled, {}, el( ServerSideRender, { block: block.name, attributes: props.attributes } ) )
					)
				);
			},
			save: function () {
				return null;
			},
		} );
	} );
}( window.wp ) );
