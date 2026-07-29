<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\OurService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

class OurServiceController extends Controller
{
    public function index(){
        if(checkpermission('home-service') != 1){
            return $this->permissionmessage();
        }

        $services = OurService::latest()->get();
        return response()->json([
            'status' => 200,
            'data' => $services,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title'       => 'required',
            'description' => 'required',
            'icon'        => 'required',
            'photo'       => 'nullable|mimes:jpeg,png,jpg,webp',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }

        $data = $request->only(['title', 'description', 'icon']);

        if ($request->hasFile('photo')) {
            $data['photo'] = fileUpload($request->photo, 'uploads/our-services', 310, 231);
        }

        OurService::create($data);

        return response()->json([
            'status' => 200,
            'message' => 'Data Inserted Successfully !',
        ]);
    }

    public function show($id){
        $service = OurService::find($id);
        if($service){
            return response()->json([
                'status' => 200,
                'datas' =>$service,
            ]);
        }

        return response()->json([
            'status' => 404,
            'message' => 'No Service Data Found',
        ]);
    }


    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'title'       => 'required',
            'description' => 'required',
            'icon'        => 'required',
            'photo'       => 'nullable|mimes:jpeg,png,jpg,webp',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }

        $service = OurService::find($id);

        if ( ! $service ) {
            return response()->json([
                'status'  => 404,
                'message' => 'No Service Data Found',
            ]);
        }

        $data = $request->only(['title', 'description', 'icon']);

        if ($request->hasFile('photo')) {
            $this->deletePhoto($service->photo);
            $data['photo'] = fileUpload($request->photo, 'uploads/our-services', 310, 231);
        }

        $service->update($data);

        return response()->json([
            'status' => 200,
            'message' => 'Service Updated Successfully !',
        ]);
    }

    public function destroy($id){
        $service = OurService::find($id);

        if ( ! $service ) {
            return response()->json([
                'status'  => 404,
                'message' => 'No Service Data Found',
            ]);
        }

        $this->deletePhoto($service->photo);
        $service->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Service Deleted Successfully !',
        ]);
    }

    private function deletePhoto(?string $photo): void
    {
        if ( ! $photo ) {
            return;
        }

        $relativePath = ltrim(preg_replace('#/{2,}#', '/', $photo), '/');
        $fullPath     = public_path($relativePath);

        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }
    }

}
