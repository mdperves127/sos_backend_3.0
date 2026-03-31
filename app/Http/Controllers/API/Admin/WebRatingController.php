<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\WebRating;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

class WebRatingController extends Controller
{
    private string $uploadPath = 'uploads/web-ratings';

    public function index()
    {
        $ratings = WebRating::on('mysql')->latest()->get();

        return response()->json([
            'status' => 200,
            'data' => $ratings,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }

        $data = $this->payload($request);

        if ($request->hasFile('image')) {
            $data['image'] = $this->uploadImage($request->file('image'));
        }

        WebRating::on('mysql')->create($data);

        return response()->json([
            'status' => 200,
            'message' => 'Web rating created successfully !',
        ]);
    }

    public function create()
    {
        return response()->json([
            'status' => 405,
            'message' => 'Create view is not available for this API resource',
        ], 405);
    }

    public function show($id)
    {
        $rating = WebRating::on('mysql')->find($id);

        if (!$rating) {
            return response()->json([
                'status' => 404,
                'message' => 'No web rating data found',
            ]);
        }

        return response()->json([
            'status' => 200,
            'datas' => $rating,
        ]);
    }

    public function update(Request $request, $id)
    {
        $rating = WebRating::on('mysql')->find($id);

        if (!$rating) {
            return response()->json([
                'status' => 404,
                'message' => 'No web rating data found',
            ]);
        }

        $validator = Validator::make($request->all(), $this->rules(false));

        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }

        $data = $this->payload($request, false);

        if ($request->hasFile('image')) {
            $this->deleteImage($rating->image);
            $data['image'] = $this->uploadImage($request->file('image'));
        }

        $rating->update($data);

        return response()->json([
            'status' => 200,
            'message' => 'Web rating updated successfully !',
        ]);
    }

    public function edit($id)
    {
        return response()->json([
            'status' => 405,
            'message' => 'Edit view is not available for this API resource',
        ], 405);
    }

    public function destroy($id)
    {
        $rating = WebRating::on('mysql')->find($id);

        if (!$rating) {
            return response()->json([
                'status' => 404,
                'message' => 'No web rating data found',
            ]);
        }

        $this->deleteImage($rating->image);
        $rating->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Web rating deleted successfully !',
        ]);
    }

    private function rules(bool $isStore = true): array
    {
        if ($isStore) {
            return [
                'ratting' => ['required_without:rating', 'nullable', 'string'],
                'rating' => ['required_without:ratting', 'nullable', 'string'],
                'comment' => ['required', 'string'],
                'author_name' => ['nullable', 'string'],
                'author_description' => ['nullable', 'string'],
                'image' => ['nullable', 'mimes:jpeg,png,jpg,webp'],
            ];
        }

        return [
            'ratting' => ['sometimes', 'nullable', 'string'],
            'rating' => ['sometimes', 'nullable', 'string'],
            'comment' => ['sometimes', 'nullable', 'string'],
            'author_name' => ['nullable', 'string'],
            'author_description' => ['nullable', 'string'],
            'image' => ['nullable', 'mimes:jpeg,png,jpg,webp'],
        ];
    }

    private function payload(Request $request, bool $isStore = true): array
    {
        $data = [];

        if ($isStore || $request->exists('ratting') || $request->exists('rating')) {
            $data['ratting'] = $request->input('ratting', $request->input('rating'));
        }

        foreach (['comment', 'author_name', 'author_description'] as $field) {
            if ($isStore || $request->exists($field)) {
                $data[$field] = $request->input($field);
            }
        }

        return $data;
    }

    private function uploadImage($image): string
    {
        File::ensureDirectoryExists(public_path($this->uploadPath));

        return fileUpload($image, $this->uploadPath, 100, 100);
    }

    private function deleteImage(?string $image): void
    {
        if (!$image) {
            return;
        }

        $imagePath = public_path($image);

        if (File::exists($imagePath)) {
            File::delete($imagePath);
        }
    }
}
