<?php
/**
 * Who this site is to the centre.
 *
 * A connected site holds an Ed25519 key pair it made itself. The private
 * half never leaves the site: it is kept through the settings framework's
 * secret mechanism, so it is encrypted in the database, never printed into
 * a form, and can be supplied instead as MAILHOUSE_CENTRAL_PRIVATE_KEY in
 * wp-config.php. The centre holds the public half and nothing that could
 * act as this site.
 *
 * Everything else the connector remembers -- where the centre is, this
 * site's id there, the centre's own public key, when it last answered --
 * is one option. A site that has never connected has no such option, and
 * the connector does nothing at all.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * The connection's stored state, registration, and the cloned-site guard.
 */
class Mailhouse_Central_Identity {

	/**
	 * Option holding the connection's state.
	 */
	const OPTION = 'mailhouse_central';

	/**
	 * Setting holding the private key.
	 */
	const KEY_SETTING = 'central_private_key';

	/**
	 * Setting saying whether there is a connection at all.
	 */
	const FLAG_SETTING = 'central_connected';

	/**
	 * The states a connection can be in.
	 *
	 * ''          Never connected, or disconnected. The connector is inert.
	 * connected   Registered and allowed to talk.
	 * suspended   The centre is refusing this site (key revoked, website
	 *             disconnected there, ...). Only heartbeats are sent.
	 * clone       This site is not at the address it registered from: it is
	 *             a copy. Nothing is sent, to anyone.
	 * gone        The centre says the website was removed. Nothing is sent.
	 */
	const STATUSES = array( '', 'connected', 'suspended', 'clone', 'gone' );

	/**
	 * Loaded state, or null before the first read.
	 *
	 * @var array|null
	 */
	protected static $state = null;

	/**
	 * A state with nothing in it.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'status'            => '',
			'central_url'       => '',
			'website_id'        => '',
			'key_id'            => '',
			'public_key'        => '',
			'registered_url'    => '',
			'platform_keys'     => array(),
			'protocol'          => 0,
			'registered_at'     => 0,
			// Seconds to add to this server's clock to get the centre's.
			'clock_offset'      => 0,
			'last_tick_at'      => 0,
			'last_heartbeat_at' => 0,
			// What the centre said this site's standing is: connected or paused.
			'centre_status'     => '',
			'suspended_reason'  => '',
			'last_error'        => '',
			'failures'          => 0,
			'next_attempt_at'   => 0,
		);
	}

	/**
	 * The connection's state.
	 *
	 * @return array
	 */
	public static function state() {
		if ( null === self::$state ) {
			// A site that is not connected has no option to read, and is not
			// made to look for one: the flag is in the settings every request
			// has already loaded.
			$stored = Mailhouse_Settings::get( self::FLAG_SETTING, false ) ? get_option( self::OPTION, array() ) : array();

			self::$state = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );

			if ( ! in_array( self::$state['status'], self::STATUSES, true ) ) {
				self::$state['status'] = '';
			}
		}

		return self::$state;
	}

	/**
	 * One value from the state.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$state = self::state();

		return isset( $state[ $key ] ) ? $state[ $key ] : null;
	}

	/**
	 * Change the state.
	 *
	 * @param array $changes Key/value pairs.
	 * @return void
	 */
	public static function update( array $changes ) {
		$state = array_merge( self::state(), $changes );

		if ( $state === self::$state && Mailhouse_Settings::get( self::FLAG_SETTING, false ) ) {
			return;
		}

		self::$state = $state;

		update_option( self::OPTION, $state, true );

		if ( ! Mailhouse_Settings::get( self::FLAG_SETTING, false ) ) {
			Mailhouse_Settings::set( array( self::FLAG_SETTING => true ) );
		}
	}

	/**
	 * Forget the in-memory copy.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$state = null;
	}

	/**
	 * The connection's status. An empty string means there is none.
	 *
	 * @return string
	 */
	public static function status() {
		return (string) self::get( 'status' );
	}

	/**
	 * This site's private key, base64, or an empty string.
	 *
	 * @return string
	 */
	public static function private_key() {
		return (string) Mailhouse_Settings::get( self::KEY_SETTING, '' );
	}

	/**
	 * Whether plain http is acceptable, for the centre's address and this
	 * site's own. Only ever for development, and only by a constant in
	 * wp-config.php: the test rig runs everything on one machine without
	 * certificates. Even then this site's own address must be a loopback
	 * one, and the centre refuses anything else.
	 *
	 * @return bool
	 */
	public static function insecure_allowed() {
		return defined( 'MAILHOUSE_CENTRAL_ALLOW_INSECURE' ) && MAILHOUSE_CENTRAL_ALLOW_INSECURE;
	}

	/**
	 * This site's address in the one spelling the centre compares.
	 *
	 * @return array{0:?string,1:?string} Canonical address and problem; one is null.
	 */
	public static function site_url() {
		return Mailhouse_Central_Protocol::canonical_site_url( home_url(), self::insecure_allowed() );
	}

	/**
	 * Check and normalise the centre's address as an administrator typed it.
	 *
	 * @param string $input Address.
	 * @return string|WP_Error The origin, without a trailing slash.
	 */
	public static function central_url( $input ) {
		$text  = trim( (string) $input );
		$parts = wp_parse_url( $text );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'mailhouse_central_url', __( 'Enter the centre’s full address, starting with https://', 'mailhouse' ) );
		}

		$scheme = strtolower( $parts['scheme'] );

		if ( 'https' !== $scheme && ! ( 'http' === $scheme && self::insecure_allowed() ) ) {
			return new WP_Error( 'mailhouse_central_url', __( 'The centre’s address must start with https://', 'mailhouse' ) );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return new WP_Error( 'mailhouse_central_url', __( 'Enter the centre’s address only, with no user name and nothing after a ? or #.', 'mailhouse' ) );
		}

		if ( isset( $parts['path'] ) && '' !== trim( $parts['path'], '/' ) ) {
			return new WP_Error( 'mailhouse_central_url', __( 'Enter the centre’s address only, without a path.', 'mailhouse' ) );
		}

		return $scheme . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	/**
	 * The storage engine of the plugin's tables: 'InnoDB' when they all
	 * are, otherwise the first that is not.
	 *
	 * @return string
	 */
	public static function db_engine() {
		global $wpdb;

		$engine = 'InnoDB';

		foreach ( array( 'subscribers', 'tags', 'subscriber_tags', 'queue', 'log' ) as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( Mailhouse_Install::table( $name ) ) ) );

			if ( $row && isset( $row->Engine ) && 'innodb' !== strtolower( (string) $row->Engine ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				return (string) $row->Engine; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}

		return $engine;
	}

	/**
	 * Register this site with the centre.
	 *
	 * Makes a key pair, proves to the centre that this site holds it, and
	 * exchanges the single-use enrolment token for a registration. The
	 * private key is stored only once the centre has accepted the public
	 * one, so a refused attempt leaves nothing behind.
	 *
	 * @param string $central_input The centre's address, as typed.
	 * @param string $token         The enrolment token, as pasted.
	 * @return true|WP_Error
	 */
	public static function register( $central_input, $token ) {
		if ( Mailhouse_Central::disabled() ) {
			return new WP_Error( 'mailhouse_central_disabled', __( 'The connector is switched off in wp-config.php (MAILHOUSE_CENTRAL_DISABLED).', 'mailhouse' ) );
		}

		if ( '' !== self::status() ) {
			return new WP_Error( 'mailhouse_central_connected', __( 'This site is already connected. Disconnect it first.', 'mailhouse' ) );
		}

		if ( ! Mailhouse_Central_Protocol::available() ) {
			return new WP_Error( 'mailhouse_central_sodium', __( 'This server cannot make the signatures the connection needs (sodium is missing).', 'mailhouse' ) );
		}

		$central = self::central_url( $central_input );

		if ( is_wp_error( $central ) ) {
			return $central;
		}

		$token = trim( (string) $token );

		if ( ! preg_match( '/^mhe_[A-Za-z0-9_-]{43}$/', $token ) ) {
			return new WP_Error( 'mailhouse_central_token', __( 'That is not an enrolment token. Copy it again from the dashboard: it starts with mhe_.', 'mailhouse' ) );
		}

		list( $site_url, $problem ) = self::site_url();

		if ( null === $site_url ) {
			return new WP_Error( 'mailhouse_central_site_url', self::site_url_problem( $problem ) );
		}

		// A key supplied in wp-config.php is used as it is; otherwise a new
		// one is made for every registration.
		if ( Mailhouse_Settings::is_constant( self::KEY_SETTING ) ) {
			$raw = Mailhouse_Central_Protocol::decode( self::private_key(), SODIUM_CRYPTO_SIGN_SECRETKEYBYTES );

			if ( null === $raw ) {
				return new WP_Error( 'mailhouse_central_key', __( 'MAILHOUSE_CENTRAL_PRIVATE_KEY in wp-config.php is not a valid key.', 'mailhouse' ) );
			}

			$key = Mailhouse_Central_Protocol::describe_key( $raw );
		} else {
			$key = Mailhouse_Central_Protocol::generate_keypair();
		}

		$body = array(
			'enrolment_token'   => $token,
			'site_url'          => $site_url,
			'public_key'        => $key['public'],
			'key_id'            => $key['key_id'],
			'proof'             => Mailhouse_Central_Protocol::sign(
				Mailhouse_Central_Protocol::registration_message( $token, $site_url, $key['public'], $key['key_id'] ),
				$key['secret']
			),
			'plugin_version'    => MAILHOUSE_VERSION,
			'protocol_versions' => array( Mailhouse_Central_Protocol::VERSION ),
			'capabilities'      => Mailhouse_Central_Status::capabilities(),
			'environment'       => array(
				'wp'        => get_bloginfo( 'version' ),
				'php'       => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
				'db_engine' => self::db_engine(),
				'timezone'  => wp_timezone_string(),
			),
		);

		$answer = Mailhouse_Central_Client::send( $central, 'POST', '/api/v1/connector/register', $body, null );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		if ( 201 !== $answer['status'] ) {
			return new WP_Error( 'mailhouse_central_refused', self::registration_refusal( $answer ) );
		}

		$accepted = self::read_registration( $answer['body'] );

		if ( is_wp_error( $accepted ) ) {
			return $accepted;
		}

		if ( ! Mailhouse_Settings::is_constant( self::KEY_SETTING ) ) {
			Mailhouse_Settings::set( array( self::KEY_SETTING => $key['secret'] ) );
		}

		self::$state = null;
		self::update(
			array_merge(
				self::defaults(),
				array(
					'status'         => 'connected',
					'central_url'    => $central,
					'website_id'     => $accepted['website_id'],
					'key_id'         => $key['key_id'],
					'public_key'     => $key['public'],
					'registered_url' => $site_url,
					'platform_keys'  => $accepted['platform_keys'],
					'protocol'       => $accepted['protocol'],
					'registered_at'  => time(),
					'clock_offset'   => Mailhouse_Central_Client::offset_from( $answer['body'] ),
				)
			)
		);

		return true;
	}

	/**
	 * Check the centre's answer to a registration before trusting any of it.
	 *
	 * @param mixed $body Decoded response body.
	 * @return array|WP_Error
	 */
	protected static function read_registration( $body ) {
		$malformed = new WP_Error( 'mailhouse_central_answer', __( 'The centre accepted the registration but its answer could not be read. Disconnect the website in the dashboard and try again with a new token.', 'mailhouse' ) );

		if ( ! is_array( $body ) || empty( $body['website_id'] ) || empty( $body['platform_keys'] ) || ! is_array( $body['platform_keys'] ) ) {
			return $malformed;
		}

		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $body['website_id'] ) ) {
			return $malformed;
		}

		if ( ! isset( $body['protocol'] ) || Mailhouse_Central_Protocol::VERSION !== (int) $body['protocol'] ) {
			return $malformed;
		}

		$keys = array();

		foreach ( $body['platform_keys'] as $key ) {
			if ( ! is_array( $key ) || empty( $key['key_id'] ) || ! is_string( $key['key_id'] ) || ! isset( $key['public_key'] ) ) {
				return $malformed;
			}

			if ( null === Mailhouse_Central_Protocol::decode( $key['public_key'], SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) ) {
				return $malformed;
			}

			$keys[] = array(
				'key_id'     => substr( $key['key_id'], 0, 64 ),
				'public_key' => $key['public_key'],
				'status'     => isset( $key['status'] ) && 'next' === $key['status'] ? 'next' : 'active',
			);
		}

		return array(
			'website_id'    => (string) $body['website_id'],
			'platform_keys' => $keys,
			'protocol'      => (int) $body['protocol'],
		);
	}

	/**
	 * Why the centre refused a registration, in words an administrator can act on.
	 *
	 * @param array $answer Response: status and body.
	 * @return string
	 */
	protected static function registration_refusal( $answer ) {
		$code = is_array( $answer['body'] ) && isset( $answer['body']['code'] ) ? (string) $answer['body']['code'] : '';

		switch ( $code ) {
			case 'gone':
				return __( 'That enrolment token is unknown, already used or more than an hour old. Make a new one in the dashboard.', 'mailhouse' );
			case 'site_url_mismatch':
				/* translators: %s: this site's address. */
				return sprintf( __( 'The address entered for this website in the dashboard is not this site’s address, which is %s. Correct it there (by adding the website again) and use the new token.', 'mailhouse' ), home_url() );
			case 'storage_engine_unsupported':
				return __( 'The centre requires this plugin’s database tables to use InnoDB.', 'mailhouse' );
			case 'protocol_unsupported':
				return __( 'This version of the plugin and the centre do not speak a common protocol version. Update whichever is older.', 'mailhouse' );
			case 'rate_limited':
				return __( 'Too many registration attempts from this server. Wait an hour and try again.', 'mailhouse' );
			case 'unauthenticated':
				return __( 'The centre could not verify this site’s proof of its new key. Try again; if it keeps happening, report it.', 'mailhouse' );
		}

		/* translators: %d: HTTP status. */
		return sprintf( __( 'The centre refused the registration (status %d).', 'mailhouse' ), (int) $answer['status'] );
	}

	/**
	 * Why this site's own address cannot be registered.
	 *
	 * @param string|null $problem Problem code.
	 * @return string
	 */
	public static function site_url_problem( $problem ) {
		if ( 'scheme' === $problem ) {
			/* translators: %s: this site's address. */
			return sprintf( __( 'This site’s address (%s) is not https. Only an https site can be connected.', 'mailhouse' ), home_url() );
		}

		/* translators: %s: this site's address. */
		return sprintf( __( 'This site’s address (%s) is not one the centre can register.', 'mailhouse' ), home_url() );
	}

	/**
	 * Whether this site is still at the address it registered from.
	 *
	 * A copy of a connected site -- a staging clone, a restored backup at
	 * another address -- carries the original's key and would otherwise
	 * report as it and act on its commands. A copy finds here that it is
	 * one, and stops before sending anything. If the address comes back
	 * (the copy was the original all along, moved and moved back), so does
	 * the connection.
	 *
	 * @return bool True when the addresses match.
	 */
	public static function at_registered_address() {
		$state = self::state();

		if ( '' === $state['status'] || '' === $state['registered_url'] ) {
			return true;
		}

		list( $current ) = self::site_url();
		$matches         = null !== $current && hash_equals( $state['registered_url'], $current );

		if ( ! $matches && 'clone' !== $state['status'] ) {
			self::update( array( 'status' => 'clone' ) );
		} elseif ( $matches && 'clone' === $state['status'] ) {
			self::update(
				array(
					'status'          => 'connected',
					'failures'        => 0,
					'next_attempt_at' => 0,
				)
			);
		}

		return $matches;
	}

	/**
	 * Disconnect this site.
	 *
	 * Tells the centre, when that is both possible and right, and then
	 * forgets the key and everything else about the connection. The local
	 * part always happens: a site can always be disconnected from its own
	 * settings, whether or not the centre can be reached.
	 *
	 * A copy of a site never tells the centre: it holds the original's key,
	 * and the request would disconnect the original.
	 *
	 * @return bool Whether the centre was told and agreed.
	 */
	public static function disconnect() {
		$state = self::state();
		$told  = false;

		if ( 'connected' === $state['status'] && ! Mailhouse_Central::disabled() && self::at_registered_address() ) {
			$answer = Mailhouse_Central_Client::signed( 'POST', '/api/v1/connector/disconnect', null, 10 );
			$told   = ! is_wp_error( $answer ) && 200 === $answer['status'];
		}

		Mailhouse_Settings::set(
			Mailhouse_Settings::is_constant( self::KEY_SETTING )
				? array( self::FLAG_SETTING => false )
				: array(
					self::FLAG_SETTING => false,
					self::KEY_SETTING  => '',
				)
		);

		delete_option( self::OPTION );
		self::$state = null;

		return $told;
	}
}
