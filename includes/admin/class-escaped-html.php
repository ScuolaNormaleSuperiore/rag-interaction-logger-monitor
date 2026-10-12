<?php
/**
 * Markup that has already been escaped.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks a string as HTML whose untrusted parts were escaped when it was built.
 *
 * Lets a table cell carry markup (for example bold names) while every plain string
 * is still escaped by the code that prints it.
 */
final class Escaped_Html {

	/**
	 * Escaped markup.
	 *
	 * @var string
	 */
	public $html;

	/**
	 * Wraps markup that is already safe to print.
	 *
	 * @param string $html Escaped markup.
	 */
	public function __construct( string $html ) {
		$this->html = $html;
	}
}
