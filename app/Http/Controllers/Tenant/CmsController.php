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

        if($request->hasFile('populer_section_banner')){
            $file = $request->file('populer_section_banner');
            $extension = $file->getClientOriginalExtension();
            $filename = time() . '.' . $extension;
            $file->move('uploads/populer-section-banner/', $filename);
            $data->populer_section_banner = 'uploads/populer-section-banner/' . $filename;
        }

        if ($request->hasFile('banner_1')) {
            $file = $request->file('banner_1');
            $extension = $file->getClientOriginalExtension();
            $filename = time() . '.' . $extension;
            $file->move('uploads/banner-1/', $filename);
            $data->banner_1 = 'uploads/banner-1/' . $filename;
        }
        if ($request->hasFile('banner_2')) {
            $file = $request->file('banner_2');
            $extension = $file->getClientOriginalExtension();
            $filename = time() . '.' . $extension;
            $file->move('uploads/banner-2/', $filename);
            $data->banner_2 = 'uploads/banner-2/' . $filename;
        }
        if ($request->hasFile('banner_3')) {
            $file = $request->file('banner_3');
            $extension = $file->getClientOriginalExtension();
            $filename = time() . '.' . $extension;
            $file->move('uploads/banner-3/', $filename);
            $data->banner_3 = 'uploads/banner-3/' . $filename;
        }

        if ($request->hasFile('three_column_banner_1')) {
            $file = $request->file('three_column_banner_1');
            $extension = $file->getClientOriginalExtension();
            $filename = time() . '.' . $extension;
            $file->move('uploads/three-column-banner-1/', $filename);
            $data->three_column_banner_1 = 'uploads/three-column-banner-1/' . $filename;
        }
        if ($request->hasFile('three_column_banner_2')) {
            $file = $request->file('three_column_banner_2');
            $extension = $file->getClientOriginalExtension();
            $filename = time() . '.' . $extension;
            $file->move('uploads/three-column-banner-2/', $filename);
            $data->three_column_banner_2 = 'uploads/three-column-banner-2/' . $filename;
        }
        if ($request->hasFile('three_column_banner_3')) {
            $file = $request->file('three_column_banner_3');
            $extension = $file->getClientOriginalExtension();
            $filename = time() . '.' . $extension;
            $file->move('uploads/three-column-banner-3/', $filename);
            $data->three_column_banner_3 = 'uploads/three-column-banner-3/' . $filename;
        }

        if ($request->hasFile('two_column_banner_1')) {
            $file = $request->file('two_column_banner_1');
            $extension = $file->getClientOriginalExtension();
            $filename = time() . '.' . $extension;
            $file->move('uploads/two-column-banner-1/', $filename);
            $data->two_column_banner_1 = 'uploads/two-column-banner-1/' . $filename;
        }
        if ($request->hasFile('two_column_banner_2')) {
            $file = $request->file('two_column_banner_2');
            $extension = $file->getClientOriginalExtension();
            $filename = time() . '.' . $extension;
            $file->move('uploads/two-column-banner-2/', $filename);
            $data->two_column_banner_2 = 'uploads/two-column-banner-2/' . $filename;
        }
        if ($request->hasFile('footer_payment_methods')) {
            $file = $request->file('footer_payment_methods');
            $extension = $file->getClientOriginalExtension();
            $filename = time() . '.' . $extension;
            $file->move('uploads/footer-payment-methods/', $filename);
            $data->footer_payment_methods = 'uploads/footer-payment-methods/' . $filename;
        }

        $data->fill($request->except(['logo', 'footer_logo', 'populer_section_banner', 'banner_1', 'banner_2', 'banner_3', 'three_column_banner_1', 'three_column_banner_2', 'three_column_banner_3', 'two_column_banner_1', 'two_column_banner_2', 'footer_payment_methods']));
        $data->save();

        return response()->json([
            'status' => true,
            'message' => 'Cms Setting Updated Successfully',
            'data' => $data
        ]);
    }
}
