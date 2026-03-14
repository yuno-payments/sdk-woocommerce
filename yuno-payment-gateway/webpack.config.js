const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const WPDependencyExtractionPlugin = require('@wordpress/dependency-extraction-webpack-plugin');
const path = require('path');

// Remove CleanWebpackPlugin (would delete api.js/checkout.js from assets/js/)
// and DependencyExtractionPlugin (replace with one that handles @woocommerce/*).
const plugins = defaultConfig.plugins.filter(
    (plugin) =>
        plugin.constructor.name !== 'DependencyExtractionWebpackPlugin' &&
        plugin.constructor.name !== 'CleanWebpackPlugin'
);

plugins.push(
    new WPDependencyExtractionPlugin({
        requestToExternal(request) {
            if (request === '@woocommerce/blocks-registry') {
                return ['wc', 'wcBlocksRegistry'];
            }
            if (request === '@woocommerce/settings') {
                return ['wc', 'wcSettings'];
            }
        },
        requestToHandle(request) {
            if (request === '@woocommerce/blocks-registry') {
                return 'wc-blocks-registry';
            }
            if (request === '@woocommerce/settings') {
                return 'wc-settings';
            }
        },
    })
);

module.exports = {
    ...defaultConfig,
    plugins,
    entry: {
        'blocks/yuno-blocks': path.resolve(__dirname, 'src/blocks/yuno-blocks.js'),
    },
    output: {
        path: path.resolve(__dirname, 'assets/js'),
        filename: '[name].js',
    },
};
