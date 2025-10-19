<?php

namespace App\Http\Requests;

use App\Rules\AdvertiseBudgetRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreAdminAdvertiseRequest extends FormRequest {
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize() {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules() {
        return [
            'campaign_objective'         => ['required'],
            'campaign_name'              => ['required', 'max:256'],
            'conversion_location'        => ['required'],
            'performance_goal'           => ['required'],
            // 'platforms'  => ['required'],
            'ad_creative'                => ['required', 'array'],
            'budget'                     => ['required'],
            'budget_amount'              => ['required', 'numeric', 'min:1', new AdvertiseBudgetRule()],
            'start_date'                 => ['required'],
            'end_date'                   => ['required'],
            'age'                        => ['required', 'string'],
            'ageto'                      => ['required', 'string'],
            'gender'                     => ['required'],
            'detail_targeting'           => ['required'],
            'country'                    => ['required'],
            'city'                       => ['required', 'array'],
            'device'                     => ['required'],
            'platform'                   => ['required'],
            'inventory'                  => ['required'],
            'format'                     => ['required'],

            'destination'                => ['nullable'],
            'tracking'                   => ['nullable'],
            'url_perimeter'              => ['nullable', 'url'],
            'number'                     => ['nullable', 'numeric'],
            'last_description'           => ['nullable'],
            'status'                     => ['required', 'in:pending,progress,cancel,complited'],

            // 'advertise_audience_files' => 'required|array',
            // 'location_files'   => 'required|array',
            'advertise_audience_files'   => 'array',
            'advertise_audience_files.*' => 'image|mimes:jpeg,png,jpg,gif|max:20480', // You can customize the allowed image types and maximum file size.
            'location_files' => 'array',
            'location_files.*'           => 'image|mimes:jpeg,png,jpg,gif|max:20480',

            'placements'                 => 'required',
            'feeds'                      => 'nullable',
            'story_reels'                => 'nullable',
            'adds_video_and_reels'       => 'nullable',
            'search_result'              => 'nullable',
            'messages'                   => 'nullable',
            'apps_and_sites'             => 'nullable',
            'paymethod'                  => ['required', Rule::in( ['aamarpay', 'my-wallet'] )],
            'audience'                   => ['required'],
            'number'                     => 'required|string',

        ];
    }

    public function failedValidation( Validator $validator ) {
        throw new HttpResponseException( response()->json( [
            'success' => false,
            'message' => 'Validation errors',
            'data'    => $validator->errors(),
        ] ) );
    }
}
