<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CmsSetting;

class CmsController extends Controller
{
    public function index()
    {
        $data = CmsSetting::first();
        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function update(Request $request)
    {
        $data = CmsSetting::first();
        $data->update($request->all());
        return response()->json([
            'status' => true,
            'message' => 'Cms Setting Updated Successfully',
            'data' => $data
        ]);
    }
}
