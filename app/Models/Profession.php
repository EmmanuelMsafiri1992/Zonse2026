<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profession extends Model
{
    protected $fillable = ['key', 'name', 'description', 'icon', 'group', 'module_keys', 'is_featured', 'sort_order'];

    protected function casts(): array
    {
        return ['module_keys' => 'array', 'is_featured' => 'boolean'];
    }

    /** Recommended modules that actually exist in the catalogue. */
    public function recommendedModules()
    {
        $keys = Module::expandDependencies($this->module_keys ?? []);

        // Eloquent's only() filters by primary key, so filter on our string keys explicitly.
        return Module::byKey()->filter(fn (Module $m) => in_array($m->key, $keys, true))->sortBy('sort_order');
    }
}
