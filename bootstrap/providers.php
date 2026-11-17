<?php

use App\Modules\AccessControl\AccessControlServiceProvider;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Domains\DomainsServiceProvider;
use App\Modules\GoogleWorkspace\GoogleWorkspaceServiceProvider;
use App\Modules\Installer\InstallerServiceProvider;
use App\Modules\Organisations\OrganisationsServiceProvider;
use App\Modules\Zoho\ZohoServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    InstallerServiceProvider::class,    // first: switches to install mode when there is no .env
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    AccessControlServiceProvider::class,
    OrganisationsServiceProvider::class,
    GoogleWorkspaceServiceProvider::class,
    DomainsServiceProvider::class,
    BillingServiceProvider::class,
    ZohoServiceProvider::class,
];
