/**
 * Sidcraft Commerce — events: ticket selector, attendee uploads at checkout.
 */
( function () {
	'use strict';

	var config = window.sidccofoCommerce || {};
	var events = window.sidccofoEvents || {};
	var i18n = events.i18n || {};

	function formatPrice( amount ) {
		var p = config.price || {};
		var decimals = parseInt( p.decimals, 10 );
		decimals = isNaN( decimals ) ? 2 : decimals;
		var parts = Math.abs( amount ).toFixed( decimals ).split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, p.thousand == null ? ',' : p.thousand );
		var number = parts.join( p.decimal == null ? '.' : p.decimal );
		var symbol = p.symbol == null ? '$' : p.symbol;
		return ( amount < 0 ? '-' : '' ) + ( p.position === 'right' ? number + symbol : symbol + number );
	}

	function api( path, body ) {
		if ( window.sidccofoCart && window.sidccofoCart.api ) {
			return window.sidccofoCart.api( path, 'POST', body );
		}
		return fetch( String( config.api || '/wp-json/sidcraft-commerce/v1/' ) + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: config.lang ? { 'Content-Type': 'application/json', 'X-Sidccofo-Lang': config.lang } : { 'Content-Type': 'application/json' },
			body: JSON.stringify( body ),
		} ).then( function ( r ) {
			return r.json().then( function ( json ) {
				if ( ! r.ok ) {
					var err = new Error( ( json && json.message ) || i18n.error );
					err.data = json;
					throw err;
				}
				return json;
			} );
		} );
	}

	/* ---------------------------- Ticket selector ---------------------------- */

	function unitPrice( input, qty ) {
		var price = parseFloat( input.dataset.price || '0' ) || 0;
		var rates = [];
		try {
			rates = JSON.parse( input.dataset.rates || '[]' );
		} catch ( e ) {
			rates = [];
		}
		rates.forEach( function ( r ) {
			if ( qty >= r[ 0 ] ) {
				price = r[ 1 ];
			}
		} );
		return price;
	}

	function clamp( input, value, direction ) {
		var max = parseInt( input.max || '0', 10 );
		var min = parseInt( input.dataset.min || '0', 10 );
		value = isNaN( value ) ? 0 : value;
		if ( value > 0 && min > 1 && value < min ) {
			value = direction < 0 ? 0 : min;
		}
		if ( max >= 0 && ! isNaN( max ) && value > max ) {
			value = max;
		}
		return Math.max( 0, value );
	}

	function refreshTotal( form ) {
		var count = 0;
		var sum = 0;
		form.querySelectorAll( '[data-sc-ev-qty]' ).forEach( function ( input ) {
			var qty = parseInt( input.value, 10 ) || 0;
			count += qty;
			sum += qty * unitPrice( input, qty );
		} );
		var out = form.querySelector( '[data-sc-ev-total]' );
		if ( out ) {
			var money = sum > 0 ? formatPrice( sum ) : ( i18n.free || 'Free' );
			out.textContent = ! count ? '' : ( 1 === count ? String( i18n.oneTicket || '1 ticket · %s' ).replace( '%s', money ) : String( i18n.manyTickets || '%1$d tickets · %2$s' ).replace( '%1$d', count ).replace( '%2$s', money ) );
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '[data-sc-ev-step]' );
		if ( ! btn ) {
			return;
		}
		var input = btn.closest( '.sc-qty' ).querySelector( '[data-sc-ev-qty]' );
		var dir = parseInt( btn.dataset.scEvStep, 10 );
		input.value = clamp( input, ( parseInt( input.value, 10 ) || 0 ) + dir, dir );
		refreshTotal( btn.closest( '[data-sc-ev-form]' ) );
	} );

	document.addEventListener( 'change', function ( e ) {
		if ( e.target.matches && e.target.matches( '[data-sc-ev-qty]' ) ) {
			e.target.value = clamp( e.target, parseInt( e.target.value, 10 ), 1 );
			refreshTotal( e.target.closest( '[data-sc-ev-form]' ) );
		}
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest && e.target.closest( '[data-sc-ev-form]' );
		if ( ! form ) {
			return;
		}
		e.preventDefault();
		var notice = form.querySelector( '[data-sc-ev-error]' );
		var button = form.querySelector( '[data-sc-ev-submit]' );
		var tickets = {};
		var any = false;
		form.querySelectorAll( '[data-sc-ev-qty]' ).forEach( function ( input ) {
			var qty = parseInt( input.value, 10 ) || 0;
			var m = input.name.match( /^t\[(.+)\]$/ );
			if ( qty > 0 && m ) {
				tickets[ m[ 1 ] ] = qty;
				any = true;
			}
		} );
		notice.hidden = true;
		notice.classList.remove( 'sc-notice--success' );
		notice.classList.add( 'sc-notice--error' );
		if ( ! any ) {
			notice.textContent = i18n.choose;
			notice.hidden = false;
			return;
		}
		var label = button.textContent;
		button.setAttribute( 'aria-busy', 'true' );
		button.textContent = i18n.adding;
		api( 'events/add', { event_id: parseInt( form.dataset.eventId, 10 ), tickets: tickets } )
			.then( function ( data ) {
				if ( window.sidccofoCart && window.sidccofoCart.applyFragments ) {
					window.sidccofoCart.applyFragments( data.fragments );
				}
				document.dispatchEvent( new CustomEvent( 'sc:cart_updated', { detail: { data: data } } ) );
				if ( data.redirect ) {
					window.location.href = data.redirect;
					return;
				}
				notice.textContent = data.message;
				notice.classList.remove( 'sc-notice--error' );
				notice.classList.add( 'sc-notice--success' );
				notice.hidden = false;
				form.querySelectorAll( '[data-sc-ev-qty]' ).forEach( function ( input ) {
					input.value = 0;
				} );
				refreshTotal( form );
				button.removeAttribute( 'aria-busy' );
				button.textContent = label;
			} )
			.catch( function ( err ) {
				notice.textContent = err.message || i18n.error;
				notice.hidden = false;
				button.removeAttribute( 'aria-busy' );
				button.textContent = label;
			} );
	} );

	document.querySelectorAll( '[data-sc-ev-form]' ).forEach( refreshTotal );

	/* ------------------------------- Checkout ------------------------------- */

	// Attendee file uploads: sent first, the form carries the token.
	document.addEventListener( 'change', function ( e ) {
		var input = e.target;
		if ( ! input.matches || ! input.matches( '[data-scev-upload]' ) ) {
			return;
		}
		var field = input.closest( '.sc-field' );
		var token = field.querySelector( '[data-scev-token]' );
		var status = field.querySelector( '[data-scev-status]' );
		token.value = '';
		if ( ! input.files || ! input.files[ 0 ] ) {
			status.textContent = '';
			return;
		}
		var file = input.files[ 0 ];
		var max = parseFloat( input.dataset.max || '0' );
		if ( max && file.size > max * 1048576 ) {
			status.textContent = '✕ ' + max + ' MB';
			status.className = 'sc-ev-upload-status is-error';
			return;
		}
		var body = new FormData();
		body.append( 'file', file );
		status.textContent = i18n.uploading;
		status.className = 'sc-ev-upload-status';
		fetch( String( config.api || '/wp-json/sidcraft-commerce/v1/' ) + 'events/upload', { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json().then( function ( json ) {
					if ( ! r.ok ) {
						throw new Error( ( json && json.message ) || i18n.error );
					}
					return json;
				} );
			} )
			.then( function ( json ) {
				token.value = json.token;
				status.textContent = '✓ ' + ( i18n.uploaded || '' ) + ': ' + json.name;
				status.className = 'sc-ev-upload-status is-ok';
			} )
			.catch( function ( err ) {
				input.value = '';
				status.textContent = err.message || i18n.error;
				status.className = 'sc-ev-upload-status is-error';
			} );
	} );

	// "Use my details" copies the buyer's name, email and phone into ticket 1.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '[data-sc-ev-copy]' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var form = btn.closest( 'form' ) || document;
		var box = btn.closest( 'fieldset' );
		var val = function ( id ) {
			var el = form.querySelector( '#sc-' + id );
			return el ? el.value.trim() : '';
		};
		var set = function ( role, value ) {
			var el = box.querySelector( '[data-sc-ev-role="' + role + '"]' );
			if ( el && value ) {
				el.value = value;
				el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		};
		set( 'name', ( val( 'first_name' ) + ' ' + val( 'last_name' ) ).trim() );
		set( 'email', val( 'email' ) );
		set( 'phone', val( 'phone' ) );
		btn.textContent = '✓ ' + ( i18n.copied || '' );
	} );
}() );
