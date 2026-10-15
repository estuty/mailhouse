/**
 * Admin behaviour: the test email button and the two panel switchers.
 *
 * Plain DOM, no build step, no jQuery dependency beyond what WordPress
 * already loads elsewhere.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		testEmail();
		mailerPanels();
		typePanels();
		smtpPresets();
		imagePicker();
	} );

	/**
	 * Wire up the "send test" button wherever one appears.
	 */
	function testEmail() {
		var button = document.getElementById( 'mailhouse-send-test' );

		if ( ! button ) {
			return;
		}

		var field  = document.getElementById( 'mailhouse-test' );
		var result = document.querySelector( '.mailhouse-test-result' );

		button.addEventListener( 'click', function () {
			if ( ! field || ! field.value ) {
				return;
			}

			var body = new FormData();

			body.append( 'action', 'mailhouse_test_email' );
			body.append( 'nonce', button.getAttribute( 'data-nonce' ) );
			body.append( 'email', field.value );

			if ( button.getAttribute( 'data-post' ) ) {
				body.append( 'post', button.getAttribute( 'data-post' ) );
			}

			button.disabled = true;
			show( result, mailhouseAdmin.sending, '' );

			window.fetch( mailhouseAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					var message = json && json.data && json.data.message ? json.data.message : '';

					show( result, message, json && json.success ? 'ok' : 'error' );
				} )
				.catch( function () {
					show( result, mailhouseAdmin.failed, 'error' );
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	}

	/**
	 * Print a result message.
	 *
	 * @param {Element} target Where to put it.
	 * @param {string}  text   Message.
	 * @param {string}  state  ok, error, or empty while in flight.
	 */
	function show( target, text, state ) {
		if ( ! target ) {
			return;
		}

		target.textContent = text;
		target.className   = 'mailhouse-test-result' + ( state ? ' is-' + state : '' );
	}

	/**
	 * Show the settings for every transport in use: the main one, the
	 * backup, and the newsletter one. The backup and newsletter pickers sit
	 * on the same tab, so their settings appear under the main list.
	 */
	function mailerPanels() {
		var select = document.getElementById( 'mailhouse-mailer' );

		if ( ! select ) {
			return;
		}

		var panels  = document.querySelectorAll( '.mailhouse-mailer-panel' );
		var others  = document.querySelectorAll( '.mailhouse-provider-select' );
		var update  = function () {
			var wanted = [ select.value ];

			Array.prototype.forEach.call( others, function ( other ) {
				if ( other.value ) {
					wanted.push( other.value );
				}
			} );

			Array.prototype.forEach.call( panels, function ( panel ) {
				panel.style.display = wanted.indexOf( panel.getAttribute( 'data-mailer' ) ) !== -1 ? '' : 'none';
			} );
		};

		select.addEventListener( 'change', update );

		Array.prototype.forEach.call( others, function ( other ) {
			other.addEventListener( 'change', update );
		} );

		update();
	}

	/**
	 * Fill in the SMTP server details for a well-known provider.
	 */
	function smtpPresets() {
		var preset = document.getElementById( 'mailhouse-smtp_preset' );

		if ( ! preset ) {
			return;
		}

		var presets = {
			gmail: [ 'smtp.gmail.com', 587, 'tls' ],
			microsoft: [ 'smtp.office365.com', 587, 'tls' ],
			outlook: [ 'smtp-mail.outlook.com', 587, 'tls' ],
			zoho: [ 'smtp.zoho.com', 465, 'ssl' ],
			yahoo: [ 'smtp.mail.yahoo.com', 465, 'ssl' ],
			icloud: [ 'smtp.mail.me.com', 587, 'tls' ],
			ses: [ 'email-smtp.us-east-1.amazonaws.com', 587, 'tls' ],
			hostinger: [ 'smtp.hostinger.com', 465, 'ssl' ]
		};

		preset.addEventListener( 'change', function () {
			var values = presets[ preset.value ];

			if ( ! values ) {
				return;
			}

			var host       = document.getElementById( 'mailhouse-smtp_host' );
			var port       = document.getElementById( 'mailhouse-smtp_port' );
			var encryption = document.getElementById( 'mailhouse-smtp_encryption' );
			var auth       = document.getElementById( 'mailhouse-smtp_auth' );

			if ( host ) {
				host.value = values[ 0 ];
			}

			if ( port ) {
				port.value = values[ 1 ];
			}

			if ( encryption ) {
				encryption.value = values[ 2 ];
			}

			if ( auth ) {
				auth.checked = true;
			}
		} );
	}

	/**
	 * The "Choose from Media Library" button next to the logo field.
	 */
	function imagePicker() {
		var buttons = document.querySelectorAll( '.mailhouse-image-pick' );

		Array.prototype.forEach.call( buttons, function ( button ) {
			if ( ! window.wp || ! window.wp.media ) {
				button.style.display = 'none';
				return;
			}

			var frame;

			button.addEventListener( 'click', function () {
				var input   = document.getElementById( button.getAttribute( 'data-target' ) );
				var preview = document.querySelector( '.mailhouse-image-preview[data-for="' + button.getAttribute( 'data-target' ) + '"]' );

				if ( ! frame ) {
					frame = window.wp.media( {
						title: mailhouseAdmin.logo,
						button: { text: mailhouseAdmin.useLogo },
						library: { type: 'image' },
						multiple: false
					} );

					frame.on( 'select', function () {
						var attachment = frame.state().get( 'selection' ).first().toJSON();

						if ( ! input || ! attachment || ! /^https?:\/\//i.test( attachment.url ) ) {
							return;
						}

						input.value = attachment.url;

						if ( preview ) {
							var img = document.createElement( 'img' );

							img.src            = attachment.url;
							img.alt            = '';
							img.style.maxWidth = '240px';
							img.style.height   = 'auto';
							img.style.marginTop = '8px';

							preview.textContent = '';
							preview.appendChild( img );
						}
					} );
				}

				frame.open();
			} );
		} );
	}

	/**
	 * Show only the fields belonging to the selected email type.
	 */
	function typePanels() {
		var select = document.getElementById( 'mailhouse-type' );

		if ( ! select ) {
			return;
		}

		var panels = document.querySelectorAll( '.mailhouse-type-panel' );

		select.addEventListener( 'change', function () {
			Array.prototype.forEach.call( panels, function ( panel ) {
				panel.style.display = panel.getAttribute( 'data-type' ) === select.value ? '' : 'none';
			} );
		} );
	}
}() );
