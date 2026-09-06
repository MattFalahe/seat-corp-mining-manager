<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Models\TheftIncident;
use MiningManager\Models\MiningLedger;
use MiningManager\Services\Theft\TheftDetectionService;
use MiningManager\Services\Character\CharacterInfoService;
use Carbon\Carbon;
use MiningManager\Http\Controllers\Concerns\GuardsDataExport;

class TheftIncidentController extends Controller
{
    use GuardsDataExport;

    protected $detectionService;
    protected $characterService;

    public function __construct(
        TheftDetectionService $detectionService,
        CharacterInfoService $characterService
    ) {
        $this->detectionService = $detectionService;
        $this->characterService = $characterService;
    }

    /**
     * Display list of theft incidents with filters
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        // Build query
        $query = TheftIncident::with(['character', 'corporation', 'miningTax']);

        // Apply filters
        if ($request->filled('status')) {
            $status = $request->input('status');

            // Special handling for theft list filters
            if ($status === 'on_list') {
                $query->where('on_theft_list', true)
                      ->whereIn('status', ['detected', 'investigating']);
            } elseif ($status === 'removed_paid') {
                $query->where('status', 'removed_paid');
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->filled('character_id')) {
            $query->where('character_id', $request->input('character_id'));
        }

        if ($request->filled('corporation_id')) {
            $query->where('corporation_id', $request->input('corporation_id'));
        }

        // Active only filter
        if ($request->has('active_only') && $request->input('active_only') == 1) {
            $query->where('is_active_theft', true);
        }

        // Date range filter
        if ($request->filled('date_from') && $request->filled('date_to')) {
            $dateFrom = Carbon::parse($request->input('date_from'))->startOfDay();
            $dateTo = Carbon::parse($request->input('date_to'))->endOfDay();
            $query->whereBetween('incident_date', [$dateFrom, $dateTo]);
        }

        // Default sorting - prioritize active thefts, severity by logical order
        $query->orderBy('is_active_theft', 'desc')
              ->orderBy('incident_date', 'desc')
              ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')");

        $incidents = $query->paginate(50);

        // Get statistics for summary cards
        $statistics = $this->detectionService->getStatistics();

        // Count active thefts for alert banner
        $activeTheftsCount = TheftIncident::activeThefts()->count();

        // Count theft list and removed (paid)
        $theftListCount = TheftIncident::onTheftList()->count();
        $removedPaidCount = TheftIncident::removedPaid()
            ->where('resolved_at', '>=', Carbon::now()->subMonth())
            ->count();

        // Get current status for filter
        $status = $request->input('status');

        return view('mining-manager::theft.index', array_merge(compact(
            'incidents',
            'statistics',
            'activeTheftsCount',
            'theftListCount',
            'removedPaidCount',
            'status'
        ), ['features' => app(\MiningManager\Services\Configuration\SettingsManagerService::class)->getFeatureFlags()]));
    }

    /**
     * Display details of a specific theft incident
     *
     * @param int $id
     * @return \Illuminate\View\View
     */
    public function show($id)
    {
        $incident = TheftIncident::with(['character', 'corporation', 'miningTax'])
            ->findOrFail($id);

        // Get mining history for the character during the incident period
        $miningHistory = MiningLedger::where('character_id', $incident->character_id)
            ->whereBetween('date', [$incident->mining_date_from, $incident->mining_date_to])
            ->where('is_moon_ore', true)
            ->with(['type'])
            ->orderBy('date', 'desc')
            ->get();

        // Get character info (handles unregistered characters)
        $characterInfo = $this->characterService->getCharacterInfo($incident->character_id);

        return view('mining-manager::theft.show', compact('incident', 'miningHistory', 'characterInfo'));
    }

    /**
     * Update the status of an incident
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:detected,investigating,resolved,false_alarm,removed_paid',
            'notes' => 'nullable|string|max:1000',
        ]);

        $incident = TheftIncident::findOrFail($id);

        $status = $request->input('status');
        $notes = $request->input('notes');

        try {
            $shouldNotifyResolved = false;

            if ($status === 'investigating') {
                $incident->markInvestigating($notes);
                $message = 'Incident marked as under investigation.';
            } elseif ($status === 'resolved') {
                $incident->resolve(Auth::id(), $notes);
                $message = 'Incident marked as resolved.';
                $shouldNotifyResolved = true;
            } elseif ($status === 'false_alarm') {
                $incident->markFalseAlarm(Auth::id(), $notes);
                $message = 'Incident marked as false alarm.';
                $shouldNotifyResolved = true;
            } else {
                $incident->status = $status;
                if ($notes) {
                    $incident->notes = $notes;
                }
                $incident->save();
                $message = 'Incident status updated.';
            }

            Log::info('TheftIncidentController: Incident status updated', [
                'incident_id' => $id,
                'new_status' => $status,
                'user_id' => Auth::id()
            ]);

            // Fire incident_resolved webhooks when the incident moves out of
            // active status (either to 'resolved' or 'false_alarm').
            if ($shouldNotifyResolved) {
                try {
                    app(\MiningManager\Services\Notification\TheftNotificationService::class)
                        ->notifyIncidentResolved($incident->fresh());
                } catch (\Exception $e) {
                    Log::warning('TheftIncidentController: Failed to dispatch incident-resolved notification', [
                        'incident_id' => $id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return redirect()->back()->with('success', $message);

        } catch (\Exception $e) {
            Log::error('TheftIncidentController: Failed to update incident status', [
                'incident_id' => $id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()->with('error', 'Failed to update incident status: ' . $e->getMessage());
        }
    }

    /**
     * Resolve an incident with notes
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function resolve(Request $request, $id)
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000',
            'resolution_type' => 'required|in:resolved,false_alarm',
        ]);

        $incident = TheftIncident::findOrFail($id);
        $notes = $request->input('notes');
        $resolutionType = $request->input('resolution_type');

        try {
            if ($resolutionType === 'false_alarm') {
                $incident->markFalseAlarm(Auth::id(), $notes);
                $message = 'Incident marked as false alarm.';
            } else {
                $incident->resolve(Auth::id(), $notes);
                $message = 'Incident resolved successfully.';
            }

            Log::info('TheftIncidentController: Incident resolved', [
                'incident_id' => $id,
                'resolution_type' => $resolutionType,
                'user_id' => Auth::id()
            ]);

            // Fire incident_resolved webhooks for either resolution type.
            try {
                app(\MiningManager\Services\Notification\TheftNotificationService::class)
                    ->notifyIncidentResolved($incident->fresh());
            } catch (\Exception $e) {
                Log::warning('TheftIncidentController: Failed to dispatch incident-resolved notification', [
                    'incident_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }

            return redirect()->route('mining-manager.theft.index')
                ->with('success', $message);

        } catch (\Exception $e) {
            Log::error('TheftIncidentController: Failed to resolve incident', [
                'incident_id' => $id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()->with('error', 'Failed to resolve incident: ' . $e->getMessage());
        }
    }

    /**
     * Export incidents to CSV
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function export(Request $request)
    {
        if (!$this->dataExportIsAllowed()) {
            return $this->refuseDataExport($request);
        }

        // Build query with same filters as index
        $query = TheftIncident::with(['character', 'corporation', 'miningTax']);

        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === 'on_list') {
                $query->where('on_theft_list', true)
                      ->whereIn('status', ['detected', 'investigating']);
            } elseif ($status === 'active') {
                $query->where('is_active_theft', true)
                      ->whereIn('status', ['detected', 'investigating']);
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->filled('character_id')) {
            $query->where('character_id', $request->input('character_id'));
        }

        if ($request->filled('corporation_id')) {
            $query->where('corporation_id', $request->input('corporation_id'));
        }

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $dateFrom = Carbon::parse($request->input('date_from'))->startOfDay();
            $dateTo = Carbon::parse($request->input('date_to'))->endOfDay();
            $query->whereBetween('incident_date', [$dateFrom, $dateTo]);
        }

        $incidents = $query->orderBy('incident_date', 'desc')->limit(50000)->get();

        $filename = 'theft_incidents_' . Carbon::now()->format('Y-m-d_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($incidents) {
            $file = fopen('php://output', 'w');

            // CSV headers
            fputcsv($file, [
                'Incident ID',
                'Character ID',
                'Character Name',
                'Corporation ID',
                'Corporation Name',
                'Incident Date',
                'Mining Period Start',
                'Mining Period End',
                'Ore Value (ISK)',
                'Tax Owed (ISK)',
                'Quantity Mined',
                'Status',
                'Severity',
                'Notes',
                'Resolved At',
                'Resolved By'
            ]);

            // Data rows
            foreach ($incidents as $incident) {
                fputcsv($file, [
                    $incident->id,
                    $incident->character_id,
                    $incident->getCharacterName(),
                    $incident->corporation_id,
                    $incident->getCorporationName(),
                    $incident->incident_date->toDateTimeString(),
                    $incident->mining_date_from->toDateString(),
                    $incident->mining_date_to->toDateString(),
                    $incident->ore_value,
                    $incident->tax_owed,
                    $incident->quantity_mined,
                    $incident->status,
                    $incident->severity,
                    $incident->notes,
                    $incident->resolved_at ? $incident->resolved_at->toDateTimeString() : '',
                    $incident->resolved_by
                ]);
            }

            fclose($file);
        };

        Log::info('TheftIncidentController: Exported incidents to CSV', [
            'count' => $incidents->count(),
            'user_id' => Auth::id()
        ]);

        return response()->stream($callback, 200, $headers);
    }
}
