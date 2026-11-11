<?php
/**
 * One side of the claim race in test-foundations.php.
 *
 * Waits for the moment both workers were told to start, then claims rows in
 * small batches until none are left, and prints the ids it got. The parent
 * checks that the two lists never overlap.
 *
 * Usage: php tests/helpers/claim-worker.php <campaign id> <start, unix seconds>
 *
 * @package Mailhouse
 */

require dirname( __DIR__ ) . '/bootstrap.php';

$mailhouse_campaign = isset( $argv[1] ) ? (int) $argv[1] : 0;
$mailhouse_start    = isset( $argv[2] ) ? (float) $argv[2] : 0;

while ( microtime( true ) < $mailhouse_start ) {
	usleep( 500 );
}

$mailhouse_ids = array();

do {
	$mailhouse_rows = Mailhouse_Queue::claim( 7 );

	foreach ( $mailhouse_rows as $mailhouse_row ) {
		if ( (int) $mailhouse_row->campaign_id === $mailhouse_campaign ) {
			$mailhouse_ids[] = (int) $mailhouse_row->id;
		}
	}
} while ( $mailhouse_rows );

echo implode( ',', $mailhouse_ids );
