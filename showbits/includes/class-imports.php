<?php
/**
 * The import log and the queue of pushes.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * One table, `{prefix}showbits_imports`: every import, picked here (manual) or pushed from ShowBits (push), each with
 * its push in ShowBits, taken once. An import picked here waits as requested until ShowBits' push brings the ticket for
 * the clip's files; then it is queued until the library page or WP-Cron imports it. The queue is read fresh each time
 * (one process takes each push); the list on the settings page comes from the object cache, emptied by every change.
 */
final class Imports {
	public const DB_VERSION = 2;
	private const OPTION    = 'showbits_db_version';
	private const CACHE     = 'showbits_imports';
	/** A push stuck in running this long (the request died) is tried again, three times in all. */
	private const STALE = 15 * MINUTE_IN_SECONDS;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'showbits_imports';
	}

	/** Creates or updates the table (on activation, and once after an update of the plugin). */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				clip_id varchar(64) NOT NULL,
				variant varchar(16) NOT NULL DEFAULT '',
				source varchar(10) NOT NULL,
				push_id varchar(64) DEFAULT NULL,
				ticket varchar(64) DEFAULT NULL,
				status varchar(10) NOT NULL,
				title varchar(200) NOT NULL DEFAULT '',
				attachment_id bigint(20) unsigned DEFAULT NULL,
				error varchar(60) DEFAULT NULL,
				user_id bigint(20) unsigned DEFAULT NULL,
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY push_id (push_id),
				KEY clip_id (clip_id),
				KEY status (status)
			) {$charset};"
		);
		update_option( self::OPTION, self::DB_VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::OPTION, 0 ) < self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * An import picked here, asked of ShowBits: it waits as requested for its push. The same push again (ShowBits
	 * answers an open one) stays the one row. Returns the row's id.
	 *
	 * @param string $push_id The push ShowBits queued.
	 * @param string $clip_id Its clip.
	 * @param string $variant A quote card's format, '' for a video.
	 * @param int    $user_id Who asked (the attachment's author).
	 * @param string $title   The clip's title, for the list.
	 */
	public static function request( string $push_id, string $clip_id, string $variant, int $user_id, string $title ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (clip_id, variant, source, push_id, status, title, user_id, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s, %d, %s, %s)',
				self::table(),
				$clip_id,
				$variant,
				'manual',
				$push_id,
				'requested',
				mb_substr( $title, 0, 200 ),
				$user_id,
				$now,
				$now
			)
		);
		wp_cache_delete( 'recent', self::CACHE );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the row just written
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE push_id = %s', self::table(), $push_id ) );
	}

	/**
	 * A push as it arrives, with the ticket for its clip's files: queued to import. One picked here and waiting turns
	 * queued; a delivery again (ShowBits retries) brings a new ticket; one imported already stays. Returns whether
	 * there is something to import now.
	 *
	 * @param string $push_id The push.
	 * @param string $clip_id Its clip.
	 * @param int    $user_id Whose import it becomes when pushed from ShowBits (the person who connected the site).
	 * @param string $ticket  The ticket for the clip's files.
	 * @param string $variant A quote card's format picked here, '' otherwise.
	 */
	public static function enqueue_push( string $push_id, string $clip_id, int $user_id, string $ticket, string $variant = '' ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// The unique push id decides, in one statement: two deliveries of one push never make two rows.
		$changed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table
			$wpdb->prepare(
				"INSERT INTO %i (clip_id, variant, source, push_id, ticket, status, user_id, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s, %d, %s, %s)
				ON DUPLICATE KEY UPDATE ticket = IF(status IN ('requested', 'queued', 'running'), VALUES(ticket), ticket), status = IF(status = 'requested', 'queued', status), updated_at = VALUES(updated_at)",
				self::table(),
				$clip_id,
				$variant,
				'push',
				$push_id,
				$ticket,
				'queued',
				$user_id,
				$now,
				$now
			)
		);
		wp_cache_delete( 'recent', self::CACHE );
		return is_int( $changed ) && $changed > 0;
	}

	/**
	 * Where these imports stand (the library page asks while they run).
	 *
	 * @param array<int, int> $ids Rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function states( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$ids = array_slice( $ids, 0, 50 );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the plugin's own table, read fresh while imports run; one %d per id
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, clip_id, variant, status, attachment_id, error FROM %i WHERE id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( self::table() ), $ids ) ),
			ARRAY_A
		);
		// phpcs:enable
		return is_array( $rows ) ? $rows : array();
	}

	/** Whether pushes wait to be imported. */
	public static function waiting(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the queue is read fresh
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE status = 'queued' LIMIT 1", self::table() ) );
	}

	/**
	 * The next queued push, marked running (one process gets it); null when there is none.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function claim(): ?array {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql', true );
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::STALE );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the queue is read fresh: a cached row could hand one push to two processes
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'queued', updated_at = %s WHERE status = 'running' AND updated_at < %s AND attempts < 3", $table, $now, $stale ) );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'failed', error = 'interrupted', updated_at = %s WHERE status = 'running' AND updated_at < %s", $table, $now, $stale ) );
		$claimed = null;
		for ( $tries = 0; $tries < 3 && null === $claimed; $tries++ ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'queued' ORDER BY id LIMIT 1", $table ), ARRAY_A );
			if ( ! $row ) {
				break;
			}
			$taken = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'running', attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = 'queued'", $table, $now, $row['id'] ) );
			if ( 1 === $taken ) {
				$claimed = $row;
			}
		}
		// phpcs:enable
		wp_cache_delete( 'recent', self::CACHE );
		return $claimed;
	}

	/**
	 * Finishes a push.
	 *
	 * @param int                  $id     The row.
	 * @param array<string, mixed> $result status imported or failed, attachment_id, error, title, variant (its ticket ends).
	 */
	public static function finish( int $id, array $result ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table
			self::table(),
			array(
				'status'        => (string) $result['status'],
				'attachment_id' => $result['attachment_id'] ?? null,
				'error'         => $result['error'] ?? null,
				'title'         => mb_substr( (string) ( $result['title'] ?? '' ), 0, 200 ),
				'variant'       => (string) ( $result['variant'] ?? '' ),
				'ticket'        => null,
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
		wp_cache_delete( 'recent', self::CACHE );
	}

	/**
	 * The latest 20 imports, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent(): array {
		global $wpdb;
		$rows = wp_cache_get( 'recent', self::CACHE );
		if ( false === $rows ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table(), 20 ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table, cached
			wp_cache_set( 'recent', $rows, self::CACHE, MINUTE_IN_SECONDS );
		}
		return is_array( $rows ) ? $rows : array();
	}

	public static function drop(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own table on uninstall
		wp_cache_delete( 'recent', self::CACHE );
		delete_option( self::OPTION );
	}
}
