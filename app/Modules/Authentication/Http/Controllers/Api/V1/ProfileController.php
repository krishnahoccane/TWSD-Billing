<?php

namespace App\Modules\Authentication\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Http\Resources\ProfileResource;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __invoke(Request $request): ProfileResource
    {
        return new ProfileResource($request->user());
    }
}
