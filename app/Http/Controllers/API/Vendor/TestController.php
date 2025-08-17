<?php

namespace App\Http\Controllers\API\Vendor;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoretestRequest;
use App\Http\Requests\UpdatetestRequest;
use App\Models\Test;
use App\Services\Vendor\TestService;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Services\SmsService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class TestController extends Controller
{

    protected $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $datas = Test::latest()->paginate(10);
        return response($datas);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoretestRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoretestRequest $request)
    {
        $validateData = $request->validated();
        TestService::create($validateData);
        return $this->response('Data Created Successfully');

    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\test  $test
     * @return \Illuminate\Http\Response
     */
    public function show(Test $test)
    {
        return response($test);
    }



    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\test  $test
     * @return \Illuminate\Http\Response
     */
    public function edit(Test $test)
    {
        return response($test);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdatetestRequest  $request
     * @param  \App\Models\test  $test
     * @return \Illuminate\Http\Response
     */
    public function update(UpdatetestRequest $request, Test $test)
    {
        $validateData = $request->validated();
        return TestService::update($validateData, $test->id);
        return $this->response('Data Updated Successfully');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\test  $test
     * @return \Illuminate\Http\Response
     */
    public function destroy(Test $test)
    {
        $test->delete();
        return response('item deleted successfully');
    }


    public function sendSms(Request $request)
    {
        $user =[
            'number' => '8801671668631',
            'verify_code' => '59658'
        ];
        SmsService::sendSms($user);

        return response()->json([
            'status' => 200,
            'message' => 'Successfully send!',
        ]);

    }
}
