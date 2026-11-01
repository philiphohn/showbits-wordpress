<?php
/**
 * The import log and the queue of pushes.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * One table, `{prefix}showbits_imports`: every import, whether someone picked it here (manual) or ShowBits pushed it
 * (push, with its push id, taken once). Pushes wait as queued until WP-Cron imports them. The queue is read fresh
 * each time (one process takes each push); the list on the settings page comes from the object cache, emptied by
 * every change.
 */
final class Imports {
	public const DB_VERSION = 1;
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
	 * A push to import later; false when this push came before (ShowBits delivers at least once).
	 *
	 * @param string $push_id The push.
	 * @param string $clip_id Its clip.
	 * @param int    $user_id Whose import it becomes (the person who connected the site).
	 */
	public static function enqueue_push( string $push_id, string $clip_id, int $user_id ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// The unique push id decides, in one statement: two deliveries of one push never both get in.
		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (clip_id, source, push_id, status, user_id, created_at, updated_at) VALUES (%s, %s, %s, %s, %d, %s, %s)',
				self::table(),
				$clip_id,
				'push',
				$push_id,
				'queued',
				$user_id,
				$now,
				$now
			)
		);
		wp_cache_delete( 'recent', self::CACHE );
		return 1 === $inserted;
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
	 * Records an import picked in the library (manual), as done.
	 *
	 * @param array<string, mixed> $row clip_id, variant, title, status, attachment_id, error, user_id.
	 */
	public static function record( array $row ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table
			self::table(),
			array(
				'clip_id'       => (string) $row['clip_id'],
				'variant'       => (string) ( $row['variant'] ?? '' ),
				'source'        => 'manual',
				'status'        => (string) $row['status'],
				'title'         => mb_substr( (string) ( $row['title'] ?? '' ), 0, 200 ),
				'attachment_id' => $row['attachment_id'] ?? null,
				'error'         => $row['error'] ?? null,
				'user_id'       => (int) ( $row['user_id'] ?? 0 ),
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		wp_cache_delete( 'recent', self::CACHE );
	}

	/**
	 * Finishes a push.
	 *
	 * @param int                  $id     The row.
	 * @param array<string, mixed> $result status imported or failed, attachment_id, error, title, variant.
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
