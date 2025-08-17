<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Testimonial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use function PHPUnit\Framework\fileExists;

class TestimonialController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (checkpermission('testimonial') != 1) {
            return $this->permissionmessage();
        }

        $testimonial = Testimonial::latest()->get();
        return response()->json([
            'status' => 200,
            'data' => $testimonial,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'description'    => 'required',
            'image'          => 'required|mimes:jpeg,png,jpg,jpeg',
            'name'           => 'required',
            'designation'    => 'required',
            'rating'         => 'required',

        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        } else {
            $data = $request->all();
            if ($request->image) {
                $data['image'] = fileUpload($request->image, 'uploads/testimonials/', 60, 60);
            }
            Testimonial::create($data);
            return response()->json([
                'status'  => 200,
                'message' => 'Testimonial Infos Saved Successfully !',
            ]);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $testimonial = Testimonial::find($id);
        if ($testimonial) {
            return response()->json([
                'status' => 200,
                'datas'  => $testimonial,
            ]);
        } else {
            return response()->json([
                'status'  => 404,
                'message' => 'No testimonial data Found',
            ]);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'description'    => 'required',
            'image'          => 'mimes:jpeg,png,jpg,jpeg',
            'name'           => 'required',
            'designation'    => 'required',
            'rating'         => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        } else {
            $old_image = Testimonial::find($id);
            $data = $request->all();
            if ($request->image) {
                if ($old_image->image) {
                    unlink($old_image->image);
                }
                $data['image'] = fileUpload($request->image, 'uploads/testimonials/', 60, 60);
            }
            Testimonial::find($id)->update($data);
            return response()->json([
                'status' => 200,
                'message' => 'Testimonial Updated Successfully !',
            ]);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $data = Testimonial::find($id);
        if ($data->image) {
            unlink($data->image);
        }
        $data->delete();
        return response()->json([
            'status' => 200,
            'message' => 'Testimonial Deleted Successfully !',
        ]);
    }
}
