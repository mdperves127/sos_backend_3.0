<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequiremntRequest;
use App\Http\Requests\UpdateCustomerRequiremntRequest;
use App\Models\CustomerRequiremnt;

class CustomerRequiremntController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreCustomerRequiremntRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreCustomerRequiremntRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\CustomerRequiremnt  $customerRequiremnt
     * @return \Illuminate\Http\Response
     */
    public function show(CustomerRequiremnt $customerRequiremnt)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateCustomerRequiremntRequest  $request
     * @param  \App\Models\CustomerRequiremnt  $customerRequiremnt
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateCustomerRequiremntRequest $request, CustomerRequiremnt $customerRequiremnt)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\CustomerRequiremnt  $customerRequiremnt
     * @return \Illuminate\Http\Response
     */
    public function destroy(CustomerRequiremnt $customerRequiremnt)
    {
        //
    }
}
