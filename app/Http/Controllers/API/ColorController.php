<?php

namespace App\Http\Controllers\API;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Color;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ColorController extends Controller
{
    public function ColorIndex()
    {

        $color=Color::where('vendor_id',vendorId())
        ->when(request('status') == 'active', function ($q) {
            return $q->where('status', 'active');
        })
        ->latest()->get();

        return response()->json([
            'status'=>200,
            'color'=>$color,
        ]);
    }

    public function ColorStore(Request $request)
    {

        // $validator = Validator::make($request->all(), [
        //     'name' => 'required',
        //     'code'=>'nullable'
        // ]);

        // $otherUserIds = [vendorId()];
        // $validator = Validator::make($request->all(), [
        //     'name' => 'required',
        //     'name' => [
        //         'required',
        //         Rule::unique('colors')->where(function ($query) use ($otherUserIds) {
        //             return $query->whereIn('vendor_id', $otherUserIds);
        //         })
        //     ],
        // ]);

        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:colors,name,NULL,id,vendor_id,'.vendorId(),
        ]);

        if($validator->fails())
        {
            return response()->json([
                'status'=>400,
                'validation_errors'=>$validator->messages(),
            ]);
        }
          else
          {
            $color =new Color();
            $color->name=$request->input('name');
            $color->code=$request->input('code');
            $color->slug = slugCreate(Color::class,$request->name);
            $color->user_id=Auth::id();
            $color->status= $request->status;
            $color->created_by = Status::Vendor->value;
            $color->vendor_id = vendorId();
            $color->save();
            return response()->json([
            'status'=>200,
             'message'=>'Color Added Sucessfully',
            ]);
          }
    }

    public function ColorEdit($id)
    {
        $userId =Auth::id();
         $color = Color::where('user_id',$userId)->find($id);
        if($color)
        {
            return response()->json([
                'status'=>200,
                'color'=>$color
            ]);
        }
        else
        {
            return response()->json([
                'status'=>404,
                'message'=>'No Color Id Found'
            ]);
        }
    }

    public function ColorUpdate(Request $request, $id)
    {
        // $currentUserId = vendorId();
        // $rules = [
        //     'name' => [
        //         'required',
        //         Rule::unique('colors')->where(function ($query) use ($currentUserId) {
        //             return $query->where('vendor_id', $currentUserId);
        //         })->ignore($id), // Ignore the current color ID when checking uniqueness
        //     ],
        // ];

        // // Check if name is present and not empty
        // if ($request->has('name') && !empty($request->name)) {
        //     // Add other validation rules for 'name' if needed
        // }

        // $validator = Validator::make($request->all(), $rules);

        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:colors,name,'.$id.',id,vendor_id,'.vendorId(),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'=>400,
                'validation_errors'=>$validator->messages(),
            ]);
        } else {
            $color = Color::find($id);
            if($color)
            {
                $color->name = $request->input('name');
                $color->slug = slugUpdate(Color::class,$request->name,$id);

                // Update other fields only if they are present in the request
                if ($request->has('status')) {
                    $color->status = $request->input('status');
                }

                if ($request->has('code')) {
                    $color->code = $request->input('code');
                }

                $color->user_id = Auth::id();
                $color->vendor_id = vendorId();
                $color->save();

                return response()->json([
                    'status'=>200,
                    'message'=>'Color Updated Successfully',
                ]);
            }
            else
            {
                return response()->json([
                    'status'=>404,
                    'message'=>'No Color ID Found',
                ]);
            }
        }
    }


    public function destroy($id)
    {
        $userId =Auth::id();
        $color = Color::where('user_id',$userId)->find($id);
        if($color)
        {
            $color->delete();
            return response()->json([
                'status'=>200,
                'message'=>'Color Deleted Successfully',
            ]);
        }
        else
        {
            return response()->json([
                'status'=>404,
                'message'=>'No COlor ID Found',
            ]);
        }
    }



}
