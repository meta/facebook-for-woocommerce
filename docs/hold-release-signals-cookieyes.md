# Meta for WooCommerce: Configure Hold/Release Signals with CookieYes

This guide explains how to connect CookieYes to the Hold/Release Signals feature in Meta for WooCommerce. It provides a technical implementation that starts signal delivery in the state that matches the visitor's CookieYes choice and responds when that choice changes.

The setup treats CookieYes's **Advertisement** category as permission to send Meta Signals. If the visitor allows Advertisement cookies, signal delivery is active. If the visitor denies Advertisement cookies or has not completed a choice, signal delivery stays held.

Hold/Release Signals does not provide a consent banner or determine whether permission is required. CookieYes collects and stores the visitor's choice; this integration communicates that choice to Meta for WooCommerce.

## Before you begin

Confirm that:

* Meta for WooCommerce is installed and connected to Meta.
* CookieYes is installed and its consent banner is configured.
* CookieYes includes the **Advertisement** category with the `advertisement` slug.
* You can add a file to the site's `wp-content` directory.

The example expects the current CookieYes interfaces:

* The `cookieyes-consent` first-party cookie.
* The `advertisement` category.
* The `cookieyes_consent_update` browser event.
* The `window.getCkyConsent()` browser API.

If the category was renamed, or the site uses a legacy or standalone CookieYes setup, confirm the available category, event and browser API before adapting the example.

## How CookieYes controls Hold/Release Signals

CookieYes remains the source of the visitor's choice. The integration translates that choice into the state used by Meta for WooCommerce:

* `held` means signal delivery is on hold.
* `active` means signal delivery is active.

The setup considers Advertisement cookies allowed only when the visitor has completed a choice and the Advertisement category is enabled:

```js
var allowed =
	consent.isUserActionCompleted &&
	consent.categories.advertisement;
```

This prevents CookieYes default category settings from being treated as permission before the visitor makes a choice.

### State model

| CookieYes state | Meta state | What happens |
| :--- | :--- | :--- |
| The visitor has not made a choice | `held` | Signals stay held. |
| The visitor allows Advertisement cookies | `active` | Signals are released and begin sending normally. |
| The visitor denies Advertisement cookies | `held` | Signals stay held. |
| The visitor withdraws Advertisement permission | Transitioning to `held` | The integration holds signals and reloads the page so the held state takes effect right away. |
| The visitor allows Advertisement cookies again | `active` | Signals are released again. |

The integration uses two first-party cookies for different purposes:

| Cookie | Purpose |
| :--- | :--- |
| `cookieyes-consent` | Stores the visitor's CookieYes choices. |
| `wc_facebook_signals_state` | Stores whether Meta Signals are `held` or `active`. |

The Meta state cookie contains only the current state. It does not contain event data. The integration recalculates this state from the CookieYes choice on each request that reaches WordPress. A full-page cache can serve a page without running the integration, so cached-page behavior must be tested separately.

This state model applies to visitor activity on the website's public pages. Backend, administrator and scheduled activity is outside this browser permission flow.

## How the integration works

The integration has two parts:

1. Backend code establishes the signal state before Meta for WooCommerce processes the page.
2. Browser code responds when the visitor saves or changes their CookieYes choice.

Both parts are required. The backend code prevents eligible public-page Pixel and Conversions API events from being sent before permission. The browser code can release eligible recent events from the current page after permission is granted and can apply a new hold after permission is withdrawn.

## Add the integration as a must-use plugin

A must-use plugin is appropriate for this integration because it loads before regular plugins, does not depend on the active theme and cannot be accidentally deactivated from the regular Plugins page.

Do not add this code directly to Meta for WooCommerce, CookieYes or the active theme. Plugin and theme updates may overwrite it.

### 1. Create the must-use plugins folder

Inside `wp-content`, create the following folder if it does not already exist:

```text
wp-content/mu-plugins
```

WordPress automatically loads PHP files placed directly inside this folder. It does not automatically discover plugin files inside nested folders.

### 2. Create the integration file

Create this file:

```text
wp-content/mu-plugins/meta-cookieyes-signals.php
```

Add the following code:

```php
<?php
/**
 * Plugin Name: Meta for WooCommerce Hold/Release Signals for CookieYes
 * Description: Connects CookieYes Advertisement permission to Meta for WooCommerce signal delivery.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read the values stored in CookieYes's consent cookie.
 *
 * @return array<string, string>
 */
function meta_for_woocommerce_cookieyes_get_consent_values() {
	$encoded = isset( $_COOKIE['cookieyes-consent'] )
		&& is_string( $_COOKIE['cookieyes-consent'] )
			// Sanitization must happen after URL decoding.
			? wp_unslash( $_COOKIE['cookieyes-consent'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: '';

	$raw    = sanitize_text_field( rawurldecode( $encoded ) );
	$values = array();

	foreach ( explode( ',', $raw ) as $entry ) {
		$parts = array_map( 'trim', explode( ':', $entry, 2 ) );

		if ( 2 !== count( $parts ) || '' === $parts[0] ) {
			continue;
		}

		$values[ $parts[0] ] = $parts[1];
	}

	return $values;
}

/**
 * Whether the visitor made a choice and allowed Advertisement cookies.
 *
 * @return bool
 */
function meta_for_woocommerce_cookieyes_allows_advertising() {
	$consent = meta_for_woocommerce_cookieyes_get_consent_values();

	return isset( $consent['action'], $consent['advertisement'] )
		&& 'yes' === $consent['action']
		&& 'yes' === $consent['advertisement'];
}

/**
 * Start the current page in the state that matches the CookieYes choice.
 */
function meta_for_woocommerce_cookieyes_set_initial_state() {
	$state = meta_for_woocommerce_cookieyes_allows_advertising()
		? 'active'
		: 'held';

	// Make the state available during the current PHP request.
	$_COOKIE['wc_facebook_signals_state'] = $state;

	// Make the state available to JavaScript and later page requests.
	if ( ! headers_sent() ) {
		setcookie(
			'wc_facebook_signals_state',
			$state,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}
}
meta_for_woocommerce_cookieyes_set_initial_state();

/**
 * Keep public-page signal delivery held unless CookieYes allows advertising.
 */
add_filter(
	'facebook_signals_held',
	function ( $held ) {
		return $held
			|| ! meta_for_woocommerce_cookieyes_allows_advertising();
	}
);

/**
 * React when the visitor saves or changes their CookieYes choice.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! wp_script_is( 'wc-facebook-signals', 'enqueued' ) ) {
			return;
		}

		$script = <<<'JS'
( function () {
	'use strict';

	function cookieYesAllowsAdvertising() {
		if ( typeof window.getCkyConsent !== 'function' ) {
			return false;
		}

		var consent = window.getCkyConsent();

		return !! (
			consent &&
			consent.isUserActionCompleted &&
			consent.categories &&
			consent.categories.advertisement
		);
	}

	function reportError( action, error ) {
		if (
			window.console &&
			typeof window.console.error === 'function'
		) {
			window.console.error(
				'Meta for WooCommerce could not ' +
					action +
					' signals.',
				error
			);
		}
	}

	function reloadPage() {
		window.location.reload();
	}

	function updateMetaTracking() {
		if (
			! window.fbwcsignal ||
			typeof window.fbwcsignal.getState !== 'function' ||
			typeof window.fbwcsignal.hold !== 'function' ||
			typeof window.fbwcsignal.release !== 'function'
		) {
			return;
		}

		var allowed = cookieYesAllowsAdvertising();
		var desired = allowed ? 'active' : 'held';

		if ( window.fbwcsignal.getState() === desired ) {
			return;
		}

		if ( allowed ) {
			window.fbwcsignal.release().catch(
				function ( error ) {
					reportError( 'release', error );
				}
			);
			return;
		}

		window.fbwcsignal.hold().then(
			reloadPage,
			function ( error ) {
				reportError( 'hold', error );
				reloadPage();
			}
		);
	}

	document.addEventListener(
		'cookieyes_consent_update',
		updateMetaTracking
	);
} )();
JS;

		wp_add_inline_script(
			'wc-facebook-signals',
			$script,
			'after'
		);
	},
	20
);
```

Do not add a closing `?>` tag to the file. Avoiding it helps prevent accidental output before WordPress sends cookies and other response headers.

### 3. Confirm that WordPress loaded it

In WordPress, go to **Plugins** and open the **Must-Use** section. The following entry should appear:

```text
Meta for WooCommerce Hold/Release Signals for CookieYes
```

Must-use plugins do not require activation.

### 4. Clear cached pages

Clear any WordPress page cache, server cache and CDN cache after adding or changing the integration. An old cached page may contain signal initialization generated before the integration was installed.

## How the backend setup works

### Reading the CookieYes choice

CookieYes stores values in `cookieyes-consent` using entries such as:

```text
action:yes,necessary:yes,functional:no,analytics:no,advertisement:yes
```

The PHP code parses these into exact key/value pairs. Advertisement permission is allowed only when both of these values are present:

```php
'action'        => 'yes'
'advertisement' => 'yes'
```

Using exact values avoids treating an incomplete choice or a similarly named category as permission.

### Establishing the initial state

The must-use plugin runs before Meta for WooCommerce. It translates the CookieYes choice into:

```php
$state = $allowed ? 'active' : 'held';
```

It then updates the state for the current PHP request:

```php
$_COOKIE['wc_facebook_signals_state'] = $state;
```

It also sends `wc_facebook_signals_state` to the browser with `setcookie()` so JavaScript and later requests can read it.

### Applying the server-side hold

Meta for WooCommerce exposes `facebook_signals_held` so integrations can control whether eligible public-page signals are held. The example returns `true` when CookieYes has not recorded Advertisement permission:

```php
return $held
	|| ! meta_for_woocommerce_cookieyes_allows_advertising();
```

The existing `$held` value is preserved. The CookieYes integration can add a hold, but it does not force signals to become active when another integration has already held them.

## How the browser setup works

The CookieYes bridge is attached after Meta for WooCommerce's signal helper:

```php
wp_add_inline_script(
	'wc-facebook-signals',
	$script,
	'after'
);
```

This makes `window.fbwcsignal` available before the bridge runs. The `wp_enqueue_scripts` hook uses priority `20` because Meta for WooCommerce normally enqueues its helper at the default priority before the integration attaches the inline script.

### Responding to CookieYes

The bridge listens for:

```text
cookieyes_consent_update
```

When CookieYes dispatches that event, the bridge reads the current choice through:

```js
window.getCkyConsent();
```

It calculates the desired Meta state and compares it with the current state:

```js
if ( window.fbwcsignal.getState() === desired ) {
	return;
}
```

This avoids unnecessary AJAX requests and prevents an initial denial from causing a reload when signals are already held.

### Releasing Signals

When Advertisement permission is allowed, the bridge calls:

```js
window.fbwcsignal.release();
```

Release changes the state to `active` and allows Meta for WooCommerce to send eligible recent events held during the current page visit. The page does not reload.

### Holding Signals

When Advertisement permission is denied or withdrawn, the bridge calls:

```js
window.fbwcsignal.hold();
```

`window.fbwcsignal.hold()` revokes browser consent immediately, before the state request completes, so nothing fires while that request is in flight. If a release was still in progress when the hold happened, its response is discarded when it arrives: consent stays revoked, nothing is replayed, and events the server had already accepted are dropped from the browser queue so they are not posted twice.

The page reloads after the hold request so the next request starts in the held state. The error callback also reloads because the backend will recalculate the state from CookieYes when the new request reaches WordPress.

## What happens during each visitor flow

### First visit before the visitor makes a choice

When a visitor opens the website without an existing CookieYes choice:

1. The backend setup does not find a completed choice that allows Advertisement cookies.
2. It sets Meta Signals to `held` before Meta for WooCommerce processes the page.
3. The Meta Pixel does not begin normal event delivery.
4. Eligible Conversions API events generated from activity on that public page are not sent immediately.
5. Eligible event data from the current page visit can be held temporarily in the browser's memory.

If the visitor leaves the page before giving permission, the held browser events are not saved or sent. They are lost when the page closes.

### The visitor allows Advertisement cookies

When the visitor allows Advertisement cookies:

1. CookieYes updates `cookieyes-consent`.
2. CookieYes dispatches `cookieyes_consent_update`.
3. The integration confirms that the visitor completed a choice and allowed the Advertisement category.
4. It calls `window.fbwcsignal.release()`.
5. Meta for WooCommerce changes `wc_facebook_signals_state` to `active`.
6. The plugin can send eligible recent events held during that same page visit.
7. The Meta Pixel begins normal event delivery.
8. Signals remain active on later pages while Advertisement permission remains allowed.

Release does not recover events from an earlier page visit. It can only send eligible events still available from the current page.

### The visitor denies Advertisement cookies

When the visitor completes a choice without allowing Advertisement cookies:

1. CookieYes records the completed choice with Advertisement permission disabled.
2. The desired Meta state remains `held`.
3. The integration does not release signals.
4. Meta Pixel and eligible public-page Conversions API events remain held.

If signals were already held, no additional hold request or page reload is necessary.

### A returning visitor loads another page

At the beginning of each request that reaches WordPress, the backend setup reads the current CookieYes choice:

* If the visitor previously allowed Advertisement cookies, the page starts with signals `active`.
* If the visitor denied Advertisement cookies or has not completed a choice, the page starts with signals `held`.

### The visitor withdraws Advertisement permission

When a visitor disables the Advertisement category after signals were active:

1. CookieYes updates the visitor's choice.
2. The integration detects that Advertisement permission is no longer allowed.
3. It calls `window.fbwcsignal.hold()`.
4. Meta for WooCommerce changes `wc_facebook_signals_state` to `held`.
5. The integration reloads the page after the hold request finishes.
6. The reloaded page begins with signals held.

The reload is required because holding signals does not retract events already sent earlier on the active page. It ensures that subsequent visitor activity is processed from a page that started in the held state.

### The visitor grants permission again

If the visitor later enables Advertisement cookies again:

1. CookieYes records the new choice.
2. The integration calls `release()`.
3. Meta Signals become `active`.
4. Eligible recent events from the current page can be sent.
5. Signals continue normally on later pages.

## How to verify the setup

Test in a private browser window so existing CookieYes and Meta cookies do not affect the result. These Console commands show the current CookieYes choice and Meta state:

```js
window.getCkyConsent();
window.fbwcsignal.getState();
```

Check these scenarios:

| Scenario | Expected result |
| :--- | :--- |
| No choice or Advertisement denied | `getState()` returns `held`. Meta for WooCommerce does not set new `_fbp` or `_fbc` attribution cookies or immediately send eligible public-page events. |
| Advertisement allowed | CookieYes reports a completed choice with `categories.advertisement` set to `true`, and `getState()` returns `active`. The Network panel can show `wc_facebook_update_signals_state` followed by `facebook_release_signals`. |
| Advertisement withdrawn | The page reloads and `getState()` returns `held`. Events sent before withdrawal are not retracted. |
| Later page load | The page starts `active` after permission or `held` after denial or withdrawal. |

The Meta Pixel library itself may still load while signals are held. The important check is whether event delivery remains held.

## Verify Conversions API events in WooCommerce logs

Browser developer tools cannot show the final server-to-server request from WordPress to Meta. To check it:

1. Go to **Marketing > Facebook**, enable **Enable debug mode**, and save. Leave request headers excluded. Treat the entire log as sensitive because connection details, including access credentials, may still appear in request URLs.
2. While signals are `held`, view a product or generate another controlled event.
3. Go to **WooCommerce > Status > Logs** and open the latest log with the `facebook_for_woocommerce` source. It should not contain a corresponding `/events` request for that controlled public-page event.
4. Return to the same page and allow Advertisement cookies. After release, refresh the log and confirm that a `/{pixel-id}/events` request contains the event name or event identifier, followed by Meta's response.
5. Do not share the log. Turn debug mode off after testing because logs can grow and may contain event payloads or sensitive connection data.

Other plugin and backend activity may appear in the log, so compare the controlled event's name, time or event identifier. Complete request and response information may be unavailable if a custom integration enables non-blocking server requests through `wc_facebook_pixel_events_non_blocking`. Meta Events Manager can provide an additional final check.

## Test cached pages

A full-page cache can serve HTML without running the must-use plugin. After clearing the cache, repeat the no-choice, grant and withdrawal checks on cached pages. Confirm that a cached page never begins active before Advertisement permission.

If the result differs, check whether caching or performance tools delay or reorder CookieYes, `wc-facebook-signals` or the bridge, or serve expired WordPress AJAX nonces. Adjust that configuration before using the setup in production.

## Troubleshooting

| Problem | What to check |
| :--- | :--- |
| The must-use plugin does not appear in WordPress | Confirm that the PHP file is directly inside `wp-content/mu-plugins`, not in a nested folder. |
| `window.fbwcsignal` is undefined | Confirm that Meta for WooCommerce is connected and `wc-facebook-signals` is enqueued. Check for earlier JavaScript errors. |
| `window.getCkyConsent` is undefined | Confirm that the current CookieYes banner script is loaded. Legacy or standalone CookieYes setups may expose a different interface. |
| Signals remain held after permission | Check that `cookieyes-consent` contains both `action:yes` and `advertisement:yes`. |
| Signals are active before a choice | Confirm that the must-use plugin is loading and clear all cached pages. |
| Granting permission does not release events | Check the two WordPress AJAX requests and the browser Console for a release error. |
| Withdrawing permission does not reload | Confirm that CookieYes dispatches `cookieyes_consent_update` after the preference is saved. |
| The page repeatedly reloads | Check whether CookieYes saves the updated choice and whether `wc_facebook_signals_state` can be written. |
| An AJAX request returns `403` | Clear cached pages and check for an expired nonce or a missing signal-state cookie. |
| Events from an earlier page are missing | Only eligible events from the current page can be released. Previous-page events are not recovered. |
| A Conversions API event is missing from the debug log | Confirm debug mode is enabled, reproduce a controlled event and check whether non-blocking server requests were enabled through a custom filter. |

## Limitations

* Held event data exists only in the current page's browser memory.
* The event data is not stored in the WordPress database or in long-term browser storage.
* Closing or leaving the page before release discards the held events.
* The current Meta for WooCommerce release endpoint processes no more than 20 queued events per release request.
* Held events older than 30 minutes are not accepted by the release endpoint.
* Calling `hold()` does not retract events already sent from an active page.
* Existing Meta attribution cookies are not deleted when signals are held. Meta for WooCommerce avoids setting new attribution cookies while held.
* Visitors with JavaScript disabled may still register the basic Meta Pixel `noscript` PageView.
* Backend, administrator and scheduled events are outside this public-page permission flow.
* If release fails because of a network or server error, held events may be lost when the visitor leaves or reloads the page.
* Custom events and events sent directly through `fbq` or a separate Conversions API integration may bypass this flow.
* A renamed CookieYes category, legacy CookieYes setup or future API change may require adapting the integration.

The current release flow recognizes these event names:

```text
PageView
ViewContent
ViewCategory
Search
AddToCart
InitiateCheckout
Purchase
Lead
Subscribe
```

## Privacy responsibility

Hold/Release Signals does not make a site automatically compliant with privacy laws. It provides a technical way to connect CookieYes's Advertisement choice to Meta for WooCommerce signal delivery.

The website owner remains responsible for determining:

* Whether and when permission is required.
* How the consent banner is presented.
* Which CookieYes category should control Meta Signals.
* How regional privacy requirements are handled.
* How third-party scripts and custom integrations respond to the visitor's choice.

Third-party tools may interfere with or bypass this setup, so the complete site should be tested before the integration is used in production.
