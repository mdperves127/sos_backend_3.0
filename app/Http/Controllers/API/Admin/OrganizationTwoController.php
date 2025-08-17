<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationTwo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrganizationTwoController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if(checkpermission('organization-two') != 1){
            return $this->permissionmessage();
        }

        $orgtwo = OrganizationTwo::latest()->get();
        return response()->json([
            'status' => 200,
            'data' => $orgtwo,
        ]);
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
            'title'       => 'required',
            'description' => 'required',
            'icon'        => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            OrganizationTwo::create($request->all());
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
        $OrgTwo = OrganizationTwo::find($id);
        if($OrgTwo){
            return response()->json([
                'status' => 200,
                'datas' => $OrgTwo,
            ]);
        }else{
            return response()->json([
                'status' => 404,
                'message' => 'No Organization Two Infos Found',
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
            'title'       => 'required',
            'description' => 'required',
            'icon'        => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            OrganizationTwo::find($id)->update($request->all());
            return response()->json([
                'status' => 200,
                'message' => 'Organization Two Updated Successfully !',
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
        OrganizationTwo::find($id)->delete();
        return response()->json([
            'status' => 200,
            'message' => 'Organization Two Deleted Successfully !',
        ]);
    }
}
