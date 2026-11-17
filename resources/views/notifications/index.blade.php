<x-layouts.app title="Notifications" :description="$unreadCount.' unread'"
               :breadcrumbs="[['label' => 'Notifications']]">
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('account.notifications')" icon="settings">E-mail settings</x-ui.button>
        @if ($unreadCount > 0)
            <form method="POST" action="{{ route('notifications.read') }}">@csrf <x-ui.button type="submit" variant="secondary" icon="check">Mark all read</x-ui.button></form>
        @endif
    </x-slot:actions>

    <x-ui.table>
        <x-slot:toolbar>
            <x-ui.button :variant="$unreadOnly ? 'ghost' : 'secondary'" size="sm" :href="route('notifications.index')">All</x-ui.button>
            <x-ui.button :variant="$unreadOnly ? 'secondary' : 'ghost'" size="sm" :href="route('notifications.index', ['unread' => 1])">Unread</x-ui.button>
        </x-slot:toolbar>
        <x-slot:head><th></th><th>Notification</th><th>Organisation</th><th>When</th></x-slot:head>

        @forelse ($notifications as $notification)
            <tr @class(['font-medium' => $notification->read_at === null])>
                <td class="w-4"><span class="block size-2 rounded-full {{ $notification->read_at === null ? 'bg-accent-500' : 'bg-transparent' }}"></span></td>
                <td>
                    <a href="{{ route('notifications.open', $notification->id) }}" class="hover:underline">{{ $notification->data['title'] ?? 'Notification' }}</a>
                    <div class="muted text-xs font-normal">{{ $notification->data['body'] ?? '' }}</div>
                </td>
                <td class="muted font-normal">{{ $notification->data['organisation'] ?? '—' }}</td>
                <td class="muted whitespace-nowrap font-normal">{{ $notification->created_at->diffForHumans() }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="!p-0"><x-ui.empty-state title="No notifications" icon="bell" /></td></tr>
        @endforelse

        @if ($notifications->hasPages())
            <x-slot:footer>{{ $notifications->links() }}</x-slot:footer>
        @endif
    </x-ui.table>
</x-layouts.app>
