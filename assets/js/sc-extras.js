/**
 * Sidcraft Commerce: store tools front end.
 *
 * One small script for every optional tool. Each section does nothing unless
 * its markup is on the page.
 *
 *   1. Cart extras:  [data-sc-extra="key"] inputs save a choice in the cart
 *      (delivery method, date, deposit, donation…) and refresh the totals.
 *
 * Builds on window.sidccofoCart (sc-cart.js).
 */
( function () {
	'use strict';

	var cart = window.sidccofoCart;
	var config = window.sidccofoCommerce || {};
	var i18n = config.i18n || {};
	if ( ! cart ) {
		return;
	}

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}
	window.sidccofoExtras = { esc: esc, ready: ready };

	/* ------------------------------------------------------------------
	 * 1. Cart extras.
	 * ---------------------------------------------------------------- */

	var extraTimer = 0;

	function contextOf( el ) {
		if ( el.closest( '[data-sc-checkout]' ) ) {
			return 'checkout';
		}
		return el.closest( '.sc-cart' ) ? 'cart' : '';
	}

	function saveExtra( el ) {
		var value;
		if ( el.type === 'checkbox' ) {
			value = el.checked ? ( el.value || '1' ) : '';
		} else if ( el.type === 'radio' ) {
			if ( ! el.checked ) {
				return;
			}
			value = el.value;
		} else {
			value = el.value;
		}
		var ctx = contextOf( el );
		var scope = el.closest( '[data-sc-checkout], .sc-cart' ) || document;
		clearTimeout( extraTimer );
		extraTimer = setTimeout( function () {
			scope.setAttribute( 'aria-busy', 'true' );
			cart.api( 'extras/cart', 'POST', { key: el.getAttribute( 'data-sc-extra' ), value: value, context: ctx } )
				.then( function ( data ) {
					cart.applyFragments( data.fragments );
					document.dispatchEvent( new CustomEvent( 'sidccofo:extra', { detail: { key: el.getAttribute( 'data-sc-extra' ), data: data } } ) );
				} )
				.catch( function ( err ) {
					var slot = scope.querySelector( '[data-sc-extra-error]' );
					if ( slot ) {
						slot.textContent = err.message;
					}
				} )
				.finally( function () {
					scope.removeAttribute( 'aria-busy' );
				} );
		}, 200 );
	}

	document.addEventListener( 'change', function ( e ) {
		if ( e.target.matches && e.target.matches( '[data-sc-extra]' ) ) {
			saveExtra( e.target );
		}
	} );
	document.addEventListener( 'input', function ( e ) {
		if ( e.target.matches && e.target.matches( 'input[data-sc-extra][type="number"], input[data-sc-extra][type="text"]' ) && e.target.hasAttribute( 'data-sc-live' ) ) {
			saveExtra( e.target );
		}
	} );
} )();

/* --------------------------------------------------------------------
 * Notify me when back in stock.
 * ------------------------------------------------------------------ */
( function () {
	'use strict';
	var cart = window.sidccofoCart;
	if ( ! cart ) {
		return;
	}
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest && e.target.closest( '[data-sc-notify]' );
		if ( ! form ) {
			return;
		}
		e.preventDefault();
		var msg = form.querySelector( '.sc-notify__msg' );
		cart.api( 'stock/notify', 'POST', { product_id: form.dataset.product, email: form.email.value, website: form.website ? form.website.value : '' }, true )
			.then( function ( data ) {
				msg.textContent = data.message;
				form.email.value = '';
			} )
			.catch( function ( err ) {
				msg.textContent = err.message;
			} );
	} );
} )();
