<?php

namespace App\Support;

/**
 * Normalize and shape the tenant Header & Footer Builder JSON payload.
 */
class HeaderFooterConfig
{
    public const TEMPLATES = ['one', 'two', 'three', 'four'];

    private const HEADER_BOOLS = [
        'show_offers',
        'show_search',
        'show_cart',
        'show_wishlist',
        'show_account',
        'show_categories',
    ];

    private const FOOTER_BOOLS = [
        'show_features',
        'show_social',
        'show_contact',
        'show_payment',
    ];

    /**
     * Decode a stored value into an array, or null when empty / invalid.
     */
    public static function decode( mixed $value ): ?array
    {
        if ( $value === null || $value === '' || $value === [] ) {
            return null;
        }

        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( ! is_array( $decoded ) || $decoded === [] ) {
                return null;
            }
            $value = $decoded;
        }

        if ( ! is_array( $value ) ) {
            return null;
        }

        return $value;
    }

    /**
     * Shape stored config for API responses (admin GET).
     * Returns null when nothing is saved.
     */
    public static function forAdminResponse( mixed $value ): ?array
    {
        $config = self::decode( $value );
        if ( $config === null ) {
            return null;
        }

        return self::shape( $config );
    }

    /**
     * Shape for public frontend CMS with safe defaults.
     */
    public static function forFrontend( mixed $value ): array
    {
        $config = self::decode( $value );

        if ( $config === null ) {
            return [
                'active_header' => 'one',
                'active_footer' => 'one',
                'header'        => null,
                'footer'        => null,
            ];
        }

        return [
            'active_header' => self::templateOrDefault( $config['active_header'] ?? null ),
            'active_footer' => self::templateOrDefault( $config['active_footer'] ?? null ),
            'header'        => array_key_exists( 'header', $config )
                ? self::sectionForResponse( $config['header'] )
                : null,
            'footer'        => array_key_exists( 'footer', $config )
                ? self::sectionForResponse( $config['footer'] )
                : null,
        ];
    }

    /**
     * Build the canonical four-key object to persist.
     * Preserves nested structure and empty strings; only normalizes known booleans.
     */
    public static function buildForStorage( array $input ): array
    {
        $header = is_array( $input['header'] ?? null ) ? $input['header'] : [];
        $footer = is_array( $input['footer'] ?? null ) ? $input['footer'] : [];

        return [
            'active_header' => self::templateOrDefault( $input['active_header'] ?? null ),
            'active_footer' => self::templateOrDefault( $input['active_footer'] ?? null ),
            'header'        => self::normalizeBooleans( $header, self::HEADER_BOOLS ),
            'footer'        => self::normalizeBooleans( $footer, self::FOOTER_BOOLS ),
        ];
    }

    public static function normalizeBoolean( mixed $value ): bool
    {
        if ( is_bool( $value ) ) {
            return $value;
        }

        if ( is_int( $value ) || is_float( $value ) ) {
            return (int) $value === 1;
        }

        if ( is_string( $value ) ) {
            $normalized = strtolower( trim( $value ) );
            if ( in_array( $normalized, ['false', 'no', '0', 'off', ''], true ) ) {
                return false;
            }
            if ( in_array( $normalized, ['true', 'yes', '1', 'on'], true ) ) {
                return true;
            }
        }

        return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
    }

    private static function shape( array $config ): array
    {
        return [
            'active_header' => self::templateOrDefault( $config['active_header'] ?? null ),
            'active_footer' => self::templateOrDefault( $config['active_footer'] ?? null ),
            'header'        => self::sectionForResponse( $config['header'] ?? null ),
            'footer'        => self::sectionForResponse( $config['footer'] ?? null ),
        ];
    }

    /**
     * Empty PHP arrays encode as JSON [] — coerce to {} for object sections.
     */
    private static function sectionForResponse( mixed $section ): mixed
    {
        if ( $section === null ) {
            return null;
        }

        if ( ! is_array( $section ) ) {
            return $section;
        }

        return $section === [] ? new \stdClass() : $section;
    }

    private static function templateOrDefault( mixed $value ): string
    {
        $value = is_string( $value ) ? strtolower( trim( $value ) ) : $value;

        return in_array( $value, self::TEMPLATES, true ) ? $value : 'one';
    }

    private static function normalizeBooleans( array $section, array $keys ): array
    {
        foreach ( $keys as $key ) {
            if ( array_key_exists( $key, $section ) ) {
                $section[$key] = self::normalizeBoolean( $section[$key] );
            }
        }

        return $section;
    }
}
