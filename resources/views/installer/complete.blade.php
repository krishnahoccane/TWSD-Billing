<x-layouts.installer :step="$step" heading="Installation complete"
    subheading="The application is installed and the installer has been switched off.">
    <x-ui.alert tone="success" title="Ready">
        Sign in as <strong>{{ $adminEmail }}</strong>. You will be asked to set up two-factor authentication first.
    </x-ui.alert>

    <div class="space-y-2 text-sm">
        <h2 class="font-semibold">Still to do on the server</h2>
        <ul class="muted list-disc space-y-1 pl-5">
            <li>Start a queue worker: <code>php artisan queue:work --queue=google,zoho,billing,notifications,default</code> (under Supervisor).</li>
            <li>Add the scheduler to cron: <code>* * * * * php artisan schedule:run</code>.</li>
            <li>Run <code>php artisan optimize</code> after each change to <code>.env</code>.</li>
            @unless ($mailConfigured)
                <li>E-mail is not set up: password-reset and notification e-mails go to the log until <code>MAIL_*</code> is filled in.</li>
            @endunless
            <li>Connect Google Workspace and Zoho Books (see docs/DEPLOYMENT.md, go-live checklist).</li>
            <li>Back up <code>.env</code> — its <code>APP_KEY</code> is needed to read two-factor secrets and integration tokens.</li>
        </ul>
    </div>

    <div class="flex justify-end">
        <x-ui.button :href="$loginUrl">Go to sign in</x-ui.button>
    </div>
</x-layouts.installer>
