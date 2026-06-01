<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DOWNLOADING = 'downloading';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'product_id',
        'inventory_import_id',
        'source_url',
        'path',
        'sort_order',
        'is_primary',
        'status',
        'error_message',
        'attempts',
        'queued_at',
        'downloaded_at',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'queued_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isPendingDownload(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_DOWNLOADING], true);
    }

    public function publicUrl(): ?string
    {
        if ($this->path && Storage::disk('public')->exists($this->path)) {
            return Storage::disk('public')->url($this->path);
        }

        return $this->source_url;
    }
}
