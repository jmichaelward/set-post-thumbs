<?php
/**
 * This class can be used out of the box to register a CLI command to apply a featured image to a post type based on its
 * content, or extended to apply additional and/or different conditions for setting the featured image.
 */
namespace JMichaelWard\SetPostThumbs;

use WP_CLI;
use WP_CLI_Command;
use WP_Query;
use WP_Post;
use function WP_CLI\Utils\make_progress_bar;

/**
 * Adds featured images to posts that do not have them based on the first image located in the post content.
 *
 * @package JMichaelWard\SetPostThumbs
 */
class ThumbnailCommand extends WP_CLI_Command {
	/**
	 * Meta key saved to posts which have been processed and have no thumbnails.
	 */
	protected const META_KEY_POST_NO_THUMBNAIL = 'set-post-thumbs--no-thumbnail';

	/**
	 * Meta key saved to posts where multiple images were available to choose from.
	 */
	protected const META_KEY_MULTIPLE_IMAGES = 'set-post-thumbs--multiple-images';

	/**
	 * Default number of posts to query if no argument is passed.
	 */
	protected const DEFAULT_POSTS_PER_PAGE = 500;

	/**
	 * Attempt to set a post's featured image based on its content.
	 *
	 * [--all]
	 * : Set post thumbnails on all posts in the database.
	 *
	 * [--amount=<amount>]
	 * : Set the quantity of posts to process.
	 *
	 * [--post_type=<post_type>]
	 * : The post type to query.
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function set( array $args, array $assoc_args ) {
		$quantity  = $this->get_quantity( $assoc_args );
		$post_type = $this->get_post_type( $assoc_args );

		$query = $this->query_posts_with_metadata( $args, $post_type, $quantity );
		$total = $query->post_count;

		if ( 0 === $total ) {
			WP_CLI::success( __( "All post thumbnails have been processed.", 'set-post-thumbs' ) );
			return;
		}

		$progress = make_progress_bar( "Processing {$total} posts...", $total );

		foreach ( $query->get_posts() as $post_id ) {
			$post = get_post( $post_id, OBJECT );
			$id   = $this->maybe_set_featured_image( $post );

			if ( ! $id ) {
				update_post_meta( $post->ID, self::META_KEY_POST_NO_THUMBNAIL, true );
			}

			$progress->tick();
		}

		$progress->finish();
	}

	/**
	 * List processed posts with no thumbnails.
	 *
	 * <unset|multiple>
	 * : List processed post IDS which have no thumbnails (unset) or which had multiple images in the content (multiple).
	 *
	 * [--post_type=<post_type>]
	 * : The post type to query.
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command options.
	 */
	public function show( array $args, array $assoc_args ) {
		$query    = $this->query_posts_with_metadata( $args, $this->get_post_type( $assoc_args ), - 1, true );
		$ids      = $query->get_posts();
		$multiple = in_array( 'multiple', $args, true );

		if ( $multiple ) {
			if ( empty( $ids ) ) {
				WP_CLI::success(
					__(
						'No processed posts found containing multiple available options for featured images.',
						'set-post-thumbs'
					)
				);
			} else {
				WP_CLI::success( __( 'Located the following processed posts with multiple images:', 'set-post-thumbs' ) );
				WP_CLI::log( __( 'Post IDs: ', 'set-post-thumbs' ) . implode( ', ', $ids ) );
			}

			return;
		}

		if ( empty( $ids ) ) {
			WP_CLI::success(
				__(
					'All processed posts have thumbnails, but there may still be additional posts to process.',
					'set-post-thumbs'
				)
			);
			return;
		}

		WP_CLI::success( __( 'Located processed posts which contain no thumbnails:', 'set-post-thumbs' ) );
		WP_CLI::log( __( 'Post IDs: ', 'set-post-thumbs' ) . implode( ', ', $ids ) );
	}

	/**
	 * Deletes metadata saved to posts processed by this command.
	 *
	 * @param array $args Command arguments.
	 * @param array $assoc_args Command options.
	 */
	public function cleanup( array $args, array $assoc_args ) {
		$query = $this->query_posts_with_command_meta();
		$count = $query->post_count;

		if ( 0 === $count ) {
			WP_CLI::success( __( 'No posts found with set-post-thumbs meta. Exiting.', 'set-post-thumbs' ) );
			return;
		}

		foreach ( $query->get_posts() as $post_id ) {
			delete_post_meta( $post_id, self::META_KEY_MULTIPLE_IMAGES );
			delete_post_meta( $post_id, self::META_KEY_POST_NO_THUMBNAIL );
		}

		$post_phrase = $count === 1 ? 'post' : 'posts';

		WP_CLI::success( __( "Deleted metadata from {$count} {$post_phrase}.", 'set-post-thumbs' ) );
	}

	/**
	 * Build the query to locate posts without thumbnails.
	 *
	 * This method can be overridden for command authors who need to extend or alter this query.
	 *
	 * @param array        $args      Command arguments.
	 * @param string|array $post_type The post type to query.
	 * @param int          $quantity  Number of posts to query.
	 * @param bool         $processed Whether to query processed posts. Defaults to false.
	 *
	 * @return WP_Query
	 */
	protected function query_posts_with_metadata(
		array $args,
		$post_type,
		int $quantity = self::DEFAULT_POSTS_PER_PAGE,
		bool $processed = false
	): WP_Query {
		$multiple = in_array( 'multiple', $args, true );

		return new WP_Query(
			[
				'post_type'      => $post_type,
				'posts_per_page' => $quantity,
				'fields'         => 'ids',
				'meta_query'     => [
					'relation'              => 'AND',
					'post_thumbnail_clause' => [
						'key'     => '_thumbnail_id',
						'compare' => $multiple ? 'EXISTS' : 'NOT EXISTS',
					],
					'post_processed_clause' => [
						'key'     => $multiple ? self::META_KEY_MULTIPLE_IMAGES : self::META_KEY_POST_NO_THUMBNAIL,
						'compare' => $processed ? 'EXISTS' : 'NOT EXISTS',
					],
				],
			]
		);
	}

	/**
	 * Query posts which have metadata produced by this command.
	 *
	 * This method can be overridden for command authors who need to extend or alter this query.
	 *
	 * @return WP_Query
	 */
	protected function query_posts_with_command_meta() : WP_Query {
		return new WP_Query(
			[
				'fields' => 'ids',
				'posts_per_page' => -1,
				'meta_query' => [
					'relation' => 'OR',
					'post_processed_clause' => [
						'key' => self::META_KEY_POST_NO_THUMBNAIL,
						'compare' => 'EXISTS'
					],
					'post_duplicate_image_clause' => [
						'key' => self::META_KEY_MULTIPLE_IMAGES,
						'compare' => 'EXISTS',
					]
				]
			]
		);
	}

	/**
	 * Maybe set the featured image on the post.
	 *
	 * This method searches the post content for an existing image to set as the featured image. If multiple are found,
	 * we'll set the first instance and apply meta so users can review which posts had multiple.
	 *
	 * @param WP_Post $post
	 *
	 * @return int
	 */
	protected function maybe_set_featured_image( WP_Post $post ) : int {
		$attached_images = get_children( "post_parent={$post->ID}&amp;post_type=attachment&amp;post_mime_type=image&amp;numberposts=1" );

		if ( empty( $attached_images ) ) {
			return 0;
		}

		$attachment_ids = array_keys( $attached_images );

		if ( count( $attachment_ids ) > 1 ) {
			$attachment_ids_string = implode( ',', array_keys( $attached_images ) );

			update_post_meta( $post->ID, self::META_KEY_MULTIPLE_IMAGES, $attachment_ids_string );
		}

		return set_post_thumbnail( $post->ID, array_pop( $attachment_ids ) );
	}

	/**
	 * Get the post type to process.
	 *
	 * @param $assoc_args
	 *
	 * @return string
	 */
	private function get_post_type( $assoc_args ) : string {
		return $assoc_args['post_type'] ?? 'post';
	}

	/**
	 * Get the quantity of posts to query.
	 *
	 * @param array $assoc_args Command options.
	 *
	 * @return int
	 */
	private function get_quantity( array $assoc_args ) : int {
		switch ( $assoc_args ) {
			case ! empty( $assoc_args['all'] ) :
				return -1;
			case isset( $assoc_args['amount'] ) :
				return absint( $assoc_args['amount'] );
			default:
				return self::DEFAULT_POSTS_PER_PAGE;
		}
	}
}
