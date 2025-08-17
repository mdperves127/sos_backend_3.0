<?php

namespace App\Action\Admin;

use App\Enums\Status;
use App\Models\Color;

class ColorAction
{
    function store($requestData)
    {
        $color = new Color();
        $color->name = $requestData['name'];
        $color->code = $requestData['code'];
        $color->slug = slugCreate(Color::class, $requestData['name']);
        $color->user_id = auth()->user()->id;
        $color->status = Status::Active->value;
        $color->created_by = Status::Admin->value;
        $color->save();
        return $color;
    }

    function update($requestData,$id){

        $color = Color::find($id);
        $color->name = $requestData['name'];
        $color->code = $requestData['code'];
        $color->slug = slugCreate(Color::class, $requestData['name']);
        $color->user_id = auth()->user()->id;
        $color->status = Status::Active->value;
        $color->created_by = Status::Admin->value;
        $color->save();
        return $color;
    }
    function delete($id){

        $color = Color::where(['user_id'=>auth()->user()->id, 'id'=>$id])->firstOrFail();
        $color->delete();
        return $color;
    }
}
