<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

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

    protected $appends = ['document_urls'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'documents' => 'array',
            'birth_date' => 'date',
        ];
    }

    protected function documentUrls(): Attribute
    {
        return Attribute::get(function () {
            $docs = $this->documents ?? [];
            if (! is_array($docs)) {
                return [];
            }

            return collect($docs)->mapWithKeys(function ($path, $key) {
                return [$key => $path ? Storage::disk('public')->url($path) : null];
            })->all();
        });
    }

    public function sales(): HasMany
    {
        return $this->hasMany(GatewaySale::class);
    }
}
