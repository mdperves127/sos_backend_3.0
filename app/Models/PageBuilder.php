<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PageBuilder extends Model
{
    use SoftDeletes;

    protected $table = 'page_builders';

    protected $guarded = [];

    protected $casts = [
        'blocks'       => 'array',
        'seo'          => 'array',
        'settings'     => 'array',
        'sort_order'   => 'integer',
        'published_at' => 'datetime',
    ];

    public const PAGE_TYPES = [
        'STANDARD',
        'SERVICE',
        'LOCATION',
        'LANDING',
        'CATEGORY',
        'BLOG',
    ];

    public const STATUSES = [
        'DRAFT',
        'PUBLISHED',
        'ARCHIVED',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $created = $this->created_at?->toIso8601String();
        $updated = $this->updated_at?->toIso8601String();

        return [
            'id'          => (string) $this->id,
            'path'        => (string) $this->path,
            'slug'        => (string) $this->slug,
            'pageName'    => (string) $this->page_name,
            'pageType'    => (string) $this->page_type,
            'template'    => $this->template,
            'status'      => (string) $this->status,
            'blocks'      => $this->blocks ?? [],
            'sortOrder'   => (int) ( $this->sort_order ?? 0 ),
            'publishedAt' => $this->published_at?->toIso8601String(),
            'seo'         => $this->seo,
            'settings'    => $this->settings,
            'createdAt'   => $created,
            'updatedAt'   => $updated,
            // snake_case aliases for flexibility
            'page_name'    => (string) $this->page_name,
            'page_type'    => (string) $this->page_type,
            'sort_order'   => (int) ( $this->sort_order ?? 0 ),
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at'   => $created,
            'updated_at'   => $updated,
        ];
    }
}
