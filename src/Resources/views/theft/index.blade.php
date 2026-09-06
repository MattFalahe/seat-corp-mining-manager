@extends('web::layouts.grids.12')

@section('title', 'Theft Detection')
@section('page_header', 'Moon Theft Detection')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=2">
<style>
.theft-detection-wrapper {
    padding: 15px;
}
.severity-badge-critical {
    background-color: #343a40 !important;
    color: #fff !important;
}
.info-box-custom {
    min-height: 90px;
    border-radius: 5px;
}
.filter-card {
    margin-bottom: 20px;
}
.active-theft-indicator {
    display: inline-block;
    padding: 3px 8px;
    background-color: #dc3545;
    color: white;
    border-radius: 3px;
    font-weight: bold;
    animation: pulse-red 2s infinite;
    margin-right: 5px;
}
@keyframes pulse-red {
    0%, 100% {
        opacity: 1;
        transform: scale(1);
    }
    50% {
        opacity: 0.7;
        transform: scale(1.05);
    }
}
.activity-badge {
    background-color: #ffc107;
    color: #000;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 0.85em;
    font-weight: bold;
}
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard theft-detection-wrapper">

    {{-- Active Thefts Alert --}}
    @if($activeTheftsCount > 0)
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <h4 class="alert-heading">
            <i class="fas fa-exclamation-triangle"></i>
            {{ trans('mining-manager::ledger.active_miners') }} - {{ trans('mining-manager::taxes.overdue') }}
        </h4>
        <p>
            <strong>{{ $activeTheftsCount }}</strong> {{ trans('mining-manager::taxes.all_unpaid_members') }}
        </p>
        <hr>
        <p class="mb-0">
            <a href="{{ route('mining-manager.theft.index', ['active_only' => 1]) }}" class="btn btn-danger">
                <i class="fas fa-search"></i> {{ trans('mining-manager::taxes.view_details') }}
            </a>
        </p>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    @endif

    {{-- Flash Messages --}}
    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle"></i> {{ session('success') }}
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    @endif

    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle"></i> {{ session('error') }}
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    @endif

    {{-- SUMMARY STATISTICS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-exclamation-triangle text-danger"></i>
                        Theft Detection Summary
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        {{-- Total Incidents --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box info-box-custom bg-gradient-info">
                                <span class="info-box-icon">
                                    <i class="fas fa-shield-alt"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Total Incidents</span>
                                    <span class="info-box-number">{{ $statistics['total_incidents'] }}</span>
                                </div>
                            </div>
                        </div>

                        {{-- Unresolved Incidents --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box info-box-custom bg-gradient-warning">
                                <span class="info-box-icon">
                                    <i class="fas fa-hourglass-half"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Unresolved</span>
                                    <span class="info-box-number">{{ $statistics['unresolved_incidents'] }}</span>
                                </div>
                            </div>
                        </div>

                        {{-- Total Value at Risk --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box info-box-custom bg-gradient-danger">
                                <span class="info-box-icon">
                                    <i class="fas fa-coins"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Value at Risk</span>
                                    <span class="info-box-number">{{ number_format($statistics['total_value_at_risk'], 0) }}</span>
                                    <small>ISK</small>
                                </div>
                            </div>
                        </div>

                        {{-- Critical Incidents --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box info-box-custom bg-gradient-dark severity-badge-critical">
                                <span class="info-box-icon">
                                    <i class="fas fa-exclamation-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Critical Incidents</span>
                                    <span class="info-box-number">{{ $statistics['critical_incidents'] }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Second Row: Theft List Management --}}
                    <div class="row mt-3">
                        {{-- On Theft List --}}
                        <div class="col-lg-6 col-md-6">
                            <div class="info-box info-box-custom bg-gradient-warning">
                                <span class="info-box-icon">
                                    <i class="fas fa-list"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">On Theft List</span>
                                    <span class="info-box-number">{{ $theftListCount }}</span>
                                    <small>Monitored every 6 hours</small>
                                </div>
                            </div>
                        </div>

                        {{-- Removed (Paid) This Month --}}
                        <div class="col-lg-6 col-md-6">
                            <div class="info-box info-box-custom bg-gradient-success">
                                <span class="info-box-icon">
                                    <i class="fas fa-check-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Removed This Month</span>
                                    <span class="info-box-number">{{ $removedPaidCount }}</span>
                                    <small>Taxes paid and removed from list</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- FILTERS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark filter-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-filter"></i> Filters
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="collapse">
                            <i class="fas fa-minus"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <form method="GET" action="{{ route('mining-manager.theft.index') }}" id="filterForm">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Status</label>
                                    <select name="status" class="form-control">
                                        <option value="">All Statuses</option>
                                        <optgroup label="Theft List">
                                            <option value="on_list" {{ $status === 'on_list' ? 'selected' : '' }}>On Theft List</option>
                                            <option value="removed_paid" {{ $status === 'removed_paid' ? 'selected' : '' }}>Removed (Paid)</option>
                                        </optgroup>
                                        <optgroup label="Regular Status">
                                            <option value="detected" {{ $status === 'detected' ? 'selected' : '' }}>Detected</option>
                                            <option value="investigating" {{ $status === 'investigating' ? 'selected' : '' }}>Investigating</option>
                                            <option value="resolved" {{ $status === 'resolved' ? 'selected' : '' }}>Resolved</option>
                                            <option value="false_alarm" {{ $status === 'false_alarm' ? 'selected' : '' }}>False Alarm</option>
                                        </optgroup>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Severity</label>
                                    <select name="severity" class="form-control">
                                        <option value="">All Severities</option>
                                        <option value="low" {{ request('severity') == 'low' ? 'selected' : '' }}>Low</option>
                                        <option value="medium" {{ request('severity') == 'medium' ? 'selected' : '' }}>Medium</option>
                                        <option value="high" {{ request('severity') == 'high' ? 'selected' : '' }}>High</option>
                                        <option value="critical" {{ request('severity') == 'critical' ? 'selected' : '' }}>Critical</option>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Date From</label>
                                    <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Date To</label>
                                    <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Apply Filters
                                </button>
                                <a href="{{ route('mining-manager.theft.index') }}" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear Filters
                                </a>
                                @if($features['allow_export_data'] ?? true)
                                <a href="{{ route('mining-manager.theft.export', request()->all()) }}" class="btn btn-success float-right">
                                    <i class="fas fa-download"></i> Export CSV
                                </a>
                                @endif
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- INCIDENTS TABLE --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i> Theft Incidents
                    </h3>
                </div>
                <div class="card-body table-responsive p-0">
                    @if($incidents->count() > 0)
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Character</th>
                                <th>Corporation</th>
                                <th>Incident Date</th>
                                <th>Mining Period</th>
                                <th class="text-right">Ore Value</th>
                                <th class="text-right">Tax Owed</th>
                                <th>Severity</th>
                                <th>Actions</th>
                                <th class="text-center">Manage</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($incidents as $incident)
                            <tr style="{{ $incident->is_active_theft ? 'background-color: rgba(220, 53, 69, 0.1);' : '' }}">
                                <td>
                                    @if($incident->is_active_theft)
                                        <span class="active-theft-indicator" title="Active theft in progress">
                                            🔴 ACTIVE
                                        </span>
                                        <br>
                                        <span class="activity-badge" title="Number of times caught mining">
                                            Caught {{ $incident->activity_count }}x
                                        </span>
                                    @else
                                        <span class="badge {{ $incident->getStatusBadgeClass() }}">
                                            {{ ucfirst(str_replace('_', ' ', $incident->status)) }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <img src="https://images.evetech.net/characters/{{ $incident->character_id }}/portrait?size=32"
                                         class="img-circle"
                                         style="width: 32px; height: 32px; margin-right: 5px;">
                                    {{ $incident->getCharacterName() }}
                                    @if($incident->is_active_theft && $incident->last_activity_at)
                                        <br>
                                        <small class="text-muted" title="Last mining activity">
                                            <i class="fas fa-clock"></i> {{ $incident->last_activity_at->diffForHumans() }}
                                        </small>
                                    @endif
                                </td>
                                <td>
                                    @if($incident->corporation_id)
                                    <img src="https://images.evetech.net/corporations/{{ $incident->corporation_id }}/logo?size=32"
                                         class="img-circle"
                                         style="width: 32px; height: 32px; margin-right: 5px;">
                                    @endif
                                    {{ $incident->getCorporationName() }}
                                </td>
                                <td>{{ $incident->incident_date->format('Y-m-d H:i') }}</td>
                                <td>
                                    {{ $incident->mining_date_from->format('Y-m-d') }}
                                    to
                                    {{ $incident->mining_date_to->format('Y-m-d') }}
                                </td>
                                <td class="text-right">
                                    {{ number_format($incident->ore_value, 2) }} ISK
                                    @if($incident->is_active_theft && $incident->activity_count > 1)
                                        <br>
                                        <small class="text-danger">
                                            <i class="fas fa-arrow-up"></i> Increasing
                                        </small>
                                    @endif
                                </td>
                                <td class="text-right">{{ number_format($incident->tax_owed, 2) }} ISK</td>
                                <td>
                                    <span class="badge {{ $incident->getSeverityBadgeClass() }}">
                                        {{ ucfirst($incident->severity) }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-secondary" title="Incident status">
                                        {{ ucfirst(str_replace('_', ' ', $incident->status)) }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="{{ route('mining-manager.theft.show', $incident->id) }}"
                                       class="btn btn-sm btn-info"
                                       title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    @can('mining-manager.admin')
                                        @if($incident->isUnresolved())
                                        <button type="button"
                                                class="btn btn-sm btn-warning"
                                                data-toggle="modal"
                                                data-target="#investigateModal{{ $incident->id }}"
                                                title="Mark as Investigating">
                                            <i class="fas fa-search"></i>
                                        </button>
                                        <button type="button"
                                                class="btn btn-sm btn-success"
                                                data-toggle="modal"
                                                data-target="#resolveModal{{ $incident->id }}"
                                                title="Resolve">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        @endif
                                    @endcan
                                </td>
                            </tr>

                            {{-- Investigate Modal --}}
                            @can('mining-manager.admin')
                            <div class="modal fade" id="investigateModal{{ $incident->id }}" tabindex="-1" role="dialog">
                                <div class="modal-dialog" role="document">
                                    <div class="modal-content">
                                        <form method="POST" action="{{ route('mining-manager.theft.update-status', $incident->id) }}">
                                            @csrf
                                            <input type="hidden" name="status" value="investigating">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Mark as Investigating</h5>
                                                <button type="button" class="close" data-dismiss="modal">
                                                    <span>&times;</span>
                                                </button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="form-group">
                                                    <label>Notes</label>
                                                    <textarea name="notes" class="form-control" rows="3" placeholder="Investigation notes..."></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-warning">Mark as Investigating</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            {{-- Resolve Modal --}}
                            <div class="modal fade" id="resolveModal{{ $incident->id }}" tabindex="-1" role="dialog">
                                <div class="modal-dialog" role="document">
                                    <div class="modal-content">
                                        <form method="POST" action="{{ route('mining-manager.theft.resolve', $incident->id) }}">
                                            @csrf
                                            <div class="modal-header">
                                                <h5 class="modal-title">Resolve Incident</h5>
                                                <button type="button" class="close" data-dismiss="modal">
                                                    <span>&times;</span>
                                                </button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="form-group">
                                                    <label>Resolution Type</label>
                                                    <select name="resolution_type" class="form-control" required>
                                                        <option value="resolved">Resolved</option>
                                                        <option value="false_alarm">False Alarm</option>
                                                    </select>
                                                </div>
                                                <div class="form-group">
                                                    <label>Notes</label>
                                                    <textarea name="notes" class="form-control" rows="3" placeholder="Resolution notes..."></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-success">Resolve Incident</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            @endcan
                            @endforeach
                        </tbody>
                    </table>
                    @else
                    <div class="text-center p-5">
                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                        <h4>No Theft Incidents Found</h4>
                        <p class="text-muted">No theft incidents match your current filters.</p>
                    </div>
                    @endif
                </div>
                @if($incidents->hasPages())
                <div class="card-footer clearfix">
                    {{ $incidents->appends(request()->all())->links() }}
                </div>
                @endif
            </div>
        </div>
    </div>

</div>
@endsection

@push('javascript')
<script>
$(document).ready(function() {
    // Auto-submit form on filter change
    $('#filterForm select').on('change', function() {
        $('#filterForm').submit();
    });
});
</script>
@endpush
