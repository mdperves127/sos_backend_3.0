<?php

namespace App\Services\Vendor;

use App\Enums\Status;
use App\Models\ServiceImages;
use App\Models\ServicePackage;
use App\Models\VendorService;
use Illuminate\Support\Facades\File;

/**
 * Class ProductService.
 */
class ProductService
{
    static function store($data)
    {
        $vendorService = new VendorService;
        $vendorService->user_id = userid();
        $vendorService->service_category_id =  $data['service_category_id'];
        $vendorService->service_sub_category_id =  $data['service_sub_category_id'];
        $vendorService->service_sub_category_id =  $data['service_sub_category_id'];
        $vendorService->title =   $data['title'];
        $vendorService->description =   $data['description'];
        $vendorService->tags =   $data['tags'];
        $vendorService->contract =   $data['contract'];
        $vendorService->status =   Status::Pending->value;
        $vendorService->commission =   $data['commission'];
        $vendorService->commission_type =   $data['commission_type'];
        if (request()->hasFile('image')) {
            $vendorService->image =  fileUpload(request()->file('image'), 'uploads/vendor');
        }
        $vendorService->uniqueid = uniqid();
        $vendorService->save();



        foreach (request('package_title') as $key => $value) {
            $servicepackages = new ServicePackage();
            $servicepackages->vendor_service_id = $vendorService->id;
            $servicepackages->time =  request('time')[$key];
            $servicepackages->package_title = $value;
            $servicepackages->package_description = request('package_description')[$key];
            $servicepackages->revision_max_time = request('revision_max_time')[$key];
            $servicepackages->price =  request('price')[$key];
            $servicepackages->save();
        }

        foreach ($data['images'] as $key => $value) {
            $serviceImages =  new ServiceImages();
            $serviceImages->vendor_service_id = $vendorService->id;
            $serviceImages->images = fileUpload($value, 'uploads/vendor');
            $serviceImages->save();
        }

        return true;
    }

    static function update($data, $id)
    {
        $vendorService = VendorService::where(['user_id' => userid(), 'id' => $id])->with('servicepackages')->first();
        if (!$vendorService) {
            return responsejson('Not found', 'fail');
        }

        $vendorService->user_id = userid();
        $vendorService->service_category_id =  $data['service_category_id'];
        $vendorService->service_sub_category_id =  $data['service_sub_category_id'];
        $vendorService->service_sub_category_id =  $data['service_sub_category_id'];
        $vendorService->title =   $data['title'];
        $vendorService->description =   $data['description'];
        $vendorService->tags =   $data['tags'];
        $vendorService->contract =   $data['contract'];
        $vendorService->status =   Status::Pending->value;
        $vendorService->commission =   $data['commission'];
        $vendorService->commission_type =   $data['commission_type'];

        if (request()->hasFile('image')) {
            if (File::exists($vendorService->image)) {
                File::delete($vendorService->image);
            }
            $vendorService->image =  fileUpload(request()->file('image'), 'uploads/vendor');
        }
        $vendorService->save();

        foreach ($vendorService->servicepackages as $key => $value) {
            $servicepackages = ServicePackage::find($value->id);
            $servicepackages->vendor_service_id = $vendorService->id;
            $servicepackages->time =  request('time')[$key];
            $servicepackages->revision_max_time =  request('revision_max_time')[$key];
            $servicepackages->package_title = request('package_title')[$key];
            $servicepackages->package_description = request('package_description')[$key];
            $servicepackages->price =  request('price')[$key];
            $servicepackages->save();
        }

        if (request()->has('images')) {
            foreach ($data['images'] as $key => $value) {
                $serviceImages =  new ServiceImages();
                $serviceImages->vendor_service_id = $vendorService->id;
                $serviceImages->images = fileUpload($value, 'uploads/vendor');
                $serviceImages->save();
            }
            return true;
        }
    }
}
