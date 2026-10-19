/**
 * Sidcraft Commerce — events admin: ticket/field rows and the check-in scanner.
 */
( function () {
	'use strict';

	var cfg = window.sidccofoEventsAdmin || {};
	var i18n = cfg.i18n || {};
	var seq = 0;

	/* ------------------------------ Repeaters ------------------------------ */

	document.addEventListener( 'click', function ( e ) {
		var add = e.target.closest && e.target.closest( '[data-sc-ev-add]' );
		if ( add ) {
			e.preventDefault();
			var key = add.dataset.scEvAdd;
			var tpl = document.querySelector( '[data-sc-ev-template="' + key + '"]' );
			var rows = document.querySelector( '[data-sc-ev-rows="' + key + '"]' );
			if ( tpl && rows ) {
				var holder = document.createElement( 'tbody' === rows.tagName.toLowerCase() ? 'tbody' : 'div' );
				holder.innerHTML = tpl.innerHTML.replace( /__i__/g, 'n' + Date.now() + ( seq++ ) );
				var row = holder.firstElementChild;
				rows.appendChild( row );
				var first = row.querySelector( 'input[type="text"]' );
				if ( first ) {
					first.focus();
				}
			}
			return;
		}
		var remove = e.target.closest && e.target.closest( '[data-sc-ev-remove]' );
		if ( remove ) {
			e.preventDefault();
			var row2 = remove.closest( '[data-sc-ev-row]' );
			var pid = row2 && row2.querySelector( 'input[name$="[product_id]"]' );
			if ( pid && parseInt( pid.value, 10 ) > 0 && ! window.confirm( i18n.remove ) ) {
				return;
			}
			if ( row2 ) {
				row2.remove();
			}
			return;
		}
		var confirmLink = e.target.closest && e.target.closest( '[data-sc-ev-confirm]' );
		if ( confirmLink && ! window.confirm( confirmLink.dataset.scEvConfirm ) ) {
			e.preventDefault();
		}
	} );

	document.addEventListener( 'input', function ( e ) {
		if ( e.target.matches && e.target.matches( '[data-sc-ev-name]' ) ) {
			var title = e.target.closest( '[data-sc-ev-row]' ).querySelector( '[data-sc-ev-row-title]' );
			if ( title ) {
				title.textContent = e.target.value || '—';
			}
		}
	} );

	function syncOnline() {
		var toggle = document.querySelector( '[data-sc-ev-online-toggle]' );
		if ( ! toggle ) {
			return;
		}
		document.querySelectorAll( '[data-sc-ev-online-field]' ).forEach( function ( el ) {
			el.hidden = ! toggle.checked;
		} );
	}
	document.addEventListener( 'change', function ( e ) {
		if ( e.target.matches && e.target.matches( '[data-sc-ev-online-toggle]' ) ) {
			syncOnline();
		}
	} );
	syncOnline();

	/* --------------------------- Location preview --------------------------- */

	// Same URLs as Event::map_embed_url() in PHP.
	function mapUrl( label, lat, lng ) {
		var map = cfg.map || {};
		var coords = '' !== lat && '' !== lng && isFinite( lat ) && isFinite( lng );
		var query = coords ? lat + ',' + lng : label;
		if ( 'osm' === map.provider && coords ) {
			var la = parseFloat( lat );
			var ln = parseFloat( lng );
			var d = 0.006;
			return 'https://www.openstreetmap.org/export/embed.html?bbox=' + encodeURIComponent( ( ln - d ) + ',' + ( la - d ) + ',' + ( ln + d ) + ',' + ( la + d ) ) +
				'&layer=mapnik&marker=' + encodeURIComponent( la + ',' + ln );
		}
		if ( map.key ) {
			return 'https://www.google.com/maps/embed/v1/place?key=' + encodeURIComponent( map.key ) + '&q=' + encodeURIComponent( query );
		}
		return 'https://maps.google.com/maps?q=' + encodeURIComponent( query ) + '&output=embed';
	}

	var mapBox = document.querySelector( '[data-sc-ev-mapbox]' );
	if ( mapBox ) {
		var mapFrame = mapBox.querySelector( '[data-sc-ev-map-frame]' );
		var mapEmpty = mapBox.querySelector( '[data-sc-ev-map-empty]' );
		var mapOff = mapBox.querySelector( '[data-sc-ev-map-off]' );
		var mapTimer = null;
		var val = function ( id ) {
			var el = document.getElementById( id );
			return el ? el.value.trim() : '';
		};
		var updateMap = function () {
			var label = [ val( 'sc-ev-venue' ), val( 'sc-ev-address' ) ].filter( Boolean ).join( ', ' );
			var lat = val( 'sc-ev-lat' );
			var lng = val( 'sc-ev-lng' );
			var src = ( label || ( lat && lng ) ) && 'none' !== ( cfg.map || {} ).provider ? mapUrl( label, lat, lng ) : '';
			if ( src && mapFrame.getAttribute( 'src' ) !== src ) {
				mapFrame.setAttribute( 'src', src );
			}
			mapFrame.hidden = ! src;
			mapEmpty.hidden = !! src;
		};
		document.addEventListener( 'input', function ( e ) {
			if ( e.target.id && /^sc-ev-(venue|address|lat|lng)$/.test( e.target.id ) ) {
				clearTimeout( mapTimer );
				mapTimer = setTimeout( updateMap, 700 );
			}
		} );
		document.addEventListener( 'change', function ( e ) {
			if ( 'sidccofo_ev[show_map]' === e.target.name ) {
				mapBox.classList.toggle( 'is-off', ! e.target.checked );
				mapOff.hidden = e.target.checked;
			}
		} );
	}

	/* ------------------------------- Check-in ------------------------------- */

	var root = document.querySelector( '[data-sc-ev-checkin]' );
	if ( ! root ) {
		return;
	}
	var form = root.querySelector( '[data-sc-ev-checkin-form]' );
	var input = root.querySelector( '[data-sc-ev-checkin-code]' );
	var select = root.querySelector( '[data-sc-ev-checkin-event]' );
	var result = root.querySelector( '[data-sc-ev-checkin-result]' );
	var log = root.querySelector( '[data-sc-ev-checkin-log]' );
	var camBtn = root.querySelector( '[data-sc-ev-camera]' );
	var videoWrap = root.querySelector( '[data-sc-ev-video-wrap]' );
	var video = root.querySelector( '[data-sc-ev-video]' );
	var last = { code: '', at: 0 };
	var stream = null;
	var timer = null;

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	function call( code, mode ) {
		return fetch( cfg.api, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( { code: code, event_id: parseInt( select.value, 10 ) || 0, mode: mode || 'checkin' } ),
		} ).then( function ( r ) {
			return r.json().then( function ( json ) {
				if ( ! r.ok ) {
					throw new Error( ( json && json.message ) || i18n.error );
				}
				return json;
			} );
		} );
	}

	function show( data ) {
		var t = data.ticket || {};
		var kind = data.result === 'ok' || data.result === 'undone' ? 'ok' : ( data.result === 'already' ? 'warn' : 'bad' );
		var fields = ( t.fields || [] ).map( function ( f ) {
			return '<li><strong>' + esc( f.label ) + ':</strong> ' + esc( f.value ) + '</li>';
		} ).join( '' );
		result.className = 'sc-ev-checkin__result is-' + kind;
		result.innerHTML = '<p class="sc-ev-checkin__msg">' + esc( data.message ) + '</p>' +
			( t.code ? '<p class="sc-ev-checkin__who"><strong>' + esc( t.name ) + '</strong> · ' + esc( t.type ) + '<br>' + esc( t.event ) + ' · ' + esc( t.date ) + '<br><code>' + esc( t.code ) + '</code>' + ( t.order ? ' · #' + esc( t.order ) : '' ) + '</p>' : '' ) +
			( fields ? '<ul class="sc-ev-checkin__fields">' + fields + '</ul>' : '' ) +
			( data.result === 'ok' ? '<p><button type="button" class="button" data-sc-ev-undo="' + esc( t.code ) + '">' + esc( i18n.undo ) + '</button></p>' : '' );
		if ( navigator.vibrate ) {
			navigator.vibrate( kind === 'ok' ? 80 : [ 60, 60, 60 ] );
		}
		var li = document.createElement( 'li' );
		li.className = 'is-' + kind;
		li.innerHTML = '<span>' + esc( new Date().toLocaleTimeString() ) + '</span> ' + ( t.code ? '<code>' + esc( t.code ) + '</code> ' + esc( t.name ) + ' — ' : '' ) + esc( data.message );
		log.insertBefore( li, log.firstChild );
	}

	function submit( code ) {
		code = String( code || '' ).trim();
		if ( ! code ) {
			return;
		}
		var now = Date.now();
		if ( code === last.code && now - last.at < 3000 ) {
			return; // Same code seen twice by the camera.
		}
		last = { code: code, at: now };
		result.className = 'sc-ev-checkin__result';
		result.textContent = i18n.checking;
		call( code ).then( show ).catch( function ( err ) {
			result.className = 'sc-ev-checkin__result is-bad';
			result.textContent = err.message || i18n.error;
		} );
		input.value = '';
		input.focus();
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		last.code = '';
		submit( input.value );
	} );

	root.addEventListener( 'click', function ( e ) {
		var undo = e.target.closest && e.target.closest( '[data-sc-ev-undo]' );
		if ( undo ) {
			call( undo.dataset.scEvUndo, 'undo' ).then( show );
		}
	} );

	function stopCamera() {
		if ( timer ) {
			clearInterval( timer );
			timer = null;
		}
		if ( stream ) {
			stream.getTracks().forEach( function ( tr ) {
				tr.stop();
			} );
			stream = null;
		}
		videoWrap.hidden = true;
		camBtn.textContent = i18n.startCamera;
	}

	camBtn.addEventListener( 'click', function () {
		if ( stream ) {
			stopCamera();
			return;
		}
		if ( ! ( 'BarcodeDetector' in window ) || ! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia ) {
			result.className = 'sc-ev-checkin__result is-warn';
			result.textContent = i18n.noCamera;
			return;
		}
		var detector;
		try {
			detector = new window.BarcodeDetector( { formats: [ 'qr_code', 'code_128' ] } );
		} catch ( err ) {
			result.className = 'sc-ev-checkin__result is-warn';
			result.textContent = i18n.noCamera;
			return;
		}
		navigator.mediaDevices.getUserMedia( { video: { facingMode: 'environment' }, audio: false } ).then( function ( s ) {
			stream = s;
			video.srcObject = s;
			video.play();
			videoWrap.hidden = false;
			camBtn.textContent = i18n.stopCamera;
			result.className = 'sc-ev-checkin__result';
			result.textContent = i18n.scanning;
			timer = setInterval( function () {
				if ( video.readyState < 2 ) {
					return;
				}
				detector.detect( video ).then( function ( codes ) {
					if ( codes && codes.length ) {
						submit( codes[ 0 ].rawValue );
					}
				} ).catch( function () {} );
			}, 350 );
		} ).catch( function ( err ) {
			result.className = 'sc-ev-checkin__result is-bad';
			result.textContent = err.message || i18n.noCamera;
		} );
	} );
}() );
