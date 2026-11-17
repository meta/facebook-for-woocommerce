/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

// Load the default @wordpress/scripts config object
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// Legacy jQuery UI powered admin files
const jQueryUIAdminFileNames = [
	'google-product-category-fields',
	'infobanner',
	'metabox',
	'modal',
	'product-categories',
	'products-admin',
	'settings-commerce',
	'enhanced-settings-sync',
	'plugin-rendering'
];

const jQueryUIAdminFileEntries = {};

jQueryUIAdminFileNames.forEach( ( name ) => {
    jQueryUIAdminFileEntries[ `admin/${ name }` ] = `./assets/js/admin/${ name }.js`;
} );

module.exports = {
    ...defaultConfig,
    entry: {
        // Use admin/index.js for any new React-powered UI
        'admin/index': './assets/js/admin/index.js',
        ...jQueryUIAdminFileEntries,
    },
    output: {
        filename: '[name].js',
        path: __dirname + '/assets/build',
    },
};
