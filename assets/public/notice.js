/**
 * Niceties for the post-click popup.
 *
 * Everything here is optional. Without it the popup is still shown and still
 * dismissed -- the backdrop and the button are ordinary links back to the same
 * page without the message in the address. This adds: closing in place rather
 * than reloading, Escape, and clearing the query argument so a refresh or a
 * shared link does not show the message a second time.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var popup = document.getElementById( 'mailhouse-popup' );

		if ( ! popup ) {
			return;
		}

		var box      = popup.querySelector( '.mailhouse-popup__box' );
		var button   = popup.querySelector( '[data-mailhouse-close]' );
		var backdrop = popup.querySelector( '.mailhouse-popup__backdrop' );
		var previous = document.activeElement;

		// Take the message out of the address bar straight away: the
		// confirmation has already happened, and a reload should not replay it.
		if ( window.history && window.history.replaceState ) {
			try {
				var url = new URL( window.location.href );

				url.searchParams.delete( 'mailhouse_notice' );
				window.history.replaceState( {}, '', url.toString() );
			} catch ( e ) {
				// An older browser, or an opaque origin. The links still work.
			}
		}

		/**
		 * Hide the popup and give focus back.
		 *
		 * @param {Event} event The event asking to close, if any.
		 */
		function close( event ) {
			if ( event ) {
				event.preventDefault();
			}

			popup.hidden = true;

			if ( previous && previous.focus ) {
				previous.focus();
			}

			document.removeEventListener( 'keydown', onKey );
		}

		/**
		 * Escape closes, and Tab is kept inside the box while it is open.
		 *
		 * @param {KeyboardEvent} event Key event.
		 */
		function onKey( event ) {
			if ( 'Escape' === event.key || 'Esc' === event.key ) {
				close( event );
				return;
			}

			if ( 'Tab' === event.key && box && ! box.contains( event.target ) ) {
				event.preventDefault();

				if ( button ) {
					button.focus();
				}
			}
		}

		if ( button ) {
			button.addEventListener( 'click', close );
		}

		if ( backdrop ) {
			backdrop.addEventListener( 'click', close );
		}

		document.addEventListener( 'keydown', onKey );

		if ( button ) {
			button.focus();
		}
	} );
}() );
