<?php

namespace App\Services;

use App\Models\VendorService;

/**
 * Class ShowAllService.
 */
class ShowAllService
{

    static function show()
    {
        $datas = VendorService::query()
            ->where('status', 'active')
            ->with(['user:id,name,image', 'firstpackage:id,price,vendor_service_id'])
            ->select('id', 'title', 'user_id', 'image', 'tags')
            ->withAvg('servicerating', 'rating')
            ->when(request('tags') != '', function ($query) {
                $query->whereJsonContains('tags', request('tags'));
            })
            ->when(request('category_id') != '', function ($query) {
                $query->where('service_category_id', request('category_id'));
            })
            ->when(request('type') == 'latest', function ($query) {
                $query->latest();
            })
            ->when(request('type') == 'best_selling', function ($query) {
                $query->withCount('serviceorders')
                    ->orderBy('serviceorders_count', 'desc');
            })
            ->when(request('type') == 'avg_rating', function ($query) {
                $query->withAvg('servicerating', 'rating')
                    ->orderBy('servicerating_avg_rating', 'desc');
            })
            ->when(request('search') != '', function ($query) {
                $query->where('title', 'like', '%' . request('search') . '%');
            })
            ->inRandomOrder()
            ->paginate(12);

        $datas->each(function ($item) {
            $item->servicerating_avg_rating = number_format($item->servicerating_avg_rating ?? 0, 2);
        });

        return $datas;
    }
}
