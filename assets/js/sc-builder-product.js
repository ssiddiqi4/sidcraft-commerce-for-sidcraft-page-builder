/**
 * Sidcraft Commerce — "Product data" panel inside Sidcraft Page Builder.
 *
 * Adds a "Product data" button next to the builder's Save button. It opens a
 * side panel for price, sale price, SKU, stock, badge, categories, image and
 * short description, saved through /sidcraft-commerce/v1/builder/product/ID.
 * Unsaved panel changes are also saved when the builder saves the page.
 */
( function () {
	'use strict';

	var cfg = window.sidccofoBuilderProduct;
	if ( ! cfg || ! window.fetch ) {
		return;
	}
	var t = cfg.i18n || {};
	var state = null;
	var dirty = false;
	var panel = null;

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( 'text' === key ) {
				node.textContent = attrs[ key ];
			} else if ( 'className' === key ) {
				node.className = attrs[ key ];
			} else if ( 0 === key.indexOf( 'on' ) ) {
				node.addEventListener( key.slice( 2 ), attrs[ key ] );
			} else if ( true === attrs[ key ] ) {
				node.setAttribute( key, '' );
			} else if ( false !== attrs[ key ] && null != attrs[ key ] ) {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( 'string' === typeof child ? document.createTextNode( child ) : child );
			}
		} );
		return node;
	}

	function postId() {
		var root = document.getElementById( 'lb-editor' );
		var lb = window.SidcraftPageBuilder;
		var id = root ? parseInt( root.getAttribute( 'data-post-id' ), 10 ) : 0;
		if ( ! id && lb && lb.app && lb.app.D ) {
			id = parseInt( lb.app.D.postId, 10 ) || 0;
		}
		return id || 0;
	}

	function request( method, data ) {
		return window.fetch( cfg.route + postId(), {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: data ? JSON.stringify( data ) : undefined,
		} ).then( function ( res ) {
			if ( ! res.ok ) {
				throw new Error( 'HTTP ' + res.status );
			}
			return res.json();
		} );
	}

	/* ---------------- Button ---------------- */

	function button() {
		var btn = document.getElementById( 'sc-bp-toggle' );
		if ( btn ) {
			return btn;
		}
		btn = el( 'button', {
			type: 'button',
			id: 'sc-bp-toggle',
			className: 'sc-bp-toggle',
			'aria-expanded': 'false',
			'aria-controls': 'sc-bp-panel',
			onclick: function () {
				toggle();
			},
		}, [ el( 'span', { className: 'sc-bp-toggle__icon', 'aria-hidden': 'true' } ), el( 'span', { text: t.button } ) ] );
		return btn;
	}

	// The builder re-renders its top bar, so keep the button next to Save.
	function placeButton() {
		var btn = button();
		var save = document.getElementById( 'lb-save' );
		if ( save && save.parentNode ) {
			if ( btn.nextSibling !== save || btn.parentNode !== save.parentNode ) {
				save.parentNode.insertBefore( btn, save );
			}
			btn.classList.remove( 'is-floating' );
		} else if ( ! btn.isConnected ) {
			btn.classList.add( 'is-floating' );
			document.body.appendChild( btn );
		}
		btn.classList.toggle( 'needs-price', !! state && '' === state.regular && ! state.gift_card );
	}

	/* ---------------- Panel ---------------- */

	function field( label, input, help, id ) {
		return el( 'div', { className: 'sc-bp-field' }, [
			el( 'label', { for: id, text: label } ),
			input,
			help ? el( 'p', { className: 'sc-bp-help', text: help } ) : null,
		] );
	}

	function bind( input, key, kind ) {
		input.addEventListener( 'checkbox' === kind ? 'change' : 'input', function () {
			state[ key ] = 'checkbox' === kind ? input.checked : input.value;
			dirty = true;
			setStatus( '' );
			refreshVisibility();
		} );
		return input;
	}

	function textInput( key, attrs ) {
		var input = el( 'input', Object.assign( { type: 'text', id: 'sc-bp-' + key, value: state[ key ] }, attrs || {} ) );
		input.value = null == state[ key ] ? '' : state[ key ];
		return bind( input, key );
	}

	function checkbox( key, label ) {
		var input = el( 'input', { type: 'checkbox', id: 'sc-bp-' + key } );
		input.checked = !! state[ key ];
		bind( input, key, 'checkbox' );
		return el( 'label', { className: 'sc-bp-check', for: 'sc-bp-' + key }, [ input, el( 'span', { text: label } ) ] );
	}

	function select( key, options ) {
		var input = el( 'select', { id: 'sc-bp-' + key } );
		Object.keys( options ).forEach( function ( value ) {
			var opt = el( 'option', { value: value, text: options[ value ] } );
			opt.selected = String( state[ key ] ) === value;
			input.appendChild( opt );
		} );
		return bind( input, key );
	}

	function money( key, attrs ) {
		var input = textInput( key, Object.assign( { inputmode: 'decimal', autocomplete: 'off' }, attrs || {} ) );
		return el( 'div', { className: 'sc-bp-money' }, [ el( 'span', { className: 'sc-bp-money__symbol', text: cfg.symbol || '' } ), input ] );
	}

	function categories() {
		var box = el( 'div', { className: 'sc-bp-cats', role: 'group', 'aria-labelledby': 'sc-bp-cats-label' } );
		if ( ! state.categories.length ) {
			box.appendChild( el( 'p', { className: 'sc-bp-help', text: t.noCategories } ) );
		}
		state.categories.forEach( function ( cat ) {
			var input = el( 'input', { type: 'checkbox', value: cat.id } );
			input.checked = state.category_ids.indexOf( cat.id ) > -1;
			input.addEventListener( 'change', function () {
				state.category_ids = state.category_ids.filter( function ( id ) {
					return id !== cat.id;
				} );
				if ( input.checked ) {
					state.category_ids.push( cat.id );
				}
				dirty = true;
				setStatus( '' );
			} );
			box.appendChild( el( 'label', { className: 'sc-bp-check' }, [ input, el( 'span', { text: cat.name } ) ] ) );
		} );
		return el( 'div', { className: 'sc-bp-field' }, [ el( 'span', { className: 'sc-bp-label', id: 'sc-bp-cats-label', text: t.categories } ), box ] );
	}

	function image() {
		var preview = el( 'div', { className: 'sc-bp-image__preview' } );
		var remove = el( 'button', { type: 'button', className: 'sc-bp-link', text: t.removeImage } );
		var choose = el( 'button', { type: 'button', className: 'sc-bp-button', text: t.chooseImage } );
		function paint() {
			preview.textContent = '';
			if ( state.image_url ) {
				preview.appendChild( el( 'img', { src: state.image_url, alt: '' } ) );
			}
			remove.hidden = ! state.image_id;
		}
		choose.addEventListener( 'click', function () {
			if ( ! window.wp.media ) {
				return;
			}
			var frame = window.wp.media( { title: t.image, library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var file = frame.state().get( 'selection' ).first().toJSON();
				state.image_id = file.id;
				state.image_url = ( file.sizes && file.sizes.medium ? file.sizes.medium.url : file.url ) || '';
				dirty = true;
				setStatus( '' );
				paint();
			} );
			frame.open();
		} );
		remove.addEventListener( 'click', function () {
			state.image_id = 0;
			state.image_url = '';
			dirty = true;
			setStatus( '' );
			paint();
		} );
		paint();
		return el( 'div', { className: 'sc-bp-field' }, [
			el( 'span', { className: 'sc-bp-label', text: t.image } ),
			el( 'div', { className: 'sc-bp-image' }, [ preview, el( 'div', { className: 'sc-bp-image__actions' }, [ choose, remove ] ) ] ),
		] );
	}

	function setStatus( text, kind ) {
		var node = panel && panel.querySelector( '.sc-bp-status' );
		if ( node ) {
			node.textContent = text || ( dirty ? t.unsaved : '' );
			node.className = 'sc-bp-status' + ( kind ? ' is-' + kind : ( dirty ? ' is-dirty' : '' ) );
		}
	}

	function refreshVisibility() {
		if ( ! panel ) {
			return;
		}
		var qty = panel.querySelector( '[data-sc-bp="stock"]' );
		var status = panel.querySelector( '[data-sc-bp="stock_status"]' );
		var warn = panel.querySelector( '.sc-bp-warn' );
		if ( qty ) {
			qty.hidden = ! state.manage_stock;
		}
		if ( status ) {
			status.hidden = !! state.manage_stock;
		}
		var files = panel.querySelector( '[data-sc-bp="files"]' );
		if ( files ) {
			files.hidden = ! state.downloadable;
		}
		if ( warn ) {
			warn.hidden = '' !== String( state.regular ).trim() || state.gift_card;
		}
		placeButton();
	}

	function render() {
		var body = panel.querySelector( '.sc-bp-body' );
		body.textContent = '';

		var stock = field( t.stock, textInput( 'stock', { type: 'number', min: '0', step: '1' } ), '', 'sc-bp-stock' );
		stock.setAttribute( 'data-sc-bp', 'stock' );
		var status = field( t.stockStatus, select( 'stock_status', { instock: t.inStock, outofstock: t.outOfStock } ), '', 'sc-bp-stock_status' );
		status.setAttribute( 'data-sc-bp', 'stock_status' );
		var options = el( 'textarea', { id: 'sc-bp-options', rows: '4', className: 'sc-bp-code', placeholder: t.optionsHint || '' } );
		options.value = state.options || '';
		bind( options, 'options' );
		var excerpt = el( 'textarea', { id: 'sc-bp-excerpt', rows: '3' } );
		excerpt.value = state.excerpt || '';
		bind( excerpt, 'excerpt' );

		[
			el( 'p', { className: 'sc-bp-warn', role: 'status', text: t.needPrice } ),
			state.gift_card ? el( 'p', { className: 'sc-bp-note', text: t.giftCard } ) : null,
			el( 'div', { className: 'sc-bp-row' }, [
				field( t.regular, money( 'regular' ), '', 'sc-bp-regular' ),
				field( t.sale, money( 'sale' ), t.saleHelp, 'sc-bp-sale' ),
			] ),
			field( t.sku, textInput( 'sku' ), '', 'sc-bp-sku' ),
			checkbox( 'manage_stock', t.manageStock ),
			stock,
			status,
			state.gift_card ? null : field( t.options, options, t.optionsHelp, 'sc-bp-options' ),
			field( t.badge, select( 'badge', cfg.badges || {} ), '', 'sc-bp-badge' ),
			checkbox( 'featured', t.featured ),
			image(),
			categories(),
			field( t.excerpt, excerpt, t.excerptHelp, 'sc-bp-excerpt' ),
			checkbox( 'hide_buy_box', t.hideBuyBox ),
			t.virtual ? checkbox( 'virtual', t.virtual ) : null,
			t.downloadable ? checkbox( 'downloadable', t.downloadable ) : null,
			t.files ? el( 'p', { className: 'sc-bp-help', 'data-sc-bp': 'files', text: String( t.files ).replace( '%d', String( state.file_count || 0 ) ) } ) : null,
			el( 'p', { className: 'sc-bp-more' }, [ el( 'a', { href: cfg.editUrl + postId(), target: '_blank', rel: 'noopener', text: t.more } ) ] ),
		].forEach( function ( node ) {
			if ( node ) {
				body.appendChild( node );
			}
		} );
		refreshVisibility();
	}

	function buildPanel() {
		panel = el( 'aside', { id: 'sc-bp-panel', className: 'sc-bp', 'aria-label': t.title, hidden: true }, [
			el( 'header', { className: 'sc-bp-head' }, [
				el( 'h2', { text: t.title } ),
				el( 'button', { type: 'button', className: 'sc-bp-close', 'aria-label': t.close, text: '×', onclick: function () {
					toggle( false );
				} } ),
			] ),
			el( 'div', { className: 'sc-bp-body' }, [ el( 'p', { className: 'sc-bp-help', text: t.loading } ) ] ),
			el( 'footer', { className: 'sc-bp-foot' }, [
				el( 'span', { className: 'sc-bp-status', role: 'status', 'aria-live': 'polite' } ),
				el( 'button', { type: 'button', className: 'sc-bp-button sc-bp-button--primary', text: t.save, onclick: function () {
					save();
				} } ),
			] ),
		] );
		panel.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				toggle( false );
			}
		} );
		document.body.appendChild( panel );
	}

	function toggle( open ) {
		if ( ! panel ) {
			buildPanel();
		}
		var show = 'boolean' === typeof open ? open : panel.hidden;
		panel.hidden = ! show;
		button().setAttribute( 'aria-expanded', show ? 'true' : 'false' );
		document.body.classList.toggle( 'sc-bp-open', show );
		if ( show ) {
			if ( state ) {
				render();
				setStatus( '' );
			} else {
				load();
			}
			var first = panel.querySelector( 'input, select, textarea' );
			if ( first ) {
				first.focus();
			}
		}
	}

	function load() {
		if ( ! postId() ) {
			return Promise.resolve();
		}
		return request( 'GET' ).then( function ( data ) {
			state = data;
			dirty = false;
			if ( panel && ! panel.hidden ) {
				render();
			}
			placeButton();
			return data;
		} );
	}

	function save() {
		if ( ! state ) {
			return Promise.resolve();
		}
		setStatus( t.saving );
		return request( 'POST', state ).then(
			function ( data ) {
				var notice = data.notice;
				state = data;
				dirty = false;
				if ( panel && ! panel.hidden ) {
					render();
				}
				setStatus( notice || t.saved, notice ? 'warning' : 'success' );
				placeButton();
			},
			function () {
				setStatus( t.error, 'error' );
			}
		);
	}

	/* ---------------- Boot ---------------- */

	function boot() {
		placeButton();
		new MutationObserver( function () {
			var btn = document.getElementById( 'sc-bp-toggle' );
			var save = document.getElementById( 'lb-save' );
			if ( ! btn || ( save && btn.nextSibling !== save ) ) {
				placeButton();
			}
		} ).observe( document.body, { childList: true, subtree: true } );

		load().then( function ( data ) {
			// A new product has no price yet: open the panel so it gets one.
			if ( data && '' === data.regular && ! data.gift_card ) {
				toggle( true );
			}
		} );

		var lb = window.SidcraftPageBuilder;
		if ( lb && lb.hooks ) {
			// Saving the page also saves unsaved product data.
			lb.hooks.addAction( 'editor/save/before', function () {
				if ( dirty ) {
					save();
				}
			}, 10, 'sidccofo' );
		}
		window.addEventListener( 'beforeunload', function ( e ) {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );
	}

	if ( window.SidcraftPageBuilder && 'function' === typeof window.SidcraftPageBuilder.ready ) {
		window.SidcraftPageBuilder.ready( boot );
	} else if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
