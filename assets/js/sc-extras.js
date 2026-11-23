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

/* --------------------------------------------------------------------
 * Cart drawer and sticky add-to-cart bar.
 * ------------------------------------------------------------------ */
( function () {
	'use strict';
	var cart = window.sidccofoCart;
	var cfg = ( window.sidccofoCommerce || {} ).drawer || {};
	if ( ! cart ) {
		return;
	}
	var lastFocus = null;

	function drawer() {
		return document.getElementById( 'sc-drawer' );
	}

	function open() {
		var d = drawer();
		if ( ! d ) {
			return;
		}
		lastFocus = document.activeElement;
		d.hidden = false;
		document.documentElement.classList.add( 'sc-drawer-open' );
		requestAnimationFrame( function () {
			d.classList.add( 'is-open' );
			var close = d.querySelector( '.sc-drawer__close' );
			if ( close ) {
				close.focus();
			}
		} );
	}

	function close() {
		var d = drawer();
		if ( ! d || d.hidden ) {
			return;
		}
		d.classList.remove( 'is-open' );
		document.documentElement.classList.remove( 'sc-drawer-open' );
		setTimeout( function () {
			d.hidden = true;
		}, 200 );
		if ( lastFocus && lastFocus.focus ) {
			lastFocus.focus();
		}
	}

	document.addEventListener( 'sc:added_to_cart', function () {
		if ( cfg.open !== false ) {
			open();
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		var t = e.target;
		if ( ! t.closest ) {
			return;
		}
		if ( t.closest( '[data-sc-drawer-close]' ) ) {
			close();
			return;
		}
		var mini = t.closest( '.sc-mini-cart' );
		if ( mini && cfg.mini !== false && drawer() ) {
			e.preventDefault();
			open();
			return;
		}
		var item = t.closest( '.sc-drawer__item' );
		if ( item ) {
			var qty = t.closest( '[data-sc-drawer-qty]' );
			var rm = t.closest( '[data-sc-drawer-remove]' );
			if ( qty || rm ) {
				var current = parseInt( ( item.querySelector( '.sc-drawer__qty' ) || {} ).textContent, 10 ) || 0;
				var next = rm ? 0 : Math.max( 0, current + parseInt( qty.dataset.scDrawerQty, 10 ) );
				item.setAttribute( 'aria-busy', 'true' );
				cart.update( item.dataset.productId, next, '', item.dataset.lineKey ).catch( function () {} );
			}
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			close();
		}
		// Keep Tab inside the open drawer.
		var d = drawer();
		if ( e.key === 'Tab' && d && ! d.hidden ) {
			var f = d.querySelectorAll( 'a[href], button:not([disabled]), input' );
			if ( f.length ) {
				var first = f[ 0 ];
				var last = f[ f.length - 1 ];
				if ( e.shiftKey && document.activeElement === first ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}
		}
	} );

	/* Sticky bar: show it when the real Add to cart button is out of view. */
	function sticky() {
		var bar = document.querySelector( '[data-sc-sticky]' );
		var btn = document.querySelector( '[data-sc-atc-form] [data-sc-add-to-cart]' );
		if ( ! bar || ! btn || ! ( 'IntersectionObserver' in window ) ) {
			return;
		}
		new IntersectionObserver( function ( entries ) {
			var visible = entries[ 0 ].isIntersecting;
			var below = entries[ 0 ].boundingClientRect.top < 0;
			bar.hidden = visible || ! below;
		} ).observe( btn );
		bar.querySelector( '[data-sc-sticky-add]' ).addEventListener( 'click', function () {
			btn.click();
		} );
	}
	if ( document.readyState !== 'loading' ) {
		sticky();
	} else {
		document.addEventListener( 'DOMContentLoaded', sticky );
	}
} )();

/* --------------------------------------------------------------------
 * Live search.
 * ------------------------------------------------------------------ */
( function () {
	'use strict';
	var cart = window.sidccofoCart;
	var ex = window.sidccofoExtras;
	if ( ! cart || ! ex ) {
		return;
	}
	var timers = new WeakMap();

	document.addEventListener( 'input', function ( e ) {
		var form = e.target.closest && e.target.closest( '[data-sc-search]' );
		if ( ! form || e.target.name !== 's' ) {
			return;
		}
		var list = form.querySelector( '.sc-search__list' );
		clearTimeout( timers.get( form ) );
		var q = e.target.value.trim();
		if ( q.length < 2 ) {
			list.hidden = true;
			return;
		}
		timers.set( form, setTimeout( function () {
			cart.api( 'search?q=' + encodeURIComponent( q ), 'GET', null, true ).then( function ( data ) {
				list.innerHTML = '';
				( data.results || [] ).forEach( function ( r ) {
					var li = document.createElement( 'li' );
					li.setAttribute( 'role', 'option' );
					var a = document.createElement( 'a' );
					a.href = r.url;
					a.innerHTML = ( r.image ? '<img src="' + ex.esc( r.image ) + '" alt="" width="40" height="40">' : '' ) + '<span>' + ex.esc( r.name ) + '</span><small>' + ex.esc( r.price ) + '</small>';
					li.appendChild( a );
					list.appendChild( li );
				} );
				list.hidden = ! list.children.length;
			} ).catch( function () {
				list.hidden = true;
			} );
		}, 220 ) );
	} );

	document.addEventListener( 'click', function ( e ) {
		document.querySelectorAll( '.sc-search__list' ).forEach( function ( l ) {
			if ( ! l.parentNode.contains( e.target ) ) {
				l.hidden = true;
			}
		} );
	} );
} )();

/* --------------------------------------------------------------------
 * Wishlist, compare, recently viewed.
 * ------------------------------------------------------------------ */
( function () {
	'use strict';
	var cart = window.sidccofoCart;
	var ex = window.sidccofoExtras;
	if ( ! cart || ! ex ) {
		return;
	}
	var state = { wishlist: [], compare: [] };
	var loaded = false;

	function mark() {
		document.querySelectorAll( '[data-sc-wish]' ).forEach( function ( b ) {
			var on = state.wishlist.indexOf( Number( b.dataset.scWish ) ) > -1;
			b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			b.classList.toggle( 'is-active', on );
			var s = b.querySelector( '[aria-hidden]' );
			if ( s ) {
				s.textContent = on ? '♥' : '♡';
			}
		} );
		document.querySelectorAll( '[data-sc-cmp]' ).forEach( function ( b ) {
			var on = state.compare.indexOf( Number( b.dataset.scCmp ) ) > -1;
			b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			b.classList.toggle( 'is-active', on );
		} );
	}

	function loadState() {
		return cart.api( 'wishlist', 'GET', null, true ).then( function ( d ) {
			state.wishlist = ( d.wishlist || [] ).map( Number );
			state.compare = ( d.compare || [] ).map( Number );
			loaded = true;
			mark();
		} ).catch( function () {} );
	}

	function fill( box, url ) {
		var ul = box.querySelector( '[data-sc-list-items]' );
		cart.api( url, 'GET', null, true ).then( function ( d ) {
			ul.innerHTML = d.html;
			var empty = box.querySelector( '.sc-list__empty' );
			if ( empty ) {
				empty.hidden = d.count > 0;
			}
			if ( box.dataset.scList === 'recent' ) {
				box.hidden = d.count === 0;
			}
			cart.refresh && cart.refresh();
		} ).catch( function () {} );
	}

	function cookie( name, value ) {
		var suffix = ( window.sidccofoCommerce || {} ).cookieSuffix;
		var full = name + ( suffix ? '_' + suffix : '' );
		if ( value === undefined ) {
			var m = document.cookie.match( new RegExp( '(?:^|; )' + full + '=([^;]*)' ) );
			return m ? decodeURIComponent( m[ 1 ] ) : '';
		}
		document.cookie = full + '=' + encodeURIComponent( value ) + ';path=/;max-age=' + 60 * 86400 + ';SameSite=Lax';
	}

	function recentIds() {
		return cookie( 'sidccofo_recent' ).split( ',' ).map( Number ).filter( Boolean );
	}

	function table() {
		var t = document.querySelector( '[data-sc-compare-table]' );
		if ( t ) {
			cart.api( 'compare/table', 'GET', null, true ).then( function ( d ) {
				t.innerHTML = d.html;
			} ).catch( function () {} );
		}
	}

	ex.ready( function () {
		var tracked = document.querySelector( '[data-sc-track-product]' );
		if ( tracked ) {
			var id = Number( tracked.dataset.scTrackProduct );
			cookie( 'sidccofo_recent', [ id ].concat( recentIds().filter( function ( x ) {
				return x !== id;
			} ) ).slice( 0, 24 ).join( ',' ) );
		}
		if ( document.querySelector( '[data-sc-wish],[data-sc-cmp]' ) ) {
			loadState();
		}
		document.querySelectorAll( '[data-sc-list="wishlist"]' ).forEach( function ( b ) {
			fill( b, 'cards?list=wishlist' );
		} );
		document.querySelectorAll( '[data-sc-list="recent"]' ).forEach( function ( b ) {
			var ids = recentIds();
			if ( tracked ) {
				ids = ids.filter( function ( x ) {
					return x !== Number( tracked.dataset.scTrackProduct );
				} );
			}
			ids = ids.slice( 0, parseInt( b.dataset.limit, 10 ) || 8 );
			if ( ! ids.length ) {
				b.hidden = true;
				return;
			}
			fill( b, 'cards?ids=' + ids.join( ',' ) );
		} );
		table();
	} );

	document.addEventListener( 'click', function ( e ) {
		var w = e.target.closest && e.target.closest( '[data-sc-wish]' );
		if ( w ) {
			cart.api( 'wishlist/toggle', 'POST', { product_id: Number( w.dataset.scWish ) } ).then( function ( d ) {
				state.wishlist = d.wishlist.map( Number );
				mark();
				document.querySelectorAll( '[data-sc-list="wishlist"]' ).forEach( function ( b ) {
					fill( b, 'cards?list=wishlist' );
				} );
			} ).catch( function () {} );
			return;
		}
		var c = e.target.closest && e.target.closest( '[data-sc-cmp]' );
		if ( c ) {
			cart.api( 'compare/toggle', 'POST', { product_id: Number( c.dataset.scCmp ) } ).then( function ( d ) {
				state.compare = d.compare.map( Number );
				mark();
				table();
			} ).catch( function ( err ) {
				window.alert( err.message );
			} );
			return;
		}
		var r = e.target.closest && e.target.closest( '[data-sc-compare-remove]' );
		if ( r ) {
			cart.api( 'compare/toggle', 'POST', { product_id: Number( r.dataset.scCompareRemove ) } ).then( table ).catch( function () {} );
		}
	} );
} )();

/* --------------------------------------------------------------------
 * Bundles: add every product of a set, one after the other.
 * ------------------------------------------------------------------ */
( function () {
	'use strict';
	var cart = window.sidccofoCart;
	if ( ! cart ) {
		return;
	}
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest && e.target.closest( '[data-sc-bundle]' );
		if ( ! b || b.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}
		var ids = b.dataset.scBundle.split( ',' );
		b.setAttribute( 'aria-busy', 'true' );
		ids.reduce( function ( p, id ) {
			return p.then( function () {
				return cart.add( id, 1, null );
			} );
		}, Promise.resolve() ).finally( function () {
			b.removeAttribute( 'aria-busy' );
		} );
	} );
} )();
