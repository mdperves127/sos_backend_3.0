<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationTwo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

class OrganizationTwoController extends Controller
{
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

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title'       => 'required',
            'description' => 'required',
            'icon'        => 'required',
            'image'       => 'nullable|mimes:jpeg,png,jpg,webp',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }

        $data = $request->only(['title', 'description', 'icon']);

        if ($request->hasFile('image')) {
            $data['image'] = fileUpload($request->image, 'uploads/organization-two', 310, 231);
        }

        OrganizationTwo::create($data);

        return response()->json([
            'status' => 200,
            'message' => 'Data Inserted Successfully !',
        ]);
    }

    public function show($id)
    {
        $OrgTwo = OrganizationTwo::find($id);
        if($OrgTwo){
            return response()->json([
                'status' => 200,
                'datas' => $OrgTwo,
            ]);
        }

        return response()->json([
            'status' => 404,
            'message' => 'No Organization Two Infos Found',
        ]);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'title'       => 'required',
            'description' => 'required',
            'icon'        => 'required',
            'image'       => 'nullable|mimes:jpeg,png,jpg,webp',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }

        $orgTwo = OrganizationTwo::find($id);

        if ( ! $orgTwo ) {
            return response()->json([
                'status'  => 404,
                'message' => 'No Organization Two Infos Found',
            ]);
        }

        $data = $request->only(['title', 'description', 'icon']);

        if ($request->hasFile('image')) {
            $this->deleteImage($orgTwo->image);
            $data['image'] = fileUpload($request->image, 'uploads/organization-two', 310, 231);
        }

        $orgTwo->update($data);

        return response()->json([
            'status' => 200,
            'message' => 'Organization Two Updated Successfully !',
        ]);
    }

    public function destroy($id)
    {
        $orgTwo = OrganizationTwo::find($id);

        if ( ! $orgTwo ) {
            return response()->json([
                'status'  => 404,
                'message' => 'No Organization Two Infos Found',
            ]);
        }

        $this->deleteImage($orgTwo->image);
        $orgTwo->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Organization Two Deleted Successfully !',
        ]);
    }

    private function deleteImage(?string $image): void
    {
        if ( ! $image ) {
            return;
        }

        $relativePath = ltrim(preg_replace('#/{2,}#', '/', $image), '/');
        $fullPath     = public_path($relativePath);

        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }
    }
}
