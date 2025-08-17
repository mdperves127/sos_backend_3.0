<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BankController extends Controller
{
    function index()
    {
        return response()->json([
            'status' => 200,
            'message' => Bank::latest()->get()
        ]);
    }
    function store(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'number' => 'required|string',
            'account_holder_name' => 'nullable|string',
            'branch_name' => 'nullable'
        ]);
        if ($validator->fails()) {
            return response()->json([
                'validation_errors' => $validator->messages(),
            ]);
        }

        Bank::create([
            'name' => $request->name,
            'number' => $request->number,
            'account_holder_name' => $request->account_holder_name,
            'branch_name' => $request->branch_name
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Bank Added Successfully!'
        ]);
    }
    function destroy($id)
    {
        $bank = Bank::find($id);
        if ($bank) {
            $bank->delete();
            return response()->json([
                'status' => 200,
                'message' => 'Bank Delete Successfully!'
            ]);
        } else {
            return response()->json([
                'status' => 404,
                'message' => 'Not found'
            ]);
        }
    }
    //
}
