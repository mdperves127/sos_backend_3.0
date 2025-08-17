<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Itservice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ItServiceController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if(checkpermission('it-service') != 1){
            return $this->permissionmessage();
        }

        $data = Itservice::latest()->paginate(8);
        return response()->json([
            'status' => 200,
            'data' => $data,
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
            'icon'       => 'required',
            'title'       => 'required',
            'description' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            $data = $request->all();
            Itservice::create($data);
            return response()->json([
                'status' => 200,
                'message' => 'Data services Successfully !',
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
        $itservice = Itservice::find($id);

        if($itservice){
            return response()->json([
                'status' => 200,
                'datas' => $itservice,
            ]);
        }else{
            return response()->json([
                'status' => 404,
                'message' => 'No services Found',
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
            'icon'       => 'required',
            'title'       => 'required',
            'description' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            $data = $request->all();
            Itservice::find($id)->update($data);
            return response()->json([
                'status' => 200,
                'message' => 'It services Updated Successfully !',
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
        $data = Itservice::find($id);
        $data->delete();
        return response()->json([
            'status' => 200,
            'message' => 'services Deleted Successfully !',
        ]);
    }
}
