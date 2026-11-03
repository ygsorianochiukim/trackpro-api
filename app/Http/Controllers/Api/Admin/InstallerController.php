<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Installation;
use App\Models\Installer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstallerController extends Controller
{
    /** GET /api/admin/installers — the installation crew, with how many open jobs each holds. */
    public function index(): JsonResponse
    {
        $rows = Installer::withCount(['installations as open_jobs' => fn ($q) => $q->open()])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rows]);
    }

    /** POST /api/admin/installers */
    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => Installer::create($this->validated($request))], 201);
    }

    /** PATCH /api/admin/installers/{installer} */
    public function update(Request $request, Installer $installer): JsonResponse
    {
        $installer->update($this->validated($request, true));

        return response()->json(['data' => $installer->fresh()]);
    }

    /** DELETE /api/admin/installers/{installer} — their not-yet-started jobs go back to the backlog. */
    public function destroy(Installer $installer): JsonResponse
    {
        DB::transaction(function () use ($installer) {
            Installation::where('installer_id', $installer->id)
                ->whereIn('status', ['pending', 'scheduled'])
                ->update(['installer_id' => null, 'status' => 'pending']);

            $installer->delete();
        });

        return response()->json(['message' => 'Deleted']);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'skills' => ['nullable', 'string', 'max:160'],
            'base_address' => ['nullable', 'string', 'max:255'],
            'base_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:base_lng'],
            'base_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:base_lat'],
            'service_radius_km' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'daily_capacity' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'name.required' => 'Enter the installer’s name.',
            'daily_capacity.max' => 'Twenty jobs a day is the most one person can be booked for.',
        ]);
    }
}
