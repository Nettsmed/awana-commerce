<?php
/**
 * Logger class for Awana Digital Sync
 *
 * @package Awana_Digital_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logger class
 */
class Awana_Logger {

	/**
	 * Log source name
	 *
	 * @var string
	 */
	const LOG_SOURCE = 'awana_digital';

	/**
	 * Log a message
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @param string $level Log level (info, warning, error, etc.).
	 */
	public static function log( $message, $context = array(), $level = 'info' ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		$logger->log( $level, $message, array( 'source' => self::LOG_SOURCE, 'context' => $context ) );
	}

	/**
	 * Log info message
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public static function info( $message, $context = array() ) {
		self::log( $message, $context, 'info' );
	}

	/**
	 * Log warning message
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public static function warning( $message, $context = array() ) {
		self::log( $message, $context, 'warning' );
	}

	/**
	 * Log error message
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public static function error( $message, $context = array() ) {
		self::log( $message, $context, 'error' );

		// Also capture in Sentry for centralized error monitoring
		if ( function_exists( '\\Sentry\\captureMessage' ) ) {
			\Sentry\withScope( function ( \Sentry\State\Scope $scope ) use ( $message, $context ) {
				$scope->setContext( 'awana', $context );
				\Sentry\captureMessage( $message, \Sentry\Severity::error() );
			} );
		}
	}
}

/**
 * Convenience function for logging
 *
 * @param string $message Log message.
 * @param array  $context Additional context data.
 */
function awana_log( $message, $context = array() ) {
	Awana_Logger::info( $message, $context );
}



