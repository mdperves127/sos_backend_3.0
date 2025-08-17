<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Models\Settings;
use Illuminate\Support\Facades\File;

class SettingsController extends Controller {
    public function index() {
        $data = Settings::all();
        return $this->response( $data );
    }

    public function update( SettingsRequest $request, $id ) {
        $data = Settings::first();
        if ( !$data ) {
            $data        = new Settings();
            $input       = $request->all();
            $image_files = ['logo', 'org_one_photo', 'org_photo', 'footer_image', 'advertise_banner_image', 'about_banner_image', 'vision_image_one', 'vision_image_two', 'vision_image_three', 'mission_image'];

            foreach ( $image_files as $image_file ) {
                if ( $file = $request->file( $image_file ) ) {
                    $input[$image_file] = fileUpload( $file, 'uploads/setting_images/', 300, 300 );
                }
            }
            $data->create( $input );
            return $this->response( 'Settings Created Successfuly' );

        } else {
            $data        = Settings::find( $id );
            $input       = $request->all();
            $image_files = ['logo', 'org_one_photo', 'org_photo', 'footer_image', 'advertise_banner_image', 'about_banner_image', 'vision_image_one', 'vision_image_two', 'vision_image_three', 'mission_image'];

            foreach ( $image_files as $image_file ) {
                if ( $file = $request->file( $image_file ) ) {
                    $input[$image_file] = fileUpload( $file, 'uploads/setting_images', 300, 300 );
                }
            }
            $data->update( $input );
            return $this->response( 'Settings Updated Successfuly' );
        }

    }

}
