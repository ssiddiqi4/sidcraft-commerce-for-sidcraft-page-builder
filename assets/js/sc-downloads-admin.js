/**
 * Sidcraft Commerce — the "Digital product" part of the Product data box.
 *
 * Adds and removes file rows, shows the files table when "Downloadable" is
 * ticked, and opens the media library for "Upload / choose file". Uploads made
 * from here are stored in the protected downloads folder (the request carries
 * sidccofo_download_upload=1, which the server checks).
 */
( function () {
	'use strict';

	var cfg = window.sidccofoDownloads || {};

	function box( el ) {
		return el.closest( '[data-sc-downloads]' );
	}

	function addRow( root ) {
		var tpl = root.querySelector( 'template[data-template]' );
		var body = root.querySelector( '[data-rows]' );
		if ( ! tpl || ! body ) {
			return null;
		}
		var holder = document.createElement( 'tbody' );
		holder.innerHTML = tpl.innerHTML.replace( /__i__/g, 'n' + Date.now().toString( 36 ) + Math.floor( Math.random() * 1000 ) );
		var row = holder.firstElementChild;
		body.appendChild( row );
		return row;
	}

	function choose( row ) {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		var frame = window.wp.media( {
			title: cfg.title || '',
			button: { text: cfg.button || '' },
			multiple: false
		} );
		frame.on( 'ready', function () {
			// Uploads from this frame go to the protected folder.
			if ( frame.uploader && frame.uploader.options && frame.uploader.options.uploader ) {
				frame.uploader.options.uploader.params = frame.uploader.options.uploader.params || {};
				frame.uploader.options.uploader.params.sidccofo_download_upload = 1;
			}
		} );
		frame.on( 'select', function () {
			var file = frame.state().get( 'selection' ).first();
			if ( ! file ) {
				return;
			}
			file = file.toJSON();
			var url = row.querySelector( '[data-file-url]' );
			var name = row.querySelector( '[data-file-name]' );
			if ( url ) {
				url.value = file.url || '';
			}
			if ( name && ! name.value ) {
				name.value = file.title || file.filename || '';
			}
		} );
		frame.open();
	}

	document.addEventListener( 'change', function ( e ) {
		if ( ! e.target.matches( '[data-sc-dl-toggle]' ) ) {
			return;
		}
		var root = box( e.target );
		var body = root && root.querySelector( '[data-sc-dl-body]' );
		if ( ! body ) {
			return;
		}
		body.hidden = ! e.target.checked;
		if ( e.target.checked && ! root.querySelector( '[data-rows] [data-row]' ) ) {
			addRow( root );
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		var root = box( e.target );
		if ( ! root ) {
			return;
		}
		var add = e.target.closest( '[data-add-file]' );
		var pick = e.target.closest( '[data-choose-file]' );
		var remove = e.target.closest( '[data-remove-file]' );
		if ( add ) {
			e.preventDefault();
			var row = addRow( root );
			var first = row && row.querySelector( 'input[type="text"]' );
			if ( first ) {
				first.focus();
			}
		} else if ( pick ) {
			e.preventDefault();
			choose( pick.closest( '[data-row]' ) );
		} else if ( remove ) {
			e.preventDefault();
			var tr = remove.closest( '[data-row]' );
			if ( tr ) {
				tr.remove();
			}
		}
	} );
}() );
