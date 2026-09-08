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
        $path = ltrim( (string) $this->path, '/' );

        if ( $path === '' ) {
            return '';
        }

        if ( str_starts_with( $path, 'http://' ) || str_starts_with( $path, 'https://' ) ) {
            return $path;
        }

        return asset( $path );
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
