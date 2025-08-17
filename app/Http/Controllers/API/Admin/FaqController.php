<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FaqController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if(checkpermission('faq') != 1){
            return $this->permissionmessage();
        }

        $faqs = Faq::latest()->get();
        if($faqs){
            return response()->json([
                'status' => 200,
                'datas' => $faqs,
            ]);
        }else{
            return response()->json([
                'status' => 404,
                'message' => 'No Faq data Found',
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
            'heading'        => 'required',
            'description'    => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            Faq::create($request->all());
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
        $faq = Faq::find($id);
        if($faq){
            return response()->json([
                'status' => 200,
                'datas' => $faq,
            ]);
        }else{
            return response()->json([
                'status' => 404,
                'message' => 'No Faq data Found',
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
            'heading'        => 'required',
            'description'    => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            Faq::find($id)->update($request->all());
            return response()->json([
                'status' => 200,
                'message' => 'Faq Data Updated Successfully !',
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
        Faq::find($id)->delete();
        return response()->json([
            'status' => 200,
            'message' => 'Faq Data Deleted Successfully !',
        ]);
    }
}
