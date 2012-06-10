(function($) {

/**
 * Handle Generator
 */
blx.ui.HandleGenerator = blx.ui.InputGenerator.extend({

	generateTargetValue: function(sourceVal)
	{
		// Remove HTML tags
		var handle = sourceVal.replace("/<(.*?)>/g", '');

		// Make it lowercase
		handle = handle.toLowerCase();

		// Convert extended ASCII characters to basic ASCII
		handle = blx.asciiString(handle);

		// Remove any non alphanumeric characters
		handle = handle.replace(/[^a-z0-9]+/g, '');

		// Handle must start with a letter
		handle = handle.replace(/^[^a-z]+/, '');

		return handle;
	}
});

})(jQuery);
