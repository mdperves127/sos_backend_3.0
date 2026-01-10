<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ContentServiceController extends Controller
{
    public function index()
    {
        $contentServices = ContentService::orderBy('order', 'asc')->get();
        return response()->json($contentServices);
    }

    public function store(Request $request)
    {
        $contentService = ContentService::create($request->all());
        
            if ($request->hasFile('icon')) {
                $file = $request->file('icon');
                $extension = $file->getClientOriginalExtension();
                $filename = time() . '.' . $extension;
                $file->move('uploads/content-service/', $filename);
                $contentService->icon = 'uploads/content-service/' . $filename;
            }

        $contentService->save();
        return response()->json([
            'status' => 'success',
            'message' => 'Content Service created successfully',
            'data' => $contentService
        ]);
    }

    public function show($id)
    {
        $contentService = ContentService::find($id);
        return response()->json($contentService);
    }

    public function update(Request $request)
    {
        $contentService = ContentService::find($request->id);
        
        if ($request->hasFile('icon')) {
            $file = $request->file('icon');
            $extension = $file->getClientOriginalExtension();
            $filename = time() . '.' . $extension;
            $file->move('uploads/content-service/', $filename);
            $contentService->icon = 'uploads/content-service/' . $filename;
        }
        $contentService->update($request->all());
        return response()->json([
            'status' => 'success',
            'message' => 'Content Service updated successfully',
            'data' => $contentService
        ]);
    }

    public function destroy($id)
    {
        $contentService = ContentService::find($id);
        $contentService->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Content Service deleted successfully',
            'data' => $contentService
        ]);
    }
}
