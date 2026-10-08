<?php

namespace Modules\Appointments\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Appointments\Database\Factories\ServiceFactory;

class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    public const COLORS = ['#0ea5a4', '#2563eb', '#7c3aed', '#db2777', '#ea580c', '#ca8a04', '#16a34a', '#475569'];

    protected $fillable = ['workspace_id', 'name', 'description', 'duration_minutes', 'price', 'color', 'is_active'];

    protected function casts(): array
    {
        return ['duration_minutes' => 'integer', 'price' => 'float', 'is_active' => 'boolean'];
    }

    protected static function newFactory(): ServiceFactory
    {
        return ServiceFactory::new();
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $term === '' ? $query : $query->where('name', 'like', '%'.$term.'%');
    }

    public function durationLabel(): string
    {
        $hours = intdiv($this->duration_minutes, 60);
        $minutes = $this->duration_minutes % 60;

        return trim(($hours ? $hours.'h ' : '').($minutes ? $minutes.'min' : ''));
    }
}
