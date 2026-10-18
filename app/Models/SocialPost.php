<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One attempt to publish a post from the platform's own social series (see App\Social). */
class SocialPost extends Model
{
    public const STATUS_POSTED = 'posted';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'channel', 'kind', 'key', 'title', 'message', 'image_path', 'external_id',
        'status', 'scheduled_for', 'posted_at', 'error',
    ];

    protected function casts(): array
    {
        return ['scheduled_for' => 'datetime', 'posted_at' => 'datetime'];
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    /** The post on Facebook, for the admin log. Graph returns ids like PAGEID_POSTID, which the site resolves. */
    public function permalink(): ?string
    {
        return $this->external_id ? 'https://www.facebook.com/'.$this->external_id : null;
    }
}
