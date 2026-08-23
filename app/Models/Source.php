<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $source_type
 * @property string $username
 * @property string|null $title
 * @property string|null $description
 * @property int $subscribers_count
 * @property bool $is_active
 * @property bool $is_own
 * @property string|null $category
 * @property Carbon|null $last_collected_at
 * @property Carbon|null $last_queried_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Post> $posts
 * @property-read Collection<int, CollectionRun> $collectionRuns
 */
class Source extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source_type',
        'username',
        'title',
        'description',
        'subscribers_count',
        'is_active',
        'is_own',
        'category',
        'last_collected_at',
        'last_queried_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_own' => 'boolean',
            'subscribers_count' => 'integer',
            'last_collected_at' => 'datetime',
            'last_queried_at' => 'datetime',
        ];
    }

    /**
     * Scope for sources actively tracked within TTL.
     *
     * @param  Builder<Source>  $query
     * @return Builder<Source>
     */
    public function scopeActiveTracked($query, int $ttlDays = 14)
    {
        return $query->where('is_active', true)
            ->where(function ($q) use ($ttlDays) {
                $q->where('last_queried_at', '>=', now()->subDays($ttlDays))
                    ->orWhere('is_own', true);
            });
    }

    /**
     * Scope for expired sources that should be pruned.
     *
     * @param  Builder<Source>  $query
     * @return Builder<Source>
     */
    public function scopeExpired($query, int $ttlDays = 14)
    {
        return $query->where('is_own', false)
            ->where(function ($q) use ($ttlDays) {
                $q->where('last_queried_at', '<', now()->subDays($ttlDays))
                    ->orWhere(function ($sub) use ($ttlDays) {
                        $sub->whereNull('last_queried_at')
                            ->where('created_at', '<', now()->subDays($ttlDays));
                    });
            });
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * @return HasMany<CollectionRun, $this>
     */
    public function collectionRuns(): HasMany
    {
        return $this->hasMany(CollectionRun::class);
    }
}
