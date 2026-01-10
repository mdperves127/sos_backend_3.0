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

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $extension = $file->getClientOriginalExtension();
            $filename = time() . '.' . $extension;
            $file->move('uploads/logo/', $filename);
            $data->logo = 'uploads/logo/' . $filename;
        }
        if($request->hasFile('footer_logo')){
            $file = $request->file('footer_logo');
            $extension = $file->getClientOriginalExtension();
            $filename = time() . '.' . $extension;
            $file->move('uploads/footer-logo/', $filename);
            $data->footer_logo = 'uploads/footer-logo/' . $filename;
        }

        $data->fill($request->except(['logo', 'footer_logo']));
        $data->save();
        
        return response()->json([
            'status' => true,
            'message' => 'Cms Setting Updated Successfully',
            'data' => $data
        ]);
    }
}
