<?php

namespace App\Http\Controllers\API\Admin;

use App\Models\SubUnit;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Unit;
use Illuminate\Support\Facades\Validator;

class SubUnitController extends Controller
{

    /**
     * Store the newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return response()->json([
            'status' => 200,
            'message' => SubUnit::whereStatus('active')->latest()->get(),
            'unit' => Unit::whereStatus('active')->latest()->get(),
        ]);
    }

    /**
     * Store the newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'unit_id' => 'required',
            'subunit_name' => 'required|unique:sub_units|string',
            'subunit_slug' => 'required|unique:sub_units|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'validation_errors' => $validator->messages(),
            ]);
        }

        SubUnit::create([
            'unit_id' => $request->unit_id,
            'subunit_name' => $request->subunit_name,
            'subunit_slug' => $request->subunit_slug,
            'status' => 'active',
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Sub-unit Added Successfully!'
        ]);
    }

    /**
     * Display the resource.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        return response()->json([
            'status' => 200,
            'message' => SubUnit::find($id),
            'unit' => Unit::whereStatus('active')->latest()->get(),
        ]);
    }

    /**
     * Update the resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'unit_id' => 'required',
            'subunit_name' => 'required',
            'subunit_slug' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            SubUnit::find($id)->update($request->all());
            return response()->json([
                'status'  => 200,
                'message' => 'Sub-unit Updated Successfully !',
            ]);
        }
    }

    /**
     * Remove the resource from storage.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        abort(404);
    }
}
