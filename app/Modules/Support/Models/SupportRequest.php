<?php

namespace App\Modules\Support\Models;

use App\Models\User;
use App\Modules\AccessControl\Concerns\BelongsToOrganisation;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportRequest extends Model
{
    use BelongsToOrganisation, HasUuid;

    public const CATEGORIES = [
        'email' => 'Email accounts',
        'domain' => 'Domains',
        'billing' => 'Billing and payments',
        'access' => 'Dashboard access',
        'other' => 'Something else',
    ];

    public const STATUSES = [
        'open' => 'Open',
        'in_progress' => 'In progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function tone(): string
    {
        return ['open' => 'warning', 'in_progress' => 'info', 'resolved' => 'success'][$this->status] ?? 'neutral';
    }
}
