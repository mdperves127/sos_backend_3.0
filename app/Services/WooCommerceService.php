<?php

namespace App\Services;

use App\Models\WoocommerceCredential;
use GuzzleHttp\Client;

class WooCommerceService {

    public function wcCredential() {
        return WoocommerceCredential::where( 'vendor_id', vendorId() )->first();
    }

    public function getProducts() {
        // Make the API request to get products
        $response = $this->client->request( 'GET', 'products', [
            'auth' => [$this->wcCredential->wc_key, $this->wcCredential->wc_secret],
        ] );

        return json_decode( $response->getBody()->getContents(), true );
    }
}
