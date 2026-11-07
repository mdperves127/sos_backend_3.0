<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\VariantAttribute;
use App\Models\VariantAttributeValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VariantAttributeController extends Controller
{
    public function index()
    {
        $items = VariantAttribute::query()
            ->with( 'values:id,variant_attribute_id,value,sort_order' )
            ->when( request( 'status' ) === 'active', function ( $q ) {
                return $q->where( 'status', 'active' );
            } )
            ->latest()
            ->get();

        return response()->json( [
            'status'   => 200,
            'variants' => $items,
        ] );
    }

    public function store( Request $request )
    {
        $validator = Validator::make( $request->all(), [
            'name'     => [
                'required',
                'string',
                'max:255',
                Rule::unique( 'variant_attributes', 'name' )
                    ->where( fn ( $q ) => $q->whereNull( 'deleted_at' ) ),
            ],
            'status'   => 'nullable|in:active,inactive',
            'values'   => 'required|array|min:1',
            'values.*' => 'required|string|max:255',
        ], [
            'values.required' => 'At least one value is required under this variant name.',
            'values.min'      => 'At least one value is required under this variant name.',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ], 400 );
        }

        $values = $this->normalizeValues( $request->input( 'values', [] ) );

        if ( $values === [] ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'At least one value is required under this variant name.',
                'errors'  => ['values' => ['At least one value is required under this variant name.']],
            ], 400 );
        }

        $attribute = DB::transaction( function () use ( $request, $values ) {
            $attribute = VariantAttribute::create( [
                'name'   => trim( (string) $request->input( 'name' ) ),
                'slug'   => $this->uniqueSlug( (string) $request->input( 'name' ) ),
                'status' => $request->input( 'status', 'active' ),
            ] );

            $this->syncValues( $attribute, $values );

            return $attribute->load( 'values:id,variant_attribute_id,value,sort_order' );
        } );

        return response()->json( [
            'status'  => 200,
            'message' => 'Variant added successfully.',
            'variant' => $attribute,
        ] );
    }

    public function edit( $id )
    {
        $attribute = VariantAttribute::query()
            ->with( 'values:id,variant_attribute_id,value,sort_order' )
            ->find( $id );

        if ( ! $attribute ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Variant Id Found',
            ], 404 );
        }

        return response()->json( [
            'status'  => 200,
            'variant' => $attribute,
        ] );
    }

    public function update( Request $request, $id )
    {
        $attribute = VariantAttribute::query()->find( $id );

        if ( ! $attribute ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Variant Id Found',
            ], 404 );
        }

        $validator = Validator::make( $request->all(), [
            'name'     => [
                'required',
                'string',
                'max:255',
                Rule::unique( 'variant_attributes', 'name' )
                    ->ignore( $id )
                    ->where( fn ( $q ) => $q->whereNull( 'deleted_at' ) ),
            ],
            'status'   => 'nullable|in:active,inactive',
            'values'   => 'required|array|min:1',
            'values.*' => 'required|string|max:255',
        ], [
            'values.required' => 'At least one value is required under this variant name.',
            'values.min'      => 'At least one value is required under this variant name.',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ], 400 );
        }

        $values = $this->normalizeValues( $request->input( 'values', [] ) );

        if ( $values === [] ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'At least one value is required under this variant name.',
                'errors'  => ['values' => ['At least one value is required under this variant name.']],
            ], 400 );
        }

        $attribute = DB::transaction( function () use ( $request, $attribute, $values ) {
            $attribute->name = trim( (string) $request->input( 'name' ) );
            $attribute->slug = $this->uniqueSlug( (string) $request->input( 'name' ), $attribute->id );

            if ( $request->has( 'status' ) ) {
                $attribute->status = $request->input( 'status' );
            }

            $attribute->save();

            $this->syncValues( $attribute, $values );

            return $attribute->load( 'values:id,variant_attribute_id,value,sort_order' );
        } );

        return response()->json( [
            'status'  => 200,
            'message' => 'Variant updated successfully.',
            'variant' => $attribute,
        ] );
    }

    public function destroy( $id )
    {
        $attribute = VariantAttribute::query()->find( $id );

        if ( ! $attribute ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Variant Id Found',
            ], 404 );
        }

        DB::transaction( function () use ( $attribute ) {
            $attribute->values()->delete();
            $attribute->delete();
        } );

        return response()->json( [
            'status'  => 200,
            'message' => 'Variant deleted successfully.',
        ] );
    }

    /**
     * Append values to an existing variant attribute without replacing others.
     */
    public function addValues( Request $request, $id )
    {
        $attribute = VariantAttribute::query()->find( $id );

        if ( ! $attribute ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Variant Id Found',
            ], 404 );
        }

        $validator = Validator::make( $request->all(), [
            'values'   => 'required|array|min:1',
            'values.*' => 'required|string|max:255',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ], 400 );
        }

        $incoming = $this->normalizeValues( $request->input( 'values', [] ) );
        $existing = $attribute->values()->pluck( 'value' )
            ->map( fn ( $value ) => Str::lower( trim( (string) $value ) ) )
            ->all();

        $toCreate = [];
        foreach ( $incoming as $value ) {
            $key = Str::lower( $value );
            if ( in_array( $key, $existing, true ) ) {
                continue;
            }
            $toCreate[] = $value;
            $existing[] = $key;
        }

        if ( $toCreate === [] ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'All provided values already exist for this variant.',
            ], 400 );
        }

        $sortStart = (int) $attribute->values()->max( 'sort_order' );

        foreach ( $toCreate as $index => $value ) {
            VariantAttributeValue::create( [
                'variant_attribute_id' => $attribute->id,
                'value'                => $value,
                'sort_order'           => $sortStart + $index + 1,
            ] );
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'Values added successfully.',
            'variant' => $attribute->fresh()->load( 'values:id,variant_attribute_id,value,sort_order' ),
        ] );
    }

    public function deleteValue( $id, $valueId )
    {
        $attribute = VariantAttribute::query()->find( $id );

        if ( ! $attribute ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Variant Id Found',
            ], 404 );
        }

        $value = $attribute->values()->where( 'id', $valueId )->first();

        if ( ! $value ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Value Id Found',
            ], 404 );
        }

        if ( $attribute->values()->count() <= 1 ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'A variant must keep at least one value.',
            ], 400 );
        }

        $value->delete();

        return response()->json( [
            'status'  => 200,
            'message' => 'Value deleted successfully.',
            'variant' => $attribute->fresh()->load( 'values:id,variant_attribute_id,value,sort_order' ),
        ] );
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizeValues( array $values ): array
    {
        $normalized = [];
        $seen       = [];

        foreach ( $values as $value ) {
            if ( is_array( $value ) ) {
                $value = $value['value'] ?? $value['name'] ?? null;
            }

            $value = trim( (string) $value );

            if ( $value === '' ) {
                continue;
            }

            $key = Str::lower( $value );
            if ( isset( $seen[$key] ) ) {
                continue;
            }

            $seen[$key]   = true;
            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * Replace attribute values with the provided ordered list (matched by value text).
     *
     * @param  array<int, string>  $values
     */
    private function syncValues( VariantAttribute $attribute, array $values ): void
    {
        $existing = $attribute->values()->get()->keyBy( fn ( $row ) => Str::lower( $row->value ) );
        $keepIds  = [];

        foreach ( $values as $index => $value ) {
            $key = Str::lower( $value );

            if ( $existing->has( $key ) ) {
                $row             = $existing->get( $key );
                $row->value      = $value;
                $row->sort_order = $index;
                $row->save();
                $keepIds[] = $row->id;
                continue;
            }

            $created = VariantAttributeValue::create( [
                'variant_attribute_id' => $attribute->id,
                'value'                => $value,
                'sort_order'           => $index,
            ] );
            $keepIds[] = $created->id;
        }

        $attribute->values()
            ->whereNotIn( 'id', $keepIds )
            ->delete();
    }

    private function uniqueSlug( string $name, ?int $ignoreId = null ): string
    {
        $base = Str::slug( $name ) ?: 'variant';
        $slug = $base;
        $i    = 1;

        while (
            VariantAttribute::query()
                ->when( $ignoreId, fn ( $q ) => $q->where( 'id', '!=', $ignoreId ) )
                ->where( 'slug', $slug )
                ->exists()
        ) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }
}
