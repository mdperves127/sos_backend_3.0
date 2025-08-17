<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PartnerController extends Controller
{
    public function index()
    {
        if(checkpermission('partner') != 1){
            return $this->permissionmessage();
        }
        $pertner = Partner::latest()->get();
        return response()->json([
            'status' => 200,
            'data' => $pertner,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image'     => 'required|mimes:jpeg,png,jpg,svg',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            $data = $request->all();
            if($request->image){
                $data['image'] = fileUpload($request->image, 'uploads/partners/', 101, 40);
            }
            Partner::create($data);
            return response()->json([
                'status' => 200,
                'message' => 'Data Inserted Successfully !',
            ]);
        }
    }

    public function show($id)
    {
        $partner = Partner::find($id);
        if($partner){
            return response()->json([
                'status' => 200,
                'datas' => $partner,
            ]);
        }else{
            return response()->json([
                'status' => 404,
                'message' => 'No partner Data Found',
            ]);
        }
    }


    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'image'     => 'mimes:jpeg,png,jpg',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            $old_image = Partner::find($id);
            $data = $request->all();
            if ($request->image) {
                if ($old_image->image) {
                    unlink($old_image->image);
                }
                $data['image'] = fileUpload($request->image, 'uploads/partners/', 101, 40);
            }
            Partner::find($id)->update($data);
            return response()->json([
                'status' => 200,
                'message' => 'Partner Updated Successfully !',
            ]);
        }
    }

    public function destroy($id)
    {
        $data = Partner::find($id);
            if ($data->image) {
                unlink($data->image);
            }
        $data->delete();
        return response()->json([
            'status' => 200,
            'message' => 'Partner Deleted Successfully !',
        ]);
    }

}
