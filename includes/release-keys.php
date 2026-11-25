<?php
/**
 * The keys a release must be signed by before this version installs it.
 *
 * Base64 Ed25519 public keys, one a line. The secret halves are kept away from
 * GitHub, by whoever signs releases; see "Cutting a release" in
 * docs/DEVELOPER-GUIDE.md. A key reaches a site only inside a release signed by
 * a key the site already has, so a new key is added here one release before it
 * signs alone, and a retired one is taken out.
 *
 * With none here, this version installs no update at all, and the release
 * workflow refuses to build it.
 *
 * Add one with: php tools/release.php key <secret-file> --pin
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

return array(
	'84Y+K5Rg1agqt1rkijWUUPi6jgZHYNC6CBCGfYy4Zv0=', // r-7558199df8302f6a, added 2026-11-25
	'CWXU6zXbcKriPqddR/kAfWmvlgEwX+MdckGnFJD6UzA=', // r-e4d1353af12245fd, added 2026-11-25
	// Keys go above this line.
);
