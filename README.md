# Meta for WooCommerce, formerly Facebook for WooCommerce, is now an official plugin from Meta.

[![PHP Coding Standards](https://github.com/woocommerce/facebook-for-woocommerce/actions/workflows/php-cs-on-changes.yml/badge.svg)](https://github.com/woocommerce/facebook-for-woocommerce/actions/workflows/php-coding-standards.yml)

**We're excited to announce that the plugin is is now owned by Meta, and we invite the developer community to join us in shaping its future through contributions.**

Grow your business on Facebook, Instagram and WhatsApp. Easily promote your products and target accurately using powerful sales and marketing tools. Update your customers about their orders on WhatsApp at every step, freeing up more time for you to focus on your business. Reach new customers and drive traffic to your website with seamless ad experiences, from discovery to conversion. Automatically sync your eligible products to your Meta catalog, so you can easily create ads right where your customers are.
- Help drive better ad performance by setting up a conversion pixel
- Easily set up your ads with a one-time account connection
- Sell from one inventory that automatically syncs to your catalog used for ads


### This is the development repository for the Meta for WooCommerce plugin.

- [WordPress.org plugin page](https://wordpress.org/plugins/facebook-for-woocommerce/)
- [User documentation](https://www.facebook.com/business/search/?q=woocommerce)

## Support

Please first take a look at the Meta helpcenter docs, by searching for keywords like 'WooCommerce' [here](https://www.facebook.com/business/help). If you didn't find what you were looking for, you can go to [Meta direct support](https://www.facebook.com/business-support-home) and ask your question.

If you have a WooCommerce.com account, you
can [search for help or submit a help request on WooCommerce.com](https://woocommerce.com/my-account/contact-support/).

### Logging

The plugin offers logging that can help debug various problems. You can enable debug mode in the main plugin settings
panel under the `Enable debug mode` section.
By default plugin omits headers in the requests to make the logs more readable. If debugging with headers is necessary
you can enable the headers in the logs by setting `wc_facebook_request_headers_in_debug_log` option to true.

### Crash recovery

A shutdown handler watches for fatal errors and uncaught exceptions raised in plugin files (including `vendor/`).
Each one increments a consecutive-crash streak for its request class (storefront or backend); crashes within
30 seconds of each other count once, and any request that runs the plugin and finishes cleanly resets the streak.
Three counted crashes in a row would pause the plugin. Before that happens, for a storefront crash, the guard runs a
self-check: a plain loopback request to the crashing page that carries nothing the client sent (no cookies, query
string or body), marked with a one-time token so the events tracker sends nothing to Meta for it. If that request
runs the plugin cleanly, the crash depends on client input, the streak resets and the plugin stays on. If it crashes
too, or no verdict can be obtained (loopback blocked, a cached page, an auth wall), the plugin pauses for 10 minutes.
Backend crashes (wp-admin, admin-ajax, REST, cron, CLI) are not self-checked, since a storefront request cannot vouch
for them; they pause on their own streak, which only clean backend requests reset. A full
streak again within a day grows the pause to 1 hour, 6 hours and then 24 hours; it never becomes permanent, a single
crash after a pause never escalates on its own, and the level decays after a day without crashes. The state lives in
`wp-content/uploads/facebook-for-woocommerce/.disabled`; the plugins screen shows a notice with a "Resume now"
button, and every crash writes one `Meta for WooCommerce crash guard:` line to the PHP error log with the self-check
result and the decision taken. See `includes/Framework/CrashGuardState.php` and `PluginCrashHandler::run_self_check()`.

### Crash recovery limitation (known gap)

Crash recovery uses a shutdown handler to write a disable flag and queue a sanitized crash report.
In rare PHP memory-exhaustion fatals, the process may not have enough memory left to run the shutdown handler.
When that happens, the site still recovers on the next request, but the disable flag and crash report may be skipped for that request.

## Documentation

- [Offline (physical store) events](docs/offline-events.md) — how in-store orders
  are detected, what is sent to Meta, and how to add a point-of-sale integration.

## Development

### Developing

- Clone this repository into the `wp-content/plugins/` folder your WooCommerce development environment.
- Install dependencies:
	- `npm install`
	- `composer install`
- Build assets:
	- `npm start` to build a development version
- Linting:
	- `npm run lint:php` to run PHPCS linter on all PHP files
- Testing:
	- `./bin/install-wp-tests.sh <test-db-name> <db-user> <db-password> [db-host]` to set up testing environment
	- `npm run test:php` to run PHP unit tests on all PHP files
	- `./vendor/bin/phpunit --coverage-html=reports/coverage` to run PHP unit tests with coverage

#### Running E2E tests locally (events flow)

Use the helper script at `tests/e2e/scripts/run-local-events.sh`.

Before running it, export all required environment variables (the script reads values directly from your process environment):

```bash
export WORDPRESS_PATH="/absolute/path/to/wordpress"
export WORDPRESS_URL="http://your-local-site.test"

export WP_USERNAME="admin"
export WP_PASSWORD="admin"
export WP_CUSTOMER_USERNAME="customer"
export WP_CUSTOMER_PASSWORD="customer"

export TEST_PRODUCT_URL="${WORDPRESS_URL%/}/product/beanie/"
export TEST_CATEGORY_URL="${WORDPRESS_URL%/}/product-category/clothing/accessories/"
export TEST_FBCLID="IwAR123TestClickId456"

export FB_E2E_TEST_COOKIE_NAME="facebook_test_id"
export FB_E2E_LOGGER_PATH="/tests/e2e/helpers/php/event-logger.php"

export WP_DEBUG_LOG="${WORDPRESS_PATH}/wp-content/debug.log"
export WC_LOG_PATH="${WORDPRESS_PATH}/wp-content/uploads/wc-logs"
```

Then run:

```bash
./tests/e2e/scripts/run-local-events.sh
```

Optional flags:

```bash
./tests/e2e/scripts/run-local-events.sh --project chromium-wp-customer --workers 1
./tests/e2e/scripts/run-local-events.sh --spec tests/e2e/theme-compatibility.spec.js

# Run with real browser binaries
./tests/e2e/scripts/run-local-events.sh --project edge-wp-customer --real-edge
./tests/e2e/scripts/run-local-events.sh --project firefox-wp-customer --real-firefox
./tests/e2e/scripts/run-local-events.sh --project brave-wp-customer --real-brave
./tests/e2e/scripts/run-local-events.sh --project opera-wp-customer --real-opera

# Allow script-driven install when browser is missing
./tests/e2e/scripts/run-local-events.sh --project edge-wp-customer --real-edge --auto-install
./tests/e2e/scripts/run-local-events.sh --project firefox-wp-customer --real-firefox --auto-install
./tests/e2e/scripts/run-local-events.sh --project brave-wp-customer --real-brave --auto-install
./tests/e2e/scripts/run-local-events.sh --project opera-wp-customer --real-opera --auto-install
```

> ⚠️ `--auto-install` may run `sudo`, install system packages, and add apt repositories/keyrings on Linux.
> It is opt-in and should only be used in trusted local dev environments.

#### Production build

- `npm run build` : Builds a production version.

### Releasing

Refer to
the [wiki for details of how to build and release the plugin](https://github.com/woocommerce/facebook-for-woocommerce/wiki/Build-&-Release).

### PHPCS Linting and PHP 8.1+

We currently do not support PHPCS on PHP 8.1+ versions. Please run PHPCS checks on PHP 8.0 or lower versions.
Refer [#2624 PR](https://github.com/woocommerce/facebook-for-woocommerce/pull/2624/) for additional context.

## License

Meta for WooCommerce is licensed under the [GNU General Public License v2.0 (or later)](https://www.gnu.org/licenses/gpl-2.0.html).

See the [LICENSE](LICENSE) file for the full license text.
