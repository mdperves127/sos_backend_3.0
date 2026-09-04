<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Models\Settings;
use App\Support\PublicApiCache;

class SettingsController extends Controller {
    private const IMAGE_FIELDS = [
        'logo',
        'org_one_photo',
        'org_photo',
        'footer_image',
        'advertise_banner_image',
        'about_banner_image',
        'vision_image_one',
        'vision_image_two',
        'vision_image_three',
        'mission_image',
        'f_banner_group_title_image',
        'f_banner_image_1',
        'f_banner_image_2',
        'f_banner_image_3',
        'f_feature_image_4',
        'f_feature_image_5',
        'f_feature_image_6',
        'f_feature_image_7',
        'f_feature_image_8',
    ];

    public function index() {
        $data = Settings::all();
        return $this->response( $data );
    }

    public function update( SettingsRequest $request, $id ) {
        $data = Settings::first();
        if ( !$data ) {
            $data        = new Settings();
            $input       = $request->all();

            foreach ( self::IMAGE_FIELDS as $image_file ) {
                if ( $file = $request->file( $image_file ) ) {
                    $input[$image_file] = fileUpload( $file, 'uploads/setting_images', 300, 300 );
                }
            }
            $data->create( $input );
            PublicApiCache::bump();
            return $this->response( 'Settings Created Successfuly' );

        } else {
            $data        = Settings::find( $id );
            $input       = $request->all();

            foreach ( self::IMAGE_FIELDS as $image_file ) {
                if ( $file = $request->file( $image_file ) ) {
                    $input[$image_file] = fileUpload( $file, 'uploads/setting_images', 300, 300 );
                }
            }
            $data->update( $input );
            PublicApiCache::bump();
            return $this->response( 'Settings Updated Successfuly' );
        }

    }

}
