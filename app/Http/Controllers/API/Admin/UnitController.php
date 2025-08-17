<?php

namespace App\Http\Controllers\API\Admin;

use App\Models\Unit;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UnitController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return response()->json([
            'status' => 200,
            'units' => Unit::whereStatus('active')->latest()->get(),
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
            'unit_name' => 'required|unique:units|',
            'unit_slug' => 'required|unique:units|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'validation_errors' => $validator->messages(),
            ]);
        }

        Unit::create([
            'unit_name' => $request->unit_name,
            'unit_slug' => $request->unit_slug,
            'status' => 'active',
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Unit Added Successfully!'
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
            'message' => Unit::find($id),
        ]);
    }

    /**
     * Update the resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request , $id)
    {
        $validator = Validator::make($request->all(), [
            'unit_name' => 'required',
            'unit_slug' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            Unit::find($id)->update($request->all());
            return response()->json([
                'status'  => 200,
                'message' => 'Unit Updated Successfully !',
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
        Unit::find($id)->delete();
        return response()->json([
            'status' => 200,
            'message' => 'Unit Deleted Successfully !',
        ]);
    }

    /**
     * Change the resource status.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function status($id)
    {
        $data = Unit::find($id);
        $data->status = $data->status == 'active' ? 'inactive':'active';
        $data->save();

        if($data->status == 'active'){
            return response()->json([
                'status' => 200,
                'message' => 'Unit Active Successfully !',
            ]);
        }else{
            return response()->json([
                'status' => 200,
                'message' => 'Unit Deactive Successfully !',
            ]);
        }

    }
}
