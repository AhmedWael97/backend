<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class UpgradeTicketMessage extends Model
{
    protected $fillable = [
        'upgrade_ticket_id', 'sender_user_id', 'is_admin', 'is_system',
        'body', 'attachment_path', 'attachment_name', 'attachment_mime',
    ];

    protected $appends = ['attachment_url'];

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(UpgradeTicket::class, 'upgrade_ticket_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function getAttachmentUrlAttribute(): ?string
    {
        return $this->attachment_path ? Storage::url($this->attachment_path) : null;
    }
}
