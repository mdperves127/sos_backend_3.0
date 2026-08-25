<?php

namespace App\Observers;

use App\Enums\Status;
use App\Models\Product;

class ProductObserver
{
    /**
     * Handle the Product "created" event.
     *
     * @param  \App\Models\Product  $product
     * @return void
     */
    public function created(Product $product)
    {
        //
    }

    /**
     * Handle the Product "updated" event.
     *
     * @param  \App\Models\Product  $product
     * @return void
     */
    public function updated(Product $product)
    {
        // Only affiliate products require admin re-approval after key field changes.
        if ( (int) $product->is_affiliate !== 1 ) {
            return;
        }

        if ( $product->isDirty( [
            'category_id', 'subcategory_id', 'brand_id', 'user_id', 'name', 'slug',
            'short_description', 'long_description', 'selling_price', 'original_price',
            'meta_title', 'meta_keyword', 'meta_description', 'tags', 'discount_type',
            'selling_details', 'advance_payment',
        ] ) ) {
            $product->status = Status::Pending->value;
            $product->saveQuietly();
        }
    }

    /**
     * Handle the Product "deleted" event.
     *
     * @param  \App\Models\Product  $product
     * @return void
     */
    public function deleted(Product $product)
    {
        //
    }

    /**
     * Handle the Product "restored" event.
     *
     * @param  \App\Models\Product  $product
     * @return void
     */
    public function restored(Product $product)
    {
        //
    }

    /**
     * Handle the Product "force deleted" event.
     *
     * @param  \App\Models\Product  $product
     * @return void
     */
    public function forceDeleted(Product $product)
    {
        //
    }
}
