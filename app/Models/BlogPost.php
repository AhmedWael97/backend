<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class BlogPost extends Model
{
    protected $fillable = [
        'slug', 'title_en', 'title_ar', 'excerpt_en', 'excerpt_ar',
        'body_en', 'body_ar', 'cover_image', 'status', 'published_at',
    ];

    protected $appends = ['cover_image_url'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function getCoverImageUrlAttribute(): ?string
    {
        return $this->cover_image ? Storage::disk('public')->url($this->cover_image) : null;
    }

    /** Localized helpers — fall back to EN when the AR field is empty. */
    public function title(string $locale): string
    {
        return $locale === 'ar' ? ($this->title_ar ?: $this->title_en) : $this->title_en;
    }

    public function scopePublished($q)
    {
        return $q->where('status', 'published')->whereNotNull('published_at');
    }
}
