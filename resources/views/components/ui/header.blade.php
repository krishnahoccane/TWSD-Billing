@props(['breadcrumbs' => []])
@php
    $user = auth()->user();
    $unread = $user->unreadNotifications()->latest()->limit(5)->get();
    $roles = app(\App\Modules\AccessControl\Services\AccessResolver::class)->roleLabels($user);
@endphp

<header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-zinc-200 bg-white/90 px-4 backdrop-blur sm:px-6 lg:px-8 dark:border-zinc-800 dark:bg-zinc-900/90">
    <button type="button" class="muted -ml-1 rounded-md p-1.5 hover:bg-zinc-100 lg:hidden dark:hover:bg-zinc-800"
            @click="sidebarOpen = true" aria-label="Open navigation">
        <x-ui.icon name="menu" class="size-5" />
    </button>

    <div class="hidden min-w-0 flex-1 sm:block">
        <x-ui.breadcrumbs :items="$breadcrumbs" />
    </div>

    <div class="ml-auto flex items-center gap-1.5">
        <livewire:organisation-switcher />

        <button type="button" x-data="themeToggle" @click="toggle()"
                class="muted rounded-md p-2 hover:bg-zinc-100 hover:text-zinc-900 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                aria-label="Toggle colour theme">
            <span x-show="!dark"><x-ui.icon name="moon" class="size-4" /></span>
            <span x-cloak x-show="dark"><x-ui.icon name="sun" class="size-4" /></span>
        </button>

        {{-- Notification panel --}}
        <x-ui.dropdown width="w-80">
            <x-slot:trigger>
                <button type="button"
                        class="muted relative rounded-md p-2 hover:bg-zinc-100 hover:text-zinc-900 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                        aria-label="Notifications">
                    <x-ui.icon name="bell" class="size-4" />
                    @if ($unread->isNotEmpty())
                        <span class="absolute top-1.5 right-1.5 size-2 rounded-full bg-accent-500 ring-2 ring-white dark:ring-zinc-900"></span>
                    @endif
                </button>
            </x-slot:trigger>

            <div class="border-b border-zinc-100 px-3 py-2 text-sm font-medium dark:border-zinc-800">Notifications</div>
            @forelse ($unread as $notification)
                <a href="{{ route('notifications.open', $notification->id) }}" class="block border-b border-zinc-100 px-3 py-2 text-sm last:border-b-0 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800">
                    <p class="font-medium">{{ $notification->data['title'] ?? 'Notification' }}</p>
                    <p class="muted line-clamp-2 text-xs">{{ $notification->data['body'] ?? '' }}</p>
                    <p class="muted text-xs">{{ $notification->created_at->diffForHumans() }}</p>
                </a>
            @empty
                <div class="muted px-3 py-6 text-center text-sm">You're all caught up.</div>
            @endforelse
            <a href="{{ route('notifications.index') }}" class="block border-t border-zinc-100 px-3 py-2 text-center text-xs text-accent-600 hover:underline dark:border-zinc-800 dark:text-accent-400">All notifications</a>
        </x-ui.dropdown>

        {{-- User menu --}}
        <x-ui.dropdown width="w-64">
            <x-slot:trigger>
                <button type="button" class="flex items-center gap-2 rounded-md p-1 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                        aria-label="Account menu">
                    <span class="flex size-7 items-center justify-center rounded-full bg-zinc-900 text-xs font-medium text-white dark:bg-zinc-100 dark:text-zinc-900">
                        {{ $user->initials() }}
                    </span>
                    <x-ui.icon name="chevron-down" class="muted hidden size-3.5 sm:block" />
                </button>
            </x-slot:trigger>

            <div class="border-b border-zinc-100 px-3 py-2.5 dark:border-zinc-800">
                <p class="truncate text-sm font-medium">{{ $user->name }}</p>
                <p class="muted truncate text-xs">{{ $user->email }}</p>
                @if ($roles)
                    <p class="muted mt-1 truncate text-xs">{{ implode(' · ', $roles) }}</p>
                @endif
            </div>
            <x-ui.dropdown.item :href="route('account.security')" icon="shield">Security</x-ui.dropdown.item>
            <x-ui.dropdown.item :href="route('account.notifications')" icon="bell">Notification settings</x-ui.dropdown.item>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-ui.dropdown.item type="submit" icon="log-out">Sign out</x-ui.dropdown.item>
            </form>
        </x-ui.dropdown>
    </div>
</header>
