<?php

namespace App\Modules\Domains\Services;

use App\Models\User;
use App\Modules\Domains\Models\DomainHistory;
use App\Modules\Domains\Models\DomainRequest;
use Illuminate\Support\Facades\Context;

class DomainHistoryRecorder
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function record(DomainRequest $request, string $event, array $details = [], ?User $actor = null): DomainHistory
    {
        return DomainHistory::query()->create([
            'organisation_id' => $request->organisation_id,
            'organisation_domain_id' => $request->organisation_domain_id,
            'domain_request_id' => $request->getKey(),
            'user_id' => $actor?->getKey(),
            'domain_name' => $request->domain_name,
            'event' => $event,
            'details' => $details === [] ? null : $details,
            'request_id' => Context::get('request_id'),
        ]);
    }
}
