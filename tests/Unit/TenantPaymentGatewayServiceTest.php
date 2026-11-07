<?php

namespace Tests\Unit;

use App\Models\PaymentGatewaySetting;
use App\Services\EpsConfig;
use App\Services\TenantPaymentGatewayService;
use App\Services\UddoktaPayService;
use Tests\TestCase;

class TenantPaymentGatewayServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        EpsConfig::clearRuntimeCredentials();
        UddoktaPayService::clearRuntimeCredentials();
        parent::tearDown();
    }

    public function test_mask_hides_secret_prefix(): void
    {
        $this->assertNull( TenantPaymentGatewayService::mask( '' ) );
        $this->assertSame( '****', TenantPaymentGatewayService::mask( 'abcd' ) );
        $masked = TenantPaymentGatewayService::mask( 'super-secret-key-9999' );
        $this->assertStringEndsWith( '9999', $masked );
        $this->assertStringStartsWith( '*', $masked );
        $this->assertStringNotContainsString( 'super-secret', $masked );
    }

    public function test_secret_placeholders_are_detected(): void
    {
        $this->assertTrue( TenantPaymentGatewayService::isSecretPlaceholder( '' ) );
        $this->assertTrue( TenantPaymentGatewayService::isSecretPlaceholder( '********' ) );
        $this->assertTrue( TenantPaymentGatewayService::isSecretPlaceholder( '****abcd' ) );
        $this->assertFalse( TenantPaymentGatewayService::isSecretPlaceholder( 'real-api-key' ) );
    }

    public function test_merge_keeps_existing_secrets_when_placeholder_sent(): void
    {
        $existing = [
            'api_key'  => 'keep-me-secret',
            'base_url' => 'https://sandbox.uddoktapay.com',
        ];

        $merged = TenantPaymentGatewayService::mergeCredentials(
            PaymentGatewaySetting::GATEWAY_UDDOKTAPAY,
            $existing,
            [
                'api_key'  => '********',
                'base_url' => 'https://pay.uddoktapay.com',
            ]
        );

        $this->assertSame( 'keep-me-secret', $merged['api_key'] );
        $this->assertSame( 'https://pay.uddoktapay.com', $merged['base_url'] );
    }

    public function test_merge_updates_secret_when_new_value_provided(): void
    {
        $merged = TenantPaymentGatewayService::mergeCredentials(
            PaymentGatewaySetting::GATEWAY_UDDOKTAPAY,
            ['api_key' => 'old-key', 'base_url' => 'https://sandbox.uddoktapay.com'],
            ['api_key' => 'new-key']
        );

        $this->assertSame( 'new-key', $merged['api_key'] );
        $this->assertSame( 'https://sandbox.uddoktapay.com', $merged['base_url'] );
    }

    public function test_uddoktapay_credentials_completeness(): void
    {
        $this->assertFalse( TenantPaymentGatewayService::credentialsAreComplete(
            PaymentGatewaySetting::GATEWAY_UDDOKTAPAY,
            ['api_key' => 'x']
        ) );

        $this->assertTrue( TenantPaymentGatewayService::credentialsAreComplete(
            PaymentGatewaySetting::GATEWAY_UDDOKTAPAY,
            [
                'api_key'  => 'x',
                'base_url' => 'https://sandbox.uddoktapay.com',
            ]
        ) );
    }

    public function test_eps_credentials_completeness_requires_mode(): void
    {
        $base = [
            'username'    => 'user',
            'password'    => 'pass',
            'hash_key'    => 'hash',
            'merchant_id' => 'm1',
            'store_id'    => 's1',
        ];

        $this->assertFalse( TenantPaymentGatewayService::credentialsAreComplete(
            PaymentGatewaySetting::GATEWAY_EPS,
            $base + ['mode' => 'invalid']
        ) );

        $this->assertTrue( TenantPaymentGatewayService::credentialsAreComplete(
            PaymentGatewaySetting::GATEWAY_EPS,
            $base + ['mode' => EpsConfig::MODE_SANDBOX]
        ) );
    }

    public function test_eps_runtime_credentials_override_platform_env(): void
    {
        config( [
            'services.eps.mode' => 'live',
            'services.eps.credentials.live.username' => 'platform-user',
            'services.eps.credentials.live.password' => 'platform-pass',
            'services.eps.credentials.live.hash_key' => 'platform-hash',
            'services.eps.credentials.live.merchant_id' => 'platform-m',
            'services.eps.credentials.live.store_id' => 'platform-s',
        ] );

        $this->assertSame( 'platform-user', EpsConfig::get( 'username' ) );

        EpsConfig::useRuntimeCredentials( [
            'mode'        => 'sandbox',
            'username'    => 'tenant-user',
            'password'    => 'tenant-pass',
            'hash_key'    => 'tenant-hash',
            'merchant_id' => 'tenant-m',
            'store_id'    => 'tenant-s',
        ] );

        $this->assertSame( 'sandbox', EpsConfig::mode() );
        $this->assertSame( 'tenant-user', EpsConfig::get( 'username' ) );
        $this->assertSame( 'tenant-pass', EpsConfig::get( 'password' ) );
        $this->assertTrue( EpsConfig::isConfigured() );

        EpsConfig::clearRuntimeCredentials();
        $this->assertSame( 'platform-user', EpsConfig::get( 'username' ) );
    }

    public function test_uddoktapay_runtime_credentials_do_not_use_platform_key(): void
    {
        config( [
            'services.uddoktapay.api_key'  => 'platform-api-key',
            'services.uddoktapay.base_url' => 'https://platform.example',
        ] );

        UddoktaPayService::useRuntimeCredentials( [
            'api_key'  => 'tenant-api-key',
            'base_url' => 'https://tenant.example',
        ] );

        $this->assertTrue( UddoktaPayService::isValidWebhookApiKey( 'tenant-api-key' ) );
        $this->assertFalse( UddoktaPayService::isValidWebhookApiKey( 'platform-api-key' ) );

        UddoktaPayService::clearRuntimeCredentials();
        $this->assertTrue( UddoktaPayService::isValidWebhookApiKey( 'platform-api-key' ) );
    }

    public function test_public_status_never_exposes_raw_secrets(): void
    {
        $setting = new PaymentGatewaySetting( [
            'gateway' => PaymentGatewaySetting::GATEWAY_UDDOKTAPAY,
            'enabled' => true,
        ] );
        $setting->credentials = [
            'api_key'  => 'super-secret-api-key',
            'base_url' => 'https://sandbox.uddoktapay.com',
        ];

        $status = TenantPaymentGatewayService::publicStatus(
            PaymentGatewaySetting::GATEWAY_UDDOKTAPAY,
            $setting
        );

        $encoded = json_encode( $status );
        $this->assertStringNotContainsString( 'super-secret-api-key', $encoded );
        $this->assertTrue( $status['configured'] );
        $this->assertTrue( $status['usable'] );
        $this->assertArrayHasKey( 'api_key_masked', $status );
    }

    public function test_encrypt_round_trip(): void
    {
        $this->assertSame(
            'tenant-secret',
            TenantPaymentGatewayService::encryptRoundTrip( 'tenant-secret' )
        );
    }
}
