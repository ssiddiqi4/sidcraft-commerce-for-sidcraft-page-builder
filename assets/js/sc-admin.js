/**
 * Sidcraft Commerce — admin: add / remove rows in Store → Settings tables
 * (shipping zones, tax rates, carriers), and the custom-carrier fields in
 * the order screen's Shipment tracking box.
 */
( function () {
	'use strict';

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
			var n = body.rows.length;
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
