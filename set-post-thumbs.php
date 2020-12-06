<?php
/**
 * File for bootstrapping the thumbnail command.
 */

namespace JMichaelWard\SetPostThumbs;

use WP_CLI;
use Throwable;

/**
 * Initializes the thumbnail command.
 */
function init_thumbnail_command() {
	require_once __DIR__ . '/src/ThumbnailCommand.php';

	add_action( 'cli_init', function() {
		try {
			WP_CLI::add_command( 'thumbnail', ThumbnailCommand::class );
		} catch ( Throwable $e ) {
			//
		}
	} );
}
