<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

trait FilterTrait
{

    function scopefilteredPagination($query)
    {
        $perPageDataStore = 10;
        $perPageDataGet = request('perpage');

        if (($perPageDataGet <= 500) && ($perPageDataGet > 0)) {
            $perPageDataStore = $perPageDataGet;
        }

        return $query->paginate($perPageDataStore);
    }


    function scopeSearch(Builder $builder, string $term = "")
    {
        foreach ($this->searchables as $searchable) {
            if (str_contains($searchable, '.')) {

                $relation = Str::beforeLast($searchable, '.');
                $column = Str::afterLast($searchable, '.');

                $builder->orWhereRelation($relation, $column, 'like', "%{$term}%");
                continue;
            }
            $builder->orWhere($searchable, 'like', "%{$term}%");
        }
        return $builder;
    }
}
