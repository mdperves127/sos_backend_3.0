<?php

namespace App\Http\Controllers\API\Admin;

use App\Models\VendorService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminVendorServiceRequest;
use App\Http\Requests\StoreVendorServiceRequest;
use App\Http\Requests\UpdateVendorServiceRequest;
use App\Services\Vendor\ProductService;

class VendorServiceController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if(checkpermission('manage-service') != 1){
            return $this->permissionmessage();
        }

        $data = VendorService::query()
        ->when(request('search'), function ($query) {
            $query->where('uniqueid', 'like', '%' . request('search') . '%');
        })
        ->with('servicepackages', 'serviceimages', 'user')->latest()

        ->paginate(10);
        return $this->response($data);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreVendorServiceRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store()
    {
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\VendorService  $vendorService
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $vendorService = VendorService::where(['id' => $id])
            ->with(['servicepackages', 'serviceimages'])
            ->first();

        if (!$vendorService) {
            return responsejson('Not found', 'fail');
        }

        return $this->response($vendorService);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateVendorServiceRequest  $request
     * @param  \App\Models\VendorService  $vendorService
     * @return \Illuminate\Http\Response
     */
    public function update(AdminVendorServiceRequest $request, VendorService $vendorService)
    {
        $validateData = $request->validated();

        $vendorService->commission = $validateData['commission'];
        $vendorService->status = $validateData['status'];
        $vendorService->reason = request('reason');
        $vendorService->save();

        return $this->response('Updated successfull');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\VendorService  $vendorService
     * @return \Illuminate\Http\Response
     */
    public function destroy(VendorService $vendorService)
    {
        $vendorService->delete();
        return $this->response('Deleted successfull');
    }
}
