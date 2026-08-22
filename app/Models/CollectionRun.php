<?php

namespace App\Models;

use App\Enums\CollectionRunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int|null $source_id
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property int $posts_fetched
 * @property int $posts_saved
 * @property CollectionRunStatus $status
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Source|null $source
 */
class CollectionRun extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'source_id',
        'started_at',
        'completed_at',
        'posts_fetched',
        'posts_saved',
        'status',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'posts_fetched' => 'integer',
            'posts_saved' => 'integer',
            'status' => CollectionRunStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
