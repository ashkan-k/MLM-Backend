<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'mobile',
        'person_type',
        'national_id',
        'father_name',
        'birth_date',
        'birth_certificate_no',
        'birth_place',
        'gender',
        'email',
        'province',
        'city',
        'address',
        'postal_code',
        'sheba',
        'bank_name',
        'account_number',
        'account_holder',
        'shop_name',
        'shop_category',
        'website',
        'company_name',
        'registration_no',
        'economic_code',
        'legal_national_id',
        'documents',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'documents' => 'array',
            'birth_date' => 'date',
        ];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(GatewaySale::class);
    }
}
