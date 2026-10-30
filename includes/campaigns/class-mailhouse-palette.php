<?php
/**
 * The site's own colours, read from the active theme.
 *
 * Asking somebody to type six hex codes that already exist in their theme is
 * busywork, and they will not match. A block theme declares its palette in
 * theme.json, so the email design can simply read it.
 *
 * Only colours the theme (or the site owner, through the site editor) actually
 * declared are used. WordPress ships a default palette of its own, and
 * presenting that as "your theme's colours" would be a lie -- so a classic
 * theme with no theme.json reports nothing, and the settings screen says as
 * much rather than quietly applying generic blue.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Derives email colours from the active theme.
 */
class Mailhouse_Palette {

	/**
	 * Slug fragments that identify each role, best match first.
	 *
	 * Theme authors name these things by convention rather than by rule, so
	 * the fragments are ordered by how strongly each implies the role.
	 *
	 * @var array<string,string[]>
	 */
	const ROLES = array(
		'accent'     => array( 'accent', 'primary', 'brand', 'link', 'highlight' ),
		'background' => array( 'background', 'base', 'canvas' ),
		'ink'        => array( 'foreground', 'contrast', 'text', 'body' ),
	);

	/**
	 * Whether the active theme declares colours at all.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_theme_has_theme_json' ) ) {
			return (bool) wp_theme_has_theme_json();
		}

		// Older WordPress: look for the file the same way core would.
		return is_readable( get_stylesheet_directory() . '/theme.json' )
			|| is_readable( get_template_directory() . '/theme.json' );
	}

	/**
	 * The email colours this theme implies.
	 *
	 * @return array<string,string> Setting key to hex colour; empty when the
	 *                              theme declares nothing usable.
	 */
	public static function detect() {
		if ( ! self::is_available() ) {
			return array();
		}

		$palette = self::palette();
		$styles  = function_exists( 'wp_get_global_styles' ) ? wp_get_global_styles() : array();

		// What the site actually renders is a better answer than what its
		// palette merely offers, so the styles are consulted first.
		$background = self::resolve( self::dig( $styles, array( 'color', 'background' ) ), $palette );
		$ink        = self::resolve( self::dig( $styles, array( 'color', 'text' ) ), $palette );
		$accent     = self::resolve( self::dig( $styles, array( 'elements', 'link', 'color', 'text' ) ), $palette );

		// The styles above are merged with WordPress's own theme.json, which
		// declares nothing for page background, text or links -- so whatever
		// is there came from the theme. It *does* declare a literal button
		// colour of its own (#32373c), so for buttons only a reference into
		// the theme's palette proves anything; a literal would just be core's
		// grey wearing the theme's name.
		$button = self::resolve( self::dig( $styles, array( 'elements', 'button', 'color', 'background' ) ), $palette, true );

		// Anything the styles did not settle falls back to the palette, by the
		// name the theme gave each colour.
		$background = $background ? $background : self::pick( $palette, self::ROLES['background'] );
		$ink        = $ink ? $ink : self::pick( $palette, self::ROLES['ink'] );
		$accent     = $accent ? $accent : self::pick( $palette, self::ROLES['accent'] );

		if ( ! $accent && ! $background ) {
			return array();
		}

		$background = $background ? $background : '#f3f4f6';
		$accent     = $accent ? $accent : '#2b6cb0';

		// The card is lifted a little off the page behind it, so it still
		// reads as a card on a theme whose background is already dark.
		$card = mailhouse_shade_colour( $background, 0.07 );
		$ink  = $ink ? $ink : mailhouse_contrast_colour( $card );

		$colours = array(
			'template_accent'    => $accent,
			'template_button'    => $button ? $button : $accent,
			'template_bg'        => $background,
			'template_card_bg'   => $card,
			'template_header_bg' => $card,
			'template_ink'       => $ink,
		);

		/**
		 * Filter the colours read from the theme before they are offered.
		 *
		 * @param array $colours Setting key to hex colour.
		 */
		return apply_filters( 'mailhouse_theme_colours', $colours );
	}

	/**
	 * The theme's palette as slug to hex.
	 *
	 * Core's own default palette is left out deliberately: it is not the
	 * theme's, and it is the same generic set on every site.
	 *
	 * @return array<string,string>
	 */
	protected static function palette() {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}

		$palette = wp_get_global_settings( array( 'color', 'palette' ) );
		$tiers   = array();

		if ( ! is_array( $palette ) ) {
			return array();
		}

		// Depending on the WordPress version this is either already a flat
		// list or split by origin.
		if ( isset( $palette['theme'] ) || isset( $palette['custom'] ) || isset( $palette['default'] ) ) {
			foreach ( array( 'theme', 'custom' ) as $origin ) {
				if ( ! empty( $palette[ $origin ] ) && is_array( $palette[ $origin ] ) ) {
					$tiers = array_merge( $tiers, $palette[ $origin ] );
				}
			}
		} else {
			$tiers = $palette;
		}

		$out = array();

		foreach ( $tiers as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['slug'] ) || empty( $entry['color'] ) ) {
				continue;
			}

			$colour = sanitize_hex_color( (string) $entry['color'] );

			if ( $colour ) {
				$out[ strtolower( (string) $entry['slug'] ) ] = $colour;
			}
		}

		return $out;
	}

	/**
	 * Read a nested value out of an array without tripping over gaps.
	 *
	 * @param array    $data Array to read.
	 * @param string[] $path Keys, outermost first.
	 * @return string
	 */
	protected static function dig( $data, $path ) {
		foreach ( $path as $key ) {
			if ( ! is_array( $data ) || ! isset( $data[ $key ] ) ) {
				return '';
			}

			$data = $data[ $key ];
		}

		return is_string( $data ) ? $data : '';
	}

	/**
	 * Turn a theme.json colour value into a hex colour.
	 *
	 * Values come through in three shapes: a literal hex, the internal
	 * `var:preset|color|slug` form, and the CSS custom property that form
	 * compiles to. All three end up pointing at a palette slug.
	 *
	 * @param string $value        Raw value.
	 * @param array  $palette      Slug to hex.
	 * @param bool   $presets_only Accept only references into the theme's
	 *                             palette, not literal colours.
	 * @return string Hex colour, or an empty string.
	 */
	protected static function resolve( $value, $palette, $presets_only = false ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$hex = sanitize_hex_color( $value );

		if ( $hex ) {
			return $presets_only ? '' : $hex;
		}

		if ( preg_match( '/var:preset\|color\|([a-z0-9_-]+)/i', $value, $matches )
			|| preg_match( '/--wp--preset--color--([a-z0-9_-]+)/i', $value, $matches ) ) {

			$slug = strtolower( $matches[1] );

			return isset( $palette[ $slug ] ) ? $palette[ $slug ] : '';
		}

		// Named CSS colours and rgb() are possible but rare in theme.json, and
		// guessing at them would be worse than leaving the role unfilled.
		return '';
	}

	/**
	 * The first palette entry whose slug or name suggests a role.
	 *
	 * @param array    $palette    Slug to hex.
	 * @param string[] $candidates Slug fragments, best first.
	 * @return string
	 */
	protected static function pick( $palette, $candidates ) {
		foreach ( $candidates as $candidate ) {
			if ( isset( $palette[ $candidate ] ) ) {
				return $palette[ $candidate ];
			}
		}

		foreach ( $candidates as $candidate ) {
			foreach ( $palette as $slug => $colour ) {
				if ( false !== strpos( $slug, $candidate ) ) {
					return $colour;
				}
			}
		}

		return '';
	}

	/**
	 * Store the detected colours as the site's email colours.
	 *
	 * @return array<string,string> What was applied; empty when nothing was found.
	 */
	public static function apply() {
		$colours = self::detect();

		if ( $colours ) {
			Mailhouse_Settings::set( $colours );
		}

		return $colours;
	}
}
