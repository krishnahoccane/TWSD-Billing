{{-- "Sync from Google": runs the domain synchronisation immediately (WorkspaceController::run). --}}
<form method="POST" action="{{ route('workspace.sync.run') }}" x-data="{ busy: false }" x-on:submit="busy = true">
    @csrf
    <input type="hidden" name="type" value="domains">
    <x-ui.button type="submit" variant="secondary" icon="refresh-cw" x-bind:disabled="busy">
        <span x-show="! busy">Sync domains from Google</span>
        <span x-show="busy" x-cloak>Synchronising…</span>
    </x-ui.button>
</form>
