/**
 * Live chat inbox (Store → Live chat). Polls every 4 s while the tab is
 * visible; the polling also keeps the store "online" for visitors.
 */
( function () {
	'use strict';

	var config = window.sidccofoChatAdmin;
	var root = document.getElementById( 'sc-chat-inbox' );
	if ( ! config || ! root ) {
		return;
	}
	var t = config.i18n;
	var state = { filter: 'open', current: config.open || 0, lastId: 0, timer: 0, unread: 0 };
	var el = {};

	function make( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	// "poll?after=3" on top of a REST base that may already hold "?rest_route=" (plain permalinks).
	function route( path ) {
		var parts = path.split( '?' );
		var url = config.api + parts[ 0 ];
		return parts[ 1 ] ? url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + parts[ 1 ] : url;
	}

	function api( path, method, body ) {
		return fetch( route( path ), {
			method: method || 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: Object.assign( { 'X-WP-Nonce': config.nonce }, body ? { 'Content-Type': 'application/json' } : {} ),
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( data && data.message ? data.message : t.error );
				}
				return data;
			} );
		} );
	}

	function time( unix ) {
		return new Date( unix * 1000 ).toLocaleString( [], { dateStyle: 'short', timeStyle: 'short' } );
	}

	function layout() {
		root.textContent = '';
		var side = make( 'div', 'sc-inbox__side' );
		var tabs = make( 'div', 'sc-inbox__tabs' );
		[ [ 'open', t.open ], [ 'closed', t.closed ], [ '', t.all ] ].forEach( function ( tab ) {
			var button = make( 'button', 'button' + ( tab[ 0 ] === state.filter ? ' button-primary' : '' ), tab[ 1 ] );
			button.type = 'button';
			button.addEventListener( 'click', function () {
				state.filter = tab[ 0 ];
				tabs.querySelectorAll( 'button' ).forEach( function ( b ) {
					b.classList.remove( 'button-primary' );
				} );
				button.classList.add( 'button-primary' );
				loadInbox();
			} );
			tabs.appendChild( button );
		} );
		side.appendChild( tabs );
		el.list = make( 'ul', 'sc-inbox__list' );
		side.appendChild( el.list );
		side.appendChild( make( 'p', 'description', t.online ) );
		root.appendChild( side );

		el.main = make( 'div', 'sc-inbox__main' );
		el.main.appendChild( make( 'p', 'sc-inbox__empty', t.pick ) );
		root.appendChild( el.main );
	}

	function loadInbox() {
		return api( 'inbox?status=' + encodeURIComponent( state.filter ) ).then( function ( data ) {
			el.list.textContent = '';
			if ( ! data.chats.length ) {
				el.list.appendChild( make( 'li', 'sc-inbox__none', t.none ) );
			}
			data.chats.forEach( function ( chat ) {
				var item = make( 'li' );
				var button = make( 'button', 'sc-inbox__item' + ( chat.id === state.current ? ' is-current' : '' ) + ( chat.unread ? ' is-unread' : '' ) );
				button.type = 'button';
				var top = make( 'span', 'sc-inbox__name', chat.name + ( chat.email ? ' · ' + chat.email : '' ) );
				button.appendChild( top );
				if ( chat.unread ) {
					button.appendChild( make( 'span', 'sc-inbox__count', String( chat.unread ) ) );
				}
				button.appendChild( make( 'span', 'sc-inbox__last', chat.last ) );
				button.appendChild( make( 'span', 'sc-inbox__time', time( chat.updated ) ) );
				button.addEventListener( 'click', function () {
					openChat( chat.id );
				} );
				item.appendChild( button );
				el.list.appendChild( item );
			} );
			document.title = ( data.unread ? '(' + data.unread + ') ' : '' ) + t.title;
			state.unread = data.unread;
		} );
	}

	function openChat( id ) {
		state.current = id;
		state.lastId = 0;
		el.list.querySelectorAll( '.sc-inbox__item' ).forEach( function ( b ) {
			b.classList.remove( 'is-current' );
		} );
		api( String( id ) ).then( function ( data ) {
			renderThread( data );
			loadInbox();
		} ).catch( function ( error ) {
			el.main.textContent = error.message;
		} );
	}

	function renderThread( data ) {
		var chat = data.chat;
		el.main.textContent = '';
		var head = make( 'div', 'sc-inbox__head' );
		var who = make( 'div' );
		who.appendChild( make( 'strong', '', chat.name || t.visitor ) );
		if ( chat.email ) {
			var mail = make( 'a', '', ' ' + chat.email );
			mail.href = 'mailto:' + chat.email;
			who.appendChild( mail );
		}
		var meta = make( 'div', 'sc-inbox__meta' );
		if ( chat.user ) {
			var user = make( 'a', '', t.account + ': ' + chat.user.name );
			user.href = chat.user.link;
			meta.appendChild( user );
		}
		if ( chat.ordersLink ) {
			var orders = make( 'a', '', t.orders + ': ' + chat.orders );
			orders.href = chat.ordersLink;
			meta.appendChild( orders );
		}
		if ( chat.page ) {
			var page = make( 'a', '', t.page );
			page.href = chat.page;
			page.target = '_blank';
			page.rel = 'noopener';
			meta.appendChild( page );
		}
		who.appendChild( meta );
		head.appendChild( who );
		var actions = make( 'div', 'sc-inbox__actions' );
		var toggle = make( 'button', 'button', 'open' === chat.status ? t.end : t.reopen );
		toggle.type = 'button';
		toggle.addEventListener( 'click', function () {
			api( chat.id + '/status', 'POST', { status: 'open' === chat.status ? 'closed' : 'open' } ).then( function ( d ) {
				renderThread( d );
				loadInbox();
			} );
		} );
		actions.appendChild( toggle );
		var remove = make( 'button', 'button-link button-link-delete', t.delete );
		remove.type = 'button';
		remove.addEventListener( 'click', function () {
			if ( window.confirm( t.confirm ) ) {
				api( chat.id + '/delete', 'POST', {} ).then( function () {
					state.current = 0;
					el.main.textContent = '';
					el.main.appendChild( make( 'p', 'sc-inbox__empty', t.pick ) );
					loadInbox();
				} );
			}
		} );
		actions.appendChild( remove );
		head.appendChild( actions );
		el.main.appendChild( head );

		el.log = make( 'div', 'sc-inbox__log' );
		el.log.setAttribute( 'role', 'log' );
		el.log.setAttribute( 'aria-live', 'polite' );
		el.main.appendChild( el.log );
		state.lastId = 0;
		append( data.messages );

		var form = make( 'form', 'sc-inbox__form' );
		el.reply = make( 'textarea' );
		el.reply.rows = 3;
		el.reply.placeholder = t.reply;
		el.reply.setAttribute( 'aria-label', t.reply );
		el.reply.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key && ( event.ctrlKey || event.metaKey ) ) {
				event.preventDefault();
				form.requestSubmit();
			}
		} );
		form.appendChild( el.reply );
		var row = make( 'div', 'sc-inbox__row' );
		var label = make( 'label' );
		el.emailToo = make( 'input' );
		el.emailToo.type = 'checkbox';
		label.appendChild( el.emailToo );
		label.appendChild( document.createTextNode( ' ' + t.emailToo ) );
		label.hidden = ! chat.email;
		row.appendChild( label );
		var send = make( 'button', 'button button-primary', t.send );
		send.type = 'submit';
		row.appendChild( send );
		form.appendChild( row );
		el.error = make( 'p', 'sc-inbox__error' );
		form.appendChild( el.error );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var body = el.reply.value.trim();
			if ( ! body ) {
				return;
			}
			send.disabled = true;
			api( chat.id + '/reply?after=' + state.lastId, 'POST', { body: body, email: el.emailToo.checked } )
				.then( function ( d ) {
					el.reply.value = '';
					el.emailToo.checked = false;
					el.error.textContent = '';
					append( d.messages );
				} )
				.catch( function ( error ) {
					el.error.textContent = error.message;
				} )
				.finally( function () {
					send.disabled = false;
					el.reply.focus();
				} );
		} );
		el.main.appendChild( form );
		el.reply.focus();
	}

	function append( messages ) {
		( messages || [] ).forEach( function ( m ) {
			if ( m.id <= state.lastId ) {
				return;
			}
			state.lastId = m.id;
			var item = make( 'div', 'sc-inbox__msg sc-inbox__msg--' + m.sender );
			if ( 'system' !== m.sender ) {
				item.appendChild( make( 'span', 'sc-inbox__by', ( 'operator' === m.sender ? ( m.author || t.you ) : t.visitor ) + ' · ' + time( m.time ) ) );
			}
			item.appendChild( make( 'p', '', m.body ) );
			el.log.appendChild( item );
		} );
		el.log.scrollTop = el.log.scrollHeight;
	}

	function tick() {
		clearTimeout( state.timer );
		if ( document.hidden ) {
			return;
		}
		var jobs = [ loadInbox() ];
		if ( state.current && el.log ) {
			jobs.push( api( state.current + '?after=' + state.lastId ).then( function ( d ) {
				append( d.messages );
			} ) );
		}
		Promise.all( jobs ).catch( function () {} ).finally( function () {
			state.timer = setTimeout( tick, 4000 );
		} );
	}

	layout();
	if ( state.current ) {
		openChat( state.current );
	}
	tick();
	document.addEventListener( 'visibilitychange', tick );
}() );
