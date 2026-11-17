<x-layouts.app title="Google Workspace integration" description="Connection status of the Admin SDK Directory API."
               :breadcrumbs="[['label' => 'Integrations'], ['label' => 'Google Workspace']]">
    <div class="max-w-3xl space-y-6">
        @if ($mode === 'mock')
            <x-ui.alert tone="warning" title="Mock mode — not connected to Google">
                The application is using its built-in mock directory. Mailbox operations are simulated and nothing reaches
                your Google Workspace. To go live, complete the checklist below on the server and set
                <span class="font-mono text-xs">GOOGLE_WORKSPACE_ENABLED=true</span>.
            </x-ui.alert>
        @else
            <x-ui.alert tone="success" title="Live mode">Mailbox operations are sent to Google Workspace.</x-ui.alert>
        @endif

        <x-ui.card title="Configuration checklist" :padding="false"
                   description="Values come from the server environment. Credentials are never shown, entered or stored on this page.">
            <table class="data-table">
                <tbody>
                    @foreach ($checks as $check)
                        <tr>
                            <td class="w-8">
                                <x-ui.icon :name="$check['ok'] ? 'circle-check' : 'circle-alert'"
                                           class="size-4 {{ $check['ok'] ? 'text-emerald-600' : 'text-amber-600' }}" />
                            </td>
                            <td>{{ $check['label'] }}</td>
                            <td class="muted text-right">{{ $check['detail'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <x-ui.card title="Connection test"
                   description="Reads one user through the active provider. In mock mode this does not contact Google.">
            @if ($lastTest?->last_tested_at)
                <p class="text-sm">
                    Last test {{ $lastTest->last_tested_at->diffForHumans() }}:
                    <x-ui.badge :tone="$lastTest->last_test_status === 'success' ? 'success' : 'danger'">{{ ucfirst($lastTest->last_test_status) }}</x-ui.badge>
                </p>
                <p class="muted mt-1 break-words text-xs">{{ $lastTest->last_test_message }}</p>
            @else
                <p class="muted text-sm">No test has been run yet.</p>
            @endif
            <form method="POST" action="{{ route('integrations.google.test') }}" class="mt-3">
                @csrf
                <x-ui.button type="submit" variant="secondary" icon="plug">Test connection</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card title="Required access in Google">
            <ul class="list-disc space-y-1 pl-5 text-sm">
                <li>A dedicated Google Cloud service account with the Admin SDK API enabled.</li>
                <li>Either an admin role assigned to the service account, or domain-wide delegation to a dedicated admin user.</li>
                <li>Scopes: directory user, user security (sign-out), domain and organisational unit.</li>
            </ul>
            <p class="muted mt-3 text-xs">Full steps: <span class="font-mono">docs/GOOGLE_WORKSPACE_INTEGRATION.md</span>.</p>
        </x-ui.card>
    </div>
</x-layouts.app>
