=== Meta for WooCommerce ===
Contributors: facebook
Tags: meta, facebook, whatsapp, conversions api, catalog sync
Requires at least: 5.6
Tested up to: 7.1
Stable tag: 3.7.8
Requires PHP: 7.4
MySQL: 5.6 or greater
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reach more customers and drive sales on Facebook, Instagram and WhatsApp with the official Meta for WooCommerce plugin.

== Description ==

This is the official Meta for WooCommerce plugin that connects your WooCommerce website to Facebook, Instagram and WhatsApp. With this plugin, you can install the Facebook pixel, upload your online store catalog, enabling you to easily run dynamic ads and connect your WhatsApp Business account to automatically update customers about their orders.


Marketing on Meta platforms helps your business build lasting relationships with people, find new customers, and increase sales for your online store. With this Facebook ad extension, reaching the people who matter most to your business is simple. This extension will track the results of your advertising across devices. It will also help you:

* Maximize your campaign performance. By setting up the Facebook pixel and building your audience, you will optimize your ads for people likely to buy your products, and reach people with relevant ads on Facebook after they’ve visited your website.
* Find more customers. Connecting your product catalog automatically creates carousel ads that showcase the products you sell and attract more shoppers to your website.
* Generate sales among your website visitors. When you set up the Facebook pixel and connect your product catalog, you can use dynamic ads to reach shoppers when they're on Facebook with ads for the products they viewed on your website. This will be included in a future release of Meta for WooCommerce.
* Engage with customers on WhatsApp by updating your customers about their orders at every step, freeing up more time for you to focus on your business.

== Installation ==

Visit the Facebook Help Center [here](https://www.facebook.com/business/help/900699293402826).

== Support ==

Before raising a question with Meta Support, please first take a look at the Meta [helpcenter docs](https://www.facebook.com/business/help), by searching for keywords like 'WooCommerce' here. If you didn't find what you were looking for, you can go to [Meta Direct Support](https://www.facebook.com/business-support-home) and ask your question.

When reporting an issue on Meta Direct Support, please give us as many details as possible.
* Symptoms of your problem
* Screenshot, if possible
* Your Facebook page URL
* Your website URL
* Current version of Facebook-for-WooCommerce, WooCommerce, Wordpress, PHP

To suggest technical improvements, you can raise an issue on our [Github repository](https://github.com/facebook/facebook-for-woocommerce/issues).

== Known limitations ==

Crash recovery uses a shutdown handler to write a disable flag and queue a sanitized crash report.
In rare PHP memory-exhaustion fatals, there may be too little memory left for the shutdown handler to run.
When that happens, the site still recovers on the next request, but the disable flag and crash report may be skipped for that request.

== Changelog ==

= 3.7.9 - 2026-11-16 =
* Update - Remove unused /fb-checkout endpoint; Meta checkout calls WooCommerce's /checkout-link (WC 10.0+) by @jczhuoMeta in #4082
* Dev - Use a real order in the WCPOS checkout-screen contract test by @pinahar in #4095
* Dev - Load Param Builder Client JS from unversioned CDN URLs (#4094) by @pinahar in #4094
* Fix - Respect signal hold during admin AJAX requests by @vahidkay-meta in #4097
* Fix - Ignore array-shaped attribution cookies instead of crashing the request by @ukilla in #4105
* Fix - Fix shipping profiles feed header written with the wrong delimiter (#4109) by @rafael-curran in #4109
* Fix - Fix crash guard input crashes and redesign the shutdown handler by @ukilla in #4102
* Fix - Keep net_revenue out of browser Pixel Purchase events by @vahidkay-meta in #4113
* Fix - Keep saved exclusions visible on the Product sync tab in any language by @ukilla in #4084
* Fix - Fix background sync nonce user context by @ukilla in #4087
* Breaking - Remove the Product sync tab and retire sync exclusions by @vahidkay-meta in #4118
* Dev - Download E2E WordPress dependencies once and share them with all shards by @vahidkay-meta in #4089
* Dev - Consolidate open Dependabot dependency bumps by @vahidkay-meta in #4114
* Update - Move Graph API calls from v21.0 to v23.0 by @vahidkay-meta in #4052
* Tweak - Fix PHP unit test CI on MySQL 8.4 and remove the Value Optimization rollout switch by @vahidkay-meta in #4127
* Fix - Fix add to cart fragment replay by @ukilla in #4081
* Fix - Stop logging WhatsApp order events on stores that never connected WA by @ukilla in #4080
* Dev - Add E2E and Integration Tests status badges to README by @vahidkay-meta in #4128
* Fix - Stop reporting PageView on 404 responses by @vahidkay-meta in #4057
* Fix - Fix withdrawal during release by @ukilla in #4100
* Fix - Fix subscription renewal contents by @ukilla in #4078
* Fix - Send the real page URL as event_source_url when home_url() has a path by @ukilla in #4085

[See changelog for all versions](https://raw.githubusercontent.com/facebook/facebook-for-woocommerce/refs/heads/main/changelog.txt).

== Terms of Use ==

Your use of this project is governed by [Meta's Terms of Use](https://opensource.fb.com/legal/terms).

== Privacy Policy ==

For information about how Meta collects and uses data, please review [Meta's Privacy Policy](https://opensource.fb.com/legal/privacy).

== Copyright ==

Copyright (c) Meta Platforms, Inc. and affiliates. All Rights Reserved.

Meta for WooCommerce is distributed under the terms of the GNU General Public License v2.0 (or later). See the LICENSE file in the plugin's source repository for the full license text.
