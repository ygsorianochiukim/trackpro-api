<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Installation;
use App\Models\Installer;
use App\Models\Order;
use App\Support\InstallationScheduler;
use App\Support\InstallerSuggester;
use App\Support\SalesLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class InstallationController extends Controller
{
    public function __construct(private readonly InstallationScheduler $scheduler) {}

    /**
     * GET /api/admin/installations — jobs for the board and the week schedule.
     * `from`/`to` narrow by date; `with_unscheduled=1` keeps undated jobs in.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(Installation::STATUSES)],
        ]);

        $undated = $request->boolean('with_unscheduled');

        $rows = Installation::with(['installer:id,name', 'order:id,reference'])
            ->when($request->input('status'), fn ($q, $s) => $q->whereIn('status', $s))
            ->when($request->query('from'), fn ($q, $from) => $q->where(fn ($w) => $w
                ->whereDate('scheduled_on', '>=', $from)
                ->when($undated, fn ($u) => $u->orWhereNull('scheduled_on'))))
            ->when($request->query('to'), fn ($q, $to) => $q->where(fn ($w) => $w
                ->whereDate('scheduled_on', '<=', $to)
                ->when($undated, fn ($u) => $u->orWhereNull('scheduled_on'))))
            ->orderBy('position')
            ->orderBy('id')
            ->limit(1000)
            ->get();

        return response()->json(['data' => $rows->map->toAdminArray()->values()]);
    }

    /** GET /api/admin/installations/{installation} */
    public function show(Installation $installation): JsonResponse
    {
        return response()->json(['data' => $installation->load(['installer', 'order'])->toAdminArray()]);
    }

    /** POST /api/admin/installations */
    public function store(Request $request): JsonResponse
    {
        $job = $this->scheduler->create($this->validated($request));

        return response()->json(['data' => $job->toAdminArray()], 201);
    }

    /** PATCH /api/admin/installations/{installation} */
    public function update(Request $request, Installation $installation): JsonResponse
    {
        $job = $this->scheduler->update($installation, $this->validated($request, true));

        return response()->json(['data' => $job->toAdminArray()]);
    }

    /** DELETE /api/admin/installations/{installation} */
    public function destroy(Installation $installation): JsonResponse
    {
        $installation->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /** POST /api/admin/installations/{installation}/schedule — reassign / redate (week schedule drag). */
    public function schedule(Request $request, Installation $installation): JsonResponse
    {
        $data = $request->validate($this->scheduleRules(), $this->messages());

        return response()->json(['data' => $this->scheduler->schedule($installation, $data)->toAdminArray()]);
    }

    /** POST /api/admin/installations/{installation}/move — kanban drag into a column at an index. */
    public function move(Request $request, Installation $installation): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Installation::STATUSES)],
            'index' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json([
            'data' => $this->scheduler->move($installation, $data['status'], $data['index'] ?? null)->toAdminArray(),
        ]);
    }

    /** GET /api/admin/installations/suggest — installers ranked for a site, date and time. */
    public function suggest(Request $request, InstallerSuggester $suggester): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:1440'],
            'installation_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['data' => $suggester->suggest(
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
            $data['date'] ?? null,
            $data['start_time'] ?? null,
            (int) ($data['duration_minutes'] ?? 120),
            isset($data['installation_id']) ? (int) $data['installation_id'] : null,
        )]);
    }

    /** GET /api/admin/installations/options — clients and paid orders to book installs against. */
    public function options(): JsonResponse
    {
        $booked = Installation::whereNotNull('order_id')->where('status', '!=', 'cancelled')->pluck('order_id');

        return response()->json([
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'phone', 'company']),
            'orders' => Order::where('payment_status', 'paid')
                ->whereNotIn('id', $booked)
                ->latest()
                ->limit(100)
                ->get(['id', 'reference', 'customer_id', 'customer_name', 'customer_phone', 'delivery_address']),
            'installers' => Installer::orderByDesc('is_active')->orderBy('name')
                ->get(['id', 'name', 'is_active', 'base_address', 'base_lat', 'base_lng', 'service_radius_km', 'daily_capacity']),
        ]);
    }

    /** GET /api/admin/installations/dashboard */
    public function dashboard(): JsonResponse
    {
        // Calendar days in Philippine time, like the sales report.
        $today = Carbon::now(SalesLedger::TIMEZONE)->startOfDay();
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->addDays(6);

        $counts = Installation::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
            ->map(fn ($n) => (int) $n);

        $week = Installation::with('installer:id,name')
            ->whereBetween('scheduled_on', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->get();

        $list = fn ($q) => $q->with(['installer:id,name', 'order:id,reference'])->get()->map->toAdminArray()->values();

        $todayJobs = $list(Installation::whereDate('scheduled_on', $today->toDateString())
            ->where('status', '!=', 'cancelled')
            ->orderByRaw('start_time is null')->orderBy('start_time'));

        $upcoming = $list(Installation::open()
            ->whereBetween('scheduled_on', [$today->copy()->addDay()->toDateString(), $today->copy()->addDays(7)->toDateString()])
            ->orderBy('scheduled_on')->orderBy('start_time')->limit(12));

        $backlog = $list(Installation::where('status', 'pending')
            ->orderByRaw("case priority when 'high' then 0 when 'normal' then 1 else 2 end")
            ->orderBy('created_at')->limit(8));

        $installers = Installer::where('is_active', true)->orderBy('name')->get();

        $load = $installers->map(function (Installer $p) use ($week, $weekStart, $today) {
            $mine = $week->where('installer_id', $p->id);
            $days = collect(range(0, 6))->map(fn ($d) => $mine
                ->filter(fn (Installation $i) => $i->scheduled_on->toDateString() === $weekStart->copy()->addDays($d)->toDateString())
                ->count());

            return [
                'installer_id' => $p->id,
                'name' => $p->name,
                'base_address' => $p->base_address,
                'today' => $days[(int) $weekStart->diffInDays($today)] ?? 0,
                'week' => $mine->count(),
                'daily_capacity' => $p->daily_capacity,
                'days' => $days->all(),
            ];
        })->sortByDesc('week')->values();

        $pins = Installation::open()->whereNotNull('lat')
            ->where(fn ($q) => $q->whereNull('scheduled_on')->orWhereBetween('scheduled_on', [
                $today->toDateString(), $today->copy()->addDays(14)->toDateString(),
            ]))
            ->with('installer:id,name')->limit(300)->get();

        return response()->json([
            'today' => $today->toDateString(),
            'week' => ['from' => $weekStart->toDateString(), 'to' => $weekEnd->toDateString()],
            'kpis' => [
                'today' => $todayJobs->count(),
                'week' => $week->count(),
                'backlog' => $counts->get('pending', 0),
                'in_progress' => $counts->get('in_progress', 0),
                'overdue' => Installation::whereIn('status', ['scheduled', 'in_progress'])
                    ->whereDate('scheduled_on', '<', $today->toDateString())->count(),
                'completed_month' => Installation::where('status', 'completed')
                    ->where('completed_at', '>=', $today->copy()->startOfMonth()->utc())->count(),
                'personnel' => $installers->count(),
                'units_month' => (int) Installation::where('status', 'completed')
                    ->where('completed_at', '>=', $today->copy()->startOfMonth()->utc())->sum('units'),
            ],
            'by_status' => collect(Installation::STATUSES)->map(fn ($s) => ['status' => $s, 'count' => $counts->get($s, 0)]),
            'today_jobs' => $todayJobs,
            'upcoming' => $upcoming,
            'backlog' => $backlog,
            'load' => $load,
            'map' => [
                'jobs' => $pins->map(fn (Installation $i) => [
                    'id' => $i->id, 'reference' => $i->reference, 'title' => $i->title, 'address' => $i->address,
                    'lat' => $i->lat, 'lng' => $i->lng, 'status' => $i->status,
                    'scheduled_on' => $i->scheduled_on?->toDateString(), 'installer_name' => $i->installer?->name,
                ])->values(),
                'installers' => $installers->filter->isPinned()->map(fn (Installer $p) => [
                    'id' => $p->id, 'name' => $p->name, 'base_address' => $p->base_address,
                    'lat' => $p->base_lat, 'lng' => $p->base_lng, 'service_radius_km' => $p->service_radius_km,
                ])->values(),
            ],
        ]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:160'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')],
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'vehicle' => ['nullable', 'string', 'max:160'],
            'units' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'address' => [$required, 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            ...$this->scheduleRules(),
            'priority' => ['sometimes', Rule::in(Installation::PRIORITIES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'title.required' => 'Say what is being installed.',
            'address.required' => 'Enter the installation site.',
            ...$this->messages(),
        ]);
    }

    private function scheduleRules(): array
    {
        return [
            'scheduled_on' => ['nullable', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['sometimes', 'integer', 'min:15', 'max:1440'],
            'installer_id' => ['nullable', 'integer', Rule::exists('installers', 'id')],
        ];
    }

    private function messages(): array
    {
        return [
            'installer_id.exists' => 'Pick somebody on the installation crew.',
            'start_time.date_format' => 'Send the start time as HH:MM, 24-hour.',
        ];
    }
}
