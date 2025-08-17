<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Warehouse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
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
            'warehouses' => Warehouse::where('vendor_id',vendorId())->latest()->get(),
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
        // $otherUserIds = [vendorId()];
        // $validator = Validator::make($request->all(), [
        //     'name' => 'required',
        //     'name' => [
        //         'required',
        //         Rule::unique('warehouses')->where(function ($query) use ($otherUserIds) {
        //             return $query->whereIn('vendor_id', $otherUserIds);
        //         })
        //     ],
        // ]);

        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:warehouses,name,NULL,id,vendor_id,'.vendorId(),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'validation_errors' => $validator->messages(),
            ]);
        }

        Warehouse::create([
            'user_id' => Auth::id(),
            'vendor_id' => vendorId(),
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'description' => $request->description,
            'status' => $request->status,
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Warehouse Added Successfully!'
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
            'message' => Warehouse::find($id),
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
        // $currentUserId = vendorId();
        // $validator = Validator::make($request->all(), [
        //     'name' => [
        //         'required',
        //         Rule::unique('warehouses')->where(function ($query) use ($currentUserId) {
        //             return $query->where('vendor_id', $currentUserId);
        //         })->ignore($currentUserId),
        //     ]
        // ]);

        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:warehouses,name,'.$id.',id,vendor_id,'.vendorId(),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'validation_errors' => $validator->messages(),
            ]);
        }else{

            Warehouse::find($id)->update([
                'name' => $request->name,
                'slug' => Str::slug($request->name),
                'description' => $request->description,
                'status' => $request->status,
            ]);

            return response()->json([
                'status'  => 200,
                'message' => 'Warehouse Updated Successfully !',
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
        Warehouse::find($id)->delete();
        return response()->json([
            'status' => 200,
            'message' => 'Warehouse Deleted Successfully !',
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
        $data = Warehouse::find($id);
        $data->status = $data->status == 'active' ? 'deactive':'active';
        $data->save();

        if($data->status == 'active'){
            return response()->json([
                'status' => 200,
                'message' => 'Warehouse Active Successfully !',
            ]);
        }else{
            return response()->json([
                'status' => 200,
                'message' => 'Warehouse Deactive Successfully !',
            ]);
        }

    }
}
