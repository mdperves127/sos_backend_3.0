<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CampaignCategory;
use App\Models\Placement;
use Illuminate\Http\Request;

class PlacementController extends Controller
{

    public function index($colum)
    {
        $placements = Placement::where($colum, '!=', '')
        ->when($colum == '',function($query) use($colum){
            $query->select('id', $colum);
        },function($query) use ($colum){
            $query->select('id', $colum,'campaign_category_id')
            ->with('category')
            ;
        })
        ->get();

        return $this->response($placements);
    }


    public function store(Request $request, $colum)
    {
        $request->validate([
            'colum_name' => 'required',
            'campaign_category_id' => [function ($attribute, $value, $fail) {
                if (request('colum_name') == 'add_format') {
                    $category = CampaignCategory::find(request('campaign_category_id'));
                    if (!$category) {
                        $fail('Campaign category invalid');
                    }
                }
            }]

        ]);
        if (!$colum) {
            return responsejson('Not found !', 'fail');
        }
        Placement::create([
            'colum_name' => $colum,
            $colum => request($colum),
            'campaign_category_id'=>request('campaign_category_id')
        ]);

        return $this->response('Added successfully.');
    }

    public function show($id, $colum)
    {

        $placement = Placement::select('id', $colum)->find($id);

        return $this->response($placement);
    }

    public function update($id, $colum)
    {
        if (!$id) {
            return responsejson('Not found !', 'fail');
        }
        Placement::find($id)->update([
            $colum => request($colum),
            'campaign_category_id'=>request('campaign_category_id')
        ]);
        return $this->response('Update successfull');
    }


    public function delete($id)
    {
        Placement::find($id)->delete();
        return $this->response('Deleted successfully.');
    }
}
