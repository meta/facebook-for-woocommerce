/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * Loads the inline FacebookSignals wrapper rendered by
 * WC_Facebookcommerce_Pixel::get_facebook_signals_js() into jsdom.
 *
 * The wrapper lives in facebook-commerce-pixel-event.php as a nowdoc, so it is
 * extracted from there and evaluated as a script.
 *
 * @package FacebookCommerce
 */

const fs = require('fs');
const path = require('path');

let cachedSource = null;

/**
 * Returns the wrapper's JavaScript source.
 *
 * @return {string}
 */
function loadSignalsSource() {
    if (cachedSource === null) {
        const php = fs.readFileSync(path.join(__dirname, '..', '..', '..', 'facebook-commerce-pixel-event.php'), 'utf8');
        const marker = 'window.FacebookSignals = window.FacebookSignals ||';
        const markerIndex = php.indexOf(marker);
        const opener = "<<<'JS'";
        const start = php.lastIndexOf(opener, markerIndex) + opener.length;
        const end = php.indexOf('\nJS;', markerIndex);
        cachedSource = php.slice(start, end);
    }
    return cachedSource;
}

/**
 * Evaluates the wrapper in the current window, replacing any previous one.
 *
 * @return {Object} window.FacebookSignals
 */
function loadSignals() {
    delete window.FacebookSignals;
    new Function(loadSignalsSource())(); // eslint-disable-line no-new-func
    return window.FacebookSignals;
}

module.exports = { loadSignalsSource, loadSignals };
