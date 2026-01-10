<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Banner;

class BannerController extends Controller
{
    public function index()
    {
        $banners = Banner::orderBy('order', 'asc')->get();
        return response()->json($banners);
    }
    
    public function store(Request $request)
    {
        $banner = Banner::create($request->all());
        
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $extension = $file->getClientOriginalExtension();
            $filename = time() . '.' . $extension;
            $file->move('uploads/banner/', $filename);
            $banner->image = 'uploads/banner/' . $filename;
        }
        $banner->fill($request->except(['image']));
        $banner->save();
        return response()->json([
            'status' => 'success',
            'message' => 'Banner created successfully',
            'data' => $banner
        ]);
    }
    
    public function show($id)
    {
        $banner = Banner::find($id);
        return response()->json($banner);
    }

    public function update(Request $request)
    {
        $banner = Banner::find($request->id);

        if (!$banner) {
            return response()->json([
                'status' => 'error',
                'message' => 'Banner not found'
            ], 404);
        }

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $extension = $file->getClientOriginalExtension();
            $filename = time() . '.' . $extension;
            $file->move('uploads/banner/', $filename);
            $banner->image = 'uploads/banner/' . $filename;
        }
        $banner->fill($request->except(['image']));
        $banner->save();
        return response()->json([
            'status' => 'success',
            'message' => 'Banner updated successfully',
            'data' => $banner
        ]);
    }


    public function destroy($id)
    {
        $banner = Banner::find($id);
        $banner->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Banner deleted successfully',
            'data' => $banner
        ]);
    }
}
