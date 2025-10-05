<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\SupportBoxCategory;

class SupportBoxCategoryController extends Controller
{

    public function index()
    {
        // if(checkpermission('support-category') != 1){
        //     return $this->permissionmessage();
        // }

        // $supportBoxCategory = DB::on('mysql')->table('support_box_categories')->where('deleted_at',null)->get();
        $supportBoxCategory = SupportBoxCategory::on('mysql')->where('deleted_at',null)->get();

        return $this->response($supportBoxCategory);
    }

    function ticketcategorytoproblem($id){
        $data = SupportBoxCategory::on('mysql')->find($id);

        if (!$data) {
            return $this->response(null, 'Support box category not found', 'error');
        }

        $data->load('problems');
        return $this->response($data);
    }
}
