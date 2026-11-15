/**
 * Newsletter signup forms ([data-sc-newsletter]). No dependencies.
 */
( function () {
	'use strict';

	function init( form ) {
		var message = form.querySelector( '.sc-newsletter__message' );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var email = form.querySelector( '[name="email"]' );
			var consent = form.querySelector( '[name="consent"]' );
			if ( ! email.value || ! email.checkValidity() ) {
				message.textContent = email.validationMessage || '';
				email.focus();
				return;
			}
			if ( consent && ! consent.checked ) {
				message.textContent = consent.validationMessage || '';
				consent.focus();
				return;
			}
			var button = form.querySelector( 'button[type="submit"]' );
			button.disabled = true;
			var body = {};
			new FormData( form ).forEach( function ( value, key ) {
				body[ key ] = value;
			} );
			fetch( form.dataset.api, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( body ),
			} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						return { ok: response.ok, data: data };
					} );
				} )
				.then( function ( result ) {
					message.textContent = result.data && result.data.message ? result.data.message : '';
					form.classList.toggle( 'is-done', result.ok );
					if ( result.ok ) {
						form.reset();
					}
				} )
				.catch( function () {
					message.textContent = form.dataset.error || 'Error';
				} )
				.finally( function () {
					button.disabled = false;
				} );
		} );
	}

	function boot() {
		document.querySelectorAll( '[data-sc-newsletter]' ).forEach( init );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
