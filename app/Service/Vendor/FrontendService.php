<?php

namespace App\Service\Vendor;

use App\Models\Slider;
use Illuminate\Support\Facades\Auth;

class SliderService
{

    public static function showSLider()
    {
        $sliders = Slider::all();
        return $sliders;
    }
}
