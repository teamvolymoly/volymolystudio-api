<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UniqueSlugGenerator
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public function generate(string $value, string $modelClass): string
    {
        $base = Str::slug($value) ?: 'item';
        $candidate = $base;
        $suffix = 2;

        while ($modelClass::query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
