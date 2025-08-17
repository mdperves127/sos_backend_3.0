<?php

namespace App\Service\Admin;

use App\Enums\Status;
use App\Models\Color;

class ColorService
{

    static  function getColor()
    {
        $colors = Color::whereIn('created_by', [Status::Admin->value])->latest()->paginate(10);
        return response()->json([
            'status' => 200,
            'message' => $colors
        ]);
    }
    static function ColorShow($id)
    {
        $color =  Color::where(
            [
                'user_id' => auth()->user()->id,
                'id' => $id,
            ]
        )->firstOrFail();

        return response()->json([
            'status' => 200,
            'message' => $color
        ]);
    }
}
