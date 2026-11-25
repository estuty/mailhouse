<?php
/**
 * Updates from the GitHub releases this plugin already publishes.
 *
 * A plugin installed from a zip has no idea where it came from. WordPress asks
 * wordpress.org about every plugin whose folder name it recognises, so without
 * an `Update URI` header a plugin called `mailhouse` would be matched against
 * whatever wordpress.org happens to host under that slug -- and offered as an
 * update to it. Declaring the header does two things: it stops WordPress
 * asking wordpress.org, and it routes the question here instead.
 *
 * The one hook is `update_plugins_{hostname}`, added in WordPress 5.8. It is
 * keyed on the host in the header, which means every plugin on the site that
 * updates from github.com arrives at this same filter -- so the first thing
 * it does is check the file it was asked about is ours.
 *
 * Since 10.6 nothing is installed unless it is signed (`Mailhouse_Release`).
 * A release with no `.sig` beside its zip is not offered. When WordPress comes
 * to install one, the signature is read before anything is downloaded, the zip
 * is downloaded and unpacked here and every file compared with what was signed
 * -- all before WordPress deactivates the plugin or touches its folder -- and
 * the folder WordPress is about to put in place is compared once more. Any
 * difference and WordPress is handed an error instead of a package.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reports the latest release so WordPress can offer it like any other update.
 */
class Mailhouse_Updater {

	/**
	 * Where the latest release is remembered between checks.
	 *
	 * A site transient, not a normal one: plugins live at the network level,
	 * and so does the `update_plugins` transient this feeds.
	 *
	 * @var string
	 */
	const TRANSIENT = 'mailhouse_latest_release';

	/**
	 * How long a successful lookup is trusted.
	 *
	 * WordPress checks for plugin updates twice a day, so this mostly decides
	 * how stale a manual visit to the Plugins screen can be.
	 *
	 * @var int
	 */
	const TTL = 43200; // 12 hours.

	/**
	 * How long to wait after a failed lookup before trying again.
	 *
	 * Unauthenticated GitHub requests are limited to 60 an hour per address.
	 * Backing off keeps a broken network or a renamed repository from
	 * spending that allowance.
	 *
	 * @var int
	 */
	const RETRY = 900; // 15 minutes.

	/**
	 * The slug WordPress knows this plugin by -- its folder name.
	 *
	 * @var string
	 */
	const SLUG = 'mailhouse';

	/**
	 * Where the last update refused is remembered, for the status panel.
	 *
	 * @var string
	 */
	const REFUSED = 'mailhouse_update_refused';

	/**
	 * The release checked before it was downloaded, until it is installed.
	 *
	 * @var array{package:string,version:string,files:array<string,string>}|null
	 */
	protected $verified = null;

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_filter( 'update_plugins_github.com', array( $this, 'offer' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_mailhouse_check_updates', array( $this, 'handle_check' ) );
		add_action( 'upgrader_process_complete', array( $this, 'forget' ), 10, 2 );

		// Every way WordPress updates a plugin -- the Plugins screen, the
		// Updates screen, a bulk update, an automatic update, WP-CLI -- passes
		// through both of these.
		add_filter( 'upgrader_pre_download', array( $this, 'check_download' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( $this, 'check_source' ), 999, 4 );
	}

	/**
	 * The keys a release must be signed by, as key id => base64 public key.
	 *
	 * The plugin's own pinned keys. Filterable for the same reason the
	 * repository is: a fork signs its releases with its own key.
	 *
	 * @return array<string,string>
	 */
	public static function keys() {
		/**
		 * Filters the keys a release must be signed by.
		 *
		 * @param array<string,string> $keys Key id => base64 Ed25519 public key.
		 */
		$keys = apply_filters( 'mailhouse_release_keys', Mailhouse_Release::pinned() );
		$out  = array();

		foreach ( is_array( $keys ) ? $keys : array() as $public ) {
			$id = Mailhouse_Release::key_id( $public );

			if ( '' !== $id ) {
				$out[ $id ] = $public;
			}
		}

		return $out;
	}

	/**
	 * The repository releases are read from.
	 *
	 * Overridable so a fork can point at its own without editing the plugin,
	 * and so the tests can aim it at something that does not exist.
	 *
	 * @return string `owner/name`.
	 */
	public static function repo() {
		$repo = defined( 'MAILHOUSE_UPDATE_REPO' ) ? MAILHOUSE_UPDATE_REPO : 'estuty/mailhouse';

		/**
		 * Filters the GitHub repository updates come from.
		 *
		 * @param string $repo Repository as `owner/name`.
		 */
		return (string) apply_filters( 'mailhouse_update_repo', $repo );
	}

	/**
	 * Tell WordPress about the latest release.
	 *
	 * The version comparison is deliberately left to WordPress: it files the
	 * answer under `response` or `no_update` itself, and a plugin that
	 * reported nothing when it was already current would show no "you are up
	 * to date" row and no View details link.
	 *
	 * @param array|false $update      What another filter already decided.
	 * @param array       $plugin_data Headers from the installed plugin.
	 * @param string      $plugin_file Plugin basename being asked about.
	 * @return array|false
	 */
	public function offer( $update, $plugin_data, $plugin_file ) {
		// Every github.com-hosted plugin on the site reaches this filter.
		// Answering for somebody else's would offer Mailhouse as an update to
		// it, and WordPress would install it straight over the top.
		if ( MAILHOUSE_BASENAME !== $plugin_file ) {
			return $update;
		}

		$release = self::release();

		// Offering a release that will not install would only end in an
		// error on the Plugins screen.
		if ( ! $release || empty( $release['signature'] ) ) {
			return $update;
		}

		return array(
			'id'           => 'github.com/' . self::repo(),
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => isset( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '',
			'requires_php' => isset( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '',
			'icons'        => array(),
			'banners'      => array(),
			'banners_rtl'  => array(),
		);
	}

	/**
	 * Fill in the "View details" panel.
	 *
	 * Without this the link WordPress puts in the update row lands on a
	 * wordpress.org lookup for a plugin that is not there, and the reader gets
	 * an error instead of the release notes.
	 *
	 * @param false|object|array $result Response from another filter.
	 * @param string             $action API action being performed.
	 * @param object             $args   Arguments for it.
	 * @return false|object|array
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::release();

		if ( ! $release ) {
			return $result;
		}

		$headers = array(
			'Name'        => 'Mailhouse',
			'RequiresWP'  => '',
			'RequiresPHP' => '',
			'Description' => '',
		);

		if ( function_exists( 'get_plugin_data' ) ) {
			$headers = array_merge( $headers, get_plugin_data( MAILHOUSE_FILE, false, false ) );
		}

		return (object) array(
			'name'          => $headers['Name'],
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/' . esc_attr( self::repo() ) . '">' . esc_html( $headers['Name'] ) . '</a>',
			'homepage'      => 'https://github.com/' . self::repo(),
			'download_link' => $release['package'],
			'requires'      => $headers['RequiresWP'],
			'requires_php'  => $headers['RequiresPHP'],
			'last_updated'  => $release['date'],
			'sections'      => array(
				'description' => wpautop( esc_html( $headers['Description'] ) ),
				'changelog'   => self::notes_html( $release['notes'] ),
			),
		);
	}

	/**
	 * Add a "Check for updates" link to the plugin's row.
	 *
	 * WordPress only asks twice a day, and somebody who has just been told a
	 * release exists should not have to wait for the schedule.
	 *
	 * @param string[] $meta        Existing links.
	 * @param string   $plugin_file Plugin basename for the row.
	 * @return string[]
	 */
	public function row_meta( $meta, $plugin_file ) {
		if ( MAILHOUSE_BASENAME !== $plugin_file || ! current_user_can( 'update_plugins' ) ) {
			return $meta;
		}

		$meta[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=mailhouse_check_updates' ),
					'mailhouse_check_updates'
				)
			),
			esc_html__( 'Check for updates', 'mailhouse' )
		);

		return $meta;
	}

	/**
	 * Throw away what we remember and ask again.
	 *
	 * @return void
	 */
	public function handle_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to check for plugin updates.', 'mailhouse' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'mailhouse_check_updates' );

		$this->forget();

		// Dropping the plugin update transient is what makes the next page
		// load actually re-run the check rather than read a cached answer.
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		wp_safe_redirect( self_admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Forget the cached release.
	 *
	 * Also runs after any plugin update finishes: once the new version is in
	 * place the old answer describes an update that has already happened.
	 *
	 * @return void
	 */
	public function forget() {
		delete_site_transient( self::TRANSIENT );
	}

	/**
	 * Before WordPress downloads an update of this plugin: read its signature,
	 * then download and unpack the zip here and compare every file.
	 *
	 * All of it happens before WordPress deactivates the plugin or touches its
	 * folder, so a refusal leaves the site exactly as it was. The zip handed
	 * back is the one checked, and WordPress unpacks it with the same function
	 * that unpacked it here.
	 *
	 * @param false|string|WP_Error $reply      What another filter decided.
	 * @param string                $package    The package's address.
	 * @param WP_Upgrader|null      $upgrader   The upgrader.
	 * @param array                 $hook_extra What is being upgraded.
	 * @return false|string|WP_Error
	 */
	public function check_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( ! self::ours( $hook_extra ) || is_wp_error( $reply ) ) {
			return $reply;
		}

		$this->verified = null;
		$version        = self::expected_version();

		if ( '' === $version ) {
			return self::refuse( '', __( 'there is no update of it on offer', 'mailhouse' ) );
		}

		if ( ! version_compare( $version, MAILHOUSE_VERSION, '>' ) ) {
			return self::refuse( $version, __( 'it is not newer than the version installed', 'mailhouse' ) );
		}

		if ( ! is_string( $package ) || ! preg_match( '#^https://#i', $package ) ) {
			return self::refuse( $version, __( 'it does not come from an address its signature can be fetched from', 'mailhouse' ) );
		}

		$response = wp_safe_remote_get(
			$package . '.sig',
			array(
				'timeout'             => 30,
				'limit_response_size' => Mailhouse_Release::MAX_BYTES + 1,
				'user-agent'          => 'Mailhouse/' . MAILHOUSE_VERSION . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return self::refuse( $version, __( 'its release has no signature', 'mailhouse' ) );
		}

		$why    = '';
		$signed = Mailhouse_Release::read( (string) wp_remote_retrieve_body( $response ), self::keys(), $why );

		if ( null === $signed ) {
			return self::refuse( $version, $why );
		}

		if ( $signed['version'] !== $version ) {
			/* translators: %s: version the signature is for. */
			return self::refuse( $version, sprintf( __( 'its signature is for version %s', 'mailhouse' ), $signed['version'] ) );
		}

		$downloaded = false;

		if ( is_string( $reply ) && '' !== $reply ) {
			$file = $reply;
		} else {
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$file = download_url( $package, 300 );

			if ( is_wp_error( $file ) ) {
				return $file;
			}

			$downloaded = true;
		}

		$found = self::unpacked( $file, $why );
		$diff  = null === $found ? array( $why ) : Mailhouse_Release::compare( $signed['files'], $found );

		if ( array() !== $diff ) {
			if ( $downloaded ) {
				wp_delete_file( $file );
			}

			/* translators: %s: the first few differences. */
			return self::refuse( $version, sprintf( __( 'what it holds is not what was signed (%s)', 'mailhouse' ), implode( '; ', array_slice( $diff, 0, 3 ) ) ) );
		}

		$this->verified = array(
			'package' => $package,
			'version' => $version,
			'files'   => $signed['files'],
		);

		delete_site_option( self::REFUSED );

		return $file;
	}

	/**
	 * Just before WordPress puts the new folder in place: compare it once
	 * more with what was signed.
	 *
	 * The check before the download is the one that matters; this one is for
	 * whatever might come between, such as another plugin handing WordPress a
	 * package of its own.
	 *
	 * @param string|WP_Error  $source        The unpacked folder to install.
	 * @param string           $remote_source Where the package was unpacked.
	 * @param WP_Upgrader|null $upgrader      The upgrader.
	 * @param array            $hook_extra    What is being upgraded.
	 * @return string|WP_Error
	 */
	public function check_source( $source, $remote_source = '', $upgrader = null, $hook_extra = array() ) {
		if ( is_wp_error( $source ) || ! self::ours( $hook_extra ) ) {
			return $source;
		}

		if ( null === $this->verified ) {
			return self::refuse( self::expected_version(), __( 'it was not checked before it was unpacked', 'mailhouse' ) );
		}

		$version = $this->verified['version'];

		if ( self::SLUG !== basename( untrailingslashit( (string) $source ) ) ) {
			return self::refuse( $version, __( 'the package does not hold a single mailhouse folder', 'mailhouse' ) );
		}

		$diff = Mailhouse_Release::compare( $this->verified['files'], Mailhouse_Release::tree( self::local( (string) $source ) ) );

		if ( array() !== $diff ) {
			/* translators: %s: the first few differences. */
			return self::refuse( $version, sprintf( __( 'what it holds is not what was signed (%s)', 'mailhouse' ), implode( '; ', array_slice( $diff, 0, 3 ) ) ) );
		}

		return $source;
	}

	/**
	 * Whether an upgrade is of this plugin.
	 *
	 * An administrator uploading a zip by hand is not an update, and is not
	 * asked for a signature: that is a choice made by someone who could as
	 * well edit the files.
	 *
	 * @param mixed $hook_extra What is being upgraded.
	 * @return bool
	 */
	protected static function ours( $hook_extra ) {
		return is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && MAILHOUSE_BASENAME === $hook_extra['plugin'];
	}

	/**
	 * The version WordPress is about to install.
	 *
	 * @return string
	 */
	protected static function expected_version() {
		$state = get_site_transient( 'update_plugins' );

		if ( is_object( $state ) && isset( $state->response[ MAILHOUSE_BASENAME ] ) ) {
			$row = (array) $state->response[ MAILHOUSE_BASENAME ];

			if ( ! empty( $row['new_version'] ) ) {
				return (string) $row['new_version'];
			}
		}

		$release = get_site_transient( self::TRANSIENT );

		return is_array( $release ) && ! empty( $release['version'] ) ? (string) $release['version'] : '';
	}

	/**
	 * Unpack a zip into a folder of its own and read every file, the way
	 * WordPress is about to.
	 *
	 * @param string $file The zip.
	 * @param string $why  Set to the reason, when it cannot be read.
	 * @return array<string,string>|null
	 */
	protected static function unpacked( $file, &$why ) {
		global $wp_filesystem;

		if ( ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! $wp_filesystem && ! WP_Filesystem() ) {
			$why = __( 'the package could not be opened', 'mailhouse' );
			return null;
		}

		$dir    = trailingslashit( $wp_filesystem->wp_content_dir() ) . 'upgrade/mailhouse-check-' . wp_generate_password( 12, false );
		$result = unzip_file( $file, $dir );
		$found  = null;

		if ( is_wp_error( $result ) ) {
			$why = __( 'the package could not be opened', 'mailhouse' );
		} else {
			$top = array_values( array_diff( (array) scandir( self::local( $dir ) ), array( '.', '..' ) ) );

			if ( array( self::SLUG ) !== $top || ! is_dir( self::local( $dir ) . '/' . self::SLUG ) ) {
				$why = __( 'the package does not hold a single mailhouse folder', 'mailhouse' );
			} else {
				$found = Mailhouse_Release::tree( self::local( $dir ) . '/' . self::SLUG );
				$why   = null === $found ? __( 'the package holds something that is not a plain file', 'mailhouse' ) : '';
			}
		}

		$wp_filesystem->delete( $dir, true );

		return $found;
	}

	/**
	 * A path as WordPress's filesystem names it, as PHP can read it.
	 *
	 * The same path when files are written directly, which is nearly always;
	 * under FTP the filesystem's root may be somewhere else.
	 *
	 * @param string $path Filesystem path.
	 * @return string
	 */
	protected static function local( $path ) {
		global $wp_filesystem;

		$path = untrailingslashit( str_replace( '\\', '/', $path ) );

		if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
			$theirs = trailingslashit( str_replace( '\\', '/', $wp_filesystem->wp_content_dir() ) );
			$ours   = trailingslashit( str_replace( '\\', '/', WP_CONTENT_DIR ) );

			if ( $theirs !== $ours && 0 === strpos( $path, $theirs ) ) {
				$path = $ours . substr( $path, strlen( $theirs ) );
			}
		}

		return $path;
	}

	/**
	 * Say no, and remember why for the status panel.
	 *
	 * @param string $version The version refused.
	 * @param string $why     Why.
	 * @return WP_Error
	 */
	protected static function refuse( $version, $why ) {
		update_site_option(
			self::REFUSED,
			array(
				'version' => (string) $version,
				'reason'  => (string) $why,
				'at'      => time(),
			)
		);

		return new WP_Error(
			'mailhouse_update_refused',
			'' === $version
				/* translators: %s: why. */
				? sprintf( __( 'The Mailhouse update was not installed: %s.', 'mailhouse' ), $why )
				/* translators: 1: version, 2: why. */
				: sprintf( __( 'Mailhouse %1$s was not installed: %2$s.', 'mailhouse' ), $version, $why )
		);
	}

	/**
	 * Where the last check got to, for the status panel.
	 *
	 * Reads what is already remembered and never asks GitHub: a status panel
	 * that made a network request to draw itself would be a slow screen and a
	 * wasted request. It also means a check that is failing says so, rather
	 * than the site quietly never offering an update again.
	 *
	 * @return string
	 */
	public static function status() {
		$cached  = get_site_transient( self::TRANSIENT );
		$refused = get_site_option( self::REFUSED );

		// An update refused stays said until a newer one installs.
		if ( is_array( $refused ) && ! empty( $refused['version'] ) && version_compare( MAILHOUSE_VERSION, (string) $refused['version'], '<' ) ) {
			/* translators: 1: version number, 2: why it was refused. */
			return sprintf( __( '%1$s was not installed: %2$s', 'mailhouse' ), $refused['version'], $refused['reason'] );
		}

		if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
			if ( version_compare( MAILHOUSE_VERSION, $cached['version'], '<' ) && empty( $cached['signature'] ) ) {
				/* translators: %s: version number. */
				return sprintf( __( '%s is published without a signature, so it is not offered', 'mailhouse' ), $cached['version'] );
			}

			return version_compare( MAILHOUSE_VERSION, $cached['version'], '<' )
				/* translators: %s: version number. */
				? sprintf( __( '%s is available', 'mailhouse' ), $cached['version'] )
				: __( 'up to date', 'mailhouse' );
		}

		if ( 'none' === $cached ) {
			/* translators: %s: repository the check could not reach. */
			return sprintf( __( 'last check failed -- could not reach %s', 'mailhouse' ), 'github.com/' . self::repo() );
		}

		return __( 'not checked yet', 'mailhouse' );
	}

	/**
	 * The latest published release, or null if we could not find out.
	 *
	 * @return array{version:string,url:string,package:string,notes:string,date:string}|null
	 */
	public static function release() {
		$cached = get_site_transient( self::TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		// A recent failure is remembered too, so a repository that has been
		// renamed is not re-checked on every page of the Plugins screen.
		if ( 'none' === $cached ) {
			return null;
		}

		$release = self::fetch();

		// A release published before its signature was attached is asked
		// about again soon, not in twelve hours.
		set_site_transient(
			self::TRANSIENT,
			null === $release ? 'none' : $release,
			null === $release || empty( $release['signature'] ) ? self::RETRY : self::TTL
		);

		return $release;
	}

	/**
	 * Ask GitHub for the latest release.
	 *
	 * `releases/latest` is the right endpoint rather than the list: it leaves
	 * out drafts and pre-releases by itself, so a release candidate is never
	 * pushed at a live site.
	 *
	 * @return array|null
	 */
	protected static function fetch() {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			array(
				'timeout'    => 15,
				'user-agent' => 'Mailhouse/' . MAILHOUSE_VERSION . '; ' . home_url(),
				'headers'    => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return null;
		}

		$version = ltrim( (string) $body['tag_name'], 'vV' );
		$package = self::package( isset( $body['assets'] ) ? $body['assets'] : array(), $version );

		// No attached zip means no update. GitHub's own source archive would
		// unpack to a folder named after the repository and the commit, and
		// WordPress installs a plugin into whatever folder the zip contains --
		// so it would land beside this one as a second copy rather than
		// replacing it. Offering nothing is the better failure.
		if ( ! $version || ! $package ) {
			return null;
		}

		return array(
			'version'   => $version,
			'url'       => isset( $body['html_url'] ) ? (string) $body['html_url'] : 'https://github.com/' . self::repo() . '/releases',
			'package'   => $package,
			'signature' => self::signature( isset( $body['assets'] ) ? $body['assets'] : array(), $package ),
			'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
			'date'      => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
		);
	}

	/**
	 * The signature attached beside the zip: the asset whose address is the
	 * zip's with `.sig` after it, which is where it is fetched from when the
	 * update is installed.
	 *
	 * @param array  $assets  Assets from the API response.
	 * @param string $package The zip's address.
	 * @return string Its address, or '' when there is none.
	 */
	protected static function signature( $assets, $package ) {
		if ( ! is_array( $assets ) || '' === $package ) {
			return '';
		}

		foreach ( $assets as $asset ) {
			if ( is_array( $asset ) && isset( $asset['browser_download_url'] ) && $package . '.sig' === $asset['browser_download_url'] ) {
				return (string) $asset['browser_download_url'];
			}
		}

		return '';
	}

	/**
	 * Pick the release asset to install.
	 *
	 * @param array  $assets  Assets from the API response.
	 * @param string $version Version being offered.
	 * @return string Download URL, or '' when the release has no zip on it.
	 */
	protected static function package( $assets, $version ) {
		if ( ! is_array( $assets ) ) {
			return '';
		}

		$fallback = '';

		foreach ( $assets as $asset ) {
			if ( empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
				continue;
			}

			if ( '.zip' !== strtolower( substr( $asset['name'], -4 ) ) ) {
				continue;
			}

			// The release this plugin publishes is named for its version, and
			// that is the one whose zip is known to contain a `mailhouse`
			// folder. Anything else attached is only a fallback.
			if ( self::SLUG . '-' . $version . '.zip' === $asset['name'] ) {
				return (string) $asset['browser_download_url'];
			}

			if ( ! $fallback ) {
				$fallback = (string) $asset['browser_download_url'];
			}
		}

		return $fallback;
	}

	/**
	 * Turn release notes into the HTML the details panel expects.
	 *
	 * Markdown, near enough: headings, lists, emphasis, code and links, which
	 * is all the release notes use. Everything is escaped first and only the
	 * tags generated here are put back, because the repository is filterable
	 * and release notes are therefore not necessarily ours.
	 *
	 * @param string $notes Release body.
	 * @return string
	 */
	protected static function notes_html( $notes ) {
		$notes = trim( (string) $notes );

		if ( '' === $notes ) {
			return '';
		}

		$text = esc_html( str_replace( array( "\r\n", "\r" ), "\n", $notes ) );

		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
			'<a href="$2" rel="nofollow ugc">$1</a>',
			$text
		);

		// Lists are gathered a line at a time rather than by one pattern over
		// the whole body: a single greedy match would swallow the headings
		// between two separate lists into one enormous item.
		$out  = array();
		$open = false;

		foreach ( explode( "\n", $text ) as $line ) {
			if ( preg_match( '/^\s*[-*]\s+(.+)$/', $line, $item ) ) {
				if ( ! $open ) {
					$out[] = '<ul>';
					$open  = true;
				}

				$out[] = '<li>' . trim( $item[1] ) . '</li>';
				continue;
			}

			if ( $open ) {
				$out[] = '</ul>';
				$open  = false;
			}

			if ( preg_match( '/^#{1,6}\s*(.+)$/', $line, $heading ) ) {
				$out[] = '<h4>' . trim( $heading[1] ) . '</h4>';
				continue;
			}

			$out[] = $line;
		}

		if ( $open ) {
			$out[] = '</ul>';
		}

		// Tables and anything else unrecognised are left as the lines they
		// were, which reads plainly but reads.
		return wpautop( implode( "\n", $out ) );
	}
}
