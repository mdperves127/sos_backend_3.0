<?php

namespace App\Http\Controllers\API\Admin;

use App\Models\SupportBoxCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupportBoxCategoryRequest;
use App\Http\Requests\UpdateSupportBoxCategoryRequest;
use Illuminate\Support\Facades\DB;

class SupportBoxCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // if(checkpermission('support-category') != 1){
        //     return $this->permissionmessage();
        // }

        $supportBoxCategory = DB::table('support_box_categories')->where('deleted_at',null)->get();

        return $this->response($supportBoxCategory);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreSupportBoxCategoryRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreSupportBoxCategoryRequest $request)
    {
        $validatedData = $request->validated();

        SupportBoxCategory::create($validatedData);
        return $this->response('Created Successfully');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\SupportBoxCategory  $supportBoxCategory
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        // return $this->response($supportBoxCategory);
        $data =  SupportBoxCategory::find($id);
        if (!$data) {
            return responsejson('Not found', 'fail');
        }
        return $this->response($data);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateSupportBoxCategoryRequest  $request
     * @param  \App\Models\SupportBoxCategory  $supportBoxCategory
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateSupportBoxCategoryRequest $request, $id)
    {
        $validatedData = $request->validated();
        $supportBoxCategory = SupportBoxCategory::find($id);
        if(!$supportBoxCategory){
            return responsejson('Not found!','fail');
        }

        $supportBoxCategory->name = $validatedData['name'];
        $supportBoxCategory->save();
        return $this->response('Updated successfull');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\SupportBoxCategory  $supportBoxCategory
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $category =  SupportBoxCategory::find($id);
        if(!$category){
            return responsejson('Not found','fail');
        }
        $category->delete();
        return $this->response('Deleted successfull');
    }
    function ticketcategorytoproblem($id){

        $data = SupportBoxCategory::find($id)->load('problems');
        return $this->response($data);
    }
}
