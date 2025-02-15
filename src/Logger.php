<?php
/**
 * Logger class for standardized logging.
 *
 * @package Antelope_Ig_Import
 */

namespace AlfIgImport;

/**
 * Handles standardized logging for the plugin.
 */
class Logger {
	/**
	 * Log levels.
	 */
	private const LEVEL_DEBUG   = 'DEBUG';
	private const LEVEL_INFO    = 'INFO';
	private const LEVEL_WARNING = 'WARNING';
	private const LEVEL_ERROR   = 'ERROR';

	/**
	 * Whether debug logging is enabled.
	 *
	 * @var bool
	 */
	private static bool $debug_enabled = false;

	/**
	 * Enable debug logging.
	 */
	public static function enable_debug(): void {
		self::$debug_enabled = true;
	}

	/**
	 * Disable debug logging.
	 */
	public static function disable_debug(): void {
		self::$debug_enabled = false;
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $message Message to log.
	 * @param mixed  ...$args Optional sprintf arguments.
	 */
	public static function debug( string $message, ...$args ): void {
		if ( ! self::$debug_enabled ) {
			return;
		}
		self::log( self::LEVEL_DEBUG, $message, ...$args );
	}

	/**
	 * Log an info message.
	 *
	 * @param string $message Message to log.
	 * @param mixed  ...$args Optional sprintf arguments.
	 */
	public static function info( string $message, ...$args ): void {
		self::log( self::LEVEL_INFO, $message, ...$args );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message Message to log.
	 * @param mixed  ...$args Optional sprintf arguments.
	 */
	public static function warning( string $message, ...$args ): void {
		self::log( self::LEVEL_WARNING, $message, ...$args );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Message to log.
	 * @param mixed  ...$args Optional sprintf arguments.
	 */
	public static function error( string $message, ...$args ): void {
		self::log( self::LEVEL_ERROR, $message, ...$args );
	}

	/**
	 * Internal logging method.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message to log.
	 * @param mixed  ...$args Optional sprintf arguments.
	 */
	private static function log( string $level, string $message, ...$args ): void {
		$formatted_message = empty( $args ) ? $message : sprintf( $message, ...$args );
		$log_message = sprintf( '[ALF-IG-IMPORT] [%s] %s', $level, $formatted_message );
		error_log( $log_message );
	}
} 