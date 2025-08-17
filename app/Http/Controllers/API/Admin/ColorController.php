<?php

namespace App\Http\Controllers\API\Admin;

use App\Action\Admin\ColorAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminColorRequest;
use App\Service\Admin\ColorService;
use Illuminate\Http\Request;

class ColorController extends Controller
{
    private $colorAction;

    function __construct(ColorAction $colorAction)
    {
        $this->colorAction = $colorAction;
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return ColorService::getColor();
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(AdminColorRequest $request)
    {
        $this->colorAction->store($request);
        return response()->json([
            'status'=>200,
            'message'=>'Colour created Successfully!'
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
      return  ColorService::ColorShow($id);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(AdminColorRequest $request, $id)
    {
        $this->colorAction->update($request, $id);

      return  response()->json([
            'status'=>200,
            'message'=>'Color updated Successfully!'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $this->colorAction->delete($id);

        return  response()->json([
            'status'=>200,
            'message'=>'Color Deleted Successfully!'
        ]);

    }
}
