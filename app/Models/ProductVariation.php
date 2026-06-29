<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariation extends Model
{
    protected $fillable = [
        'sku_padre',
        'sku',
        'letra',
        'atributos',
        'stock_482845934',
        'stock_7196119',
        'stock_82385465',
        'stock_total',
        'inventory_import_id',
        'wc_status',
        'wc_variation_id',
        'wc_error',
        'wc_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'atributos'      => 'array',
            'wc_synced_at'   => 'datetime',
            'stock_total'    => 'integer',
            'stock_482845934' => 'integer',
            'stock_7196119'  => 'integer',
            'stock_82385465' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku_padre', 'sku');
    }

    public function inventoryImport(): BelongsTo
    {
        return $this->belongsTo(InventoryImport::class);
    }

    public function isPending(): bool   { return $this->wc_status === 'pending'; }
    public function isSynced(): bool    { return $this->wc_status === 'synced'; }
    public function isFailed(): bool    { return $this->wc_status === 'failed'; }

    public function stockBySede(): array
    {
        $sedes = config('woocommerce.sedes', []);
        $result = [];
        foreach ($sedes as $id => $nombre) {
            $col = 'stock_' . $id;
            $result[] = [
                'id'     => $id,
                'nombre' => $nombre,
                'stock'  => $this->{$col} ?? 0,
            ];
        }
        return $result;
    }
}
