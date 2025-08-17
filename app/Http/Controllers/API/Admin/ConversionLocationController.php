<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConverstionLocationRequest;
use App\Models\ConversionLocation;
use Illuminate\Http\Request;

class ConversionLocationController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $locations = ConversionLocation::latest()
        ->with('category')
        ->paginate(10);
        return $this->response($locations);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(ConverstionLocationRequest $request)
    {
        ConversionLocation::create($request->validated());
        return $this->response('Location created successfully.');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(ConversionLocation $location)
    {
        return $this->response($location);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(ConverstionLocationRequest $request, ConversionLocation $location)
    {
        if (!$location) {
            return responsejson('Not found !', 'fail');
        }
        $location->update($request->validated());
        return $this->response($location);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(ConversionLocation $location)
    {

        if (!$location) {
            return responsejson('Not found !', 'fail');
        }

        $location->delete();

        return $this->response('Deleted successfully.');
    }
}
