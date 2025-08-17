<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\VendorPaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentRequestController extends Controller
{
    //
    function store(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'admin_bank_id' => 'required|integer',
            'vendor_bank_number' => 'required',
            'balance' => 'required|numeric|min:1',
            'transition_id' => 'required',
            'screenshot' => 'nullable',
            'reference_field' => 'nullable',

        ]);


        if ($validate->fails()) {
            return response()->json([
                'status' => 400,
                'message' => $validate->messages()
            ]);
        }

        $bank = Bank::find($request->admin_bank_id);
        if(!$bank){
            return response()->json(['message'=>'Bank not found']);
        }

        $vendorPaymentRequest = new VendorPaymentRequest();
        $vendorPaymentRequest->admin_bank_id = $request->admin_bank_id;
        $vendorPaymentRequest->vendor_bank_number = $request->vendor_bank_number;
        $vendorPaymentRequest->balance = $request->balance;
        $vendorPaymentRequest->transition_id = $request->transition_id;
        if ($request->file('screenshot')) {
            $file =    fileUpload($request->file('screenshot'), 'uploads/vendor');
            $vendorPaymentRequest->screenshot = $file;
        }

        $vendorPaymentRequest->reference_field = $request->reference_field;
        $vendorPaymentRequest->bank_name = $bank->name;
        $vendorPaymentRequest->bank_number = $bank->number;
        $vendorPaymentRequest->vendor_id = auth()->user()->id;
        $vendorPaymentRequest->save();

        return response()->json([
            'status'=>200,
            'message'=>'Request Successfully'
        ]);

    }
    function history(){
        $data = VendorPaymentRequest::where('vendor_id',auth()->user()->id)
        ->when(request('search'),fn($q, $name)=>$q->where('transition_id','like',"%{$name}%"))

        ->when(request('status') == 'cancel', function ($q) {
            return $q->where('status', 'cancel');
        })
        ->when(request('status') == 'success', function ($q) {
            return $q->where('status', 'success');
        })
        ->when(request('status') == 'pending', function ($q) {
            return $q->where('status', 'pending');
        })
        ->latest()
        ->paginate(10)
        ->withQueryString();


        return response()->json([
            'status'=>200,
            'message'=>$data
        ]);

    }
}
