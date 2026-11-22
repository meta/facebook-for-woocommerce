<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\API\ProductCatalog\ProductFeeds\Create;

use WP_UnitTestCase;

/**
 * Api unit test clas.
 */
class ResponseTest extends WP_UnitTestCase {
	/**
	 * @return void
	 */
	public function test_request() {
		$json     = '{"id":"facebook-product-id"}';
		$response = new Response( $json );

		$this->assertEquals( 'facebook-product-id', $response->id );
	}
}
