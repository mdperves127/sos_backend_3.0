<?php

namespace App\Http\Controllers;

use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CountryController extends Controller
{
    //
    function country()
    {
        $country = DB::table('countries')->get();
        return response()->json($country);
    }

    function cities($id)
    {
        $country = Country::find($id);
        if (!$country) {
            return response()->json('Country not found');
        }
        $data = $country->cites;

        return response()->json($data);
    }
}
