<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AttributeDefinition extends Model
{
    protected $table = 'attributes';

    protected $fillable = [
        'code',
        'label_es',
        'slug',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'attribute_product', 'attribute_id', 'product_id')
            ->withPivot('value')
            ->withTimestamps();
    }
}
