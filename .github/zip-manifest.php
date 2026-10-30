#!/usr/bin/env php
<?php
/**
 * Print a zip's contents as `name crc size`, one entry per line, sorted.
 *
 * Used by the release workflow to check that a release's published asset is
 * still what its tag builds. Whole-file hashes are no good for that: a zip
 * built by `git archive` carries the commit date as every entry's mtime and
 * the commit sha as the archive comment, so two archives of identical trees
 * differ in bytes. CRCs and sizes are content and nothing else.
 *
 * The comment is printed to stderr rather than stdout, for the same reason --
 * it says which commit the archive came from, which is worth seeing in the log
 * but is not part of what is being compared.
 *
 * Usage: php .github/zip-manifest.php <file.zip>
 *
 * @package Mailhouse
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$path = isset( $argv[1] ) ? $argv[1] : '';

if ( '' === $path ) {
	fwrite( STDERR, "usage: zip-manifest.php <file.zip>\n" );
	exit( 2 );
}

$zip = new ZipArchive();

if ( true !== $zip->open( $path ) ) {
	fwrite( STDERR, "cannot open $path\n" );
	exit( 2 );
}

$comment = $zip->getArchiveComment();

if ( '' !== (string) $comment ) {
	fwrite( STDERR, 'built from ' . trim( $comment ) . "\n" );
}

$lines = array();

for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$stat = $zip->statIndex( $i );

	if ( false === $stat ) {
		fwrite( STDERR, "cannot stat entry $i\n" );
		exit( 2 );
	}

	$lines[] = sprintf( '%s %08x %d', $stat['name'], $stat['crc'], $stat['size'] );
}

$zip->close();

// Sorted, because entry order follows the git tree and is not what is being
// compared -- a file moved between directories should show up as a move.
sort( $lines, SORT_STRING );

echo implode( "\n", $lines ), "\n";
