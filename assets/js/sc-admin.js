/**
 * Sidcraft Commerce — admin: add / remove rows in Store → Settings tables
 * (shipping zones, tax rates, carriers), the custom-carrier fields in the
 * order screen's Shipment tracking box, and image fields (a choice's image,
 * a category's image).
 */
( function () {
	'use strict';

	// Image fields (a choice's image on the product screen, a category's
	// image): a hidden attachment id, a preview, Choose and Remove.
	document.addEventListener( 'click', function ( e ) {
		var pick = e.target.closest( '[data-sc-image-pick]' );
		var clear = e.target.closest( '[data-sc-image-clear]' );
		var field = ( pick || clear ) && ( pick || clear ).closest( '[data-sc-image-field]' );
		if ( ! field ) {
			return;
		}
		e.preventDefault();
		var input = field.querySelector( 'input[type="hidden"]' );
		var preview = field.querySelector( '.sc-image-field__preview' );
		var remove = field.querySelector( '[data-sc-image-clear]' );
		if ( clear ) {
			input.value = '';
			preview.innerHTML = '';
			remove.hidden = true;
			return;
		}
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		var frame = window.wp.media( { library: { type: 'image' }, multiple: false } );
		frame.on( 'select', function () {
			var image = frame.state().get( 'selection' ).first().toJSON();
			var sizes = image.sizes || {};
			var thumb = ( sizes.thumbnail || sizes.medium || image ).url;
			input.value = image.id;
			preview.innerHTML = '';
			var img = document.createElement( 'img' );
			img.src = thumb;
			img.alt = '';
			img.width = 40;
			img.height = 40;
			preview.appendChild( img );
			remove.hidden = false;
		} );
		frame.open();
	} );

	document.addEventListener( 'click', function ( e ) {
		var add = e.target.closest( '.sc-rows__add' );
		var del = e.target.closest( '.sc-rows__remove' );
		if ( add ) {
			var table = document.getElementById( add.dataset.for );
			if ( ! table ) {
				return;
			}
			var body = table.tBodies[ 0 ];
			var last = body.rows[ body.rows.length - 1 ];
			var row = last.cloneNode( true );
			// Next free index: after removing a middle row, rows.length would reuse a taken one.
			var n = 0;
			body.querySelectorAll( 'input[name]' ).forEach( function ( input ) {
				var m = input.name.match( /\[(\d+)\]\[[a-z_]+\]$/ );
				if ( m ) {
					n = Math.max( n, parseInt( m[ 1 ], 10 ) + 1 );
				}
			} );
			row.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.value = '';
				input.placeholder = '';
				input.name = input.name.replace( /\[\d+\](\[[a-z_]+\])$/, '[' + n + ']$1' );
			} );
			body.appendChild( row );
			row.querySelector( 'input' ).focus();
		} else if ( del ) {
			var tr = del.closest( 'tr' );
			if ( tr.parentNode.rows.length > 1 ) {
				tr.remove();
			} else {
				tr.querySelectorAll( 'input' ).forEach( function ( input ) {
					input.value = '';
				} );
			}
		}
	} );

	// Shipment tracking: show "carrier name / link" only for a custom carrier.
	function syncShipCarrier( select ) {
		var custom = select.value === 'custom';
		document.querySelectorAll( '[data-sc-ship-custom]' ).forEach( function ( el ) {
			el.hidden = ! custom;
		} );
	}
	document.addEventListener( 'change', function ( e ) {
		if ( e.target.matches && e.target.matches( '[data-sc-ship-carrier]' ) ) {
			syncShipCarrier( e.target );
		}
	} );
} )();
