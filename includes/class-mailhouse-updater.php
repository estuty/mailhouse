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
	 * Register the hooks.
	 */
	public function __construct() {
		add_filter( 'update_plugins_github.com', array( $this, 'offer' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_mailhouse_check_updates', array( $this, 'handle_check' ) );
		add_action( 'upgrader_process_complete', array( $this, 'forget' ), 10, 2 );
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

		if ( ! $release ) {
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
		$cached = get_site_transient( self::TRANSIENT );

		if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
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

		set_site_transient(
			self::TRANSIENT,
			null === $release ? 'none' : $release,
			null === $release ? self::RETRY : self::TTL
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
			'version' => $version,
			'url'     => isset( $body['html_url'] ) ? (string) $body['html_url'] : 'https://github.com/' . self::repo() . '/releases',
			'package' => $package,
			'notes'   => isset( $body['body'] ) ? (string) $body['body'] : '',
			'date'    => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
		);
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
