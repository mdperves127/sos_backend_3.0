<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MembersController extends Controller
{
    public function index()
    {
        if(checkpermission('members') != 1){
            return $this->permissionmessage();
        }
        $member = Member::latest()->get();
        return response()->json([
            'status' => 200,
            'data' => $member,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'photo'          => 'required|mimes:jpeg,png,jpg',
            'name'           => 'required',
            'designation'    => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            $data = $request->all();
            if($request->photo){
                $data['photo'] = fileUpload($request->photo, 'uploads/members/', 310, 231);
            }
            Member::create($data);
            return response()->json([
                'status'  => 200,
                'message' => 'Member Infos Saved Successfully !',
            ]);
        }
    }

    public function show($id)
    {
        $member = Member::find($id);
        if($member){
            return response()->json([
                'status' => 200,
                'datas'  => $member,
            ]);
        }else{
            return response()->json([
                'status'  => 404,
                'message' => 'No Member Found',
            ]);
        }
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'photo'          => 'mimes:jpeg,png,jpg',
            'name'           => 'required',
            'designation'    => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }else{
            $old_image = Member::find($id);
            $data = $request->all();
            if ($request->photo) {
                if ($old_image->photo) {
                    unlink($old_image->photo);
                }
                $data['photo'] = fileUpload($request->photo, 'uploads/members/', 310, 231);
            }
            Member::find($id)->update($data);
            return response()->json([
                'status' => 200,
                'message' => 'Member Updated Successfully !',
            ]);
        }
    }

    public function destroy($id)
    {
        $data = Member::find($id);
            if ($data->photo) {
                unlink($data->photo);
            }
        $data->delete();
        return response()->json([
            'status' => 200,
            'message' => 'Member Deleted Successfully !',
        ]);
    }

}
