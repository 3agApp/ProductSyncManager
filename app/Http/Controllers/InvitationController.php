<?php

namespace App\Http\Controllers;

use App\Actions\Shops\PendingShopInvitations;
use App\Models\ShopInvitation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    /**
     * Show the shop invitations waiting for the user's answer.
     */
    public function index(Request $request, PendingShopInvitations $pending): Response
    {
        return Inertia::render('invitations/index', [
            'invitations' => $pending->query($request->user())
                ->with(['inviter', 'shop'])
                ->latest()
                ->get()
                ->map(fn (ShopInvitation $invitation) => [
                    'code' => $invitation->code,
                    'inviterName' => $invitation->inviter->name,
                    'roleLabel' => $invitation->role->label(),
                    'expiresAtDiff' => $invitation->expires_at?->diffForHumans(),
                    'shop' => [
                        'name' => $invitation->shop->name,
                        'slug' => $invitation->shop->slug,
                    ],
                ])
                ->all(),
        ]);
    }
}
