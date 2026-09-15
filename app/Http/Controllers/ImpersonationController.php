<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Users\UserResource;
use App\Support\Impersonation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ImpersonationController extends Controller
{
    /**
     * Stop impersonating and return to the user's page in the admin panel.
     */
    public function destroy(Request $request, Impersonation $impersonation): Response
    {
        $impersonated = $request->user();

        abort_if($impersonation->stop() === null, 403);

        // The admin panel is not an Inertia page, so leave the SPA entirely.
        return Inertia::location(UserResource::getUrl('view', ['record' => $impersonated]));
    }
}
