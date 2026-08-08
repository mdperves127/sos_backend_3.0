<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Placement;
use Illuminate\Http\Request;

class PlacementController extends Controller
{
    public function index( $colum )
    {
        $placements = Placement::where( $colum, '!=', '' )
            ->select( 'id', $colum, 'campaign_category_id', 'status', 'colum_name' )
            ->when( $colum === 'add_format', function ( $query ) {
                $query->with( 'category' );
            } )
            ->get();

        return $this->response( $placements );
    }

    public function store( Request $request, $colum )
    {
        $request->validate( $this->rules( $colum ) );

        if ( ! $colum ) {
            return responsejson( 'Not found !', 'fail' );
        }

        Placement::create( [
            'colum_name'           => $colum,
            $colum                  => $request->input( $colum ),
            'campaign_category_id' => $request->campaign_category_id,
            'status'               => $request->input( 'status', 'active' ),
        ] );

        return $this->response( 'Added successfully.' );
    }

    public function show( $id, $colum )
    {
        $placement = Placement::select( 'id', $colum, 'campaign_category_id', 'status', 'colum_name' )
            ->with( 'category' )
            ->find( $id );

        if ( ! $placement ) {
            return responsejson( 'Not found !', 'fail' );
        }

        return $this->response( $placement );
    }

    public function update( $id, $colum )
    {
        $placement = Placement::find( $id );

        if ( ! $placement ) {
            return responsejson( 'Not found !', 'fail' );
        }

        $request->validate( $this->rules( $colum, false ) );

        $data = [
            'campaign_category_id' => request( 'campaign_category_id', $placement->campaign_category_id ),
        ];

        if ( request()->has( $colum ) ) {
            $data[$colum] = request( $colum );
        }

        if ( request()->has( 'status' ) ) {
            $data['status'] = request( 'status' );
        }

        $placement->update( $data );

        return $this->response( [
            'message' => 'Update successfull',
            'data'    => $placement->fresh( ['category'] ),
        ] );
    }

    public function delete( $id )
    {
        $placement = Placement::find( $id );

        if ( ! $placement ) {
            return responsejson( 'Not found !', 'fail' );
        }

        $placement->delete();

        return $this->response( 'Deleted successfully.' );
    }

    private function rules( string $colum, bool $requireColumnValue = true ): array
    {
        $rules = [
            'status' => 'nullable|in:active,inactive',
        ];

        if ( $requireColumnValue ) {
            $rules['colum_name'] = 'required';
            $rules[$colum]        = 'required';
        } else {
            $rules[$colum] = 'nullable|string';
        }

        if ( $colum === 'add_format' ) {
            $rules['campaign_category_id'] = ( $requireColumnValue ? 'required' : 'nullable' ) . '|exists:campaign_categories,id';
        } else {
            $rules['campaign_category_id'] = 'nullable|exists:campaign_categories,id';
        }

        return $rules;
    }
}
