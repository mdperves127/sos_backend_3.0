<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SettingsRequest extends FormRequest
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
            'logo'                      => ['nullable','image','mimes:png,jpg,jpeg'],
            'home_banner_heading'       => ['nullable'],
            'home_banner_description'   => ['nullable'],
            'service_one_title'         => ['nullable'],
            'service_one_heading'       => ['nullable'],
            'org_one_title'             => ['nullable'],
            'org_one_heading'           => ['nullable'],
            'org_one_photo'             => ['nullable', 'image', 'mimes:png,jpg,jpeg'],
            'org_one_video_link'        => ['nullable', 'url'],
            'count_one'                 => ['nullable'],
            'one_title'                 => ['nullable'],
            'count_two'                 => ['nullable'],
            'count_two_title'           => ['nullable'],
            'count_three'               => ['nullable'],
            'count_three_title'         => ['nullable'],
            'count_four'                => ['nullable'],
            'count_four_title'          => ['nullable'],
            'service_two_title'         => ['nullable'],
            'service_two_heading'       => ['nullable'],
            'org_title'                 => ['nullable'],
            'org_heading'               => ['nullable'],
            'org_photo'                  => 'nullable',
            'chose_us_title'   => 'nullable',
            'chose_us_heading'   => 'nullable',
            'chose_description'   => 'nullable',
            'progress_title'   => 'nullable',
            'progress_value'   => 'nullable',
            'progres_two_title'   => 'nullable',
            'progres_two_value'   => 'nullable',
            'progres_three_title'   => 'nullable',
            'progres_three_value'   => 'nullable',
            'progres_four_title'   => 'nullable',
            'progres_four_value'   => 'nullable',
            'chose_us_two_title'   => 'nullable',
            'chose_us_two_heading'   => 'nullable',
            'testimonial_title'   => 'nullable',
            'testimonial_heading'   => 'nullable',
            'chose_card_one_icon'   => 'nullable',
            'chose_card_one_title'   => 'nullable',
            'chose_card_one_description'   => 'nullable',
            'chose_card_two_icon'   => 'nullable',
            'chose_card_two_title'   => 'nullable',
            'chose_card_two_description'   => 'nullable',
            'chose_card_three_icon'   => 'nullable',
            'chose_card_three_title'   => 'nullable',
            'chose_card_three_description'   => 'nullable',
            'chose_card_four_icon'   => 'nullable',
            'chose_card_four_title'   => 'nullable',
            'chose_card_four_description'   => 'nullable',
            'partner_title'   => 'nullable',
            'partner_heading'   => 'nullable',
            'newsletter_title'   => 'nullable',
            'newsletter_description'   => 'nullable',
            'footer_description'   => 'nullable',
            'footer_contact_address'   => 'nullable',
            'footer_contact_number'   => 'nullable',
            'footer_image'   => ['nullable', 'image', 'mimes:png,jpg,jpeg'],
            'copywright_text'   => 'nullable',
            'credit_name'   => 'nullable',
            'credit_link'   => 'nullable|url',
            'service_banner_heading'   => 'nullable',
            'service_banner_description'   => 'nullable',
            'advertise_banner_heading'   => 'nullable',
            'advertise_banner_description'   => 'nullable',
            'advertise_banner_image'   => ['nullable', 'image', 'mimes:png,jpg,jpeg'],
            'overview_title'   => 'nullable',
            'overview_description'   => 'nullable',
            'get_sarted_title'   => 'nullable',
            'get_sarted_description'   => 'nullable',
            'about_banner_title'   => 'nullable',
            'about_banner_heading'   => 'nullable',
            'about_banner_description'   => 'nullable',
            'about_banner_image'   => ['nullable', 'image', 'mimes:png,jpg,jpeg'],
            'about_banner_increment_one_count'   => 'nullable',
            'about_banner_increment_one_title'   => 'nullable',
            'about_banner_increment_two_count'   => 'nullable',
            'about_banner_increment_otwo_title'   => 'nullable',
            'about_banner_increment_othree_count'   => 'nullable',
            'about_banner_increment_three_title'   => 'nullable',
            'vision_title'   => 'nullable',
            'vision_heading'   => 'nullable',
            'vision_description'   => 'nullable',
            'vision_image_one'   => ['nullable', 'image', 'mimes:png,jpg,jpeg'],
            'vision_image_two'   => ['nullable', 'image', 'mimes:png,jpg,jpeg'],
            'vision_image_three'   => ['nullable', 'image', 'mimes:png,jpg,jpeg'],
            'mission_title'   => 'nullable',
            'mission_heading'   => 'nullable',
            'mission_description'   => 'nullable',
            'mission_image'   => 'nullable', 'image', 'mimes:png,jpg,jpeg',
            'subscription_heading' => 'nullable',
            'subscription_title' => 'nullable',
            'tag_manager'=> 'nullable'
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
