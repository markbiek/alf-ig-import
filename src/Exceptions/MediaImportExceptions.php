<?php
/**
 * Custom exceptions for media import operations.
 *
 * @package Antelope_Ig_Import.
 */

namespace AlfIgImport\Exceptions;

/**
 * Base exception for media import operations.
 */
class MediaImportException extends \Exception {}

/**
 * Exception thrown when media data is invalid.
 */
class InvalidMediaDataException extends MediaImportException {}

/**
 * Exception thrown when media file is not found.
 */
class FileNotFoundException extends MediaImportException {}

/**
 * Exception thrown when media is already imported.
 */
class MediaAlreadyImportedException extends MediaImportException {} 