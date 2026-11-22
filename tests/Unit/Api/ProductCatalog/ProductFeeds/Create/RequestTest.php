<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\API\ProductCatalog\ProductFeeds\Create;

use WC_Facebook_Product;
use WC_Helper_Product;
use WP_UnitTestCase;

/**
 * Api unit test clas.
 */
class RequestTest extends WP_UnitTestCase {
	/**
	 * @return void
	 */
	public function test_request() {
		$product          = WC_Helper_Product::create_simple_product();
		$facebook_product = new WC_Facebook_Product( $product );
		$product_group_id = 'facebook-product-group-id';
		$data             = $facebook_product->prepare_product();
		$request          = new Request( $product_group_id, $data );

		$this->assertEquals( 'POST', $request->get_method() );
		$this->assertEquals( '/facebook-product-group-id/product_feeds', $request->get_path() );
		$this->assertEquals( $data, $request->get_data() );
	}
}
