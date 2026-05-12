<?php
/**
 * Maps Google reviews to Directorist review comments.
 *
 * @package Directorist_Google_Importer
 */

namespace DLIG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Review_Mapper
 */
class Review_Mapper {

	/**
	 * Insert a single Google review as a Directorist review comment.
	 *
	 * Fixes:
	 *  - Bug fix: uses || instead of && in the empty check (audit §7.3)
	 *  - Uses wp_date() instead of date() for timezone compliance (audit §4.5)
	 *  - Recalculates average rating after insertion (audit §5.3)
	 *
	 * @param int   $post_id Directorist listing post ID.
	 * @param array $review  Normalised review array from Google_Places_Client.
	 * @return int|false New comment ID on success, false on skip/failure.
	 */
	public function insert( int $post_id, array $review ) {
		// FIXED: was && (AND) — should be || (OR) so that reviews with either
		// field empty are skipped (not just reviews with BOTH fields empty).
		if ( empty( $post_id ) || empty( $review['author_name'] ) || empty( $review['text'] ) ) {
			return false;
		}

		// Stable deduplication ID from author + publish timestamp
		$google_id = md5( $review['author_name'] . '|' . intval( $review['time'] ?? 0 ) );

		// Check for an existing comment with this Google review ID
		$existing = get_comments( [
			'post_id'    => $post_id,
			'meta_key'   => 'dgbi_google_review_id', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => $google_id,              // phpcs:ignore WordPress.DB.SlowDBQuery
			'count'      => true,
		] );

		if ( $existing ) {
			return false; // already imported
		}

		// Use wp_date() to respect the site's configured timezone (audit §4.5)
		$comment_date = current_time( 'mysql' );

		// Validate review timestamp before using
		if ( ! empty( $review['time'] ) && is_numeric( $review['time'] ) ) {
			$timestamp = intval( $review['time'] );
			
			// Sanity check: timestamp should be between 1970 and now + 1 day
			$now = time();
			if ( $timestamp > 0 && $timestamp <= $now + DAY_IN_SECONDS ) {
				$comment_date = wp_date( 'Y-m-d H:i:s', $timestamp );
			} else {
				error_log( 'DGBI: Invalid timestamp from Google review: ' . $timestamp );
			}
		}

		/**
		 * Filter the approval status for imported Google reviews.
		 * Defaults to 1 (approved) so imported content is immediately visible,
		 * but can be set to 0 or 'hold' to route reviews through moderation.
		 *
		 * @param int $approved  Default approval status (1 = approved).
		 * @param int $post_id   The listing post ID.
		 * @param array $review  The normalised review array.
		 */
		$comment_approved = apply_filters( 'dgbi_review_approved', 1, $post_id, $review );

		$commentdata = [
			'comment_post_ID'      => $post_id,
			'comment_author'       => sanitize_text_field( $review['author_name'] ),
			'comment_author_email' => '',
			'comment_author_url'   => '',
			'comment_content'      => sanitize_textarea_field( $review['text'] ),
			'comment_type'         => 'review',
			'comment_parent'       => 0,
			'user_id'              => 0,
			'comment_agent'        => 'directorist-listing-import/' . DLIG_VERSION,
			'comment_date'         => $comment_date,
			'comment_approved'     => $comment_approved,
		];

		$comment_id = wp_insert_comment( $commentdata );
		if ( ! $comment_id ) {
			return false;
		}

		// Store rating on the comment
		if ( ! empty( $review['rating'] ) ) {
			update_comment_meta( $comment_id, 'rating', intval( $review['rating'] ) );
		}

		// Store the deduplication hash
		update_comment_meta( $comment_id, 'dgbi_google_review_id', $google_id );

		return $comment_id;
	}

	/**
	 * After all reviews for a listing are inserted, recalculate the average
	 * rating and update Directorist's canonical review meta. (audit §5.3)
	 *
	 * Uses Directorist\Review\Listing_Review_Meta which writes to:
	 *   _directorist_listing_rating        (read by directorist_get_listing_rating())
	 *   _directorist_listing_review_count  (read by directorist_get_listing_review_count())
	 *   _directorist_listing_rating_counts (read by rating histogram UI)
	 *
	 * @param int $post_id Directorist listing post ID.
	 */
	public function recalculate_average_rating( int $post_id ): void {
		$comments = get_comments( [
			'post_id'    => $post_id,
			'type'       => 'review',
			'status'     => 'approve',
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery
				[
					'key'     => 'rating',
					'compare' => 'EXISTS',
				],
			],
		] );

		if ( empty( $comments ) ) {
			return;
		}

		$total         = 0;
		$count         = 0;
		$rating_counts = [];
		foreach ( $comments as $c ) {
			$r = (int) get_comment_meta( $c->comment_ID, 'rating', true );
			if ( $r > 0 ) {
				$total += $r;
				$count++;
				$rating_counts[ $r ] = ( $rating_counts[ $r ] ?? 0 ) + 1;
			}
		}

		if ( $count > 0 ) {
			$average = round( $total / $count, 1 );

			if ( class_exists( '\Directorist\Review\Listing_Review_Meta' ) ) {
				\Directorist\Review\Listing_Review_Meta::update_rating( $post_id, $average );
				\Directorist\Review\Listing_Review_Meta::update_review_count( $post_id, $count );
				\Directorist\Review\Listing_Review_Meta::update_rating_counts( $post_id, $rating_counts );
			} else {
				// Fallback for Directorist versions that don't ship Listing_Review_Meta.
				update_post_meta( $post_id, '_directorist_listing_rating',       $average );
				update_post_meta( $post_id, '_directorist_listing_review_count', $count );
			}
		}

		// Fire Directorist's own cache-clearing hook if it exists
		do_action( 'atbdp_after_review_added', $post_id );
	}
}
