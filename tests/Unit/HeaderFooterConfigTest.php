<?php

namespace Tests\Unit;

use App\Support\HeaderFooterConfig;
use Tests\TestCase;

class HeaderFooterConfigTest extends TestCase
{
    public function test_empty_stored_value_returns_null_for_admin(): void
    {
        $this->assertNull( HeaderFooterConfig::forAdminResponse( null ) );
        $this->assertNull( HeaderFooterConfig::forAdminResponse( '' ) );
        $this->assertNull( HeaderFooterConfig::forAdminResponse( [] ) );
        $this->assertNull( HeaderFooterConfig::forAdminResponse( '{}' ) );
    }

    public function test_frontend_defaults_when_unconfigured(): void
    {
        $this->assertSame( [
            'active_header' => 'one',
            'active_footer' => 'one',
            'header'        => null,
            'footer'        => null,
        ], HeaderFooterConfig::forFrontend( null ) );
    }

    public function test_boolean_normalization_does_not_treat_false_strings_as_true(): void
    {
        $stored = HeaderFooterConfig::buildForStorage( [
            'active_header' => 'two',
            'active_footer' => 'three',
            'header'        => [
                'logo'           => '',
                'show_offers'    => 'false',
                'show_search'    => 'no',
                'show_cart'      => '0',
                'show_wishlist'  => false,
                'show_account'   => 'yes',
                'show_categories'=> 1,
                'nav_links'      => [],
            ],
            'footer'        => [
                'description'  => '',
                'show_features'=> 'true',
                'show_social'  => 'no',
                'show_contact' => 0,
                'show_payment' => true,
            ],
        ] );

        $this->assertSame( 'two', $stored['active_header'] );
        $this->assertSame( 'three', $stored['active_footer'] );
        $this->assertSame( '', $stored['header']['logo'] );
        $this->assertFalse( $stored['header']['show_offers'] );
        $this->assertFalse( $stored['header']['show_search'] );
        $this->assertFalse( $stored['header']['show_cart'] );
        $this->assertFalse( $stored['header']['show_wishlist'] );
        $this->assertTrue( $stored['header']['show_account'] );
        $this->assertTrue( $stored['header']['show_categories'] );
        $this->assertTrue( $stored['footer']['show_features'] );
        $this->assertFalse( $stored['footer']['show_social'] );
        $this->assertFalse( $stored['footer']['show_contact'] );
        $this->assertTrue( $stored['footer']['show_payment'] );
        $this->assertSame( '', $stored['footer']['description'] );
    }

    public function test_stores_only_four_top_level_keys(): void
    {
        $stored = HeaderFooterConfig::buildForStorage( [
            'active_header' => 'one',
            'active_footer' => 'one',
            'header'        => ['logo' => 'https://cdn.example/logo.png'],
            'footer'        => ['copyright' => '© 2026'],
            'headers'       => ['one' => ['logo' => 'should-not-store']],
            'extra'         => 'ignored',
        ] );

        $this->assertSame(
            ['active_header', 'active_footer', 'header', 'footer'],
            array_keys( $stored )
        );
        $this->assertSame( 'https://cdn.example/logo.png', $stored['header']['logo'] );
    }

    public function test_decodes_json_string_compatibly(): void
    {
        $json = json_encode( [
            'active_header' => 'four',
            'active_footer' => 'two',
            'header'        => ['phone' => '123'],
            'footer'        => ['copyright' => 'c'],
        ] );

        $admin = HeaderFooterConfig::forAdminResponse( $json );
        $this->assertSame( 'four', $admin['active_header'] );
        $this->assertSame( '123', $admin['header']['phone'] );

        $front = HeaderFooterConfig::forFrontend( $json );
        $this->assertSame( 'four', $front['active_header'] );
        $this->assertSame( 'two', $front['active_footer'] );
    }

    public function test_switching_header_preserves_footer_when_frontend_sends_complete_payload(): void
    {
        $existing = [
            'active_header' => 'one',
            'active_footer' => 'two',
            'header'        => ['logo' => 'old-header'],
            'footer'        => ['copyright' => 'keep-me', 'show_social' => true],
        ];

        $next = HeaderFooterConfig::buildForStorage( [
            'active_header' => 'three',
            'active_footer' => $existing['active_footer'],
            'header'        => ['logo' => 'new-header', 'show_cart' => 'yes'],
            'footer'        => $existing['footer'],
        ] );

        $this->assertSame( 'three', $next['active_header'] );
        $this->assertSame( 'two', $next['active_footer'] );
        $this->assertSame( 'new-header', $next['header']['logo'] );
        $this->assertTrue( $next['header']['show_cart'] );
        $this->assertSame( 'keep-me', $next['footer']['copyright'] );
        $this->assertTrue( $next['footer']['show_social'] );
    }
}
