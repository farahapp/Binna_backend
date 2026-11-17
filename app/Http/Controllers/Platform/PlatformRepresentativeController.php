<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Foundation\PlatformUser;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Registration\RepresentativeAuthController;
use App\Registration\RegistrationAuditService;
use App\Registration\Representative;
use App\Registration\TenantRegistrationIdentityAuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlatformRepresentativeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Representative::on('mysql')->select([
            'id', 'name', 'email', 'phone', 'status', 'approved_by', 'approved_at', 'suspended_at', 'created_at',
        ]);
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json($query->latest('id')->paginate(min(max((int) $request->query('per_page', 20), 1), 100)));
    }

    public function audit(int $id, RegistrationAuditService $audit): JsonResponse
    {
        $representative = Representative::on('mysql')->findOrFail($id);
        $events = TenantRegistrationIdentityAuditEvent::on('mysql')
            ->where('subject_type', 'representative')
            ->where('subject_id', $representative->id)
            ->latest('id')
            ->paginate(50);
        $audit->recordIdentity('platform', request()->user()->id, 'representative', $representative->id, 'representative.audit_viewed');

        return response()->json($events);
    }

    public function store(Request $request, RegistrationAuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:mysql.registration_representatives,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'verification_confirmed' => ['required', 'accepted'],
            'verification_reference' => ['required', 'string', 'min:2', 'max:255'],
        ]);
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $representative = DB::connection('mysql')->transaction(function () use ($data, $actor, $audit): Representative {
            $representative = Representative::on('mysql')->create([
                'name' => trim($data['name']),
                'email' => Str::lower(trim($data['email'])),
                'phone' => $data['phone'] ?? null,
                'status' => 'pending',
            ]);
            $audit->recordIdentity('platform', $actor->id, 'representative', $representative->id, 'representative.created', [
                'verification_reference' => trim($data['verification_reference']),
            ]);

            return $representative;
        });

        return response()->json(['representative' => $this->payload($representative)], 201);
    }

    public function approve(
        Request $request,
        int $id,
        RegistrationAuditService $audit,
        RepresentativeAuthController $auth,
    ): JsonResponse {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $representative = DB::connection('mysql')->transaction(function () use ($id, $actor, $audit): Representative {
            $representative = Representative::on('mysql')->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($representative->status !== 'pending') {
                abort(409, 'Only a pending representative can be approved.');
            }
            $representative->status = 'invited';
            $representative->approved_by = $actor->id;
            $representative->approved_at = now();
            $representative->save();
            $audit->recordIdentity('platform', $actor->id, 'representative', $representative->id, 'representative.approved');

            return $representative;
        });
        $auth->sendInvite($representative, 'activation');
        $audit->recordIdentity('platform', $actor->id, 'representative', $representative->id, 'representative.invitation_sent');

        return response()->json(['representative' => $this->payload($representative)]);
    }

    public function resendInvitation(
        Request $request,
        int $id,
        RegistrationAuditService $audit,
        RepresentativeAuthController $auth,
    ): JsonResponse {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $representative = Representative::on('mysql')->findOrFail($id);
        abort_unless(
            $representative->status === 'invited' && ! $representative->password,
            409,
            'This representative cannot receive an activation invitation.',
        );
        $auth->sendInvite($representative, 'activation');
        $audit->recordIdentity('platform', $actor->id, 'representative', $representative->id, 'representative.invitation_resent');

        return response()->json(['message' => 'Activation invitation sent.']);
    }

    public function suspend(Request $request, int $id, RegistrationAuditService $audit): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $representative = DB::connection('mysql')->transaction(function () use ($id, $actor, $audit): Representative {
            $representative = Representative::on('mysql')->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($representative->status === 'suspended') {
                abort(409, 'Representative is already suspended.');
            }
            $representative->status = 'suspended';
            $representative->suspended_at = now();
            $representative->save();
            DB::connection('mysql')->table('registration_representative_tokens')
                ->where('representative_id', $representative->id)->delete();
            DB::connection('mysql')->table('registration_representative_invites')
                ->where('representative_id', $representative->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);
            $audit->recordIdentity('platform', $actor->id, 'representative', $representative->id, 'representative.suspended');

            return $representative;
        });

        return response()->json(['representative' => $this->payload($representative)]);
    }

    public function reactivate(Request $request, int $id, RegistrationAuditService $audit): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $representative = DB::connection('mysql')->transaction(function () use ($id, $actor, $audit): Representative {
            $representative = Representative::on('mysql')->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($representative->status !== 'suspended') {
                abort(409, 'Only a suspended representative can be restored.');
            }
            $representative->status = $representative->password ? 'active' : 'invited';
            $representative->suspended_at = null;
            $representative->save();
            $audit->recordIdentity('platform', $actor->id, 'representative', $representative->id, 'representative.reactivated');

            return $representative;
        });
        if ($representative->status === 'invited') {
            app(RepresentativeAuthController::class)->sendInvite($representative, 'activation');
        }

        return response()->json(['representative' => $this->payload($representative)]);
    }

    private function payload(Representative $representative): array
    {
        return [
            'id' => $representative->id,
            'name' => $representative->name,
            'email' => $representative->email,
            'phone' => $representative->phone,
            'status' => $representative->status,
            'approved_by' => $representative->approved_by,
            'approved_at' => $representative->approved_at?->toIso8601String(),
            'suspended_at' => $representative->suspended_at?->toIso8601String(),
            'created_at' => $representative->created_at?->toIso8601String(),
        ];
    }
}
