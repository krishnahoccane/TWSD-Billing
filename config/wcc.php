<?php

/*
|--------------------------------------------------------------------------
| Workspace Control Center configuration
|--------------------------------------------------------------------------
|
| Application code must read these values through config('wcc.*') and never
| call env() directly. Secrets referenced here must never be rendered into
| Blade or Livewire component state.
|
*/

return [

    'security' => [
        // Privileged users (anyone holding a platform-level role) must enrol
        // in TOTP two-factor authentication before using the application.
        'enforce_two_factor' => (bool) env('WCC_ENFORCE_2FA', true),

        // Lifetime of API tokens issued by POST /api/v1/login, in minutes.
        'api_token_ttl_minutes' => (int) env('WCC_API_TOKEN_TTL', 480),
    ],

    'google' => [
        'enabled' => (bool) env('GOOGLE_WORKSPACE_ENABLED', false),
        'project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
        // Path to the service-account JSON key file, stored outside the web root.
        'service_account_json' => env('GOOGLE_SERVICE_ACCOUNT_JSON'),
        'admin_email' => env('GOOGLE_ADMIN_EMAIL'),
        'customer_id' => env('GOOGLE_CUSTOMER_ID', 'my_customer'),
        'delegation_enabled' => (bool) env('GOOGLE_WORKSPACE_DELEGATION_ENABLED', false),
        // Two-person rule: the Super Admin who requests a permanent deletion
        // cannot approve it. Keep true in production.
        'require_second_approver' => (bool) env('GOOGLE_REQUIRE_SECOND_APPROVER', true),
    ],

    'domains' => [
        // Two-person rule for domain removal: the requester cannot approve.
        // (A request to ADD a domain can never be approved by its requester.)
        'require_second_approver' => (bool) env('DOMAIN_REQUIRE_SECOND_APPROVER', true),
    ],

    'zoho' => [
        'enabled' => (bool) env('ZOHO_ENABLED', false),
        'client_id' => env('ZOHO_CLIENT_ID'),
        'client_secret' => env('ZOHO_CLIENT_SECRET'),
        'redirect_uri' => env('ZOHO_REDIRECT_URI'),
        // Data-centre specific. India: accounts.zoho.in / www.zohoapis.in
        'accounts_base_url' => env('ZOHO_ACCOUNTS_BASE_URL', 'https://accounts.zoho.in'),
        'api_base_url' => env('ZOHO_API_BASE_URL', 'https://www.zohoapis.in/books/v3'),
        'organisation_id' => env('ZOHO_ORGANISATION_ID'),
        // Shared secret for incoming Zoho webhooks (12–50 letters/digits).
        'webhook_secret' => env('ZOHO_WEBHOOK_SECRET'),
    ],

    'billing' => [
        'currency' => env('BILLING_CURRENCY', 'INR'),
        'timezone' => env('BILLING_TIMEZONE', 'Asia/Kolkata'),
        'default_cycle' => env('BILLING_DEFAULT_CYCLE', 'monthly'),
        'default_due_days' => (int) env('BILLING_DEFAULT_DUE_DAYS', 15),
        // Percentage, kept as a string so it is never handled as a float.
        'default_tax_rate' => (string) env('BILLING_DEFAULT_TAX_RATE', '18.00'),
        // Your own GST state code (first two digits of your GSTIN, e.g. 36 =
        // Telangana). A customer in the same state pays CGST + SGST (each half
        // the rate, rounded per line, as Zoho Books does); any other state pays
        // IGST. Unknown customer state = same state (Zoho's default).
        'supplier_state_code' => env('BILLING_SUPPLIER_STATE_CODE'),
        // Largest difference (paise) between our total and Zoho's that is
        // treated as rounding: not flagged, and written off when Zoho shows
        // the invoice paid.
        'rounding_tolerance_minor' => (int) env('BILLING_ROUNDING_TOLERANCE_PAISE', 100),
        // prorate_daily (default) | full_month
        'proration_policy' => env('BILLING_PRORATION_POLICY', 'prorate_daily'),
        // {YY} / {YYYY} = calendar year of the issue date, {SEQ} = running number,
        // restarting at 1 each year. Default gives 26/TWSD-001.
        'invoice_number_format' => env('BILLING_INVOICE_NUMBER_FORMAT', '{YY}/TWSD-{SEQ}'),
        'invoice_number_padding' => (int) env('BILLING_INVOICE_NUMBER_PADDING', 3),
        'credit_note_prefix' => env('BILLING_CREDIT_NOTE_PREFIX', 'CN-'),
        // Super Admins (payments.reconcile) may record offline payments they
        // have verified, such as bank transfers. They are pushed to Zoho Books.
        'allow_manual_payments' => (bool) env('BILLING_ALLOW_MANUAL_PAYMENTS', true),
    ],

];
