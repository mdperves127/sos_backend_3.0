<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Mission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MissionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if(checkpermission('mission') != 1){
            return $this->permissionmessage();
        }

        $missions = Mission::latest()->get();
        if($missions){
           return response()->json([
                'status' => 200,
                'data' => $missions,
            ]);
        }else{
            return response()->json([
                'status' => 404,
                'message' => 'No mission data Found',
            ]);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'icon_class'        => 'required',
            'title'             => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            Mission::create($request->all());
            return response()->json([
                'status' => 200,
                'message' => 'Data Inserted Successfully !',
            ]);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $mission = Mission::find($id);
        if($mission){
            return response()->json([
                'status' => 200,
                'datas' => $mission,
            ]);
        }else{
            return response()->json([
                'status' => 404,
                'message' => 'No mission data Found',
            ]);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'icon_class'    => 'required',
            'title'         => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            Mission::find($id)->update($request->all());
            return response()->json([
                'status' => 200,
                'message' => 'Mission Datas Updated Successfully !',
            ]);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        Mission::find($id)->delete();
        return response()->json([
            'status' => 200,
            'message' => 'Mission Data Deleted Successfully !',
        ]);
    }
}
