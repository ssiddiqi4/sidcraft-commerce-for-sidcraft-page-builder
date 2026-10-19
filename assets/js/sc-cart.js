/**
 * Sidcraft Commerce — add to cart without reloads + cache-safe cart fragments.
 * Vanilla JS, no dependencies. Talks to /wp-json/sidcraft-commerce/v1/.
 *
 * Public API: window.sidccofoCart.{ add, update, applyFragments, refresh, api }
 * Events on document:
 *   'sc:added_to_cart'     detail: { productId, quantity, data, button }
 *   'sc:add_to_cart_error' detail: { productId, message, button }
 *   'sc:cart_updated'      detail: { data }
 */
( function () {
	'use strict';

	var config = window.sidccofoCommerce || {};
	var i18n = config.i18n || {};
	var STORE_KEY = 'sidccofo_fragments';

	/**
	 * Call the store API. Resolves with JSON; rejects with an Error whose
	 * message is the server's (already translated) message.
	 */
	function api( path, method, body, noNonce ) {
		var opts = { method: method || 'GET', credentials: 'same-origin', headers: {} };
		if ( body ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		// Logged-in shoppers send WordPress's REST nonce, so the store knows who
		// they are (checkout, member prices, reward points). Guests send none:
		// their pages may be cached, and the cart doesn't need it.
		if ( config.restNonce && ! noNonce ) {
			opts.headers[ 'X-WP-Nonce' ] = config.restNonce;
		}
		// The page's language (translated stores): names, links and emails follow it.
		if ( config.lang ) {
			opts.headers[ 'X-Sidccofo-Lang' ] = config.lang;
		}
		return fetch( String( config.api || '/wp-json/sidcraft-commerce/v1/' ) + path, opts ).then( function ( r ) {
			return r.json().then(
				function ( json ) {
					// A stale nonce (logged out in another tab): try once as a guest.
					if ( 403 === r.status && json && 'rest_cookie_invalid_nonce' === json.code && ! noNonce ) {
						return api( path, method, body, true );
					}
					if ( ! r.ok ) {
						var err = new Error( ( json && json.message ) || i18n.error );
						err.data = json;
						throw err;
					}
					return json;
				},
				function () {
					throw new Error( i18n.error );
				}
			);
		} );
	}

	function getCookie( name ) {
		var match = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
		return match ? decodeURIComponent( match[ 1 ] ) : '';
	}

	/* ---------------------------------------------------------------------
	 * Fragments: selector → HTML pairs from the server.
	 * ------------------------------------------------------------------- */

	function applyFragments( fragments ) {
		if ( ! fragments ) {
			return;
		}
		Object.keys( fragments ).forEach( function ( selector ) {
			document.querySelectorAll( selector ).forEach( function ( el ) {
				var tpl = document.createElement( 'template' );
				tpl.innerHTML = String( fragments[ selector ] ).trim();
				var next = tpl.content.firstElementChild;
				if ( next ) {
					el.replaceWith( next );
				}
			} );
		} );
	}

	/** Only the light, page-independent fragments are worth caching. */
	function storeFragments( data ) {
		try {
			var keep = {};
			Object.keys( data.fragments || {} ).forEach( function ( k ) {
				if ( k.indexOf( 'sc-mini-cart' ) !== -1 ) {
					keep[ k ] = data.fragments[ k ];
				}
			} );
			sessionStorage.setItem( STORE_KEY, JSON.stringify( { hash: data.cart_hash, fragments: keep } ) );
		} catch ( e ) {
			/* Private mode or full storage: fragments simply refetch. */
		}
	}

	function bumpCount() {
		document.querySelectorAll( '.sc-mini-cart__count' ).forEach( function ( el ) {
			el.classList.remove( 'is-bumped' );
			void el.offsetWidth; // Restart the animation.
			el.classList.add( 'is-bumped' );
		} );
	}

	/**
	 * Fill cart placeholders on page load (pages are cached with "0").
	 *  - No sidccofo_items_in_cart cookie → empty cart → no request.
	 *  - sessionStorage copy matches sidccofo_cart_hash → reuse, no request.
	 *  - Otherwise → one GET /cart.
	 */
	function refresh( force ) {
		if ( ! force && ! document.querySelector( '.sc-mini-cart__count, .sc-mini-cart__total' ) ) {
			return Promise.resolve();
		}
		if ( ! force && ! getCookie( 'sidccofo_items_in_cart' + ( config.cookieSuffix || '' ) ) ) {
			return Promise.resolve();
		}
		var hash = getCookie( 'sidccofo_cart_hash' + ( config.cookieSuffix || '' ) );
		if ( ! force ) {
			try {
				var cached = JSON.parse( sessionStorage.getItem( STORE_KEY ) || 'null' );
				if ( cached && cached.hash && cached.hash === hash ) {
					applyFragments( cached.fragments );
					return Promise.resolve();
				}
			} catch ( e ) {
				/* Ignore and fetch. */
			}
		}
		return api( 'cart' )
			.then( function ( data ) {
				applyFragments( data.fragments );
				storeFragments( data );
			} )
			.catch( function () {} );
	}

	/* ---------------------------------------------------------------------
	 * Feedback: inline notice inside a form, or a toast (grid cards).
	 * ------------------------------------------------------------------- */

	var toastEl;
	function toast( html, isError ) {
		if ( ! toastEl ) {
			toastEl = document.createElement( 'div' );
			toastEl.className = 'sc-toast';
			toastEl.setAttribute( 'role', 'status' );
			toastEl.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( toastEl );
		}
		toastEl.classList.toggle( 'is-error', !! isError );
		toastEl.innerHTML = html;
		toastEl.classList.add( 'is-visible' );
		clearTimeout( toastEl._t );
		toastEl._t = setTimeout( function () {
			toastEl.classList.remove( 'is-visible' );
		}, 4000 );
	}

	function escapeHtml( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	function notify( button, message, isError ) {
		var form = button && button.closest( '[data-sc-atc-form], [data-sc-gift-form]' );
		var notice = form && form.querySelector( '.sc-atc__notice' );
		var html = escapeHtml( message );
		if ( ! isError && config.cartUrl ) {
			html += ' <a href="' + escapeHtml( config.cartUrl ) + '">' + escapeHtml( i18n.viewCart || 'View cart' ) + '</a>';
		}
		if ( notice ) {
			notice.innerHTML = html;
			notice.classList.toggle( 'is-error', !! isError );
		} else {
			toast( html, isError );
		}
	}

	/* ---------------------------------------------------------------------
	 * Cart actions.
	 * ------------------------------------------------------------------- */

	/**
	 * The item options chosen in an Add to Cart form: { group: value | [values] }.
	 */
	function optionsFor( button ) {
		var form = button && button.closest( '[data-sc-atc-form]' );
		var out = {};
		if ( ! form || ! form.querySelector( '[data-sc-options]' ) ) {
			return out;
		}
		form.querySelectorAll( '[name^="sidccofo_options["]' ).forEach( function ( input ) {
			var m = input.name.match( /^sidccofo_options\[([^\]]+)\](\[\])?$/ );
			if ( ! m || input.disabled ) {
				return;
			}
			if ( ( input.type === 'radio' || input.type === 'checkbox' ) && ! input.checked ) {
				if ( m[ 2 ] && ! out[ m[ 1 ] ] ) {
					out[ m[ 1 ] ] = [];
				}
				return;
			}
			if ( m[ 2 ] ) {
				( out[ m[ 1 ] ] = out[ m[ 1 ] ] || [] ).push( input.value );
			} else {
				out[ m[ 1 ] ] = input.value;
			}
		} );
		return out;
	}

	function showOptionErrors( button, fields ) {
		var form = button && button.closest( '[data-sc-atc-form]' );
		if ( ! form ) {
			return;
		}
		var first = null;
		form.querySelectorAll( '[data-sc-option]' ).forEach( function ( group ) {
			var msg = fields && fields[ group.dataset.scOption ] ? fields[ group.dataset.scOption ] : '';
			var el = group.querySelector( '[data-sc-option-error]' );
			if ( el ) {
				el.textContent = msg;
			}
			group.classList.toggle( 'has-error', !! msg );
			var control = group.querySelector( 'input, select' );
			if ( control ) {
				if ( msg ) {
					control.setAttribute( 'aria-invalid', 'true' );
				} else {
					control.removeAttribute( 'aria-invalid' );
				}
			}
			if ( msg && ! first ) {
				first = control;
			}
		} );
		if ( first ) {
			first.focus();
		}
	}

	function add( productId, quantity, button ) {
		var label = button ? button.innerHTML : '';
		var options = optionsFor( button );
		var body = { product_id: Number( productId ), quantity: Number( quantity ) || 1 };
		if ( Object.keys( options ).length ) {
			body.options = options;
		}
		showOptionErrors( button, null );
		if ( button ) {
			button.setAttribute( 'aria-busy', 'true' );
			button.textContent = i18n.adding || 'Adding…';
		}

		return api( 'cart/add', 'POST', body )
			.then( function ( data ) {
				applyFragments( data.fragments );
				storeFragments( data );
				bumpCount();
				notify( button, data.message, false );
				if ( button ) {
					button.innerHTML = label;
					button.classList.add( 'is-added' );
					setTimeout( function () {
						button.classList.remove( 'is-added' );
					}, 1600 );
				}
				document.dispatchEvent(
					new CustomEvent( 'sc:added_to_cart', {
						detail: { productId: productId, quantity: quantity, data: data, button: button },
					} )
				);
				return data;
			} )
			.catch( function ( err ) {
				if ( button ) {
					button.innerHTML = label;
				}
				if ( err.data && err.data.data && err.data.data.fields ) {
					showOptionErrors( button, err.data.data.fields );
				}
				notify( button, err.message, true );
				document.dispatchEvent(
					new CustomEvent( 'sc:add_to_cart_error', {
						detail: { productId: productId, message: err.message, button: button },
					} )
				);
			} )
			.finally( function () {
				if ( button ) {
					button.removeAttribute( 'aria-busy' );
				}
			} );
	}

	/**
	 * Set a line's quantity (0 removes). context 'cart' or 'checkout'
	 * re-renders that panel in the same response.
	 */
	function update( productId, quantity, context, line ) {
		return api( 'cart/update', 'POST', {
			product_id: Number( productId ) || 0,
			line: line || '',
			quantity: Math.max( 0, Number( quantity ) || 0 ),
			context: context || '',
		} ).then(
			function ( data ) {
				applyFragments( data.fragments );
				storeFragments( data );
				document.dispatchEvent( new CustomEvent( 'sc:cart_updated', { detail: { data: data } } ) );
				return data;
			},
			function ( err ) {
				// 400 still carries fresh fragments (e.g. clamped to stock).
				if ( err.data && err.data.fragments ) {
					applyFragments( err.data.fragments );
				}
				throw err;
			}
		);
	}

	function quantityFor( button ) {
		var form = button.closest( '[data-sc-atc-form]' );
		var input = form && form.querySelector( 'input[name="quantity"]' );
		var qty = input ? parseInt( input.value, 10 ) : parseInt( button.dataset.quantity || '1', 10 );
		return qty > 0 ? qty : 1;
	}

	/* ---------------------------------------------------------------------
	 * Delegated listeners: work for Units added later (quick view, panels
	 * re-rendered from fragments, the Sidcraft editor canvas).
	 * ------------------------------------------------------------------- */

	document.addEventListener( 'click', function ( e ) {
		var step = e.target.closest( '[data-sc-qty]' );
		if ( step ) {
			var input = step.parentNode.querySelector( '.sc-qty__input' );
			var max = parseInt( input.max || '0', 10 );
			var min = parseInt( input.min || '1', 10 );
			var next = ( parseInt( input.value, 10 ) || 0 ) + parseInt( step.dataset.scQty, 10 );
			input.value = Math.max( min, max > 0 ? Math.min( max, next ) : next );
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			return;
		}

		var button = e.target.closest( '[data-sc-add-to-cart]' );
		if ( ! button || button.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}
		e.preventDefault();
		add( button.dataset.productId, quantityFor( button ), button );
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest( '[data-sc-atc-form]' );
		if ( ! form ) {
			return;
		}
		e.preventDefault();
		var button = form.querySelector( '[data-sc-add-to-cart]' );
		if ( button ) {
			add( button.dataset.productId, quantityFor( button ), button );
		}
	} );

	/* ---------------------------------------------------------------------
	 * Item options: show the price with the chosen extras.
	 * ------------------------------------------------------------------- */

	function refreshOptionsTotal( form ) {
		var total = form.querySelector( '[data-sc-options-total]' );
		if ( ! total ) {
			return;
		}
		var extra = 0;
		form.querySelectorAll( '[data-sc-options] input:checked, [data-sc-options] select' ).forEach( function ( input ) {
			var price = input.tagName === 'SELECT' ? ( input.selectedOptions[ 0 ] && input.selectedOptions[ 0 ].dataset.price ) : input.dataset.price;
			extra += parseFloat( price || '0' ) || 0;
		} );
		if ( Math.abs( extra ) < 0.005 ) {
			total.hidden = true;
			return;
		}
		// Choices can lower the price too, never below zero (as in the cart).
		var sum = Math.max( 0, ( parseFloat( total.dataset.base || '0' ) || 0 ) + extra );
		total.textContent = ( i18n.withOptions || '%s' ).replace( '%s', formatPrice( sum ) );
		total.hidden = false;
	}

	// Mirrors SidcraftCommerce\format_price() with the store's settings.
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

	document.addEventListener( 'change', function ( e ) {
		var form = e.target.closest && e.target.closest( '[data-sc-atc-form]' );
		if ( form && e.target.closest( '[data-sc-options]' ) ) {
			refreshOptionsTotal( form );
			var notice = form.querySelector( '.sc-atc__notice.is-error' );
			if ( notice ) {
				notice.textContent = '';
				notice.classList.remove( 'is-error' );
			}
			var group = e.target.closest( '[data-sc-option]' );
			if ( group && group.classList.contains( 'has-error' ) ) {
				group.classList.remove( 'has-error' );
				var err = group.querySelector( '[data-sc-option-error]' );
				if ( err ) {
					err.textContent = '';
				}
			}
		}
	} );

	/* ---------------------------------------------------------------------
	 * Gift card products: amount chips, custom amount, recipient form.
	 * ------------------------------------------------------------------- */

	document.addEventListener( 'change', function ( e ) {
		if ( e.target.name === 'amount_choice' ) {
			var form = e.target.closest( '[data-sc-gift-form]' );
			var custom = form && form.querySelector( '[data-sc-gift-custom]' );
			if ( custom ) {
				custom.hidden = e.target.value !== 'custom';
				if ( ! custom.hidden ) {
					custom.querySelector( 'input' ).focus();
				}
			}
		}
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest( '[data-sc-gift-form]' );
		if ( ! form ) {
			return;
		}
		e.preventDefault();
		var button = form.querySelector( '[data-sc-gift-submit]' );
		if ( button.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}
		form.querySelectorAll( '.sc-field__error' ).forEach( function ( el ) {
			el.textContent = '';
		} );
		var choice = form.querySelector( 'input[name="amount_choice"]:checked' );
		var amount = choice ? choice.value : 'custom';
		if ( amount === 'custom' ) {
			var input = form.querySelector( 'input[name="custom_amount"]' );
			amount = input ? input.value : '';
		}
		var body = {
			product_id: Number( form.dataset.productId ),
			amount: amount,
			recipient_name: form.recipient_name.value,
			recipient_email: form.recipient_email.value,
			sender: form.sender.value,
			send_date: form.send_date.value,
			message: form.message.value,
		};
		var label = button.innerHTML;
		button.setAttribute( 'aria-busy', 'true' );
		button.textContent = i18n.adding || 'Adding…';
		api( 'cart/gift-card', 'POST', body )
			.then( function ( data ) {
				applyFragments( data.fragments );
				storeFragments( data );
				bumpCount();
				notify( button, data.message, false );
				form.recipient_name.value = '';
				form.recipient_email.value = '';
				form.message.value = '';
				form.send_date.value = '';
				document.dispatchEvent( new CustomEvent( 'sc:added_to_cart', { detail: { productId: body.product_id, quantity: 1, data: data, button: button } } ) );
			} )
			.catch( function ( err ) {
				var fields = ( err.data && err.data.data && err.data.data.fields ) || {};
				Object.keys( fields ).forEach( function ( key ) {
					var slot = form.querySelector( '#sc-gc-' + key + '-error' );
					if ( slot ) {
						slot.textContent = fields[ key ];
					}
				} );
				notify( button, err.message, true );
			} )
			.finally( function () {
				button.innerHTML = label;
				button.removeAttribute( 'aria-busy' );
			} );
	} );

	window.addEventListener( 'pageshow', function ( e ) {
		if ( e.persisted ) {
			refresh();
		}
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			refresh();
		} );
	} else {
		refresh();
	}

	window.sidccofoCart = { add: add, update: update, applyFragments: applyFragments, refresh: refresh, api: api };
} )();
