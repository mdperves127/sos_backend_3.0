<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class PosSalesDetails extends Model {
    use HasFactory;

    protected $guarded = [];

    public function product() {
        return $this->belongsTo( Product::class, 'product_id' )
            ->select( 'id', 'name', 'sku', 'variants', 'selling_price' );
    }

    public function productVariant() {
        return $this->belongsTo( ProductVariant::class, 'variant_id' )
            ->select( 'id', 'product_id', 'unit_id', 'size_id', 'color_id', 'qty', 'rate' );
    }

    public function bundle() {
        return $this->belongsTo( ProductBundle::class, 'bundle_id' );
    }

    public function size() {
        return $this->belongsTo( Size::class, 'size_id' )->select( 'id', 'name' );
    }

    public function unit() {
        return $this->belongsTo( Unit::class, 'unit_id' )->select( 'id', 'unit_name' );
    }

    public function color() {
        return $this->belongsTo( Color::class, 'color_id' )->select( 'id', 'name' );
    }

    public function posSale() {
        return $this->belongsTo( PosSales::class, 'id' );
    }

    /**
     * Resolve display label + options from products.variants JSON / relations.
     *
     * @return array{id:?int,variant_id:?int,label:?string,sku:?string,options:array<int,mixed>,unit_id:mixed,size_id:mixed,color_id:mixed}
     */
    public function resolveVariantPayload(): array
    {
        $variantId = (int) ( $this->variant_id ?? 0 );
        $jsonVariants = [];

        if ( $this->relationLoaded( 'product' ) && $this->product ) {
            $jsonVariants = is_array( $this->product->variants ) ? $this->product->variants : [];
        }

        if ( $variantId > 0 ) {
            foreach ( $jsonVariants as $jsonVariant ) {
                $jsonVariant = (array) $jsonVariant;
                $jsonId      = (int) ( $jsonVariant['variant_id'] ?? $jsonVariant['id'] ?? 0 );
                if ( $jsonId !== $variantId ) {
                    continue;
                }

                $options = array_values( (array) ( $jsonVariant['options'] ?? [] ) );
                $label   = trim( (string) ( $jsonVariant['label'] ?? '' ) );
                if ( $label === '' && $options !== [] ) {
                    $label = collect( $options )
                        ->map( fn ( $opt ) => is_array( $opt ) ? ( $opt['value'] ?? '' ) : '' )
                        ->filter()
                        ->implode( ' / ' );
                }

                return [
                    'id'         => $variantId,
                    'variant_id' => $variantId,
                    'label'      => $label !== '' ? $label : ( $this->variant_label ?: null ),
                    'sku'        => $jsonVariant['sku'] ?? null,
                    'options'    => $options,
                    'unit_id'    => $jsonVariant['unit_id'] ?? $this->unit_id,
                    'size_id'    => $jsonVariant['size_id'] ?? $this->size_id,
                    'color_id'   => $jsonVariant['color_id'] ?? $this->color_id,
                ];
            }
        }

        // Legacy / fallback: match by unit/size/color attrs inside JSON.
        if ( $jsonVariants !== [] ) {
            foreach ( $jsonVariants as $jsonVariant ) {
                $jsonVariant = (array) $jsonVariant;
                $unitMatch  = (int) ( $jsonVariant['unit_id'] ?? 0 ) === (int) ( $this->unit_id ?? 0 );
                $sizeMatch  = (int) ( $jsonVariant['size_id'] ?? 0 ) === (int) ( $this->size_id ?? 0 );
                $colorMatch = (int) ( $jsonVariant['color_id'] ?? 0 ) === (int) ( $this->color_id ?? 0 );
                if ( ! $unitMatch || ! $sizeMatch || ! $colorMatch ) {
                    continue;
                }

                $options = array_values( (array) ( $jsonVariant['options'] ?? [] ) );
                $label   = trim( (string) ( $jsonVariant['label'] ?? '' ) );
                if ( $label === '' && $options !== [] ) {
                    $label = collect( $options )
                        ->map( fn ( $opt ) => is_array( $opt ) ? ( $opt['value'] ?? '' ) : '' )
                        ->filter()
                        ->implode( ' / ' );
                }

                return [
                    'id'         => (int) ( $jsonVariant['variant_id'] ?? $jsonVariant['id'] ?? 0 ) ?: null,
                    'variant_id' => (int) ( $jsonVariant['variant_id'] ?? $jsonVariant['id'] ?? 0 ) ?: null,
                    'label'      => $label !== '' ? $label : ( $this->variant_label ?: null ),
                    'sku'        => $jsonVariant['sku'] ?? null,
                    'options'    => $options,
                    'unit_id'    => $this->unit_id,
                    'size_id'    => $this->size_id,
                    'color_id'   => $this->color_id,
                ];
            }
        }

        $legacyLabel = collect( [
            $this->unit?->unit_name,
            $this->color?->name,
            $this->size?->name,
        ] )->filter()->implode( ' / ' );

        return [
            'id'         => $variantId > 0 ? $variantId : null,
            'variant_id' => $variantId > 0 ? $variantId : null,
            'label'      => $this->variant_label
                ?: ( $legacyLabel !== '' ? $legacyLabel : null ),
            'sku'        => null,
            'options'    => [],
            'unit_id'    => $this->unit_id,
            'size_id'    => $this->size_id,
            'color_id'   => $this->color_id,
        ];
    }

    public static function supportsVariantColumns(): bool
    {
        static $cached = null;
        if ( $cached !== null ) {
            return $cached;
        }

        $cached = Schema::hasColumn( 'pos_sales_details', 'variant_id' );

        return $cached;
    }
}
