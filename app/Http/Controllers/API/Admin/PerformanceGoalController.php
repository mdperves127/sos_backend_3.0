<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PerformanceGoalRequest;
use App\Models\ConversionLocation;
use App\Models\PerfomanceGoal;
use Illuminate\Http\Request;

class PerformanceGoalController extends Controller {
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        $goals = PerfomanceGoal::latest()
            ->with( 'category', 'conversionLocation' )
            ->paginate( 10 );
        return $this->response( $goals );
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store( PerformanceGoalRequest $request ) {
        PerfomanceGoal::create( $request->validated() );
        return $this->response( 'Goal created successfully.' );
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show( PerfomanceGoal $goal ) {
        return $this->response( $goal );
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update( PerformanceGoalRequest $request, PerfomanceGoal $goal ) {
        if ( !$goal ) {
            return responsejson( 'Not found !', 'fail' );
        }
        $goal->update( $request->validated() );
        return $this->response( $goal );
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy( PerfomanceGoal $goal ) {

        if ( !$goal ) {
            return responsejson( 'Not found !', 'fail' );
        }

        $goal->delete();

        return $this->response( 'Deleted successfully.' );
    }

    public function getConversionLocation( $id ) {
        $data = ConversionLocation::where( 'campaign_category_id', $id )->get();
        return response()->json( [
            "status"    => 200,
            "locations" => $data,
        ] );
    }
}
