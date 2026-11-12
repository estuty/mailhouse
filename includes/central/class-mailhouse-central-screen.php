<?php
/**
 * The "Central" tab of the settings screen: connect, see the connection,
 * disconnect.
 *
 * Everything here needs the same capability as the rest of the settings
 * and a nonce. The private key is never shown; the screen shows its
 * fingerprint, which is enough to tell one key from another.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings tab and its actions.
 */
class Mailhouse_Central_Screen {

	/**
	 * Transient holding the outcome of the last action, per user.
	 */
	const NOTICE = 'mailhouse_central_notice_';

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_filter( 'mailhouse_settings_tabs', array( $this, 'tab' ) );
		add_action( 'admin_post_mailhouse_central_connect', array( $this, 'connect' ) );
		add_action( 'admin_post_mailhouse_central_disconnect', array( $this, 'disconnect' ) );
		add_action( 'admin_post_mailhouse_central_heartbeat', array( $this, 'heartbeat' ) );
		add_action( 'admin_post_mailhouse_central_compare', array( $this, 'compare' ) );
	}

	/**
	 * Add the tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function tab( $tabs ) {
		$tabs['central'] = array(
			'label'    => __( 'Central', 'mailhouse' ),
			'sections' => array(),
			'render'   => array( $this, 'render' ),
		);

		return $tabs;
	}

	/**
	 * The checks made before a site may register.
	 *
	 * @param string $central The centre's address, when one has been given.
	 * @return array[] Each: label, ok, detail, blocking.
	 */
	public static function checklist( $central = '' ) {
		$checks                    = array();
		list( $site_url, $problem ) = Mailhouse_Central_Identity::site_url();
		$engine                    = Mailhouse_Central_Identity::db_engine();

		$checks[] = array(
			'label'    => __( 'This server can make signatures', 'mailhouse' ),
			'ok'       => Mailhouse_Central_Protocol::available(),
			'detail'   => __( 'Sodium is missing. WordPress 5.2 or later includes it.', 'mailhouse' ),
			'blocking' => true,
		);

		$checks[] = array(
			'label'    => __( 'This site’s address can be registered', 'mailhouse' ),
			'ok'       => null !== $site_url,
			'detail'   => null === $site_url ? Mailhouse_Central_Identity::site_url_problem( $problem ) : '',
			'blocking' => true,
		);

		$checks[] = array(
			'label'    => __( 'The plugin’s database tables use InnoDB', 'mailhouse' ),
			'ok'       => 'InnoDB' === $engine,
			/* translators: %s: storage engine name. */
			'detail'   => sprintf( __( 'At least one table uses %s. Take a backup of this site, then convert the tables with ALTER TABLE … ENGINE=InnoDB. The connector will not do that for you.', 'mailhouse' ), $engine ),
			'blocking' => true,
		);

		$conflicts = Mailhouse_Admin::conflicts();

		$checks[] = array(
			'label'    => __( 'No other plugin is managing mail or the list', 'mailhouse' ),
			'ok'       => empty( $conflicts ),
			/* translators: %s: plugin names. */
			'detail'   => sprintf( __( 'Also active: %s. Move its subscribers into Mailhouse and deactivate it before connecting.', 'mailhouse' ), implode( ', ', $conflicts ) ),
			'blocking' => false,
		);

		$checks[] = array(
			'label'    => __( 'Scheduled tasks run on this site', 'mailhouse' ),
			'ok'       => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			'detail'   => __( 'WP-Cron is disabled in wp-config.php. That is fine if the server calls wp-cron.php every minute by its own cron job; if it does not, this site will not report in.', 'mailhouse' ),
			'blocking' => false,
		);

		if ( '' !== $central ) {
			$answer    = Mailhouse_Central_Client::send( $central, 'GET', '/api/health', null, null, 10 );
			$reachable = ! is_wp_error( $answer ) && 200 === $answer['status'];

			$checks[] = array(
				'label'    => __( 'This site can reach the centre', 'mailhouse' ),
				'ok'       => $reachable,
				'detail'   => is_wp_error( $answer )
					? $answer->get_error_message()
					/* translators: %d: HTTP status. */
					: sprintf( __( 'The address answered with status %d, which is not a Mailhouse Central installation saying it is well.', 'mailhouse' ), (int) $answer['status'] ),
				'blocking' => true,
			);

			if ( $reachable && ! empty( $answer['date'] ) && false !== strtotime( $answer['date'] ) ) {
				$drift = abs( strtotime( $answer['date'] ) - time() );

				$checks[] = array(
					'label'    => __( 'This server’s clock agrees with the centre’s', 'mailhouse' ),
					'ok'       => $drift <= MINUTE_IN_SECONDS,
					/* translators: %d: seconds. */
					'detail'   => sprintf( __( 'The clocks differ by %d seconds. The connector allows for this, but a server whose clock is wrong has other problems too; ask the host to fix it.', 'mailhouse' ), $drift ),
					'blocking' => false,
				);
			}
		}

		return $checks;
	}

	/**
	 * Guard an action: capability and nonce.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	protected function guard( $action ) {
		check_admin_referer( $action );

		if ( ! mailhouse_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mailhouse' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Leave a message for the next page view, and go back to the tab.
	 *
	 * @param string $type    success, error or warning.
	 * @param string $message Message.
	 * @param array  $checks  Checklist to show with it.
	 * @return void
	 */
	protected function finish( $type, $message, $checks = array() ) {
		set_transient(
			self::NOTICE . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
				'checks'  => $checks,
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( mailhouse_admin_url( 'settings', array( 'tab' => 'central' ) ) );
		exit;
	}

	/**
	 * Connect this site, or only run the checks.
	 *
	 * @return void
	 */
	public function connect() {
		$this->guard( 'mailhouse_central_connect' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$central    = isset( $_POST['mailhouse_central_url'] ) ? sanitize_text_field( wp_unslash( $_POST['mailhouse_central_url'] ) ) : '';
		$token      = isset( $_POST['mailhouse_central_token'] ) ? sanitize_text_field( wp_unslash( $_POST['mailhouse_central_token'] ) ) : '';
		$check_only = isset( $_POST['mailhouse_central_check'] );
		// phpcs:enable

		$url = Mailhouse_Central_Identity::central_url( $central );

		if ( is_wp_error( $url ) ) {
			$this->finish( 'error', $url->get_error_message() );
		}

		$checks  = self::checklist( $url );
		$blocked = array_filter(
			$checks,
			static function ( $check ) {
				return $check['blocking'] && ! $check['ok'];
			}
		);

		if ( $blocked ) {
			$this->finish( 'error', __( 'This site cannot be connected yet. See the checks below.', 'mailhouse' ), $checks );
		}

		if ( $check_only ) {
			$this->finish( 'success', __( 'Every check that matters passed. This site can be connected.', 'mailhouse' ), $checks );
		}

		$registered = Mailhouse_Central_Identity::register( $url, $token );

		if ( is_wp_error( $registered ) ) {
			$this->finish( 'error', $registered->get_error_message(), $checks );
		}

		// Report in at once, so the dashboard shows this site without
		// waiting for the next scheduled run.
		$first = Mailhouse_Central::heartbeat();

		$this->finish(
			is_wp_error( $first ) ? 'warning' : 'success',
			is_wp_error( $first )
				/* translators: %s: error message. */
				? sprintf( __( 'This site is registered, but its first report was not accepted: %s It will try again within a minute.', 'mailhouse' ), $first->get_error_message() )
				: __( 'This site is connected and has reported in.', 'mailhouse' )
		);
	}

	/**
	 * Disconnect this site.
	 *
	 * @return void
	 */
	public function disconnect() {
		$this->guard( 'mailhouse_central_disconnect' );

		$was  = Mailhouse_Central_Identity::status();
		$told = Mailhouse_Central_Identity::disconnect();

		if ( $told ) {
			$this->finish( 'success', __( 'This site is disconnected, and the centre has been told.', 'mailhouse' ) );
		}

		$this->finish(
			'connected' === $was ? 'warning' : 'success',
			'connected' === $was
				? __( 'This site is disconnected. The centre could not be told, so the website still shows as connected there until someone disconnects it in the dashboard.', 'mailhouse' )
				: __( 'This site is disconnected. Nothing was sent to the centre.', 'mailhouse' )
		);
	}

	/**
	 * Report in now.
	 *
	 * @return void
	 */
	public function heartbeat() {
		$this->guard( 'mailhouse_central_heartbeat' );

		if ( ! Mailhouse_Central_Identity::at_registered_address() ) {
			$this->finish( 'error', __( 'This site is not at the address it registered from, so nothing was sent.', 'mailhouse' ) );
		}

		$result = Mailhouse_Central::heartbeat();

		if ( is_wp_error( $result ) ) {
			$this->finish( 'error', $result->get_error_message() );
		}

		// Asked for by a person, so sent now rather than when the last
		// failure said to try again.
		Mailhouse_Central_Identity::update( array( 'push_next_at' => 0 ) );

		$push = Mailhouse_Central_Sync::push();

		if ( '' !== $push['error'] ) {
			/* translators: %s: what went wrong. */
			$this->finish( 'error', sprintf( __( 'The centre answered, but would not take this site’s changes: %s', 'mailhouse' ), $push['error'] ) );
		}

		$this->finish(
			'success',
			$push['sent'] > 0
				/* translators: %d: number of changes. */
				? sprintf( _n( 'The centre answered, and was sent %d change.', 'The centre answered, and was sent %d changes.', $push['sent'], 'mailhouse' ), $push['sent'] )
				: __( 'The centre answered. This site is connected.', 'mailhouse' )
		);
	}

	/**
	 * Ask for this site's list to be compared with the centre's copy.
	 *
	 * Nothing is compared here and now: the comparison is made a page at a
	 * time on the scheduled runs that follow.
	 *
	 * @return void
	 */
	public function compare() {
		$this->guard( 'mailhouse_central_compare' );

		if ( 'connected' !== Mailhouse_Central_Identity::status() ) {
			$this->finish( 'error', __( 'This site is not connected, so there is nothing to compare with.', 'mailhouse' ) );
		}

		if ( '' === (string) Mailhouse_Central_Identity::get( 'needs_reconcile' ) ) {
			Mailhouse_Central_Identity::update( array( 'needs_reconcile' => 'requested' ) );
		}

		$this->finish( 'success', __( 'This site’s list will be compared with the centre’s copy over the next few minutes. Nothing on this site is changed by it.', 'mailhouse' ) );
	}

	/**
	 * Print the outcome of the last action, once.
	 *
	 * @return void
	 */
	protected function notice() {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			esc_attr( in_array( $notice['type'], array( 'success', 'error', 'warning' ), true ) ? $notice['type'] : 'info' ),
			esc_html( $notice['message'] )
		);

		if ( ! empty( $notice['checks'] ) ) {
			$this->checks( $notice['checks'] );
		}
	}

	/**
	 * Print a checklist.
	 *
	 * @param array[] $checks Checks.
	 * @return void
	 */
	protected function checks( $checks ) {
		echo '<table class="widefat striped" style="max-width:60em;margin:1em 0"><tbody>';

		foreach ( $checks as $check ) {
			$state = $check['ok'] ? __( 'Yes', 'mailhouse' ) : ( $check['blocking'] ? __( 'No', 'mailhouse' ) : __( 'Check', 'mailhouse' ) );

			printf(
				'<tr><td style="width:5em"><strong>%s</strong></td><td>%s%s</td></tr>',
				esc_html( $state ),
				esc_html( $check['label'] ),
				$check['ok'] || '' === $check['detail'] ? '' : '<br /><span class="description">' . esc_html( $check['detail'] ) . '</span>'
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * A point in time, for a person.
	 *
	 * @param int $timestamp Unix time, or zero.
	 * @return string
	 */
	protected function when( $timestamp ) {
		if ( ! $timestamp ) {
			return __( 'never', 'mailhouse' );
		}

		/* translators: %s: a length of time, e.g. "2 minutes". */
		return sprintf( __( '%s ago', 'mailhouse' ), human_time_diff( (int) $timestamp, time() ) );
	}

	/**
	 * Print the tab.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! mailhouse_can_manage() ) {
			return;
		}

		$state  = Mailhouse_Central_Identity::state();
		$status = $state['status'];

		$this->notice();

		echo '<h2>' . esc_html__( 'Mailhouse Central', 'mailhouse' ) . '</h2>';
		echo '<p class="description" style="max-width:60em">' . esc_html__( 'Optional. Connects this site to a Mailhouse Central installation, so that its newsletters can be seen and managed from one dashboard alongside other sites. This site keeps its own subscribers and keeps working by itself whether or not the centre can be reached.', 'mailhouse' ) . '</p>';

		if ( Mailhouse_Central::disabled() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The connector is switched off by MAILHOUSE_CENTRAL_DISABLED in wp-config.php. Nothing is sent to the centre and nothing is received, whatever is shown below.', 'mailhouse' ) . '</p></div>';
		}

		if ( '' === $status ) {
			$this->render_connect();
			return;
		}

		$this->render_connection( $state );
	}

	/**
	 * The form for a site that is not connected.
	 *
	 * @return void
	 */
	protected function render_connect() {
		?>
		<h3><?php esc_html_e( 'Before connecting', 'mailhouse' ); ?></h3>
		<?php $this->checks( self::checklist() ); ?>

		<h3><?php esc_html_e( 'Connect this site', 'mailhouse' ); ?></h3>
		<p class="description" style="max-width:60em">
			<?php esc_html_e( 'In the dashboard, add this website with the address shown here, exactly. It gives you an enrolment token, which works once and for one hour.', 'mailhouse' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mailhouse_central_connect" />
			<?php wp_nonce_field( 'mailhouse_central_connect' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'This site’s address', 'mailhouse' ); ?></th>
					<td><code><?php echo esc_html( home_url() ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><label for="mailhouse-central-url"><?php esc_html_e( 'Central address', 'mailhouse' ); ?></label></th>
					<td>
						<input type="url" class="regular-text" id="mailhouse-central-url" name="mailhouse_central_url" placeholder="https://" required />
						<p class="description"><?php esc_html_e( 'Where the dashboard is. It cannot be changed afterwards without connecting again.', 'mailhouse' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mailhouse-central-token"><?php esc_html_e( 'Enrolment token', 'mailhouse' ); ?></label></th>
					<td>
						<input type="password" class="regular-text" id="mailhouse-central-token" name="mailhouse_central_token" autocomplete="off" spellcheck="false" />
						<p class="description"><?php esc_html_e( 'Starts with mhe_. It is used once and not kept.', 'mailhouse' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<?php submit_button( __( 'Connect', 'mailhouse' ), 'primary', 'mailhouse_central_go', false, Mailhouse_Central::disabled() ? array( 'disabled' => 'disabled' ) : array() ); ?>
				<?php submit_button( __( 'Only run the checks', 'mailhouse' ), 'secondary', 'mailhouse_central_check', false, Mailhouse_Central::disabled() ? array( 'disabled' => 'disabled' ) : array() ); ?>
			</p>
		</form>
		<?php
	}

	/**
	 * The state of a site that is connected, or was.
	 *
	 * @param array $state Connection state.
	 * @return void
	 */
	protected function render_connection( $state ) {
		$status = $state['status'];

		$words = array(
			'connected' => 'paused' === $state['centre_status']
				? __( 'Connected. The centre has paused this website: it is given no work, and keeps reporting in.', 'mailhouse' )
				: __( 'Connected.', 'mailhouse' ),
			'suspended' => Mailhouse_Central::suspension( $state['suspended_reason'] ),
			'gone'      => __( 'The centre says this website has been removed there. Nothing more is sent. Disconnect here to tidy up.', 'mailhouse' ),
			/* translators: 1: address registered, 2: address now. */
			'clone'     => sprintf( __( 'This site registered as %1$s and is now at %2$s, so it is treated as a copy: nothing is sent to the centre from here. If this is a staging copy, disconnect it; that affects only this copy. If the site has really moved, disconnect and connect again with a new token.', 'mailhouse' ), $state['registered_url'], home_url() ),
		);

		printf(
			'<div class="notice notice-%s inline"><p><strong>%s</strong></p></div>',
			'connected' === $status ? 'success' : 'warning',
			esc_html( $words[ $status ] )
		);

		if ( '' !== $state['last_error'] ) {
			printf(
				'<div class="notice notice-error inline"><p>%s %s</p></div>',
				esc_html__( 'The last attempt to reach the centre failed:', 'mailhouse' ),
				esc_html( $state['last_error'] )
			);
		}

		if ( '' !== $state['push_error'] ) {
			printf(
				'<div class="notice notice-error inline"><p>%s %s</p></div>',
				esc_html__( 'The last attempt to send this site’s changes failed:', 'mailhouse' ),
				esc_html( $state['push_error'] )
			);
		}

		if ( '' !== $state['command_error'] ) {
			printf(
				'<div class="notice notice-error inline"><p>%s %s</p></div>',
				esc_html__( 'Something the centre asked this site to do was not done:', 'mailhouse' ),
				esc_html( $state['command_error'] )
			);
		}

		if ( '' !== $state['needs_reconcile'] && 'requested' !== $state['needs_reconcile'] ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Some of this site’s changes did not reach the centre one by one. Nothing is lost here: this site’s list is about to be compared with the centre’s copy, which brings it up to date.', 'mailhouse' )
			);
		}

		$backfill = (array) $state['backfill'];
		$compare  = (array) $state['reconcile'];
		$last     = (array) $state['last_reconcile'];

		if ( $backfill && 'done' !== $backfill['phase'] ) {
			/* translators: %s: a subscriber id. */
			$copying = sprintf( __( 'under way: subscribers up to number %s have been sent', 'mailhouse' ), number_format_i18n( (int) $backfill['cursor'] ) );
		} else {
			$copying = $backfill && ! empty( $backfill['completed_at'] ) ? $this->when( (int) $backfill['completed_at'] ) : __( 'done', 'mailhouse' );
		}

		if ( $compare ) {
			/* translators: %s: number of subscribers. */
			$comparing = sprintf( __( 'under way: %s subscribers checked so far', 'mailhouse' ), number_format_i18n( (int) $compare['checked'] ) );
		} elseif ( '' !== $state['needs_reconcile'] || $state['want_reconcile'] ) {
			$comparing = __( 'about to start', 'mailhouse' );
		} elseif ( $state['last_reconcile_at'] && $last ) {
			$comparing = sprintf(
				/* translators: 1: a length of time ago, 2: subscribers checked, 3: subscribers sent again. */
				__( '%1$s: %2$s checked, %3$s sent again', 'mailhouse' ),
				$this->when( (int) $state['last_reconcile_at'] ),
				number_format_i18n( (int) $last['checked'] ),
				number_format_i18n( (int) $last['sent'] )
			);
		} else {
			$comparing = __( 'not yet', 'mailhouse' );
		}

		$waiting = Mailhouse_Central_Outbox::waiting();

		if ( ! Mailhouse_Central_Commands::accepting() ) {
			$changes = defined( 'MAILHOUSE_CENTRAL_READ_ONLY' ) && MAILHOUSE_CENTRAL_READ_ONLY
				? __( 'refused: switched off in wp-config.php (MAILHOUSE_CENTRAL_READ_ONLY)', 'mailhouse' )
				: __( 'refused', 'mailhouse' );
		} else {
			$dealt = Mailhouse_Central_Commands::counts();
			$total = $dealt['applied'] + $dealt['rejected'] + $dealt['failed'];

			$changes = 0 === $total
				? __( 'accepted; none asked for yet', 'mailhouse' )
				: sprintf(
					/* translators: 1: number carried out, 2: number refused, 3: number that failed. */
					__( 'accepted: %1$s carried out, %2$s refused, %3$s failed', 'mailhouse' ),
					number_format_i18n( $dealt['applied'] ),
					number_format_i18n( $dealt['rejected'] ),
					number_format_i18n( $dealt['failed'] )
				);
		}

		$rows = array(
			__( 'Central address', 'mailhouse' )        => $state['central_url'],
			__( 'This website’s id there', 'mailhouse' ) => $state['website_id'],
			__( 'Registered as', 'mailhouse' )          => $state['registered_url'],
			__( 'Connected', 'mailhouse' )              => $this->when( $state['registered_at'] ),
			__( 'Last report accepted', 'mailhouse' )   => $this->when( $state['last_heartbeat_at'] ),
			__( 'Changes waiting to be sent', 'mailhouse' ) => 0 === $waiting['depth']
				? __( 'none', 'mailhouse' )
				/* translators: 1: number of changes, 2: a length of time ago. */
				: sprintf( __( '%1$s, the oldest from %2$s', 'mailhouse' ), number_format_i18n( $waiting['depth'] ), $this->when( strtotime( $waiting['oldest'] . ' UTC' ) ) ),
			__( 'Changes last sent', 'mailhouse' )      => $this->when( $state['last_push_at'] ),
			__( 'First copy of the list', 'mailhouse' ) => $copying,
			__( 'Last compared', 'mailhouse' )          => $comparing,
			__( 'Changes asked for by the centre', 'mailhouse' ) => $changes,
			/* translators: %s: key fingerprint. */
			__( 'This site’s key', 'mailhouse' )         => sprintf( __( '%s (the private half never leaves this site)', 'mailhouse' ), $state['key_id'] ),
		);

		echo '<table class="form-table" role="presentation">';

		foreach ( $rows as $label => $value ) {
			printf( '<tr><th scope="row">%s</th><td><code>%s</code></td></tr>', esc_html( $label ), esc_html( $value ) );
		}

		echo '</table>';

		if ( in_array( $status, array( 'connected', 'suspended' ), true ) && ! Mailhouse_Central::disabled() ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:1em">
				<input type="hidden" name="action" value="mailhouse_central_heartbeat" />
				<?php wp_nonce_field( 'mailhouse_central_heartbeat' ); ?>
				<?php submit_button( __( 'Report in now', 'mailhouse' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:1em">
				<input type="hidden" name="action" value="mailhouse_central_compare" />
				<?php wp_nonce_field( 'mailhouse_central_compare' ); ?>
				<?php submit_button( __( 'Compare with the centre', 'mailhouse' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
			<input type="hidden" name="action" value="mailhouse_central_disconnect" />
			<?php wp_nonce_field( 'mailhouse_central_disconnect' ); ?>
			<?php submit_button( __( 'Disconnect this site', 'mailhouse' ), 'delete', 'submit', false ); ?>
		</form>
		<p class="description" style="max-width:60em">
			<?php esc_html_e( 'Disconnecting removes this site’s key and stops everything the connector does. Subscribers, tags, campaigns and mail settings are not touched. The website’s history stays at the centre, and it can be connected again with a new token.', 'mailhouse' ); ?>
		</p>
		<?php
	}
}
