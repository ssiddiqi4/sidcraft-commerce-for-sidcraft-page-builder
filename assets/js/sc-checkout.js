/**
 * Sidcraft Commerce — Cart page and Checkout behaviour.
 *
 * Cart: quantity changes and removals update the server and re-render the
 *       cart panel from the response (debounced while the stepper is used).
 * Checkout: submits to POST /checkout, shows field errors inline, then
 *       redirects to the payment page (or POSTs a form to it, e.g. PayU),
 *       or to the order-received page.
 *       Country/state changes swap the state list and re-price shipping
 *       and tax via POST /cart/location.
 */
( function () {
	'use strict';

	var cart = window.sidccofoCart;
	var i18n = ( window.sidccofoCommerce && window.sidccofoCommerce.i18n ) || {};
	if ( ! cart ) {
		return;
	}

	/* -------------------------------- Cart -------------------------------- */

	var timers = {};

	function status( msg, isError ) {
		var el = document.querySelector( '.sc-cart__status' );
		if ( el ) {
			el.textContent = msg || '';
			el.classList.toggle( 'is-error', !! isError );
		}
	}

	function updateLine( item, qty ) {
		var id = item.dataset.productId;
		var line = item.dataset.lineKey || '';
		var timerKey = line || id;
		var panel = item.closest( '.sc-cart' );
		if ( panel ) {
			panel.setAttribute( 'aria-busy', 'true' );
		}
		clearTimeout( timers[ timerKey ] );
		timers[ timerKey ] = setTimeout( function () {
			cart.update( id, qty, 'cart', ( line.indexOf( 'gc_' ) === 0 || line.indexOf( 'op_' ) === 0 ) ? line : '' )
				.then( function () {
					status( '' );
				} )
				.catch( function ( err ) {
					status( err.message, true );
				} )
				.finally( function () {
					var p = document.querySelector( '.sc-cart' );
					if ( p ) {
						p.removeAttribute( 'aria-busy' );
					}
				} );
		}, 350 );
	}

	document.addEventListener( 'change', function ( e ) {
		var input = e.target.closest( '[data-sc-cart-qty]' );
		if ( input ) {
			updateLine( input.closest( '.sc-cart__item' ), parseInt( input.value, 10 ) || 0 );
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		var remove = e.target.closest( '[data-sc-remove]' );
		if ( remove ) {
			e.preventDefault();
			var item = remove.closest( '.sc-cart__item' );
			item.classList.add( 'is-removing' );
			updateLine( item, 0 );
		}
	} );

	/* ------------------------------- Coupon ------------------------------- */

	function couponContext( el ) {
		return el.closest( '.sc-checkout-summary, [data-sc-checkout]' ) ? 'checkout' : 'cart';
	}

	function couponMessage( text, isError ) {
		var slot = document.getElementById( 'sc-coupon-error' );
		if ( slot ) {
			slot.textContent = text || '';
			slot.classList.toggle( 'is-success', ! isError && !! text );
		}
	}

	function couponRequest( box, path, body ) {
		var context = couponContext( box );
		var panel = box.closest( '.sc-cart, .sc-checkout-summary' );
		var buttons = box.querySelectorAll( 'button' );
		body.context = context;
		buttons.forEach( function ( b ) {
			b.setAttribute( 'aria-busy', 'true' );
		} );
		if ( panel ) {
			panel.setAttribute( 'aria-busy', 'true' );
		}
		return cart
			.api( path, 'POST', body )
			.then( function ( data ) {
				cart.applyFragments( data.fragments );
				couponMessage( data.message, false );
				syncPaid();
				document.dispatchEvent( new CustomEvent( 'sc:cart_updated', { detail: { data: data } } ) );
			} )
			.catch( function ( err ) {
				// Errors still carry fresh fragments; keep what the customer typed.
				var typed = body.code || '';
				if ( err.data && err.data.fragments ) {
					cart.applyFragments( err.data.fragments );
				}
				var input = document.querySelector( '[data-sc-coupon-input]' );
				if ( input && typed ) {
					input.value = typed;
					input.setAttribute( 'aria-invalid', 'true' );
					input.focus();
				}
				couponMessage( err.message, true );
			} )
			.finally( function () {
				document.querySelectorAll( '.sc-cart, .sc-checkout-summary, [data-sc-coupon] button' ).forEach( function ( el ) {
					el.removeAttribute( 'aria-busy' );
				} );
			} );
	}

	function applyCoupon( box ) {
		var input = box.querySelector( '[data-sc-coupon-input]' );
		var code = input ? input.value.trim() : '';
		if ( ! code ) {
			couponMessage( i18n.couponEmpty || '', true );
			if ( input ) {
				input.focus();
			}
			return;
		}
		couponRequest( box, 'cart/coupon', { code: code } );
	}

	document.addEventListener( 'click', function ( e ) {
		var apply = e.target.closest( '[data-sc-coupon-apply]' );
		var remove = e.target.closest( '[data-sc-coupon-remove]' );
		if ( ( apply || remove ) && ( apply || remove ).getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}
		if ( apply ) {
			e.preventDefault();
			applyCoupon( apply.closest( '[data-sc-coupon]' ) );
		} else if ( remove ) {
			e.preventDefault();
			couponRequest( remove.closest( '[data-sc-coupon]' ), 'cart/coupon/remove', {} );
		}
	} );

	// Enter in the coupon box applies the coupon instead of placing the order.
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' && e.target.matches && e.target.matches( '[data-sc-coupon-input]' ) ) {
			e.preventDefault();
			applyCoupon( e.target.closest( '[data-sc-coupon]' ) );
		}
	} );

	/* ---------------------------- Gift cards ----------------------------- */

	function redeemMessage( text, isError ) {
		var slot = document.getElementById( 'sc-redeem-error' );
		if ( slot ) {
			slot.textContent = text || '';
			slot.classList.toggle( 'is-success', ! isError && !! text );
		}
	}

	function redeemRequest( box, path, body ) {
		body.context = couponContext( box );
		var panel = box.closest( '.sc-cart, .sc-checkout-summary' );
		if ( panel ) {
			panel.setAttribute( 'aria-busy', 'true' );
		}
		return cart
			.api( path, 'POST', body )
			.then( function ( data ) {
				cart.applyFragments( data.fragments );
				redeemMessage( data.message, false );
				syncPaid();
			} )
			.catch( function ( err ) {
				if ( err.data && err.data.fragments ) {
					cart.applyFragments( err.data.fragments );
				}
				var input = document.querySelector( '[data-sc-redeem-input]' );
				if ( input && body.code && path === 'cart/redeem' ) {
					input.value = body.code;
					input.setAttribute( 'aria-invalid', 'true' );
					input.focus();
				}
				redeemMessage( err.message, true );
			} )
			.finally( function () {
				document.querySelectorAll( '.sc-cart, .sc-checkout-summary' ).forEach( function ( el ) {
					el.removeAttribute( 'aria-busy' );
				} );
			} );
	}

	function applyRedeem( box ) {
		var input = box.querySelector( '[data-sc-redeem-input]' );
		var code = input ? input.value.trim() : '';
		if ( ! code ) {
			redeemMessage( i18n.giftCardEmpty || '', true );
			return;
		}
		redeemRequest( box, 'cart/redeem', { code: code } );
	}

	document.addEventListener( 'click', function ( e ) {
		var apply = e.target.closest( '[data-sc-redeem-apply]' );
		var remove = e.target.closest( '[data-sc-redeem-remove]' );
		if ( apply ) {
			e.preventDefault();
			applyRedeem( apply.closest( '[data-sc-redeem]' ) );
		} else if ( remove ) {
			e.preventDefault();
			redeemRequest( remove.closest( '[data-sc-redeem]' ), 'cart/redeem/remove', { code: remove.dataset.scRedeemRemove } );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' && e.target.matches && e.target.matches( '[data-sc-redeem-input]' ) ) {
			e.preventDefault();
			applyRedeem( e.target.closest( '[data-sc-redeem]' ) );
		}
	} );

	/* --------------------------- Reward points --------------------------- */

	function pointsRequest( box, points ) {
		var panel = box.closest( '.sc-checkout-summary' );
		var slot = function ( text, isError ) {
			var el = document.getElementById( 'sc-points-msg' );
			if ( el ) {
				el.textContent = text || '';
				el.classList.toggle( 'is-success', ! isError && !! text );
			}
		};
		if ( panel ) {
			panel.setAttribute( 'aria-busy', 'true' );
		}
		return cart
			.api( 'cart/points', 'POST', { points: points, context: couponContext( box ) } )
			.then( function ( data ) {
				cart.applyFragments( data.fragments );
				slot( data.message, false );
				syncPaid();
			} )
			.catch( function ( err ) {
				if ( err.data && err.data.fragments ) {
					cart.applyFragments( err.data.fragments );
				}
				slot( err.message, true );
			} )
			.finally( function () {
				document.querySelectorAll( '.sc-checkout-summary' ).forEach( function ( el ) {
					el.removeAttribute( 'aria-busy' );
				} );
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var apply = e.target.closest( '[data-sc-points-apply]' );
		var remove = e.target.closest( '[data-sc-points-remove]' );
		if ( apply ) {
			e.preventDefault();
			var box = apply.closest( '[data-sc-points]' );
			var input = box.querySelector( '[data-sc-points-input]' );
			pointsRequest( box, Math.max( 0, parseInt( input && input.value, 10 ) || 0 ) );
		} else if ( remove ) {
			e.preventDefault();
			pointsRequest( remove.closest( '[data-sc-points]' ), 0 );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' && e.target.matches && e.target.matches( '[data-sc-points-input]' ) ) {
			e.preventDefault();
			var box = e.target.closest( '[data-sc-points]' );
			pointsRequest( box, Math.max( 0, parseInt( e.target.value, 10 ) || 0 ) );
		}
	} );

	/**
	 * Nothing left to pay (gift cards or a 100% coupon): hide the payment
	 * methods and always show "Place order".
	 */
	function syncPaid() {
		document.querySelectorAll( '[data-sc-checkout]' ).forEach( function ( form ) {
			var summary = form.querySelector( '.sc-checkout-summary' );
			var zero = !! summary && parseFloat( summary.dataset.total || '1' ) <= 0;
			// Subscription free trial: still offer to save a card for the renewals.
			var trial = zero && form.hasAttribute( 'data-sc-save-card' );
			var free = zero && ! trial;
			var section = form.querySelector( '[data-sc-payment-section]' );
			var note = form.querySelector( '[data-sc-paid-by-cards]' );
			if ( section ) {
				section.hidden = free;
			}
			if ( note ) {
				note.hidden = ! free;
			}
			form.querySelectorAll( '[data-sc-trial-note], [data-sc-no-card]' ).forEach( function ( el ) {
				el.hidden = ! trial;
			} );
			form.querySelectorAll( '.sc-gateway:not([data-sc-auto]):not([data-sc-no-card])' ).forEach( function ( el ) {
				el.hidden = trial;
			} );
			var picked = form.querySelector( 'input[name="payment_method"]:checked' );
			if ( picked && picked.closest( '.sc-gateway' ) && picked.closest( '.sc-gateway' ).hidden ) {
				var first = Array.prototype.find.call( form.querySelectorAll( 'input[name="payment_method"]' ), function ( input ) {
					return ! input.closest( '.sc-gateway' ).hidden;
				} );
				if ( first ) {
					first.checked = true;
				}
			}
			form.dataset.scFree = free ? '1' : '';
			syncGateway( form );
		} );
	}
	document.addEventListener( 'sc:cart_updated', syncPaid );

	/* ------------------------------ Checkout ------------------------------ */

	function clearErrors( form ) {
		form.querySelectorAll( '.sc-field__error' ).forEach( function ( el ) {
			el.textContent = '';
		} );
		form.querySelectorAll( '[aria-invalid]' ).forEach( function ( el ) {
			el.removeAttribute( 'aria-invalid' );
		} );
		var top = form.querySelector( '[data-sc-checkout-error]' );
		top.hidden = true;
		top.textContent = '';
	}

	function showErrors( form, err ) {
		var fields = ( err.data && err.data.data && err.data.data.fields ) || {};
		var first = null;
		Object.keys( fields ).forEach( function ( key ) {
			var slot = form.querySelector( '#sc-' + key + '-error' );
			var input = form.querySelector( '[name="' + key + '"]' );
			if ( slot ) {
				slot.textContent = fields[ key ];
			}
			if ( input ) {
				input.setAttribute( 'aria-invalid', 'true' );
				first = first || input;
			}
		} );
		var top = form.querySelector( '[data-sc-checkout-error]' );
		top.textContent = err.message || i18n.error;
		top.hidden = false;
		( first || top ).focus && ( first || top ).focus();
		if ( ! first ) {
			top.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
	}

	/** Form fields as a plain object (shared with on-page gateways). */
	function collect( form ) {
		var body = {};
		new FormData( form ).forEach( function ( value, key ) {
			body[ key ] = value;
		} );
		return body;
	}

	/**
	 * Gateways with their own on-page button (data-sc-inline="1") hide
	 * "Place order" while selected; their fields show only when selected.
	 */
	function syncGateway( form ) {
		var picked = form.querySelector( 'input[name="payment_method"]:checked' );
		var inline = picked && picked.dataset.scInline === '1' && form.dataset.scFree !== '1';
		var place = form.querySelector( '[data-sc-place-order]' );
		if ( place ) {
			place.hidden = !! inline;
		}
		form.querySelectorAll( '[data-sc-gateway-fields]' ).forEach( function ( el ) {
			el.hidden = ! picked || el.dataset.scGatewayFields !== picked.value;
		} );
	}

	document.addEventListener( 'change', function ( e ) {
		if ( e.target.name === 'payment_method' && e.target.closest( '[data-sc-checkout]' ) ) {
			syncGateway( e.target.closest( '[data-sc-checkout]' ) );
		}
	} );
	document.querySelectorAll( '[data-sc-checkout]' ).forEach( syncGateway );
	syncPaid();

	/* Country → state list, then recalculate shipping and tax. */

	var locTimer = null;

	function stateField( form, country ) {
		var current = form.querySelector( '[data-sc-state]' );
		if ( ! current ) {
			return;
		}
		var all = {};
		try {
			all = JSON.parse( form.dataset.scStates || '{}' );
		} catch ( err ) {}
		var list = all[ country ];
		var el;
		if ( list ) {
			el = document.createElement( 'select' );
			var blank = document.createElement( 'option' );
			blank.value = '';
			blank.textContent = i18n.select || 'Select…';
			el.appendChild( blank );
			Object.keys( list ).forEach( function ( code ) {
				var o = document.createElement( 'option' );
				o.value = code;
				o.textContent = list[ code ];
				el.appendChild( o );
			} );
		} else {
			el = document.createElement( 'input' );
			el.type = 'text';
		}
		[ 'id', 'name', 'autocomplete', 'aria-describedby' ].forEach( function ( a ) {
			if ( current.hasAttribute( a ) ) {
				el.setAttribute( a, current.getAttribute( a ) );
			}
		} );
		el.setAttribute( 'data-sc-state', '' );
		// Keep the value only when it still fits (same list or free text both ways).
		if ( current.tagName === el.tagName && el.tagName === 'INPUT' ) {
			el.value = current.value;
		}
		current.replaceWith( el );
	}

	function syncLocation( form ) {
		var country = form.querySelector( '[data-sc-country]' );
		var state = form.querySelector( '[data-sc-state]' );
		if ( ! country || ! country.value ) {
			return;
		}
		clearTimeout( locTimer );
		locTimer = setTimeout( function () {
			var side = form.querySelector( '.sc-checkout-summary' );
			if ( side ) {
				side.setAttribute( 'aria-busy', 'true' );
			}
			cart.api( 'cart/location', 'POST', {
				country: country.value,
				state: state ? state.value : '',
				context: 'checkout',
			} )
				.then( function ( data ) {
					cart.applyFragments( data.fragments );
					syncPaid();
					var slot = form.querySelector( '#sc-country-error' );
					if ( slot ) {
						slot.textContent = data.can_ship === false ? ( i18n.noShip || '' ) : '';
					}
				} )
				.catch( function () {} )
				.finally( function () {
					var s = form.querySelector( '.sc-checkout-summary' );
					if ( s ) {
						s.removeAttribute( 'aria-busy' );
					}
				} );
		}, 250 );
	}

	document.addEventListener( 'change', function ( e ) {
		var form = e.target.closest( '[data-sc-checkout]' );
		if ( ! form ) {
			return;
		}
		if ( e.target.hasAttribute( 'data-sc-country' ) ) {
			stateField( form, e.target.value );
			syncLocation( form );
		} else if ( e.target.hasAttribute( 'data-sc-state' ) ) {
			syncLocation( form );
		}
	} );

	/** Submit a hidden form to a payment provider. */
	function postTo( url, fields ) {
		var f = document.createElement( 'form' );
		f.method = 'post';
		f.action = url;
		f.style.display = 'none';
		Object.keys( fields ).forEach( function ( name ) {
			var input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = name;
			input.value = fields[ name ] == null ? '' : String( fields[ name ] );
			f.appendChild( input );
		} );
		document.body.appendChild( f );
		f.submit();
	}

	window.sidccofoCheckout = { collect: collect, clearErrors: clearErrors, showErrors: showErrors, sync: syncGateway, postTo: postTo };

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest( '[data-sc-checkout]' );
		if ( ! form ) {
			return;
		}
		e.preventDefault();

		var picked = form.querySelector( 'input[name="payment_method"]:checked' );
		if ( picked && picked.dataset.scInline === '1' && form.dataset.scFree !== '1' ) {
			return; // That gateway's own button handles payment.
		}
		var button = form.querySelector( '[data-sc-place-order]' );
		if ( button.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}
		clearErrors( form );

		var body = collect( form );

		var label = button.innerHTML;
		button.setAttribute( 'aria-busy', 'true' );
		button.textContent = i18n.placing || 'Placing order…';

		cart.api( 'checkout', 'POST', body )
			.then( function ( data ) {
				if ( data && data.post && data.post.url ) {
					postTo( data.post.url, data.post.fields || {} ); // Hosted pages that take a form POST (e.g. PayU).
					return;
				}
				if ( data && data.redirect ) {
					window.location.assign( data.redirect );
					return;
				}
				throw new Error( i18n.error );
			} )
			.catch( function ( err ) {
				button.innerHTML = label;
				button.removeAttribute( 'aria-busy' );
				showErrors( form, err );
			} );
	} );

	/* ------------------------- Abandoned-cart email ------------------------ */

	// Email marketing on: remember the cart for a reminder once the email is
	// typed (the server keeps it only for people who agreed to emails).
	var marketing = window.sidccofoCommerce && window.sidccofoCommerce.marketing;
	var captured = '';
	function capture( form ) {
		var email = form.querySelector( '[name="email"]' );
		var optin = form.querySelector( '[data-sc-optin]' );
		if ( ! email || ! email.value || ! email.checkValidity() ) {
			return;
		}
		var name = form.querySelector( '[name="first_name"]' );
		var key = email.value + '|' + ( optin && optin.checked ? 1 : 0 );
		if ( key === captured ) {
			return;
		}
		captured = key;
		cart.api( 'marketing/capture', 'POST', { email: email.value, first_name: name ? name.value : '', optin: !! ( optin && optin.checked ) } ).catch( function () {} );
	}
	if ( marketing && marketing.capture ) {
		document.addEventListener( 'change', function ( e ) {
			var form = e.target.closest && e.target.closest( '[data-sc-checkout]' );
			if ( form && ( 'email' === e.target.name || e.target.hasAttribute( 'data-sc-optin' ) ) ) {
				capture( form );
			}
		} );
	}
} )();
