<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Note;

class NoteController extends Controller
{
    public function myNote() {
        $myNotes = Note::on('mysql')->where( 'tenant_id', tenant()->id )->paginate( 10 );
        return response()->json( [
            'status' => 200,
            'notes'  => $myNotes,
        ] );
    }

}
