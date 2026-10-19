/**
 * Sidcraft Commerce — editor canvas previews inside Sidcraft Page Builder.
 *
 * Sidcraft draws each Unit on its canvas through the JS filter
 * `editor/node/body_html/<type>`. Commerce Units render on the server, so
 * this asks POST /sidcraft-commerce/v1/editor/preview for the HTML, caches it
 * per settings, and repaints the canvas when it arrives. The canvas iframe
 * also gets the store's front-end CSS (as <link> stylesheets) so previews
 * match the live page.
 */
( function () {
	'use strict';

	var LB = window.SidcraftPageBuilder;
	var cfg = window.sidccofoEditor || {};
	if ( ! LB || ! LB.hooks || ! Array.isArray( cfg.types ) ) {
		return;
	}

	var cache = {};
	var pending = {};

	function keyFor( node ) {
		return node.type + ':' + JSON.stringify( node.settings || {} );
	}

	function fetchPreview( node, key ) {
		pending[ key ] = true;
		var data = LB.data || {};
		fetch( cfg.preview, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce || '' },
			body: JSON.stringify( { type: node.type, settings: node.settings || {}, post_id: data.postId || 0 } ),
		} )
			.then( function ( r ) {
				return r.ok ? r.json() : null;
			} )
			.then( function ( res ) {
				delete pending[ key ];
				cache[ key ] = ( res && res.html ) || '';
				if ( typeof LB.render === 'function' ) {
					LB.render();
				}
			} )
			.catch( function () {
				delete pending[ key ];
			} );
	}

	cfg.types.forEach( function ( type ) {
		LB.hooks.addFilter(
			'editor/node/body_html/' + type,
			function ( html, node, helpers ) {
				var key = keyFor( node );
				if ( Object.prototype.hasOwnProperty.call( cache, key ) ) {
					// Links/buttons inside previews must not navigate the editor.
					return '<div class="sc-editor-preview" inert>' + cache[ key ] + '</div>';
				}
				if ( ! pending[ key ] ) {
					fetchPreview( node, key );
				}
				var esc = ( helpers && helpers.esc ) || String;
				return '<div class="sc-editor-preview sc-editor-preview--loading">' + esc( cfg.loading || 'Loading…' ) + '</div>';
			},
			10,
			'sidcraft-commerce'
		);
	} );

	/** Put the store CSS into the canvas iframe (once per frame document). */
	function injectStyles() {
		var frame = document.getElementById( 'lb-editor-frame' );
		var doc = frame && frame.contentDocument;
		if ( ! doc || ! doc.head || doc.getElementById( 'sc-editor-styles' ) ) {
			return;
		}
		var marker = doc.createElement( 'meta' );
		marker.id = 'sc-editor-styles';
		doc.head.appendChild( marker );
		( cfg.styles || [] ).forEach( function ( href ) {
			var link = doc.createElement( 'link' );
			link.rel = 'stylesheet';
			link.href = href;
			doc.head.appendChild( link );
		} );
	}

	LB.hooks.addAction( 'editor/render', injectStyles, 10, 'sidcraft-commerce' );
	LB.hooks.addAction( 'editor/init', injectStyles, 10, 'sidcraft-commerce' );
} )();
