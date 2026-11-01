const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	performance: {
		...defaultConfig.performance,
		// Admin-only React app; webpack's 244 KiB default also counts both index.css and index-rtl.css.
		maxEntrypointSize: 400000,
		maxAssetSize: 400000,
	},
};
