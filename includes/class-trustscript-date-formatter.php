<?php
/**
 * TrustScript_Date_Formatter
 *
 * @package TrustScript
 * @since 1.0.0
 */

class TrustScript_Date_Formatter {

	/**
	 * Format a date string based on the specified format type.
	 *
	 * @param string $date Date string to format.
	 * @param string $format Format type: 'relative', 'full', 'short', 'numeric'. Default 'relative'.
	 * @return string Formatted date string.
	 */
	public static function format( $date, $format = 'relative' ) {
		if ( empty( $date ) ) {
			return '';
		}

		$timestamp = strtotime( $date );

		if ( ! $timestamp ) {
			return esc_html( $date );
		}

		switch ( $format ) {
			case 'relative':
				return self::format_relative( $timestamp );

			case 'full':
				return date_i18n( 'F j, Y', $timestamp );

			case 'short':
				return date_i18n( 'M j, Y', $timestamp );

			case 'numeric':
				return date_i18n( 'm/d/Y', $timestamp );

			default:
				return self::format_relative( $timestamp );
		}
	}

	/**
	 * Format a timestamp into a relative time string (e.g., "2 weeks ago").
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string Relative time string.
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
	 * Get available date format options for display in settings.
	 *
	 * @return array Array of format options with keys as format types and values as human-readable labels.
	 */
	public static function get_formats() {
		return array(
			'relative' => __( 'Relative (e.g., "2 weeks ago")', 'trustscript' ),
			'full'     => __( 'Full (e.g., "January 15, 2024")', 'trustscript' ),
			'short'    => __( 'Short (e.g., "Jan 15, 2024")', 'trustscript' ),
			'numeric'  => __( 'Numeric (e.g., "01/15/2024")', 'trustscript' ),
		);
	}
}