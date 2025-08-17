<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\FooterMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FooterMediaController extends Controller
{
    public function index()
    {
        $footermedia = FooterMedia::latest()->get();
        return response()->json([
            'status' => 200,
            'data' => $footermedia,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'icon_class'    => 'required',
            'media_link'    => 'required|url',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            FooterMedia::create($request->all());
            return response()->json([
                'status' => 200,
                'message' => 'Data Inserted Successfully !',
            ]);
        }
    }


    public function show($id){
        $footermedia = FooterMedia::find($id);
        if($footermedia){
            return response()->json([
                'status' => 200,
                'datas' => $footermedia,
            ]);
        }else{
            return response()->json([
                'status' => 404,
                'message' => 'No footer media Info Found',
            ]);
        }
    }


    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'icon_class'    => 'required',
            'media_link'    => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            FooterMedia::find($id)->update($request->all());
            return response()->json([
                'status' => 200,
                'message' => 'Footer Media Updated Successfully !',
            ]);
        }
    }

    public function destroy($id){
        FooterMedia::find($id)->delete();
        return response()->json([
            'status' => 200,
            'message' => 'Footer Media Deleted Successfully !',
        ]);
    }


}


