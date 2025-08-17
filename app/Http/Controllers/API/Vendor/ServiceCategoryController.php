<?php

namespace App\Http\Controllers\API\Vendor;

use App\Enums\Status;
use App\Models\ServiceCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceCategoryRequest;
use App\Http\Requests\UpdateServiceCategoryRequest;
use App\Services\Vendor\ServiceCategory as VendorServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ServiceCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // if(checkpermission('service-category') != 1){
        //     return $this->permissionmessage();
        // }

        $serviceCategory = VendorServiceCategory::index();
        return $this->response($serviceCategory);

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreServiceCategoryRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {

        // $validatedData = $request->validated();
        // VendorServiceCategory::create($validatedData);
        // return $this->response('Service Category created successfulll');

        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:service_categories|',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'validation_errors' => $validator->messages(),
            ]);
        }

        VendorServiceCategory::create($request->all());
        return $this->response('Service Category created successfulll');

    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\ServiceCategory  $serviceCategory
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        // return $this->response($serviceCategoryslug);
        $vendorServicetegory =  VendorServiceCategory::show($id);
        return $vendorServicetegory;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateServiceCategoryRequest  $request
     * @param  \App\Models\ServiceCategory  $serviceCategory
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        // $validatedData = $request->validated();


        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:service_categories,name,'.$id,
        ]);

        return  VendorServiceCategory::update($request->all(), $id);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\ServiceCategory  $serviceCategory
     * @return \Illuminate\Http\Response
     */
    public function destroy(int $id)
    {
        return  VendorServiceCategory::delete($id);

    }

    public function status($id)
    {
        $data = ServiceCategory::find($id);
        $data->status = $data->status == 'active' ? 'deactivate' : 'active';
        $data->save();

        if($data->status == 'active'){
            return response()->json([
                'status' => 200,
                'message' => 'Service category Active Successfully !',
            ]);
        }else{
            return response()->json([
                'status' => 200,
                'message' => 'Service category Deactive Successfully !',
            ]);
        }
    }
}
