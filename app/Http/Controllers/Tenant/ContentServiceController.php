<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ServiceContent;

class ContentServiceController extends Controller
{
    public function index()
    {
        $contentServices = ServiceContent::orderBy('order', 'asc')->get();
        return response()->json($contentServices);
    }

    public function store(Request $request)
    {
        $contentService = ServiceContent::create($request->all());
        
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
        $contentService = ServiceContent::find($id);
        return response()->json($contentService);
    }

    public function update(Request $request)
    {
        $contentService = ServiceContent::find($request->id);

        if (!$contentService) {
            return response()->json([
                'status' => 'error',
                'message' => 'Content Service not found'
            ], 404);
        }
        
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
        $contentService = ServiceContent::find($id);
        $contentService->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Content Service deleted successfully',
            'data' => $contentService
        ]);
    }
}
