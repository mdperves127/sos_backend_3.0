<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class OfferController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Offer::latest()->get()
        ]);
    }
    public function store(Request $request)
    {
        $offer = Offer::create($request->all());
        return response()->json([
            'success' => true,
            'data' => $offer
        ]);
    }
    public function update(Request $request, $id)
    {
        $offer = Offer::findOrFail($id);
        $offer->update($request->all());
        return response()->json([
            'success' => true,
            'data' => $offer
        ]);
    }
    public function destroy($id)
    {
        $offer = Offer::findOrFail($id);
        $offer->delete();
        return response()->json([
            'success' => true,
            'data' => $offer
        ]);
    }
    public function show($id)
    {
        $offer = Offer::findOrFail($id);
        return response()->json([
            'success' => true,
            'data' => $offer
        ]);
    }
}
