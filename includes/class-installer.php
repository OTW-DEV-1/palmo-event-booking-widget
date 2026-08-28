<?php
namespace EBS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the plugin's custom tables.
 */
class Installer {

	const DB_VERSION    = '3';
	const DB_VERSION_KEY = 'ebs_db_version';

	public static function slots_table() {
		global $wpdb;
		return $wpdb->prefix . 'ebs_slots';
	}

	public static function bookings_table() {
		global $wpdb;
		return $wpdb->prefix . 'ebs_bookings';
	}

	public static function activate() {
		self::install_tables();
		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Runs on load so the tables also appear after a manual file-copy install.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_KEY ) === self::DB_VERSION ) {
			return;
		}
		self::install_tables();
		self::backfill_phone_norm();
		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	/**
	 * Fills phone_norm for rows written before the column existed, so duplicate
	 * checking sees the site's booking history and not only new submissions.
	 *
	 * Batched, and capped, because this runs inline on the request that discovers
	 * the version bump -- a site with a very large table finishes the remainder on
	 * the next admin request rather than timing out on this one.
	 */
	private static function backfill_phone_norm() {
		global $wpdb;

		$table = self::bookings_table();

		for ( $batch = 0; $batch < 20; $batch++ ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, phone FROM {$table} WHERE phone <> '' AND ( phone_norm IS NULL OR phone_norm = '' ) LIMIT %d",
					500
				)
			);

			if ( empty( $rows ) ) {
				return;
			}

			foreach ( $rows as $row ) {
				$normalized = Duplicate_Guard::normalize_phone( (string) $row->phone );

				// A number too short to match is still marked, with a single space,
				// so the next batch does not pick the same row up forever.
				$wpdb->update(
					$table,
					array( 'phone_norm' => '' === $normalized ? ' ' : $normalized ),
					array( 'id' => (int) $row->id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}
	}

	private static function install_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$slots    = self::slots_table();
		$bookings = self::bookings_table();

		// The UNIQUE key on (event_id, slot_date, slot_time) is what lets the admin
		// re-save the slot definition textarea without losing existing booked counts.
		$sql_slots = "CREATE TABLE {$slots} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			slot_date date NOT NULL,
			slot_time time NOT NULL,
			slot_end time NULL DEFAULT NULL,
			capacity int(10) unsigned NOT NULL DEFAULT 0,
			booked int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'open',
			sort_order int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY event_slot (event_id,slot_date,slot_time),
			KEY event_date (event_id,slot_date)
		) {$charset};";

		$sql_bookings = "CREATE TABLE {$bookings} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slot_id bigint(20) unsigned NOT NULL,
			event_id bigint(20) unsigned NOT NULL,
			slot_date date NOT NULL,
			slot_time time NOT NULL,
			slot_end time NULL DEFAULT NULL,
			seats int(10) unsigned NOT NULL DEFAULT 1,
			name varchar(200) NOT NULL DEFAULT '',
			email varchar(200) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			phone_norm varchar(32) NOT NULL DEFAULT '',
			payload longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'confirmed',
			form_name varchar(200) NOT NULL DEFAULT '',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip varchar(100) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY slot_id (slot_id),
			KEY event_id (event_id),
			KEY status (status),
			KEY created_at (created_at),
			KEY event_email (event_id,email(100)),
			KEY event_phone (event_id,phone_norm)
		) {$charset};";

		dbDelta( $sql_slots );
		dbDelta( $sql_bookings );
	}
}
