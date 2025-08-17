<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CampaignCategoryRequest;
use App\Models\CampaignCategory;

class CampaignCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $categories = CampaignCategory::latest()->paginate(10);
        return $this->response($categories);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CampaignCategoryRequest $request)
    {
        CampaignCategory::create([
            'name' => $request->name,
            'icon' => $request->icon
        ]);
        return $this->response('Campaign category added successfully.');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(CampaignCategory $category)
    {
        return $this->response($category);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(CampaignCategoryRequest $request, CampaignCategory $category)
    {
        if (!$category) {
            return responsejson('Not found', 'fail');
        }
        $validatedData = $request->validated();
        $category->update($validatedData);
        return $this->response('Updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(CampaignCategory $category)
    {
        if (!$category) {
            return responsejson('Not found !', 'fail');
        }
        $category->delete();

        return $this->response('Deleted successfully.');
    }
}
