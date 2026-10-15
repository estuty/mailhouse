<?php
/**
 * Tools: testing, import, export, migration and a status report.
 *
 * The migration side exists because nobody adopts a mail plugin on a fresh
 * site. There is always a list somewhere, and it has to come across without
 * anybody being emailed twice or losing their unsubscribe.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Tools screen and its actions.
 */
class Mailhouse_Tools {

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_action( 'admin_post_mailhouse_import_csv', array( $this, 'import_csv' ) );
		add_action( 'admin_post_mailhouse_export_csv', array( $this, 'export_csv' ) );
		add_action( 'admin_post_mailhouse_migrate', array( $this, 'migrate' ) );
	}

	/**
	 * Print the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! mailhouse_can_manage() ) {
			return;
		}

		?>
		<div class="wrap mailhouse">
			<h1><?php esc_html_e( 'Tools', 'mailhouse' ); ?></h1>

			<?php $this->notice(); ?>

			<div class="mailhouse-columns">
				<div class="mailhouse-panel">
					<h2><?php esc_html_e( 'Send a test email', 'mailhouse' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Goes out through the configured transport and reports back exactly what it said, including the failure if there is one.', 'mailhouse' ); ?></p>
					<p>
						<input type="email" id="mailhouse-test" class="regular-text" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" />
						<button type="button" class="button button-primary" id="mailhouse-send-test" data-nonce="<?php echo esc_attr( wp_create_nonce( 'mailhouse_test_email' ) ); ?>">
							<?php esc_html_e( 'Send test', 'mailhouse' ); ?>
						</button>
					</p>
					<div class="mailhouse-test-result"></div>
				</div>

				<div class="mailhouse-panel">
					<h2><?php esc_html_e( 'Export subscribers', 'mailhouse' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="mailhouse_export_csv" />
						<?php wp_nonce_field( 'mailhouse_export_csv' ); ?>

						<p>
							<label for="mailhouse-export-status"><?php esc_html_e( 'Include', 'mailhouse' ); ?></label>
							<select name="status" id="mailhouse-export-status">
								<option value="subscribed"><?php esc_html_e( 'Subscribed only', 'mailhouse' ); ?></option>
								<option value=""><?php esc_html_e( 'Everyone, whatever their status', 'mailhouse' ); ?></option>
								<option value="pending"><?php esc_html_e( 'Pending only', 'mailhouse' ); ?></option>
								<option value="unsubscribed"><?php esc_html_e( 'Unsubscribed only', 'mailhouse' ); ?></option>
							</select>
						</p>

						<?php submit_button( __( 'Download CSV', 'mailhouse' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
			</div>

			<div class="mailhouse-panel">
				<h2><?php esc_html_e( 'Import subscribers from a CSV', 'mailhouse' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'The first row must be a header. A column called email is required; first_name, last_name, status and tags are used if present. Addresses already on the list are updated, never duplicated.', 'mailhouse' ); ?>
				</p>

				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mailhouse_import_csv" />
					<?php wp_nonce_field( 'mailhouse_import_csv' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="mailhouse-csv"><?php esc_html_e( 'CSV file', 'mailhouse' ); ?></label></th>
							<td><input type="file" name="csv" id="mailhouse-csv" accept=".csv,text/csv" required /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mailhouse-import-status"><?php esc_html_e( 'Status for rows that do not say', 'mailhouse' ); ?></label></th>
							<td>
								<select name="status" id="mailhouse-import-status">
									<option value="subscribed"><?php esc_html_e( 'Subscribed', 'mailhouse' ); ?></option>
									<option value="pending"><?php esc_html_e( 'Pending, and send them a confirmation email', 'mailhouse' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Only import addresses that asked to hear from you. Buying or scraping a list is how a sending domain gets blocked.', 'mailhouse' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mailhouse-import-tags"><?php esc_html_e( 'Tag everyone imported with', 'mailhouse' ); ?></label></th>
							<td><input type="text" name="tags" id="mailhouse-import-tags" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. imported-2024', 'mailhouse' ); ?>" /></td>
						</tr>
					</table>

					<?php submit_button( __( 'Import', 'mailhouse' ) ); ?>
				</form>
			</div>

			<?php $this->render_migrations(); ?>
			<?php $this->render_status(); ?>
		</div>
		<?php
	}

	/**
	 * The migration panel, listing whatever is actually present on this site.
	 *
	 * @return void
	 */
	protected function render_migrations() {
		$sources = $this->available_sources();

		?>
		<div class="mailhouse-panel">
			<h2><?php esc_html_e( 'Move in from another plugin', 'mailhouse' ); ?></h2>

			<?php if ( ! $sources ) : ?>
				<p class="description"><?php esc_html_e( 'Nothing to import from was found on this site.', 'mailhouse' ); ?></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Nothing is deleted from the other plugin. Import, check the result, then deactivate it.', 'mailhouse' ); ?></p>

				<table class="widefat striped">
					<tbody>
						<?php foreach ( $sources as $key => $source ) : ?>
							<tr>
								<td style="width:26%"><strong><?php echo esc_html( $source['label'] ); ?></strong></td>
								<td><?php echo esc_html( $source['description'] ); ?></td>
								<td style="width:16%">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="mailhouse_migrate" />
										<input type="hidden" name="source" value="<?php echo esc_attr( $key ); ?>" />
										<?php wp_nonce_field( 'mailhouse_migrate' ); ?>
										<button type="submit" class="button"><?php esc_html_e( 'Import', 'mailhouse' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The system status panel.
	 *
	 * @return void
	 */
	protected function render_status() {
		global $wpdb;

		$provider = Mailhouse_Mailer::provider();
		$next     = wp_next_scheduled( 'mailhouse_process_queue' );

		$rows = array(
			__( 'Mailhouse version', 'mailhouse' )   => MAILHOUSE_VERSION,
			__( 'WordPress', 'mailhouse' )           => get_bloginfo( 'version' ),
			__( 'PHP', 'mailhouse' )                 => PHP_VERSION,
			__( 'Database', 'mailhouse' )            => $wpdb->db_version(),
			__( 'Transport', 'mailhouse' )           => $provider ? $provider->label() : __( 'unknown', 'mailhouse' ),
			__( 'Transport configured', 'mailhouse' ) => Mailhouse_Mailer::is_ready() ? __( 'yes', 'mailhouse' ) : __( 'no', 'mailhouse' ),
			__( 'Credential encryption', 'mailhouse' ) => Mailhouse_Crypto::is_available() ? __( 'available', 'mailhouse' ) : __( 'not available', 'mailhouse' ),
			__( 'WP-Cron', 'mailhouse' )             => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? __( 'disabled in wp-config.php', 'mailhouse' ) : __( 'enabled', 'mailhouse' ),
			__( 'Next queue run', 'mailhouse' )      => $next ? mailhouse_date( gmdate( 'Y-m-d H:i:s', $next ) ) : __( 'not scheduled', 'mailhouse' ),
			__( 'Waiting in the queue', 'mailhouse' ) => number_format_i18n( Mailhouse_Queue::pending_count() ),
			__( 'Subscribers', 'mailhouse' )         => number_format_i18n( Mailhouse_Subscribers::count( array( 'status' => 'subscribed' ) ) ),
			__( 'Log entries', 'mailhouse' )         => number_format_i18n( Mailhouse_Log::count() ),
		);

		$conflicts = Mailhouse_Admin::conflicts();

		if ( $conflicts ) {
			$rows[ __( 'Conflicting plugins', 'mailhouse' ) ] = implode( ', ', $conflicts );
		}

		$report = '';

		foreach ( $rows as $label => $value ) {
			$report .= $label . ': ' . $value . "\n";
		}

		?>
		<div class="mailhouse-panel">
			<h2><?php esc_html_e( 'Status', 'mailhouse' ); ?></h2>

			<table class="widefat striped">
				<tbody>
					<?php foreach ( $rows as $label => $value ) : ?>
						<tr>
							<td style="width:30%"><?php echo esc_html( $label ); ?></td>
							<td><?php echo esc_html( $value ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="description"><?php esc_html_e( 'Copy this when asking anyone for help.', 'mailhouse' ); ?></p>
			<textarea class="large-text code" rows="6" readonly onclick="this.select()"><?php echo esc_textarea( $report ); ?></textarea>
		</div>
		<?php
	}

	/**
	 * Import sources that exist on this site.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	protected function available_sources() {
		global $wpdb;

		$sources = array();

		$noptin = $wpdb->prefix . 'noptin_subscribers';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $noptin ) ) === $noptin ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$noptin}" );

			$sources['noptin'] = array(
				'label'       => 'Noptin',
				'description' => sprintf(
					/* translators: %s: number of subscribers. */
					__( '%s subscribers. Unconfirmed addresses come across as pending and will be asked to confirm before anything is sent to them.', 'mailhouse' ),
					number_format_i18n( $count )
				),
			);
		}

		$other = $wpdb->prefix . 'pon_subscribers';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $other ) ) === $other ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$other}" );

			$sources['pon'] = array(
				'label'       => __( 'PathOfNeo Core', 'mailhouse' ),
				'description' => sprintf(
					/* translators: %s: number of subscribers. */
					__( '%s subscribers from the theme plugin, with their confirmed and unsubscribed states kept.', 'mailhouse' ),
					number_format_i18n( $count )
				),
			);
		}

		if ( get_option( 'wp_mail_smtp' ) ) {
			$sources['wp_mail_smtp'] = array(
				'label'       => 'WP Mail SMTP',
				'description' => __( 'Its delivery settings: sender, transport and credentials, where they are stored in the database rather than in wp-config.php.', 'mailhouse' ),
			);
		}

		return $sources;
	}

	/**
	 * Run a migration.
	 *
	 * @return void
	 */
	public function migrate() {
		check_admin_referer( 'mailhouse_migrate' );

		if ( ! mailhouse_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mailhouse' ), '', array( 'response' => 403 ) );
		}

		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';

		switch ( $source ) {
			case 'noptin':
				$count = $this->import_noptin();
				$this->redirect( 'imported', $count );
				break;

			case 'pon':
				$count = $this->import_pon();
				$this->redirect( 'imported', $count );
				break;

			case 'wp_mail_smtp':
				$this->import_wp_mail_smtp();
				$this->redirect( 'settings_imported' );
				break;
		}

		$this->redirect( 'unknown_source' );
	}

	/**
	 * Copy Noptin's subscribers across.
	 *
	 * Column names have moved around between Noptin versions, so whatever is
	 * actually in the table decides what gets read.
	 *
	 * @return int Rows imported.
	 */
	protected function import_noptin() {
		global $wpdb;

		$table = $wpdb->prefix . 'noptin_subscribers';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

		if ( ! $columns || ! in_array( 'email', $columns, true ) ) {
			return 0;
		}

		$has = static function ( $column ) use ( $columns ) {
			return in_array( $column, $columns, true );
		};

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table}" );

		$tag      = Mailhouse_Tags::create( 'Noptin import' );
		$imported = 0;

		foreach ( $rows as $row ) {
			// In Noptin, `active` is inverted: 0 means the subscriber is on
			// the list, 1 means they are not.
			$inactive  = $has( 'active' ) && (int) $row->active === 1;
			$confirmed = $has( 'confirmed' ) ? (bool) $row->confirmed : true;

			if ( $inactive ) {
				$status = 'unsubscribed';
			} else {
				$status = $confirmed ? 'subscribed' : 'pending';
			}

			$result = Mailhouse_Subscribers::add(
				array(
					'email'       => $row->email,
					'first_name'  => $has( 'first_name' ) ? (string) $row->first_name : '',
					'last_name'   => $has( 'second_name' ) ? (string) $row->second_name : ( $has( 'last_name' ) ? (string) $row->last_name : '' ),
					'status'      => $status,
					'source'      => 'noptin',
					'tags'        => $tag ? array( $tag ) : array(),
					// Nobody expects an import to email 4,000 people.
					'send_emails' => false,
				)
			);

			if ( ! is_wp_error( $result ) ) {
				$imported++;

				if ( $has( 'date_created' ) && $row->date_created ) {
					Mailhouse_Subscribers::update( $result['id'], array( 'created_at' => $row->date_created ) );
				}
			}
		}

		return $imported;
	}

	/**
	 * Copy the subscribers held by PathOfNeo Core.
	 *
	 * @return int Rows imported.
	 */
	protected function import_pon() {
		global $wpdb;

		$table = $wpdb->prefix . 'pon_subscribers';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table}" );

		$imported = 0;

		foreach ( $rows as $row ) {
			$status = in_array( $row->status, array( 'subscribed', 'pending', 'unsubscribed' ), true ) ? $row->status : 'pending';

			$result = Mailhouse_Subscribers::add(
				array(
					'email'       => $row->email,
					'status'      => $status,
					'source'      => isset( $row->source ) ? (string) $row->source : 'import',
					'send_emails' => false,
				)
			);

			if ( ! is_wp_error( $result ) ) {
				$imported++;

				$updates = array();

				if ( ! empty( $row->created_at ) ) {
					$updates['created_at'] = $row->created_at;
				}

				if ( ! empty( $row->confirmed_at ) ) {
					$updates['confirmed_at'] = $row->confirmed_at;
				}

				// The old unsubscribe links keep working, because the token
				// they carry becomes this subscriber's management key -- but
				// only a token as strong as Mailhouse's own (32 hex characters).
				// A shorter one gets a fresh key rather than carrying a
				// guessable one forward.
				if ( ! empty( $row->unsubscribe_token ) && preg_match( '/^[a-f0-9]{32,}$/i', (string) $row->unsubscribe_token ) ) {
					$updates['manage_key'] = substr( (string) $row->unsubscribe_token, 0, 32 );
				}

				if ( $updates ) {
					Mailhouse_Subscribers::update( $result['id'], $updates );
				}
			}
		}

		return $imported;
	}

	/**
	 * Copy WP Mail SMTP's delivery settings across.
	 *
	 * @return void
	 */
	protected function import_wp_mail_smtp() {
		$options = get_option( 'wp_mail_smtp' );

		if ( ! is_array( $options ) ) {
			return;
		}

		$mail   = isset( $options['mail'] ) ? (array) $options['mail'] : array();
		$smtp   = isset( $options['smtp'] ) ? (array) $options['smtp'] : array();
		$values = array();

		if ( ! empty( $mail['from_email'] ) ) {
			$values['from_email'] = mailhouse_clean_email( $mail['from_email'] );
		}

		if ( ! empty( $mail['from_name'] ) ) {
			$values['from_name'] = sanitize_text_field( $mail['from_name'] );
		}

		$values['force_from_email'] = ! empty( $mail['from_email_force'] );
		$values['force_from_name']  = ! empty( $mail['from_name_force'] );
		$values['return_path']      = ! empty( $mail['return_path'] );

		// Their names for the same services.
		$mailers = array(
			'smtp'       => 'smtp',
			'mail'       => 'php',
			'sendgrid'   => 'sendgrid',
			'mailgun'    => 'mailgun',
			'sendinblue' => 'brevo',
			'postmark'   => 'postmark',
		);

		$mailer = isset( $mail['mailer'] ) ? (string) $mail['mailer'] : '';

		if ( isset( $mailers[ $mailer ] ) ) {
			$values['mailer'] = $mailers[ $mailer ];
		}

		if ( $smtp ) {
			$map = array(
				'host'       => 'smtp_host',
				'port'       => 'smtp_port',
				'encryption' => 'smtp_encryption',
				'user'       => 'smtp_user',
				'pass'       => 'smtp_pass',
			);

			foreach ( $map as $from => $to ) {
				if ( isset( $smtp[ $from ] ) && '' !== $smtp[ $from ] ) {
					$values[ $to ] = $smtp[ $from ];
				}
			}

			$values['smtp_auth']    = ! empty( $smtp['auth'] );
			$values['smtp_autotls'] = ! empty( $smtp['autotls'] );
		}

		foreach ( array( 'sendgrid' => 'sendgrid_key', 'sendinblue' => 'brevo_key' ) as $section => $key ) {
			if ( ! empty( $options[ $section ]['api_key'] ) ) {
				$values[ $key ] = $options[ $section ]['api_key'];
			}
		}

		if ( ! empty( $options['mailgun'] ) ) {
			$mailgun = (array) $options['mailgun'];

			if ( ! empty( $mailgun['api_key'] ) ) {
				$values['mailgun_key'] = $mailgun['api_key'];
			}

			if ( ! empty( $mailgun['domain'] ) ) {
				$values['mailgun_domain'] = $mailgun['domain'];
			}

			if ( ! empty( $mailgun['region'] ) ) {
				$values['mailgun_region'] = 'EU' === strtoupper( $mailgun['region'] ) ? 'eu' : 'us';
			}
		}

		if ( ! empty( $options['postmark'] ) ) {
			$postmark = (array) $options['postmark'];

			if ( ! empty( $postmark['server_api_token'] ) ) {
				$values['postmark_token'] = $postmark['server_api_token'];
			}

			if ( ! empty( $postmark['message_stream'] ) ) {
				$values['postmark_stream'] = $postmark['message_stream'];
			}
		}

		Mailhouse_Settings::set( $values );
	}

	/**
	 * Handle the CSV upload.
	 *
	 * @return void
	 */
	public function import_csv() {
		check_admin_referer( 'mailhouse_import_csv' );

		if ( ! mailhouse_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mailhouse' ), '', array( 'response' => 403 ) );
		}

		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( sanitize_text_field( wp_unslash( $_FILES['csv']['tmp_name'] ) ) ) ) {
			$this->redirect( 'no_file' );
		}

		$result = self::import_file(
			sanitize_text_field( wp_unslash( $_FILES['csv']['tmp_name'] ) ),
			isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'subscribed',
			isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : ''
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect( $result->get_error_code() );
		}

		$this->redirect( 'imported', $result['imported'], $result['skipped'] );
	}

	/**
	 * Read a CSV of subscribers into the list.
	 *
	 * Separate from the upload handling so it can be pointed at any file --
	 * a WP-CLI import, a scheduled sync, or a test.
	 *
	 * @param string $path   Path to the file.
	 * @param string $status Status for rows that do not carry one.
	 * @param string $tags   Extra tags for every row, comma separated.
	 * @return array{imported:int,skipped:int}|WP_Error
	 */
	public static function import_file( $path, $status = 'subscribed', $tags = '' ) {
		if ( ! in_array( $status, array( 'subscribed', 'pending' ), true ) ) {
			$status = 'subscribed';
		}

		$handle = is_readable( $path ) ? fopen( $path, 'r' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return new WP_Error( 'no_file', __( 'That file could not be read.', 'mailhouse' ) );
		}

		$header = fgetcsv( $handle );

		if ( ! $header ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			return new WP_Error( 'bad_csv', __( 'That file could not be read as a CSV.', 'mailhouse' ) );
		}

		// Strip a UTF-8 byte order mark, which Excel adds and which would
		// otherwise turn the first column name into something unmatchable.
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );

		$aliases = array(
			'email_address' => 'email',
			'e_mail'        => 'email',
			'firstname'     => 'first_name',
			'first'         => 'first_name',
			'name'          => 'first_name',
			'lastname'      => 'last_name',
			'last'          => 'last_name',
			'second_name'   => 'last_name',
			'surname'       => 'last_name',
			'tag'           => 'tags',
			'created'       => 'created_at',
			'date'          => 'created_at',
			'signed_up'     => 'created_at',
		);

		$map = array();

		foreach ( $header as $index => $name ) {
			$name = strtolower( trim( str_replace( array( ' ', '-' ), '_', (string) $name ) ) );

			$map[ isset( $aliases[ $name ] ) ? $aliases[ $name ] : $name ] = $index;
		}

		if ( ! isset( $map['email'] ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			return new WP_Error( 'no_email_column', __( 'The CSV needs a column called email.', 'mailhouse' ) );
		}

		$imported = 0;
		$skipped  = 0;

		$value = static function ( $row, $map, $key ) {
			return isset( $map[ $key ], $row[ $map[ $key ] ] ) ? trim( (string) $row[ $map[ $key ] ] ) : '';
		};

		while ( false !== ( $row = fgetcsv( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$email = $value( $row, $map, 'email' );

			if ( ! mailhouse_clean_email( $email ) ) {
				$skipped++;
				continue;
			}

			$row_status = $value( $row, $map, 'status' );
			$row_status = array_key_exists( $row_status, Mailhouse_Subscribers::statuses() ) ? $row_status : $status;

			$row_tags = $value( $row, $map, 'tags' );
			$row_tags = $row_tags ? explode( ',', $row_tags ) : array();

			if ( $tags ) {
				$row_tags = array_merge( $row_tags, explode( ',', $tags ) );
			}

			$result = Mailhouse_Subscribers::add(
				array(
					'email'       => $email,
					'first_name'  => $value( $row, $map, 'first_name' ),
					'last_name'   => $value( $row, $map, 'last_name' ),
					'status'      => $row_status,
					'tags'        => $row_tags,
					'source'      => 'csv',
					// The file is the admin's own data, so names in it win,
					// but nobody who opted out is put back on the list.
					'overwrite_names' => true,
					// An import that emails several thousand people at once is
					// a disaster. Pending rows are asked to confirm from the
					// Subscribers screen, deliberately, one batch at a time.
					'send_emails' => false,
				)
			);

			if ( is_wp_error( $result ) ) {
				$skipped++;
				continue;
			}

			$imported++;

			$created = $value( $row, $map, 'created_at' );

			if ( $created && strtotime( $created ) ) {
				Mailhouse_Subscribers::update( $result['id'], array( 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( $created ) ) ) );
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Stream the subscriber list as a CSV download.
	 *
	 * @return void
	 */
	public function export_csv() {
		check_admin_referer( 'mailhouse_export_csv' );

		if ( ! mailhouse_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mailhouse' ), '', array( 'response' => 403 ) );
		}

		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'subscribed';

		$subscribers = Mailhouse_Subscribers::query(
			array(
				'status'   => $status,
				'per_page' => 0,
				'orderby'  => 'created_at',
				'order'    => 'ASC',
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=mailhouse-subscribers-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		fputcsv( $out, array( 'email', 'first_name', 'last_name', 'status', 'tags', 'source', 'created_at', 'confirmed_at', 'sent', 'opens', 'clicks' ) );

		foreach ( $subscribers as $subscriber ) {
			$tags = wp_list_pluck( Mailhouse_Tags::for_subscriber( (int) $subscriber->id ), 'name' );

			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_cell' ),
					array(
					$subscriber->email,
					$subscriber->first_name,
					$subscriber->last_name,
					$subscriber->status,
					implode( ', ', $tags ),
					$subscriber->source,
					$subscriber->created_at,
					$subscriber->confirmed_at,
					$subscriber->sent,
					$subscriber->opens,
					$subscriber->clicks,
					)
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * One CSV cell, made safe to open in a spreadsheet.
	 *
	 * Names and tags come from the public form. A value starting with `=`,
	 * `+`, `-` or `@` would be run as a formula by Excel or Sheets, so it is
	 * prefixed with an apostrophe, which spreadsheets treat as "this is text".
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;

		if ( '' !== $value && preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			$value = "'" . $value;
		}

		return $value;
	}

	/**
	 * Go back to Tools with a notice.
	 *
	 * @param string $notice   Notice key.
	 * @param int    $imported Rows imported.
	 * @param int    $skipped  Rows skipped.
	 * @return void
	 */
	protected function redirect( $notice, $imported = 0, $skipped = 0 ) {
		wp_safe_redirect(
			mailhouse_admin_url(
				'tools',
				array(
					'mailhouse_notice' => $notice,
					'imported'         => (int) $imported,
					'skipped'          => (int) $skipped,
				)
			)
		);
		exit;
	}

	/**
	 * Print the notice for whatever just happened.
	 *
	 * @return void
	 */
	protected function notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$notice   = isset( $_GET['mailhouse_notice'] ) ? sanitize_key( wp_unslash( $_GET['mailhouse_notice'] ) ) : '';
		$imported = isset( $_GET['imported'] ) ? absint( $_GET['imported'] ) : 0;
		$skipped  = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $notice ) {
			return;
		}

		$messages = array(
			'no_file'           => array( 'error', __( 'No file was uploaded.', 'mailhouse' ) ),
			'bad_csv'           => array( 'error', __( 'That file could not be read as a CSV.', 'mailhouse' ) ),
			'no_email_column'   => array( 'error', __( 'The CSV needs a column called email.', 'mailhouse' ) ),
			'unknown_source'    => array( 'error', __( 'That import source is not available.', 'mailhouse' ) ),
			'settings_imported' => array( 'success', __( 'Delivery settings imported. Check them, then send a test.', 'mailhouse' ) ),
		);

		if ( 'imported' === $notice ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: number imported, 2: number skipped. */
						__( 'Imported %1$s subscribers. %2$s rows were skipped.', 'mailhouse' ),
						number_format_i18n( $imported ),
						number_format_i18n( $skipped )
					)
				)
			);

			return;
		}

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}
}
