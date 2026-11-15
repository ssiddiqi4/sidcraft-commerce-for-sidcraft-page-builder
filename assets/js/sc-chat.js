/**
 * Live chat button and window. No dependencies.
 *
 * Nothing is fetched until the window is opened, or while the visitor has a
 * conversation going (a small cookie remembers that across pages). Polling
 * runs every 4 s while the window is open and the tab visible, every 20 s
 * while it is closed, and stops when the tab is hidden.
 */
( function () {
	'use strict';

	var config = window.sidccofoChat;
	if ( ! config ) {
		return;
	}
	var t = config.i18n;
	var state = { open: false, built: false, lastId: 0, chat: null, online: false, timer: 0, busy: false, unread: 0 };
	var el = {};

	function make( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function active( on ) {
		document.cookie = config.cookie + '=' + ( on ? '1' : '' ) + ';path=/;SameSite=Lax' + ( on ? ';max-age=86400' : ';max-age=0' );
	}

	function isActive() {
		return document.cookie.split( '; ' ).indexOf( config.cookie + '=1' ) !== -1;
	}

	// "poll?after=3" on top of a REST base that may already hold "?rest_route=" (plain permalinks).
	function route( path ) {
		var parts = path.split( '?' );
		var url = config.api + parts[ 0 ];
		return parts[ 1 ] ? url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + parts[ 1 ] : url;
	}

	function api( path, options ) {
		options = options || {};
		var headers = { 'X-Sidccofo-Chat': '1' };
		if ( config.restNonce ) {
			headers[ 'X-WP-Nonce' ] = config.restNonce;
		}
		if ( options.body ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		return fetch( route( path ), {
			method: options.method || 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: headers,
			body: options.body ? JSON.stringify( options.body ) : undefined,
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( data && data.message ? data.message : t.error );
				}
				return data;
			} );
		} );
	}

	function launcher() {
		el.button = make( 'button', 'sc-chat__launcher sc-chat--' + config.position );
		el.button.type = 'button';
		el.button.setAttribute( 'aria-label', t.open );
		el.button.setAttribute( 'aria-expanded', 'false' );
		el.button.innerHTML = '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2z"/></svg>';
		el.badge = make( 'span', 'sc-chat__badge' );
		el.badge.hidden = true;
		el.button.appendChild( el.badge );
		el.button.addEventListener( 'click', function () {
			toggle( ! state.open );
		} );
		document.body.appendChild( el.button );
	}

	function build() {
		state.built = true;
		el.panel = make( 'div', 'sc-chat__panel sc-chat--' + config.position );
		el.panel.setAttribute( 'role', 'dialog' );
		el.panel.setAttribute( 'aria-label', t.title );
		el.panel.hidden = true;

		var head = make( 'div', 'sc-chat__head' );
		var titles = make( 'div' );
		titles.appendChild( make( 'strong', 'sc-chat__title', t.title ) );
		el.status = make( 'span', 'sc-chat__status' );
		titles.appendChild( el.status );
		head.appendChild( titles );
		el.end = make( 'button', 'sc-chat__end', t.end );
		el.end.type = 'button';
		el.end.hidden = true;
		el.end.addEventListener( 'click', endChat );
		head.appendChild( el.end );
		var close = make( 'button', 'sc-chat__close', '×' );
		close.type = 'button';
		close.setAttribute( 'aria-label', t.close );
		close.addEventListener( 'click', function () {
			toggle( false );
		} );
		head.appendChild( close );
		el.panel.appendChild( head );

		el.log = make( 'div', 'sc-chat__log' );
		el.log.setAttribute( 'role', 'log' );
		el.log.setAttribute( 'aria-live', 'polite' );
		el.panel.appendChild( el.log );

		el.form = make( 'form', 'sc-chat__form' );
		el.who = make( 'div', 'sc-chat__who' );
		el.name = make( 'input' );
		el.name.type = 'text';
		el.name.autocomplete = 'name';
		el.name.placeholder = t.name;
		el.name.setAttribute( 'aria-label', t.name );
		el.email = make( 'input' );
		el.email.type = 'email';
		el.email.autocomplete = 'email';
		el.email.placeholder = t.email;
		el.email.setAttribute( 'aria-label', t.email );
		el.who.appendChild( el.name );
		el.who.appendChild( el.email );
		el.form.appendChild( el.who );
		var row = make( 'div', 'sc-chat__row' );
		el.text = make( 'textarea' );
		el.text.rows = 2;
		el.text.maxLength = 2000;
		el.text.placeholder = t.message;
		el.text.setAttribute( 'aria-label', t.message );
		el.text.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key && ! event.shiftKey ) {
				event.preventDefault();
				send();
			}
		} );
		row.appendChild( el.text );
		el.send = make( 'button', 'sc-chat__send', t.send );
		el.send.type = 'submit';
		row.appendChild( el.send );
		el.form.appendChild( row );
		el.error = make( 'p', 'sc-chat__error' );
		el.error.setAttribute( 'role', 'alert' );
		el.form.appendChild( el.error );
		el.form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			send();
		} );
		el.panel.appendChild( el.form );
		el.panel.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				toggle( false );
				el.button.focus();
			}
		} );
		document.body.appendChild( el.panel );
	}

	function bubble( message ) {
		var mine = 'visitor' === message.sender;
		var item = make( 'div', 'sc-chat__msg sc-chat__msg--' + message.sender );
		if ( 'system' !== message.sender ) {
			item.appendChild( make( 'span', 'sc-chat__by', mine ? t.you : ( message.author || t.store ) ) );
		}
		item.appendChild( make( 'p', '', message.body ) );
		el.log.appendChild( item );
	}

	function greet() {
		el.log.textContent = '';
		var text = state.online ? t.greeting : t.offline;
		if ( text ) {
			el.log.appendChild( make( 'div', 'sc-chat__msg sc-chat__msg--operator', text ) );
		}
	}

	function apply( data, fresh ) {
		state.online = !! data.online;
		var started = ! state.chat && data.chat;
		state.chat = data.chat;
		if ( state.built ) {
			el.status.textContent = state.online ? t.online : t.away;
			el.status.classList.toggle( 'is-online', state.online );
			if ( fresh ) {
				greet();
			}
			var needWho = ! config.known && ( ! state.chat || 'closed' === state.chat.status ) && ( config.askEmail || ! state.online );
			el.who.hidden = ! needWho;
			el.name.hidden = config.known;
			el.end.hidden = ! state.chat || 'open' !== state.chat.status;
		}
		var operatorNew = 0;
		( data.messages || [] ).forEach( function ( message ) {
			if ( message.id <= state.lastId ) {
				return;
			}
			state.lastId = message.id;
			if ( 'visitor' !== message.sender ) {
				operatorNew++;
			}
			if ( state.built ) {
				bubble( message );
			}
		} );
		if ( state.built ) {
			el.log.scrollTop = el.log.scrollHeight;
			if ( state.chat && 'closed' === state.chat.status && fresh ) {
				el.log.appendChild( make( 'div', 'sc-chat__msg sc-chat__msg--system', t.ended ) );
			}
		}
		if ( ! state.open && operatorNew && ! fresh ) {
			state.unread += operatorNew;
			el.badge.textContent = String( state.unread );
			el.badge.hidden = false;
			el.button.setAttribute( 'aria-label', t.open + ' (' + t.unread + ')' );
		}
		active( !! ( state.chat && 'open' === state.chat.status ) );
		if ( started ) {
			schedule();
		}
	}

	function schedule() {
		clearTimeout( state.timer );
		if ( ! state.chat || 'open' !== state.chat.status || document.hidden ) {
			return;
		}
		state.timer = setTimeout( poll, state.open ? 4000 : 20000 );
	}

	function poll() {
		api( 'poll?after=' + state.lastId )
			.then( function ( data ) {
				apply( data, false );
			} )
			.catch( function () {} )
			.finally( schedule );
	}

	function toggle( open ) {
		if ( open && ! state.built ) {
			build();
			api( 'state' )
				.then( function ( data ) {
					state.lastId = 0;
					apply( data, true );
				} )
				.catch( function () {
					greet();
				} )
				.finally( schedule );
		}
		state.open = open;
		el.panel.hidden = ! open;
		el.button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		el.button.setAttribute( 'aria-label', open ? t.close : t.open );
		if ( open ) {
			state.unread = 0;
			el.badge.hidden = true;
			setTimeout( function () {
				( el.who.hidden || el.email.value ? el.text : ( config.known ? el.text : el.name ) ).focus();
			}, 50 );
		}
		schedule();
	}

	function send() {
		var body = el.text.value.trim();
		if ( ! body || state.busy ) {
			return;
		}
		if ( ! el.who.hidden && ! el.email.value && ( config.askEmail || ! state.online ) ) {
			el.error.textContent = t.needEmail;
			el.email.focus();
			return;
		}
		state.busy = true;
		el.send.disabled = true;
		el.error.textContent = '';
		api( 'send', {
			method: 'POST',
			body: { body: body, name: el.name.value, email: el.email.value, page: location.href, after: state.lastId },
		} )
			.then( function ( data ) {
				el.text.value = '';
				apply( data, false );
			} )
			.catch( function ( error ) {
				el.error.textContent = error.message || t.error;
			} )
			.finally( function () {
				state.busy = false;
				el.send.disabled = false;
				schedule();
			} );
	}

	function endChat() {
		api( 'end', { method: 'POST', body: {} } ).then( function () {
			state.chat = state.chat ? Object.assign( {}, state.chat, { status: 'closed' } ) : null;
			active( false );
			el.end.hidden = true;
			el.log.appendChild( make( 'div', 'sc-chat__msg sc-chat__msg--system', t.ended ) );
			el.who.hidden = config.known || ! ( config.askEmail || ! state.online );
		} );
	}

	function boot() {
		launcher();
		// A conversation going on from another page: keep listening for answers.
		if ( isActive() ) {
			api( 'state' )
				.then( function ( data ) {
					var known = data.messages || [];
					state.lastId = known.length ? known[ known.length - 1 ].id : 0;
					apply( { online: data.online, chat: data.chat, messages: [] }, false );
					schedule();
				} )
				.catch( function () {} );
		}
		document.addEventListener( 'visibilitychange', schedule );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
