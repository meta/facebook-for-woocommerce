# Meta for WooCommerce: Configure Hold/Release Signals with Complianz

This guide explains how to connect Complianz to the Hold/Release Signals feature in Meta for WooCommerce. It provides a technical implementation that starts signal delivery in the state that matches the visitor's Complianz choice and responds when that choice changes.

The setup treats Complianz's **Marketing** category as permission to send Meta Signals. If Complianz reports that Marketing is allowed, signal delivery is active. If Complianz reports that Marketing is denied, signal delivery stays held.

Hold/Release Signals does not provide a consent banner or determine whether permission is required. Complianz applies the site's regional consent configuration and stores the visitor's choice; this integration communicates Complianz's result to Meta for WooCommerce.

## Before you begin

Confirm that:

* Meta for WooCommerce is installed and connected to Meta.
* Complianz is installed and its consent banner is configured.
* Complianz uses the **Marketing** category for Meta Signals.
* You can add a file to the site's `wp-content` directory.

The example expects the current Complianz interfaces:

* The PHP function `cmplz_has_consent( 'marketing' )`.
* The browser function `window.cmplz_has_consent( 'marketing' )`.
* The `cmplz_status_change` browser event.
* Event details containing `category` and `value`.

The implementation was prepared against Complianz 7.5.3.1. If the site uses a substantially different version, confirm these interfaces before adapting the example.

### Configure the Complianz consent flow

Complete the Complianz setup wizard before adding the integration. In the wizard:

1. Select the region and consent behavior that apply to the site. For an opt-in flow, use an opt-in configuration such as the EU region or another Complianz option that requires permission before Marketing is allowed.
2. Confirm that the site uses advertising or marketing cookies so the Marketing category appears in the banner.
3. Enable the consent banner and cookie blocker, then finish the wizard.

Do not select a region only to force a particular test result. The site's Complianz configuration should reflect the regions it targets and its privacy requirements.

For an opt-in test, open a private browser window before making a choice and confirm:

```js
window.cmplz_has_consent( 'marketing' );
```

The result should be `false`. If it is `true`, review Complianz's region and consent-type settings before continuing.

### Avoid overlapping script blocking

Complianz can detect Facebook for WooCommerce and separately block matching browser scripts until Marketing is allowed. Hold/Release Signals needs Meta for WooCommerce's browser helper to load while signal delivery is held so eligible current-page events can be kept temporarily in memory.

For this setup, go to **Complianz > Integrations > Plugins** and disable the automatic **Facebook for WooCommerce** integration. Also check the Complianz Script Center for a custom rule that blocks `wc-facebook-signals`, `FacebookSignals` or the Meta for WooCommerce event scripts.

This does not make signal delivery active before permission. The must-use plugin below establishes the held state before Meta for WooCommerce processes the page. The Meta Pixel library itself may still load while signals are held; the important check is whether event delivery remains held.

## How Complianz controls Hold/Release Signals

Complianz remains the source of the visitor's choice. The integration translates the result of Complianz's Marketing consent check into the state used by Meta for WooCommerce:

* `held` means signal delivery is on hold.
* `active` means signal delivery is active.

The backend asks Complianz directly whether Marketing is allowed:

```php
cmplz_has_consent( 'marketing' );
```

The result is region-aware. For an opt-in configuration, Marketing normally remains denied until the visitor allows it. For an opt-out configuration, Complianz may report Marketing as allowed before a category cookie exists. The integration follows that Complianz result instead of imposing a separate consent rule.

### State model

| Complianz result | Meta state | What happens |
| :--- | :--- | :--- |
| Marketing is not allowed | `held` | Signals stay held. |
| Marketing is allowed | `active` | Signals are released and begin sending normally. |
| The visitor changes Marketing from allowed to denied | Transitioning to `held` | The integration holds signals and reloads the page so the held state takes effect right away. |
| The visitor changes Marketing from denied to allowed | `active` | Signals are released again and eligible current-page events can be sent. |

The integration uses two first-party cookies for different purposes:

| Cookie | Purpose |
| :--- | :--- |
| Complianz category cookies, normally including `cmplz_marketing` | Store the visitor's Complianz choices. Complianz can use a different prefix on some multisite configurations. |
| `wc_facebook_signals_state` | Stores whether Meta Signals are `held` or `active`. |

The Meta state cookie contains only the current state. It does not contain event data. The integration recalculates the state through Complianz on each request that reaches WordPress. A full-page cache can serve a page without running the integration, so cached-page behavior must be tested separately.

This state model applies to visitor activity on the website's public pages. Backend, administrator and scheduled activity is outside this browser permission flow.

## How the integration works

The integration has two parts:

1. Backend code asks Complianz whether Marketing is allowed and establishes the signal state before public-page events are processed.
2. Browser code responds when Complianz changes the Marketing choice.

Both parts are required. The backend code prevents eligible public-page Pixel and Conversions API events from being sent when Complianz reports that Marketing is denied. The browser code can release eligible recent events from the current page after permission is granted and can apply a new hold after permission is withdrawn.

## Add the integration as a must-use plugin

A must-use plugin is appropriate for this integration because it loads before regular plugins, does not depend on the active theme and cannot be accidentally deactivated from the regular Plugins page.

Do not add this code directly to Meta for WooCommerce, Complianz or the active theme. Plugin and theme updates may overwrite it.

### 1. Create the must-use plugins folder

Inside `wp-content`, create the following folder if it does not already exist:

```text
wp-content/mu-plugins
```

WordPress automatically loads PHP files placed directly inside this folder. It does not automatically discover plugin files inside nested folders.

### 2. Create the integration file

Create this file:

```text
wp-content/mu-plugins/meta-complianz-signals.php
```

Add the following code:

```php
<?php
/**
 * Plugin Name: Meta for WooCommerce Hold/Release Signals for Complianz
 * Description: Connects Complianz Marketing permission to Meta for WooCommerce signal delivery.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether Complianz currently allows the Marketing category.
 *
 * Complianz applies its configured region and consent type when calculating
 * this result. If Complianz is unavailable, fail closed and keep signals held.
 *
 * @return bool
 */
function meta_for_woocommerce_complianz_allows_marketing() {
	if ( function_exists( 'cmplz_has_consent' ) && did_action( 'init' ) ) {
		return (bool) cmplz_has_consent( 'marketing' );
	}

	// Before init, use the stored category value and fail closed by default.
	$prefix = 'cmplz_';

	if (
		class_exists( 'COMPLIANZ' ) &&
		isset( COMPLIANZ::$banner_loader ) &&
		method_exists( COMPLIANZ::$banner_loader, 'get_cookie_prefix' )
	) {
		$prefix = COMPLIANZ::$banner_loader->get_cookie_prefix();
	}

	$cookie_name = $prefix . 'marketing';
	$value       = isset( $_COOKIE[ $cookie_name ] )
		&& is_string( $_COOKIE[ $cookie_name ] )
			? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) )
			: '';

	return 'allow' === $value;
}

/**
 * Start the current page in the state that matches the Complianz choice.
 */
function meta_for_woocommerce_complianz_set_initial_state() {
	$state = meta_for_woocommerce_complianz_allows_marketing()
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

// Wait until Complianz and WordPress translations are ready for the full check.
add_action(
	'init',
	'meta_for_woocommerce_complianz_set_initial_state',
	1
);

/**
 * Keep public-page signal delivery held unless Complianz allows Marketing.
 */
add_filter(
	'facebook_signals_held',
	function ( $held ) {
		return $held
			|| ! meta_for_woocommerce_complianz_allows_marketing();
	}
);

/**
 * React when the visitor changes their Complianz Marketing choice.
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

	function updateMetaTracking( allowed ) {
		if (
			! window.fbwcsignal ||
			typeof window.fbwcsignal.getState !== 'function' ||
			typeof window.fbwcsignal.hold !== 'function' ||
			typeof window.fbwcsignal.release !== 'function'
		) {
			return;
		}

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
		'cmplz_status_change',
		function ( event ) {
			if (
				! event.detail ||
				event.detail.category !== 'marketing'
			) {
				return;
			}

			updateMetaTracking( event.detail.value === 'allow' );
		}
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
Meta for WooCommerce Hold/Release Signals for Complianz
```

Must-use plugins do not require activation.

### 4. Clear cached pages

Clear any WordPress page cache, server cache and CDN cache after adding or changing the integration. An old cached page may contain signal initialization generated before the integration was installed.

## How the backend setup works

### Reading the Complianz result

After WordPress reaches `init`, the must-use plugin asks Complianz directly:

```php
cmplz_has_consent( 'marketing' );
```

Complianz uses its configured consent type and cookie prefix when producing this result. This is important for regional opt-out behavior and multisite configurations.

Meta for WooCommerce can inspect signal state while regular plugins are still loading. Before `init`, the helper reads only Complianz's stored Marketing category value and defaults to denied when no value exists. This early, fail-closed check prevents attribution cookies from being written before the complete Complianz API is ready.

The initial-state callback runs on `init` at priority `1`. Complianz has loaded its API and WordPress translations by then, while WordPress can still send the state cookie before page output begins. From that point onward, the helper uses `cmplz_has_consent( 'marketing' )`, including Complianz's configured regional behavior.

If Complianz is missing or inactive, the helper returns `false` and signals stay held.

### Establishing the initial state

The integration translates the Complianz result into:

```php
$state = $allowed ? 'active' : 'held';
```

It then updates the state for the current PHP request:

```php
$_COOKIE['wc_facebook_signals_state'] = $state;
```

It also sends `wc_facebook_signals_state` to the browser with `setcookie()` so JavaScript and later requests can read it.

### Applying the server-side hold

Meta for WooCommerce exposes `facebook_signals_held` so integrations can control whether eligible public-page signals are held. The example returns `true` when Complianz does not allow Marketing:

```php
return $held
	|| ! meta_for_woocommerce_complianz_allows_marketing();
```

The existing `$held` value is preserved. The Complianz integration can add a hold, but it does not force signals to become active when another integration has already held them.

## How the browser setup works

The Complianz bridge is attached after Meta for WooCommerce's signal helper:

```php
wp_add_inline_script(
	'wc-facebook-signals',
	$script,
	'after'
);
```

This makes `window.fbwcsignal` available before the bridge runs. The `wp_enqueue_scripts` hook uses priority `20` because Meta for WooCommerce normally enqueues its helper at the default priority before the integration attaches the inline script.

### Responding to Complianz

The bridge listens for:

```text
cmplz_status_change
```

Complianz dispatches this event when a category value changes. The bridge ignores other categories and reads the Marketing result from:

```js
event.detail.category === 'marketing'
event.detail.value === 'allow'
```

It calculates the desired Meta state and compares it with the current state:

```js
if ( window.fbwcsignal.getState() === desired ) {
	return;
}
```

This avoids unnecessary AJAX requests and prevents an initial denial from causing a reload when signals are already held.

### Releasing Signals

When Marketing is allowed, the bridge calls:

```js
window.fbwcsignal.release();
```

Release changes the state to `active` and allows Meta for WooCommerce to send eligible recent events held during the current page visit. The page does not reload.

### Holding Signals

When Marketing is denied or withdrawn, the bridge calls:

```js
window.fbwcsignal.hold();
```

`window.fbwcsignal.hold()` revokes browser consent immediately, before the state request completes, so nothing fires while that request is in flight. If a release was still in progress when the hold happened, its response is discarded when it arrives: consent stays revoked, nothing is replayed, and events the server had already accepted are dropped from the browser queue so they are not posted twice.

The page reloads after the hold request so the next request starts in the held state. Complianz can also reload after Marketing is withdrawn. The bridge keeps its own reload because some Complianz flows do not reload when changing from a default opt-out state to an explicit denial.

The error callback also reloads because the backend will recalculate the state through Complianz when the new request reaches WordPress.

## What happens during each visitor flow

### First visit

On each request that reaches WordPress:

1. The backend asks Complianz whether Marketing is allowed for that visitor and region.
2. It sets Meta Signals to `active` or `held` before Meta for WooCommerce processes public-page events.
3. If signals are held, eligible current-page event data can be kept temporarily in the browser's memory.

In an opt-in region, a visitor who has not made a choice normally begins held. In an opt-out region, Complianz may report Marketing as allowed before the visitor makes a choice, so the page can begin active. Configure Complianz according to the site's requirements before relying on this integration.

If a visitor leaves a held page before giving permission, the held browser events are not saved or sent. They are lost when the page closes.

### The visitor allows Marketing

When the visitor allows Marketing:

1. Complianz stores the Marketing result.
2. Complianz dispatches `cmplz_status_change` with `category: marketing` and `value: allow`.
3. The integration calls `window.fbwcsignal.release()` if signals are not already active.
4. Meta for WooCommerce changes `wc_facebook_signals_state` to `active`.
5. The plugin can send eligible recent events held during that same page visit.
6. Signals remain active on later pages while Complianz continues to allow Marketing.

Release does not recover events from an earlier page visit. It can only send eligible events still available from the current page.

### The visitor denies Marketing

When the visitor denies Marketing:

1. Complianz stores the denial.
2. Complianz dispatches `cmplz_status_change` with `category: marketing` and `value: deny`.
3. If signals are already held, the integration does not make another hold request.
4. Meta Pixel and eligible public-page Conversions API events remain held.

### The visitor withdraws Marketing permission

When a visitor changes Marketing from allowed to denied:

1. The integration calls `window.fbwcsignal.hold()`.
2. Meta for WooCommerce changes `wc_facebook_signals_state` to `held`.
3. The integration reloads the page after the hold request finishes.
4. The reloaded page asks Complianz for the current Marketing result and begins with signals held.

The reload does not retract events already sent earlier on the active page. It ensures that subsequent visitor activity is processed from a page that started in the held state.

### The visitor grants permission again

If the visitor later allows Marketing again:

1. Complianz records the new choice.
2. The integration calls `release()`.
3. Meta Signals become `active`.
4. Eligible recent events from the current page can be sent.
5. Signals continue normally on later pages.

## How to verify the setup

Test in a private browser window so existing Complianz and Meta cookies do not affect the result. These Console commands show the current Complianz result and Meta state:

```js
window.cmplz_has_consent( 'marketing' );
window.fbwcsignal.getState();
```

Check these scenarios:

| Scenario | Expected result |
| :--- | :--- |
| Complianz denies Marketing | `cmplz_has_consent( 'marketing' )` is `false` and `getState()` returns `held`. Eligible public-page events are not sent immediately. |
| Complianz allows Marketing | Both checks indicate permission/`active`. The Network panel can show `wc_facebook_update_signals_state` followed by `facebook_release_signals`. |
| Marketing is withdrawn | The page reloads and `getState()` returns `held`. Events sent before withdrawal are not retracted. |
| A later page loads | The page starts in the state that matches Complianz's current Marketing result. |

Also confirm that `window.FacebookSignals` exists while signals are held. If it is missing, review the overlapping script-blocking settings described earlier.

## Verify Conversions API events in WooCommerce logs

Browser developer tools cannot show the final server-to-server request from WordPress to Meta. To check it:

1. Go to **Marketing > Facebook**, enable **Enable debug mode**, and save. Leave request headers excluded. Treat the entire log as sensitive because connection details, including access credentials, may still appear in request URLs.
2. While signals are `held`, generate a controlled public-page event and confirm the latest `facebook_for_woocommerce` log does not contain its `/events` request.
3. Allow Marketing on the same page, then confirm the log contains the released event name or identifier and Meta's response.
4. Do not share the log. Turn debug mode off after testing because logs can grow and may contain event payloads or sensitive connection data.

Other plugin and backend activity may appear in the log, so compare the controlled event's name, time or event identifier. Complete request and response information may be unavailable if a custom integration enables non-blocking server requests through `wc_facebook_pixel_events_non_blocking`. Meta Events Manager can provide an additional final check.

## Test cached pages

A full-page cache can serve HTML without running the must-use plugin. After clearing the cache, repeat the denied, grant and withdrawal checks on cached pages. Confirm that each cached page begins in the state expected from the Complianz configuration.

If the result differs, check whether caching or performance tools delay or reorder Complianz, `wc-facebook-signals` or the bridge, or serve expired WordPress AJAX nonces. Adjust that configuration before using the setup in production.

## Troubleshooting

| Problem | What to check |
| :--- | :--- |
| The must-use plugin does not appear in WordPress | Confirm that the PHP file is directly inside `wp-content/mu-plugins`, not in a nested folder. |
| `window.fbwcsignal` is undefined | Confirm that Meta for WooCommerce is connected and `wc-facebook-signals` is enqueued. Check for earlier JavaScript errors. |
| `window.FacebookSignals` is undefined while held | Disable Complianz's automatic Facebook for WooCommerce script integration for this setup, check custom Script Center rules and clear cached pages. |
| `window.cmplz_has_consent` is undefined | Confirm that the current Complianz banner script is loaded and the Complianz setup is complete. |
| Signals remain held after permission | Confirm that Complianz reports `cmplz_has_consent( 'marketing' )` as `true` and dispatches a Marketing `cmplz_status_change` event. |
| Signals are active unexpectedly before a choice | Check Complianz's regional consent type. In an opt-out configuration, Complianz can allow Marketing when no category cookie exists. |
| Granting permission does not release events | Check the two WordPress AJAX requests and the browser Console for a release error. |
| Withdrawing permission does not reload | Confirm that Complianz dispatches `cmplz_status_change` with `category: marketing` and `value: deny`. |
| The page repeatedly reloads | Check whether Complianz saves the updated choice and whether `wc_facebook_signals_state` can be written. |
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
* A different Complianz category, older Complianz version or future API change may require adapting the integration.

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

Hold/Release Signals does not make a site automatically compliant with privacy laws. It provides a technical way to connect Complianz's Marketing result to Meta for WooCommerce signal delivery.

The website owner remains responsible for determining:

* Whether and when permission is required.
* How the consent banner is presented.
* Which Complianz category should control Meta Signals.
* How Complianz regions and consent types are configured.
* How third-party scripts and custom integrations respond to the visitor's choice.

Third-party tools may interfere with or bypass this setup, so the complete site should be tested before the integration is used in production.
