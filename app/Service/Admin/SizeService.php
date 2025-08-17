<?php
namespace App\Service\Admin;

use App\Models\Size;

class SizeService{

   static function index(){
     return   Size::where(['created_by'=>'admin'])->latest()->paginate(15);
    }
    static function  store(){

    }
}
