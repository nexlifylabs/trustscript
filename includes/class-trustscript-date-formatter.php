<?php
/**
 * TrustScript_Date_Formatter
 *
 * @package TrustScript
 * @since 1.0.0
 */

class TrustScript_Date_Formatter {

	/**
	 * Format a date string using the specified format type.
	 *
	 * Timestamps are stored in site-local time. The method parses them with the
	 * site timezone so `wp_date()` can convert to display time without double-offsetting.
	 * Falls back to a raw escaped string if the date cannot be parsed at all.
	 *
	 * Supported formats: 'relative', 'full', 'short', 'datetime', 'numeric'.
	 * Unknown values fall back to 'relative'.
	 *
	 * @since 1.0.0
	 * @param string $date   Date string in Y-m-d H:i:s format.
	 * @param string $format Format type. Default 'relative'.
	 * @return string Formatted date string, or empty string if $date is empty.
	 */
	public static function format( $date, $format = 'relative' ) {
		if ( empty( $date ) ) {
			return '';
		}

		$tz = wp_timezone();
		$dt = date_create_from_format( 'Y-m-d H:i:s', $date, $tz );
		if ( ! $dt ) {
			$dt = new DateTime( $date, $tz );
		}
		if ( ! $dt ) {
			return esc_html( $date );
		}

		$timestamp = $dt->getTimestamp();

		switch ( $format ) {
			case 'relative':
				return self::format_relative( $timestamp );

			case 'full':
				return wp_date( 'F j, Y', $timestamp );

			case 'short':
				return wp_date( 'M j, Y', $timestamp );

			case 'datetime':
				return wp_date( 'M j, Y g:i A', $timestamp );

			case 'numeric':
				return wp_date( 'm/d/Y', $timestamp );

			default:
				return self::format_relative( $timestamp );
		}
	}

	/**
	 * Format a Unix timestamp as a human-readable relative time string.
	 *
	 * Thresholds: < 1 hour → minutes, < 1 day → hours, < 1 week → days,
	 * < 30 days → weeks, otherwise → months. All strings are translatable.
	 *
	 * @since 1.0.0
	 * @param int $timestamp Unix timestamp.
	 * @return string Localised relative time string, e.g. "2 weeks ago".
	 */
	private static function format_relative( $timestamp ) {
		$diff = time() - $timestamp;

		if ( $diff < 3600 ) {
			$minutes = max( 1, floor( $diff / 60 ) );
			return sprintf(
				/* translators: %d = number of minutes */
				_n( '%d minute ago', '%d minutes ago', $minutes, 'trustscript' ),
				$minutes
			);
		}

		if ( $diff < 86400 ) {
			$hours = floor( $diff / 3600 );
			return sprintf(
				/* translators: %d = number of hours */
				_n( '%d hour ago', '%d hours ago', $hours, 'trustscript' ),
				$hours
			);
		}

		if ( $diff < 604800 ) {
			$days = floor( $diff / 86400 );
			return sprintf(
				/* translators: %d = number of days */
				_n( '%d day ago', '%d days ago', $days, 'trustscript' ),
				$days
			);
		}

		if ( $diff < 2592000 ) {
			$weeks = floor( $diff / 604800 );
			return sprintf(
				/* translators: %d = number of weeks */
				_n( '%d week ago', '%d weeks ago', $weeks, 'trustscript' ),
				$weeks
			);
		}

		$months = floor( $diff / 2592000 );
		return sprintf(
			/* translators: %d = number of months */
			_n( '%d month ago', '%d months ago', $months, 'trustscript' ),
			$months
		);
	}

	/**
	 * Get the available date format options for use in settings UI.
	 *
	 * @since 1.0.0
	 * @return array<string, string> Map of format keys to translated human-readable labels.
	 */
	public static function get_formats() {
		return array(
			'relative' => __( 'Relative (e.g., "2 weeks ago")', 'trustscript' ),
			'full'     => __( 'Full (e.g., "January 15, 2024")', 'trustscript' ),
			'short'    => __( 'Short (e.g., "Jan 15, 2024")', 'trustscript' ),
			'datetime' => __( 'Date & Time (e.g., "Jan 15, 2024 4:26 PM")', 'trustscript' ),
			'numeric'  => __( 'Numeric (e.g., "01/15/2024")', 'trustscript' ),
		);
	}
}