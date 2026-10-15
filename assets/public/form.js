/**
 * Progressive enhancement for the signup form.
 *
 * Without this file the form is an ordinary POST that redirects back with a
 * message. With it, the submission happens in place. Nothing here is required
 * for the form to work.
 */
( function () {
	'use strict';

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( ! form.classList || ! form.classList.contains( 'mailhouse-form__form' ) ) {
			return;
		}

		if ( ! window.fetch || ! window.FormData ) {
			return;
		}

		event.preventDefault();

		var wrapper = form.closest( '.mailhouse-form' );
		var button  = form.querySelector( 'button[type="submit"]' );
		var body    = new FormData( form );

		body.append( 'mailhouse_ajax', '1' );

		if ( button ) {
			button.disabled = true;
		}

		window.fetch( form.action, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( json.redirect && /^https?:\/\//i.test( json.redirect ) ) {
					window.location.href = json.redirect;
					return;
				}

				message( wrapper, form, json.message, json.success );

				if ( json.success ) {
					form.reset();
				}
			} )
			.catch( function () {
				// Whatever went wrong locally, the plain submission still
				// works -- so fall back to it rather than showing an error.
				form.submit();
			} )
			.then( function () {
				if ( button ) {
					button.disabled = false;
				}
			} );
	} );

	/**
	 * Show the response above the form.
	 *
	 * @param {Element} wrapper Form wrapper.
	 * @param {Element} form    The form itself.
	 * @param {string}  text    Message.
	 * @param {boolean} ok      Whether it succeeded.
	 */
	function message( wrapper, form, text, ok ) {
		if ( ! wrapper || ! text ) {
			return;
		}

		var box = wrapper.querySelector( '.mailhouse-form__message' );

		if ( ! box ) {
			box = document.createElement( 'p' );
			box.setAttribute( 'role', 'status' );
			wrapper.insertBefore( box, form );
		}

		box.className   = 'mailhouse-form__message mailhouse-form__message--' + ( ok ? 'ok' : 'error' );
		box.textContent = text;
	}
}() );
