<?php

namespace Tests\Feature;

use App\Http\Controllers\API\FraudCheckController;
use App\Services\FraudCheckService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FraudCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config( [
            'services.fraud.api_url' => 'https://fraudbd.test',
            'services.fraud.api_key' => 'test-key',
            'services.fraud.timeout' => 5,
        ] );
    }

    public function test_successful_fraud_check_normalizes_provider_response(): void
    {
        Http::fake( [
            'fraudbd.test/api/check-courier-info' => Http::response( [
                'status'  => true,
                'message' => 'Courier info retrieved successfully.',
                'data'    => [
                    'totalSummary' => [
                        'total'       => 20,
                        'success'     => 16,
                        'cancel'      => 4,
                        'successRate' => 80,
                        'cancelRate'  => 20,
                    ],
                ],
            ], 200 ),
        ] );

        $result = app( FraudCheckService::class )->check( '01712345678' );

        $this->assertSame( '01712345678', $result['phone'] );
        $this->assertSame( 20, $result['total_orders'] );
        $this->assertSame( 16, $result['delivered'] );
        $this->assertSame( 4, $result['cancelled'] );
        $this->assertSame( 80.0, $result['success_rate'] );
        $this->assertSame( 'low', $result['risk'] );

        Http::assertSent( function ( $request ) {
            return $request->url() === 'https://fraudbd.test/api/check-courier-info'
                && $request['phone_number'] === '01712345678'
                && $request->hasHeader( 'api_key', 'test-key' );
        } );
    }

    public function test_no_history_returns_unknown_risk(): void
    {
        Http::fake( [
            'fraudbd.test/*' => Http::response( [
                'status' => true,
                'data'   => [
                    'totalSummary' => [
                        'total'       => 0,
                        'success'     => 0,
                        'cancel'      => 0,
                        'successRate' => 0,
                    ],
                ],
            ], 200 ),
        ] );

        $result = app( FraudCheckService::class )->check( '+8801712345678' );

        $this->assertSame( '01712345678', $result['phone'] );
        $this->assertSame( 0, $result['total_orders'] );
        $this->assertNull( $result['success_rate'] );
        $this->assertSame( 'unknown', $result['risk'] );
    }

    public function test_invalid_phone_is_rejected(): void
    {
        Http::fake();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Invalid Bangladesh phone number' );

        app( FraudCheckService::class )->check( '12345' );

        Http::assertNothingSent();
    }

    public function test_provider_failure_is_handled_safely(): void
    {
        Http::fake( [
            'fraudbd.test/*' => Http::response( ['message' => 'Server error'], 500 ),
        ] );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Fraud check provider returned an error.' );

        app( FraudCheckService::class )->check( '01712345678' );
    }

    public function test_controller_returns_validation_error_for_missing_phone(): void
    {
        $controller = app( FraudCheckController::class );
        $request    = Request::create( '/api/fraud-check', 'POST', [] );

        $response = $controller->check( $request, app( FraudCheckService::class ) );

        $this->assertSame( 422, $response->getStatusCode() );
        $payload = $response->getData( true );
        $this->assertFalse( $payload['success'] );
    }

    public function test_controller_returns_success_payload(): void
    {
        Http::fake( [
            'fraudbd.test/*' => Http::response( [
                'status' => true,
                'data'   => [
                    'totalSummary' => [
                        'total'       => 10,
                        'success'     => 5,
                        'cancel'      => 5,
                        'successRate' => 50,
                    ],
                ],
            ], 200 ),
        ] );

        $controller = app( FraudCheckController::class );
        $request    = Request::create( '/api/fraud-check', 'POST', [
            'phone' => '01712345678',
        ] );

        $response = $controller->check( $request, app( FraudCheckService::class ) );
        $payload  = $response->getData( true );

        $this->assertTrue( $payload['success'] );
        $this->assertSame( 10, $payload['data']['total_orders'] );
        $this->assertSame( 'medium', $payload['data']['risk'] );
        $this->assertArrayNotHasKey( 'api_key', $payload );
    }

    public function test_controller_maps_provider_timeout_safely(): void
    {
        Http::fake( function () {
            throw new \Illuminate\Http\Client\ConnectionException( 'Connection timed out' );
        } );

        $controller = app( FraudCheckController::class );
        $request    = Request::create( '/api/fraud-check', 'POST', [
            'phone' => '01712345678',
        ] );

        $response = $controller->check( $request, app( FraudCheckService::class ) );
        $payload  = $response->getData( true );

        $this->assertSame( 502, $response->getStatusCode() );
        $this->assertFalse( $payload['success'] );
        $this->assertNull( $payload['data'] );
    }
}
