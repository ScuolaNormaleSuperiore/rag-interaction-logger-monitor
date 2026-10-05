/**
 * RAG Interaction Logger Monitor — administration script.
 *
 * Loaded only on the plugin screens. It shows the "From" and "To" fields only while
 * the period is "custom". Without this script both fields stay visible, so a custom
 * period can always be chosen.
 */
( function () {
	'use strict';

	function init() {
		var select = document.getElementById( 'rilm-period' );
		var fields = document.querySelectorAll( '[data-rilm-custom-date]' );

		if ( ! select || 0 === fields.length ) {
			return;
		}

		function sync() {
			var custom = 'custom' === select.value;

			Array.prototype.forEach.call( fields, function ( field ) {
				field.hidden = ! custom;
			} );
		}

		select.addEventListener( 'change', sync );
		sync();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
