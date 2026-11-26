/**
 * Sidcraft Commerce: point of sale (admin).
 */
( function () {
	'use strict';
	var cfg = window.sidccofoPos;
	var q = document.getElementById( 'sc-pos-q' );
	var results = document.getElementById( 'sc-pos-results' );
	var body = document.getElementById( 'sc-pos-cart' );
	var totalEl = document.getElementById( 'sc-pos-total' );
	var msg = document.getElementById( 'sc-pos-msg' );
	if ( ! cfg || ! q ) {
		return;
	}
	var cart = {};

	function api( path, opts ) {
		opts = opts || {};
		opts.headers = Object.assign( { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }, opts.headers || {} );
		opts.credentials = 'same-origin';
		return fetch( cfg.api + path, opts ).then( function ( r ) {
			return r.json().then( function ( j ) {
				if ( ! r.ok ) {
					throw new Error( j.message || 'Error' );
				}
				return j;
			} );
		} );
	}

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	function money( n ) {
		return cfg.symbol + ( Math.round( n * 100 ) / 100 ).toFixed( 2 );
	}

	function render() {
		var sum = 0;
		body.innerHTML = '';
		Object.keys( cart ).forEach( function ( id ) {
			var l = cart[ id ];
			var line = l.price * l.qty;
			sum += line;
			var tr = document.createElement( 'tr' );
			tr.innerHTML = '<td>' + esc( l.name ) + '<br><small>' + esc( l.sku ) + '</small></td><td><button class="button" data-d="-1" data-id="' + id + '">−</button> ' + l.qty + ' <button class="button" data-d="1" data-id="' + id + '">+</button></td><td>' + money( line ) + '</td><td><button class="button-link" data-rm="' + id + '" aria-label="Remove">×</button></td>';
			body.appendChild( tr );
		} );
		totalEl.textContent = money( sum );
	}

	function add( p ) {
		cart[ p.id ] = cart[ p.id ] || { name: p.name, sku: p.sku, price: p.price, qty: 0 };
		cart[ p.id ].qty++;
		render();
	}

	var timer;
	q.addEventListener( 'input', function () {
		clearTimeout( timer );
		timer = setTimeout( function () {
			var v = q.value.trim();
			if ( v.length < 2 ) {
				results.innerHTML = '';
				return;
			}
			api( 'products?q=' + encodeURIComponent( v ) ).then( function ( d ) {
				results.innerHTML = '';
				d.products.forEach( function ( p ) {
					var li = document.createElement( 'li' );
					li.innerHTML = '<button class="button" style="width:100%;text-align:left;margin-bottom:4px">' + esc( p.name ) + ' <small>' + esc( p.sku ) + '</small> <strong style="float:right">' + money( p.price ) + '</strong></button>';
					li.firstChild.addEventListener( 'click', function () {
						add( p );
					} );
					results.appendChild( li );
				} );
			} ).catch( function () {} );
		}, 200 );
	} );

	// A scanner types the code and presses Enter: add an exact SKU straight away.
	q.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Enter' ) {
			return;
		}
		e.preventDefault();
		var v = q.value.trim();
		if ( ! v ) {
			return;
		}
		api( 'products?q=' + encodeURIComponent( v ) ).then( function ( d ) {
			var exact = d.products.filter( function ( p ) {
				return p.sku && p.sku.toLowerCase() === v.toLowerCase();
			} )[ 0 ];
			if ( exact || d.products.length === 1 ) {
				add( exact || d.products[ 0 ] );
				q.value = '';
				results.innerHTML = '';
			}
		} ).catch( function () {} );
	} );

	body.addEventListener( 'click', function ( e ) {
		var t = e.target;
		if ( t.dataset.d ) {
			cart[ t.dataset.id ].qty += parseInt( t.dataset.d, 10 );
			if ( cart[ t.dataset.id ].qty < 1 ) {
				delete cart[ t.dataset.id ];
			}
			render();
		} else if ( t.dataset.rm ) {
			delete cart[ t.dataset.rm ];
			render();
		}
	} );

	function receipt( r ) {
		var w = window.open( '', 'sc-receipt', 'width=380,height=600' );
		if ( ! w ) {
			return;
		}
		var rows = r.items.map( function ( i ) {
			return '<tr><td>' + esc( i.quantity + ' × ' + i.name ) + '</td><td style="text-align:right">' + esc( r.symbol + Number( i.total ).toFixed( 2 ) ) + '</td></tr>';
		} ).join( '' );
		w.document.write( '<!doctype html><title>Receipt</title><body style="font:13px monospace;width:300px"><h3>' + esc( r.shop ) + '</h3><p>Order #' + r.order + '<br>' + esc( r.date ) + '</p><table style="width:100%">' + rows + '<tr><td><strong>Total</strong></td><td style="text-align:right"><strong>' + esc( r.symbol + Number( r.total ).toFixed( 2 ) ) + '</strong></td></tr></table><p>Paid: ' + esc( r.method ) + '</p><p>Thank you!</p></body>' );
		w.document.close();
		w.focus();
		w.print();
	}

	document.querySelectorAll( '[data-method]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var items = Object.keys( cart ).map( function ( id ) {
				return { id: Number( id ), qty: cart[ id ].qty };
			} );
			if ( ! items.length ) {
				msg.textContent = 'Add something first.';
				return;
			}
			b.disabled = true;
			api( 'order', { method: 'POST', body: JSON.stringify( { items: items, method: b.dataset.method, email: document.getElementById( 'sc-pos-email' ).value } ) } )
				.then( function ( r ) {
					msg.textContent = 'Order #' + r.order + ' saved. Total ' + money( r.total ) + '.';
					cart = {};
					render();
					document.getElementById( 'sc-pos-email' ).value = '';
					receipt( r );
					q.focus();
				} )
				.catch( function ( err ) {
					msg.textContent = err.message;
				} )
				.finally( function () {
					b.disabled = false;
				} );
		} );
	} );
} )();
