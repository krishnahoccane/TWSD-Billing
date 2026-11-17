@php use App\Modules\Support\Models\SupportRequest; @endphp
<x-layouts.app :title="$item->subject" :description="($item->organisation?->name ?? '').' · '.(SupportRequest::CATEGORIES[$item->category] ?? $item->category)"
               :breadcrumbs="[['label' => 'Support', 'url' => route('support.index')], ['label' => $item->subject]]">
    <x-slot:actions>
        <x-ui.badge :tone="$item->tone()">{{ SupportRequest::STATUSES[$item->status] ?? $item->status }}</x-ui.badge>
    </x-slot:actions>

    <div class="max-w-3xl space-y-6">
        <x-ui.card :title="($item->user?->name ?? 'Someone').' · '.$item->created_at->format('d M Y H:i')">
            <p class="whitespace-pre-line break-words text-sm">{{ $item->message }}</p>
        </x-ui.card>

        @if ($item->response)
            <x-ui.card :title="'Response · '.($item->responder?->name ?? 'Support').' · '.$item->responded_at?->format('d M Y H:i')">
                <p class="whitespace-pre-line break-words text-sm">{{ $item->response }}</p>
            </x-ui.card>
        @endif

        @if ($canManage)
            <x-ui.card title="Respond">
                <form method="POST" action="{{ route('support.update', $item->uuid) }}" class="space-y-4">
                    @csrf @method('PUT')
                    <x-ui.form.field label="Status" for="status">
                        <x-ui.form.select name="status" :options="SupportRequest::STATUSES" :value="$item->status" />
                    </x-ui.form.field>
                    <x-ui.form.field label="Response (shown to the requester)" for="response">
                        <x-ui.form.textarea name="response" rows="5" maxlength="5000" :value="$item->response" />
                    </x-ui.form.field>
                    <x-ui.button type="submit">Save and notify</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
</x-layouts.app>
