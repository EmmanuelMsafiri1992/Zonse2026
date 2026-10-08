<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'name', 'code', 'phone', 'email', 'address', 'city', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
