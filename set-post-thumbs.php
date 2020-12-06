<?php
/**
 *
 */

use JMichaelWard\SetPostThumbs\ThumbnailCommand;

if ( ! defined( 'WP_CLI' ) || ! function_exists( 'add_action' ) ) {
	return;
}

add_action( 'cli_init', function() {
	require_once __DIR__ . '/src/ThumbnailCommand.php';

	try {
		WP_CLI::add_command( 'thumbnail', ThumbnailCommand::class );
	} catch ( Throwable $e ) {
		//
	}
} );
