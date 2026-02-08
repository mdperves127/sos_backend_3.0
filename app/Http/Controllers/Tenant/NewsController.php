<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\News;
use Illuminate\Support\Facades\Validator;
use App\Models\NCategory;
use Illuminate\Support\Facades\File;

class NewsController extends Controller
{
    public function index()
    {
        $news = News::with('nCategory')->get();
        return response()->json($news);
    }
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|max:255',
            'short_description' => 'required',
            'long_description' => 'required',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'status' => 'required|in:active,inactive',
            'n_category_id' => 'required|exists:n_categories,id',
            'meta_title' => 'required|max:255',
            'meta_description' => 'required',
            'meta_keywords' => 'required',
            'tags' => 'required',
        ]);

        if($validator->fails()){
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }

        if($request->hasFile('image')){
            $image = $request->file('image');
            $imageName = time() . '.' . $image->getClientOriginalExtension();
            $image->move(public_path('uploads/news'), $imageName);
            $imagePath = 'uploads/news/' . $imageName;
        }
        else{
            $imagePath = null;
        }
        $news = News::create([
            'title' => $request->title,
            'slug' => slugCreate(News::class, $request->title),
            'short_description' => $request->short_description,
            'long_description' => $request->long_description,
            'image' => $imagePath,
            'status' => $request->status,
            'n_category_id' => $request->n_category_id,
            'meta_title' => $request->meta_title,
            'meta_description' => $request->meta_description,
            'meta_keywords' => $request->meta_keywords,
            'tags' => $request->tags,
        ]);
        return response()->json([
            'status' => 200,
            'message' => 'News created successfully',
            'news' => $news,
        ]);
    }
    public function edit($id)
    {
        $news = News::find($id);
        return response()->json([
            'status' => 200,
            'news' => $news,
        ]);
    }
    public function update(Request $request, $id)
    {
        $news = News::find($id);

        if($news){
            return response()->json([
                'status' => 400,
                'message' => 'News not found',
            ]);
        }
        if($request->hasFile('image')){
            $image = $request->file('image');
            $imageName = time() . '.' . $image->getClientOriginalExtension();
            $image->move(public_path('uploads/news'), $imageName);
            $imagePath = 'uploads/news/' . $imageName;
        }
        else{
            $imagePath = $news->image;
        }
        $news->update([
            'title' => $request->title,
            'slug' => slugUpdate(News::class, $request->title, $id),
            'short_description' => $request->short_description,
            'long_description' => $request->long_description,
            'image' => $imagePath,
            'status' => $request->status,
            'n_category_id' => $request->n_category_id,
            'meta_title' => $request->meta_title,
            'meta_description' => $request->meta_description,
            'meta_keywords' => $request->meta_keywords,
            'tags' => $request->tags,
        ]);
        return response()->json([
            'status' => 200,
            'message' => 'News updated successfully',
            'news' => $news,
        ]);
    }
    public function destroy($id)
    {
        $news = News::find($id);
        if($news->image){
            $imagePath = $news->image;
            if(File::exists($imagePath)){
                File::delete($imagePath);
            }
        }
        $news->delete();
        return response()->json([
            'status' => 200,
            'message' => 'News deleted successfully',
        ]);
    }
}
