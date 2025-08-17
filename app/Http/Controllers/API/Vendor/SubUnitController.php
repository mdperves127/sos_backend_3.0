<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Unit;
use App\Models\SubUnit;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

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
            'message' => SubUnit::latest()->where('user_id',Auth::id())->with(['unit' => function($query) {
                $query->select('id', 'unit_name');
            }])->get(),
            // 'units' => Unit::whereStatus('active')->latest()->select('id','unit_name')->get(),
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
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'validation_errors' => $validator->messages(),
            ]);
        }

        SubUnit::create([
            'user_id' => Auth::id(),
            'vendor_id' => vendorId(),
            'unit_id' => $request->unit_id,
            'subunit_name' => $request->subunit_name,
            'subunit_slug' => Str::slug($request->subunit_name),
            'status' => $request->status,
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
            'subunit_name' => 'required|unique:sub_units,subunit_name,'.$id,
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            SubUnit::find($id)->update(
                [
                    'unit_id' => $request->unit_id,
                    'subunit_name' => $request->subunit_name,
                    'subunit_slug' => Str::slug($request->subunit_name),
                    'status' => $request->status,
                ]
            );
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
        SubUnit::find($id)->delete();
        return response()->json([
            'status' => 200,
            'message' => 'Sub-unit Deleted Successfully !',
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
        $data = SubUnit::find($id);
        $data->status = $data->status == 'active' ? 'deactive':'active';
        $data->save();

        if($data->status == 'active'){
            return response()->json([
                'status' => 200,
                'message' => 'Sub-unit Active Successfully !',
            ]);
        }else{
            return response()->json([
                'status' => 200,
                'message' => 'Sub-unit Deactive Successfully !',
            ]);
        }

    }
}
