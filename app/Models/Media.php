<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    protected $table = 'media';

    protected $guarded = [];

    protected $casts = [
        'size' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo( User::class, 'user_id' );
    }

    public function getUrlAttribute(): string
    {
        return self::absoluteUrl( (string) $this->path );
    }

    /**
     * Build a public URL on the current tenant host (not central APP_URL / tenancy asset proxy).
     */
    public static function absoluteUrl( string $path ): string
    {
        $path = ltrim( $path, '/' );

        if ( $path === '' ) {
            return '';
        }

        if ( str_starts_with( $path, 'http://' ) || str_starts_with( $path, 'https://' ) ) {
            return $path;
        }

        $base = self::tenantPublicBaseUrl();

        return rtrim( $base, '/' ) . '/' . $path;
    }

    public static function tenantPublicBaseUrl(): string
    {
        // API is initialized by tenant domain — use that host for public file URLs.
        if ( function_exists( 'request' ) && request() ) {
            $host = request()->getSchemeAndHttpHost();
            if ( is_string( $host ) && $host !== '' ) {
                return rtrim( $host, '/' );
            }
        }

        if ( function_exists( 'tenant' ) && tenant() ) {
            $domain = tenant()->domains()->first();
            if ( $domain && ! empty( $domain->domain ) ) {
                $scheme = ( function_exists( 'request' ) && request() )
                    ? request()->getScheme()
                    : 'https';

                return $scheme . '://' . $domain->domain;
            }
        }

        return rtrim( (string) config( 'app.url' ), '/' );
    }

    public static function tenantUploadDirectory(): string
    {
        $tenantId = ( function_exists( 'tenant' ) && tenant() )
            ? (string) tenant()->id
            : 'shared';

        // Sanitize for filesystem safety.
        $tenantId = preg_replace( '/[^A-Za-z0-9_\-]/', '_', $tenantId ) ?: 'shared';

        return 'uploads/media/' . $tenantId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $created = $this->created_at?->toIso8601String();
        $updated = $this->updated_at?->toIso8601String();

        return [
            'id'           => $this->id,
            'fileName'     => (string) $this->file_name,
            'originalName' => (string) $this->original_name,
            'mimeType'     => (string) $this->mime_type,
            'extension'    => (string) $this->extension,
            'size'         => (int) $this->size,
            'url'          => $this->url,
            'path'         => (string) $this->path,
            'createdAt'    => $created,
            'updatedAt'    => $updated,
            'created_at'   => $created,
            'updated_at'   => $updated,
        ];
    }

    public function deleteFileFromDisk(): void
    {
        $path = ltrim( (string) $this->path, '/' );
        if ( $path === '' ) {
            return;
        }

        $full = public_path( $path );
        if ( is_file( $full ) ) {
            @unlink( $full );
        }
    }
}
