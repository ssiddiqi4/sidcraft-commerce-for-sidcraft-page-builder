/**
 * Sidcraft Commerce — Quick view modal.
 *
 * - Native <dialog>: focus trap, Esc and backdrop handled by the browser.
 * - HTML is fetched once per product and memoised.
 * - Prefetch on hover/focus so the modal usually opens instantly.
 * - The add-to-cart form inside the modal is handled by sc-cart.js's
 *   delegated listeners; nothing extra to wire.
 */
( function () {
	'use strict';

	var cart = window.sidccofoCart;
	var i18n = ( window.sidccofoCommerce && window.sidccofoCommerce.i18n ) || {};
	if ( ! cart ) {
		return;
	}

	var cache = new Map();
	var dialog, body, lastTrigger;

	function load( productId ) {
		if ( ! cache.has( productId ) ) {
			cache.set(
				productId,
				cart.api( 'quick-view/' + encodeURIComponent( productId ) )
					.then( function ( data ) {
						return data.html;
					} )
					.catch( function ( err ) {
						cache.delete( productId ); // Allow a retry.
						throw err;
					} )
			);
		}
		return cache.get( productId );
	}

	function ensureDialog() {
		if ( dialog ) {
			return;
		}
		dialog = document.createElement( 'dialog' );
		dialog.className = 'sc-unit sc-qv-dialog';
		dialog.setAttribute( 'aria-labelledby', 'sc-qv-title' );
		dialog.innerHTML =
			'<button type="button" class="sc-qv-dialog__close" aria-label="' +
			( i18n.close || 'Close' ) +
			'">&times;</button><div class="sc-qv-dialog__body"></div>';
		body = dialog.querySelector( '.sc-qv-dialog__body' );
		document.body.appendChild( dialog );

		dialog.querySelector( '.sc-qv-dialog__close' ).addEventListener( 'click', function () {
			dialog.close();
		} );
		// Click on the backdrop (the dialog element itself) closes.
		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog ) {
				dialog.close();
			}
		} );
		dialog.addEventListener( 'close', function () {
			if ( lastTrigger ) {
				lastTrigger.focus();
			}
		} );
	}

	function open( trigger ) {
		var productId = trigger.dataset.scQuickView;
		lastTrigger = trigger;
		ensureDialog();

		// Inherit the colour scheme of the Unit that opened it.
		var unit = trigger.closest( '.sc-unit' );
		dialog.classList.toggle( 'sc-scheme-dark', !! ( unit && unit.classList.contains( 'sc-scheme-dark' ) ) );
		if ( unit && unit.style.getPropertyValue( '--sc-accent' ) ) {
			dialog.style.setProperty( '--sc-accent', unit.style.getPropertyValue( '--sc-accent' ) );
		}

		body.innerHTML = '';
		dialog.setAttribute( 'aria-busy', 'true' );
		if ( ! dialog.open ) {
			dialog.showModal();
		}

		load( productId )
			.then( function ( html ) {
				body.innerHTML = html;
				document.dispatchEvent( new CustomEvent( 'sc:quick_view_opened', { detail: { productId: productId, dialog: dialog } } ) );
			} )
			.catch( function ( err ) {
				body.innerHTML = '<p style="padding:32px">' + ( err.message || i18n.error ) + '</p>';
			} )
			.finally( function () {
				dialog.removeAttribute( 'aria-busy' );
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest( '[data-sc-quick-view]' );
		if ( trigger ) {
			e.preventDefault();
			open( trigger );
		}
	} );

	// Prefetch on intent.
	function prefetch( e ) {
		var trigger = e.target.closest && e.target.closest( '[data-sc-quick-view]' );
		if ( trigger ) {
			load( trigger.dataset.scQuickView ).catch( function () {} );
		}
	}
	document.addEventListener( 'pointerover', prefetch, { passive: true } );
	document.addEventListener( 'focusin', prefetch );
} )();
