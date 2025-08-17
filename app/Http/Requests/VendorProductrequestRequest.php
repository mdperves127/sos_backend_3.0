<?php

namespace App\Http\Requests;

use App\Models\ProductDetails;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class VendorProductrequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        $id = $this->route('id');
        return [
            'status' => ['required', Rule::in([1, 3]), function ($attribute, $value, $fail) use ($id) {
                if (request('status') != '') {
                    $data = ProductDetails::query()
                        ->where(['vendor_id' => auth()->id(), 'id' => $id])
                        ->whereHas('affiliator', function ($query) {
                            $query->withCount(['affiliatoractiveproducts' => function ($query) {
                                $query->where('status', 1);
                            }])
                                ->whereHas('usersubscription', function ($query) {
                                    $query->where('expire_date', '>', now());
                                })
                                ->withSum('usersubscription', 'product_approve')
                                ->having('affiliatoractiveproducts_count', '<', \DB::raw('usersubscription_sum_product_approve'));
                        })
                        ->first();
                        if($data){
                            if($data->status == 1 && (request('status') == 2 || request('status') == 3)){
                                $fail('You have no access');
                            }
                        }
                }
            }],
            'reason'=>['nullable', 'required_if:status,3']
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success'   => false,
            'message'   => 'Validation errors',
            'data'      => $validator->errors()
        ]));
    }
}
