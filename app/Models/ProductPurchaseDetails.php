<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductPurchaseDetails extends Model
{
    use HasFactory,SoftDeletes;

    protected $connection = 'tenant';
    protected $table = 'product_purchase_details';
    protected $guarded = [];

    public function color()
    {
        return $this->belongsTo(Color::class, 'color_id')->select('id', 'name');
    }

    public function product()
    {
        return $this->belongsTo( Product::class, 'product_id' )
            ->select( 'id', 'name', 'sku', 'image', 'variants', 'original_price', 'selling_price', 'qty' );
    }

    public function productVariant()
    {
        return $this->belongsTo( ProductVariant::class, 'variant_id' )
            ->select( 'id', 'product_id', 'unit_id', 'size_id', 'color_id', 'qty', 'rate' );
    }

    /**
     * Resolve rich variant payload (label/key/options/price) from products.variants JSON.
     */
    public function resolveVariantPayload(): ?array
    {
        $variantId = (int) ( $this->variant_id ?? 0 );
        if ( $variantId <= 0 ) {
            return null;
        }

        $jsonVariants = [];
        if ( $this->relationLoaded( 'product' ) && $this->product ) {
            $jsonVariants = is_array( $this->product->variants ) ? $this->product->variants : [];
        }

        foreach ( $jsonVariants as $jsonVariant ) {
            $jsonVariant = (array) $jsonVariant;
            $jsonId      = (int) ( $jsonVariant['variant_id'] ?? $jsonVariant['id'] ?? 0 );
            if ( $jsonId === $variantId ) {
                return [
                    'id'         => $variantId,
                    'variant_id' => $variantId,
                    'key'        => $jsonVariant['key'] ?? null,
                    'label'      => $jsonVariant['label'] ?? null,
                    'sku'        => $jsonVariant['sku'] ?? null,
                    'image'      => $jsonVariant['image'] ?? null,
                    'qty'        => isset( $jsonVariant['qty'] ) ? (int) $jsonVariant['qty'] : ( $this->productVariant->qty ?? null ),
                    'price'      => isset( $jsonVariant['price'] )
                        ? (float) $jsonVariant['price']
                        : (float) ( $jsonVariant['rate'] ?? $this->productVariant->rate ?? $this->rate ?? 0 ),
                    'rate'       => isset( $jsonVariant['rate'] )
                        ? (float) $jsonVariant['rate']
                        : (float) ( $jsonVariant['price'] ?? $this->productVariant->rate ?? $this->rate ?? 0 ),
                    'options'    => array_values( (array) ( $jsonVariant['options'] ?? [] ) ),
                    'unit_id'    => $jsonVariant['unit_id'] ?? $this->unit_id,
                    'size_id'    => $jsonVariant['size_id'] ?? $this->size_id,
                    'color_id'   => $jsonVariant['color_id'] ?? $this->color_id,
                ];
            }
        }

        if ( $this->relationLoaded( 'productVariant' ) && $this->productVariant ) {
            $db = $this->productVariant;

            return [
                'id'         => (int) $db->id,
                'variant_id' => (int) $db->id,
                'key'        => null,
                'label'      => null,
                'sku'        => null,
                'image'      => null,
                'qty'        => (int) ( $db->qty ?? 0 ),
                'price'      => (float) ( $db->rate ?? 0 ),
                'rate'       => (float) ( $db->rate ?? 0 ),
                'options'    => [],
                'unit_id'    => $db->unit_id,
                'size_id'    => $db->size_id,
                'color_id'   => $db->color_id,
            ];
        }

        return [
            'id'         => $variantId,
            'variant_id' => $variantId,
            'key'        => null,
            'label'      => null,
            'sku'        => null,
            'image'      => null,
            'qty'        => null,
            'price'      => (float) ( $this->rate ?? 0 ),
            'rate'       => (float) ( $this->rate ?? 0 ),
            'options'    => [],
            'unit_id'    => $this->unit_id,
            'size_id'    => $this->size_id,
            'color_id'   => $this->color_id,
        ];
    }

    public function size()
    {
        return $this->belongsTo(Size::class, 'size_id')->select('id', 'name');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id')->select('id', 'unit_name');
    }
}
