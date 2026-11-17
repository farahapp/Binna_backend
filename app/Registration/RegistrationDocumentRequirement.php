<?php

declare(strict_types=1);

namespace App\Registration;

use Illuminate\Database\Eloquent\Model;

class RegistrationDocumentRequirement extends Model
{
    protected $connection = 'mysql';

    protected $table = 'registration_document_requirements';

    protected $fillable = [
        'code',
        'label_ar',
        'label_en',
        'legal_type',
        'activity_type',
        'is_required',
        'expires',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'expires' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
