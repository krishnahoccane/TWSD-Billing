<?php

namespace App\Http\Controllers;

use App\Modules\Organisations\Services\OrganisationContext;
use App\Modules\Organisations\Services\OrganisationDashboard;
use App\Modules\Organisations\Services\PlatformOverview;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, OrganisationContext $context, PlatformOverview $overview, OrganisationDashboard $dashboard): View
    {
        $user = $request->user();

        // Platform staff (Super Admin, Operations Admin). Picking one
        // organisation in the header switches to that organisation's view.
        $organisation = $context->current($user);

        if ($user->isPrivileged() && ($organisation === null || ! $request->session()->has('wcc.organisation'))) {
            return view('dashboard.platform', [
                'stats' => $overview->stats($user),
                'charts' => $overview->charts($user),
                'recentActivity' => $overview->recentActivity($user),
            ]);
        }

        return view('dashboard.organisation', [
            'organisation' => $organisation,
            'memberships' => $overview->memberships($user),
            'data' => $organisation ? $dashboard->for($user, $organisation) : null,
        ]);
    }
}
