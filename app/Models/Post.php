<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $source_id
 * @property string $external_id
 * @property Carbon|null $published_at
 * @property string|null $text
 * @property string|null $format_type
 * @property string|null $url
 * @property int $views
 * @property int $forwards
 * @property int $reactions
 * @property int $comments
 * @property array<string, int>|null $reactions_breakdown
 * @property float $er
 * @property float $content_score
 * @property bool $is_ad
 * @property string|null $forward_from_chat_id
 * @property array<string, mixed>|null $raw_metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read float $viral_factor
 * @property-read float $discussion_index
 * @property-read Source $source
 */
class Post extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source_id',
        'external_id',
        'published_at',
        'text',
        'format_type',
        'url',
        'views',
        'forwards',
        'reactions',
        'comments',
        'reactions_breakdown',
        'er',
        'content_score',
        'is_ad',
        'forward_from_chat_id',
        'embedding',
        'raw_metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'views' => 'integer',
            'forwards' => 'integer',
            'reactions' => 'integer',
            'comments' => 'integer',
            'reactions_breakdown' => 'array',
            'er' => 'float',
            'content_score' => 'float',
            'is_ad' => 'boolean',
            'embedding' => 'array',
            'raw_metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return Attribute<float, never>
     */
    protected function viralFactor(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->views > 0 ? round($this->forwards / $this->views, 4) : 0.0
        );
    }

    /**
     * @return Attribute<float, never>
     */
    protected function discussionIndex(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->views > 0 ? round($this->comments / $this->views, 4) : 0.0
        );
    }
}
