const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const WPDependencyExtractionPlugin = require('@wordpress/dependency-extraction-webpack-plugin');
const TerserPlugin = require('terser-webpack-plugin');
const webpack = require('webpack');
const path = require('path');

// Remove CleanWebpackPlugin (would delete api.js/checkout.js from assets/js/)
// and DependencyExtractionPlugin (replace with one that handles @woocommerce/*).
const plugins = defaultConfig.plugins.filter(
    (plugin) =>
        plugin.constructor.name !== 'DependencyExtractionWebpackPlugin' &&
        plugin.constructor.name !== 'CleanWebpackPlugin'
);

plugins.push(
    new webpack.BannerPlugin({
        banner: [
            'Yuno Payment Gateway - WooCommerce Blocks Integration',
            '',
            'COMPILED FILE - Do not edit directly.',
            'Source: src/blocks/yuno-blocks.js',
            'Build:  npm run build (uses @wordpress/scripts)',
            '',
            '@see https://github.com/yuno-payments/sdk-woocommerce',
        ].join('\n'),
    }),
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
    optimization: {
        ...defaultConfig.optimization,
        minimizer: [
            new TerserPlugin({
                extractComments: false,
                terserOptions: {
                    format: {
                        comments: /COMPILED FILE/,
                    },
                },
            }),
        ],
    },
    entry: {
        'blocks/yuno-blocks': path.resolve(__dirname, 'src/blocks/yuno-blocks.js'),
    },
    output: {
        path: path.resolve(__dirname, 'assets/js'),
        filename: '[name].js',
    },
};
