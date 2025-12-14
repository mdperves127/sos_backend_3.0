<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Note;

class NoteController extends Controller
{
    public function myNote() {
        $myNotes = Note::on('mysql')->where( 'user_id', tenant()->id )->orWhereNull( 'user_id' )->paginate( 10 );
        return response()->json( [
            'status' => 200,
            'notes'  => $myNotes,
        ] );
    }

}
