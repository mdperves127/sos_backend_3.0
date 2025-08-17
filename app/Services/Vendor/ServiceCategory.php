<?php

namespace App\Services\Vendor;

use App\Enums\Status;
use App\Models\ServiceCategory as ModelsServiceCategory;
use Illuminate\Support\Str;

/**
 * Class ServiceCategory.
 */
class ServiceCategory
{
    static function index()
    {
        return  ModelsServiceCategory::query()->select('id', 'name', 'slug','status')->latest()->get();
    }

    static  function create($serviceCategory)
    {
        ModelsServiceCategory::create([
            'name' => $serviceCategory['name'],
            'slug' => slugCreate(ModelsServiceCategory::class, $serviceCategory['name']),
            'user_id' => auth()->user()->id,
            'status' => $serviceCategory['status'],
        ]);
        return true;
    }

    static function show($id)
    {
        $serviceCategory = ModelsServiceCategory::where(['id' => $id])->first();

        if ($serviceCategory) {
            $response = responsejson($serviceCategory);
        } else {

            $response = responsejson('Not found', 'fail');
        }

        return $response;
    }

    static function update($validateData, $id)
    {

        $serviceCategory = ModelsServiceCategory::where(['id' => $id])->first();
        if (!$serviceCategory) {
            return  $response = responsejson('Not found', 'fail');
        }
        $serviceCategory->name = $validateData['name'];
        $serviceCategory->slug = slugUpdate(ModelsServiceCategory::class, $validateData['name'], $id);
        $serviceCategory->status = request('status');
        $serviceCategory->update();
        return responsejson('Service category updated!');
    }

    static function delete($id)
    {
        $data =  ModelsServiceCategory::find($id);
        if (!$data) {
            return   responsejson('Not found', 'fail');
        }

        $data->delete();
        return responsejson('Deleted successfull');
    }
}
