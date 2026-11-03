/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * The fbq watcher exists twice: inline in the FacebookSignals wrapper rendered
 * from facebook-commerce-pixel-event.php, and in assets/js/frontend/pixel-events.js
 * for pages where the wrapper is blocked. They cannot share code at runtime, so
 * this test keeps the two copies from drifting apart.
 *
 * @package FacebookCommerce
 */

const fs = require('fs');
const path = require('path');

const SIGNATURE = 'function watchForFbq(callback) {';

/**
 * Returns the watcher function's text, from its signature to the matching brace.
 *
 * @param {string} source File contents.
 * @param {string} label  Used in failure messages.
 * @return {string}
 */
function extractWatcher(source, label) {
    const start = source.indexOf(SIGNATURE);
    if (start === -1) {
        throw new Error(SIGNATURE + ' not found in ' + label);
    }
    if (source.indexOf(SIGNATURE, start + 1) !== -1) {
        throw new Error(SIGNATURE + ' found more than once in ' + label);
    }
    let depth = 0;
    for (let i = start + SIGNATURE.length - 1; i < source.length; i++) {
        if (source[i] === '{') {
            depth++;
        } else if (source[i] === '}') {
            depth--;
            if (depth === 0) {
                return source.slice(start, i + 1);
            }
        }
    }
    throw new Error('Unbalanced braces in ' + label);
}

/**
 * Drops indentation and blank lines so tabs versus spaces do not matter.
 *
 * @param {string} code
 * @return {string[]}
 */
function normalise(code) {
    return code.split('\n').map(function (line) { return line.trim(); }).filter(Boolean);
}

describe('fbq watcher copies', function () {
    it('are identical in pixel-events.js and the inline FacebookSignals wrapper', function () {
        const root = path.join(__dirname, '..', '..');
        const pixelEvents = fs.readFileSync(path.join(root, 'assets', 'js', 'frontend', 'pixel-events.js'), 'utf8');
        const wrapper = fs.readFileSync(path.join(root, 'facebook-commerce-pixel-event.php'), 'utf8');

        expect(normalise(extractWatcher(wrapper, 'facebook-commerce-pixel-event.php')))
            .toEqual(normalise(extractWatcher(pixelEvents, 'pixel-events.js')));
    });
});
