<?php
declare(strict_types=1);

namespace EBS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asset versioning.
 *
 * Enqueues are versioned with the file's modification time rather than the
 * plugin version, so a hotfix pushed without a version bump still reaches
 * browsers and CDNs holding the previous copy.
 */
class Assets {

	/**
	 * @param string $relative Path inside the plugin folder, e.g. 'assets/js/frontend.js'.
	 * @return string Modification time, falling back to the plugin version when the
	 *                file cannot be stat'ed.
	 */
	public static function version( string $relative ): string {
		$path = EBS_PATH . ltrim( $relative, '/' );

		if ( ! file_exists( $path ) ) {
			return EBS_VERSION;
		}

		$time = filemtime( $path );

		return $time ? (string) $time : EBS_VERSION;
	}

	public static function url( string $relative ): string {
		return EBS_URL . ltrim( $relative, '/' );
	}
}
