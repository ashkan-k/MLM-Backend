<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FraSoftMapping extends Model
{
    protected $table = 'frasoft_mappings';

    protected $fillable = ['entity_type', 'internal_id', 'external_id', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
