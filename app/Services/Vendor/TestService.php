<?php

namespace App\Services\Vendor;

use App\Enums\Status;
use App\Models\Test;
use Illuminate\Support\Str;
/**
 * Class TestService.
 */
class TestService
{
    public static function create($validateData)
    {
        $data = Test::create([
            'name'     => $validateData['name'],
            'slug'     => Str::slug($validateData['name']),
            'status'   => $validateData['status'],
            'user_id'  => request()->user()->id,
        ]);

        return $data;
    }

    public static function update($validateData, $id)
    {

        return $test = Test::find($id);
        // $test->name = $validateData['name'];
        // $test->slug = Str::slug($validateData['name']);
        // $test->status = $validateData['status'];
        // $test->save();
        // return $test;
    }
}
