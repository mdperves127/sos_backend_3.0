<?php

namespace App\Http\Requests;

use App\Models\SupportBox;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreTicketReplyRequest extends FormRequest
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
        return [
            'support_box_id'=>['required', function($attribute,$value,$fail){
                $supportBox = SupportBox::query()
                ->when(checkpermission('support') != 1,function($query){
                    $query->whereHas('supportassigned',function($query){
                        $query->where('user_id',auth()->id());
                    });
                })
                ->find($value);
                if (!$supportBox) {
                    $fail('Not found');
                }
            }],
            'description'=>'required',
            'file'=>['nullable','file']
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
