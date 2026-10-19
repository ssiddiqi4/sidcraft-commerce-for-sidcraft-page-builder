/**
 * Sidcraft Commerce — verified-customer reviews.
 *
 * Any [data-sc-review-open] (the clickable stars, "Write a review") opens a
 * native <dialog> built from the page's template[data-sc-review-form]. The
 * customer picks stars, enters their order number, order email and review;
 * POST /reviews verifies the order and saves it, and the reviews section is
 * refreshed from the response. Without the template on the page, the stars
 * simply link to the product's reviews section.
 *
 * Events on document: 'sc:review_submitted' detail: { productId, data }.
 */
( function () {
	'use strict';

	var cart = window.sidccofoCart;
	var i18n = ( window.sidccofoCommerce && window.sidccofoCommerce.i18n ) || {};
	if ( ! cart ) {
		return;
	}

	var dialog, lastTrigger;

	function findTemplate( productId ) {
		return (
			document.querySelector( '[data-sc-reviews][data-product-id="' + productId + '"] template[data-sc-review-form]' ) ||
			( ! productId && document.querySelector( 'template[data-sc-review-form]' ) )
		);
	}

	function ensureDialog() {
		if ( dialog ) {
			return dialog;
		}
		dialog = document.createElement( 'dialog' );
		dialog.className = 'sc-unit sc-rv-dialog';
		dialog.setAttribute( 'aria-labelledby', 'sc-rv-title' );
		document.body.appendChild( dialog );
		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog || e.target.closest( '[data-sc-rv-cancel], [data-sc-rv-close]' ) ) {
				dialog.close();
			}
		} );
		dialog.addEventListener( 'close', function () {
			if ( lastTrigger && document.contains( lastTrigger ) ) {
				lastTrigger.focus();
			}
		} );
		dialog.addEventListener( 'submit', onSubmit );
		dialog.addEventListener( 'change', function ( e ) {
			if ( e.target.name === 'rating' ) {
				paintStars( e.target.form );
			}
		} );
		dialog.addEventListener( 'mouseover', function ( e ) {
			var label = e.target.closest( '.sc-rv-stars__row label' );
			if ( label ) {
				var input = document.getElementById( label.htmlFor );
				paintStars( label.closest( 'form' ), input ? Number( input.value ) : 0 );
			}
		} );
		dialog.addEventListener( 'mouseout', function ( e ) {
			if ( e.target.closest( '.sc-rv-stars__row' ) ) {
				paintStars( e.target.closest( 'form' ) );
			}
		} );
		return dialog;
	}

	/** Light up stars 1..n (n = hovered, else the checked value). */
	function paintStars( form, hover ) {
		var checked = form.querySelector( 'input[name="rating"]:checked' );
		var n = hover || ( checked ? Number( checked.value ) : 0 );
		form.querySelectorAll( '.sc-rv-stars__row label' ).forEach( function ( label, i ) {
			label.classList.toggle( 'is-on', i < n );
		} );
	}

	function open( trigger ) {
		var productId = trigger.dataset.productId || '';
		var tpl = findTemplate( productId );
		if ( ! tpl ) {
			return false;
		}
		lastTrigger = trigger;
		ensureDialog();

		// Inherit the colour scheme of the Unit that opened it.
		var unit = trigger.closest( '.sc-unit' );
		dialog.classList.toggle( 'sc-scheme-dark', !! ( unit && unit.classList.contains( 'sc-scheme-dark' ) ) );

		dialog.innerHTML =
			'<button type="button" class="sc-rv-dialog__close" data-sc-rv-close aria-label="' +
			( i18n.close || 'Close' ) +
			'">&times;</button>';
		dialog.appendChild( tpl.content.cloneNode( true ) );
		var form = dialog.querySelector( 'form' );

		var rating = Number( trigger.dataset.rating || 0 );
		if ( rating >= 1 && rating <= 5 ) {
			var radio = form.querySelector( 'input[name="rating"][value="' + rating + '"]' );
			if ( radio ) {
				radio.checked = true;
			}
		}
		paintStars( form );

		// Remember what the customer typed last time (this browser only).
		try {
			var saved = JSON.parse( localStorage.getItem( 'sidccofo_review_contact' ) || 'null' );
			if ( saved ) {
				form.email.value = saved.email || '';
				form.name.value = saved.name || '';
			}
		} catch ( err ) {}

		dialog.showModal();
		var first = rating ? form.querySelector( '[name="order_number"]' ) : form.querySelector( 'input[name="rating"]' );
		if ( first ) {
			first.focus();
		}
		return true;
	}

	function clearErrors( form ) {
		form.querySelectorAll( '.sc-field__error' ).forEach( function ( el ) {
			el.textContent = '';
		} );
		form.querySelectorAll( '[aria-invalid]' ).forEach( function ( el ) {
			el.removeAttribute( 'aria-invalid' );
		} );
		var top = form.querySelector( '[data-sc-rv-error]' );
		top.hidden = true;
		top.textContent = '';
	}

	function showErrors( form, message, fields ) {
		var first = null;
		Object.keys( fields || {} ).forEach( function ( key ) {
			var slot = form.querySelector( '#sc-rv-' + key + '-error' );
			var input = form.querySelector( '[name="' + key + '"]' );
			if ( slot ) {
				slot.textContent = fields[ key ];
			}
			if ( input ) {
				input.setAttribute( 'aria-invalid', 'true' );
				first = first || input;
			}
		} );
		var top = form.querySelector( '[data-sc-rv-error]' );
		if ( first ) {
			first.focus(); // The message sits under the field.
			return;
		}
		top.textContent = message || i18n.error;
		top.hidden = false;
		top.setAttribute( 'tabindex', '-1' );
		top.focus();
	}

	/** Swap in the refreshed summary and list, keeping the section's own heading. */
	function refreshSection( productId, html ) {
		var section = document.querySelector( '[data-sc-reviews][data-product-id="' + productId + '"]' );
		if ( ! section || ! html ) {
			return section;
		}
		var tpl = document.createElement( 'template' );
		tpl.innerHTML = html;
		var fresh = tpl.content.querySelector( '[data-sc-reviews]' );
		if ( ! fresh ) {
			return section;
		}
		[ '.sc-reviews__summary', '.sc-reviews__list, .sc-reviews__empty' ].forEach( function ( sel ) {
			var oldEl = section.querySelector( sel );
			var newEl = fresh.querySelector( sel );
			if ( oldEl && newEl ) {
				oldEl.replaceWith( newEl );
			}
		} );
		return section;
	}

	function onSubmit( e ) {
		var form = e.target.closest( '.sc-rv-form' );
		if ( ! form ) {
			return;
		}
		e.preventDefault();
		var button = form.querySelector( '[data-sc-rv-submit]' );
		if ( button.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}
		clearErrors( form );

		var body = {};
		new FormData( form ).forEach( function ( value, key ) {
			body[ key ] = value;
		} );
		if ( ! body.rating ) {
			showErrors( form, i18n.reviewStars || '', { rating: i18n.reviewStars || '' } );
			return;
		}

		var label = button.innerHTML;
		button.setAttribute( 'aria-busy', 'true' );
		button.textContent = i18n.reviewSending || '…';

		cart.api( 'reviews', 'POST', body )
			.then( function ( data ) {
				try {
					localStorage.setItem( 'sidccofo_review_contact', JSON.stringify( { email: body.email, name: body.name } ) );
				} catch ( err ) {}

				var section = refreshSection( body.product_id, data.html );
				var status = section && section.querySelector( '.sc-reviews__status' );
				if ( status ) {
					status.textContent = data.message;
				}
				form.innerHTML =
					'<div class="sc-rv-done"><div class="sc-rv-done__icon" aria-hidden="true">★</div><h3 class="sc-rv-form__title" id="sc-rv-title"></h3>' +
					'<button type="button" class="sc-btn sc-btn--primary" data-sc-rv-close></button></div>';
				form.querySelector( 'h3' ).textContent = data.message;
				form.querySelector( 'button' ).textContent = i18n.close || 'Close';
				form.querySelector( 'button' ).focus();
				document.dispatchEvent( new CustomEvent( 'sc:review_submitted', { detail: { productId: body.product_id, data: data } } ) );
			} )
			.catch( function ( err ) {
				button.innerHTML = label;
				button.removeAttribute( 'aria-busy' );
				var fields = ( err.data && err.data.data && err.data.data.fields ) || {};
				showErrors( form, err.message, fields );
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest( '[data-sc-review-open]' );
		if ( trigger && open( trigger ) ) {
			e.preventDefault();
		}
	} );

	// Opened from another page's star link (…/product/x/#reviews): scroll there.
	if ( window.location.hash === '#reviews' ) {
		var target = document.getElementById( 'reviews' );
		if ( target ) {
			target.scrollIntoView();
		}
	}
} )();
