@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::analytics.comparative_analysis'))
@section('page_header', trans('mining-manager::menu.analytics'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=2">
<style>
    .comparison-card {
        transition: all 0.3s;
        border-left: 4px solid transparent;
    }
    .comparison-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }
    .period-1 { border-left-color: #4e73df !important; }
    .period-2 { border-left-color: #1cc88a !important; }
    .period-3 { border-left-color: #f6c23e !important; }

    .metric-comparison {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px;
        margin-bottom: 10px;
        background: rgba(0,0,0,0.2);
        border-radius: 8px;
    }

    .metric-label {
        font-weight: 600;
        color: #a0a0a0;
        font-size: 0.85rem;
    }

    .metric-value {
        font-size: 1.3rem;
        font-weight: bold;
    }

    .change-indicator {
        padding: 5px 10px;
        border-radius: 5px;
        font-size: 0.9rem;
        font-weight: 600;
    }

    .change-positive {
        background: rgba(28, 200, 138, 0.2);
        color: #1cc88a;
    }

    .change-negative {
        background: rgba(231, 74, 59, 0.2);
        color: #e74a3b;
    }

    .change-neutral {
        background: rgba(160, 160, 160, 0.2);
        color: #a0a0a0;
    }

    .chart-container {
        height: 350px;
        position: relative;
    }

    .comparison-selector {
        margin-bottom: 20px;
    }

    .vs-divider {
        text-align: center;
        font-size: 1.5rem;
        font-weight: bold;
        color: #667eea;
        padding: 20px 0;
    }

    .top-performer-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
        color: white;
        padding: 5px 15px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: bold;
    }

    .comparison-table th {
        background: rgba(102, 126, 234, 0.2) !important;
        font-weight: 600;
    }

    .period-column {
        background: rgba(0,0,0,0.1);
    }

    .table-container {
        background: #343a40;
        border-radius: 8px;
        padding: 15px;
    }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard analytics-compare-page">

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/analytics') && !Request::is('*/analytics/*') ? 'active' : '' }}" href="{{ route('mining-manager.analytics.index') }}">
                    <i class="fas fa-chart-area"></i> {{ trans('mining-manager::menu.analytics_overview') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/analytics/charts') ? 'active' : '' }}" href="{{ route('mining-manager.analytics.charts') }}">
                    <i class="fas fa-chart-line"></i> {{ trans('mining-manager::menu.performance_charts') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/analytics/moons') ? 'active' : '' }}" href="{{ route('mining-manager.analytics.moons') }}">
                    <i class="fas fa-moon"></i> {{ trans('mining-manager::analytics.moon_analytics') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/analytics/tables') ? 'active' : '' }}" href="{{ route('mining-manager.analytics.tables') }}">
                    <i class="fas fa-table"></i> {{ trans('mining-manager::menu.data_tables') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/analytics/compare') ? 'active' : '' }}" href="{{ route('mining-manager.analytics.compare') }}">
                    <i class="fas fa-balance-scale"></i> {{ trans('mining-manager::menu.comparative_analysis') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">


<div class="analytics-compare">

    {{-- COMPARISON SELECTOR --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-balance-scale"></i>
                        {{ trans('mining-manager::analytics.comparison_settings') }}
                    </h3>
                </div>
                <div class="card-body">
                    <form method="GET" action="{{ route('mining-manager.analytics.compare') }}" id="comparisonForm">

                        {{-- Comparison Type --}}
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="font-weight-bold">{{ trans('mining-manager::analytics.comparison_type') }}</label>
                                <div class="btn-group btn-group-toggle d-block" data-toggle="buttons">
                                    <label class="btn btn-outline-primary {{ (request('comparison_type', 'periods') === 'periods') ? 'active' : '' }}">
                                        <input type="radio" name="comparison_type" value="periods" {{ (request('comparison_type', 'periods') === 'periods') ? 'checked' : '' }}>
                                        <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::analytics.compare_periods') }}
                                    </label>
                                    <label class="btn btn-outline-success {{ (request('comparison_type') === 'miners') ? 'active' : '' }}">
                                        <input type="radio" name="comparison_type" value="miners" {{ (request('comparison_type') === 'miners') ? 'checked' : '' }}>
                                        <i class="fas fa-users"></i> {{ trans('mining-manager::analytics.compare_miners') }}
                                    </label>
                                    <label class="btn btn-outline-info {{ (request('comparison_type') === 'systems') ? 'active' : '' }}">
                                        <input type="radio" name="comparison_type" value="systems" {{ (request('comparison_type') === 'systems') ? 'checked' : '' }}>
                                        <i class="fas fa-globe"></i> {{ trans('mining-manager::analytics.compare_systems') }}
                                    </label>
                                    <label class="btn btn-outline-warning {{ (request('comparison_type') === 'ores') ? 'active' : '' }}">
                                        <input type="radio" name="comparison_type" value="ores" {{ (request('comparison_type') === 'ores') ? 'checked' : '' }}>
                                        <i class="fas fa-gem"></i> {{ trans('mining-manager::analytics.compare_ores') }}
                                    </label>
                                </div>
                            </div>
                        </div>

                        {{-- Corporation Filter --}}
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="font-weight-bold"><i class="fas fa-building"></i> Corporation</label>
                                <select name="corporation_id" class="form-control">
                                    {{-- Own corporation first: it is the default now, and the question
                                         most people are actually asking. All Corporations stays as the
                                         deliberate second choice. --}}
                                    @if(isset($userCorporationId) && $userCorporationId && isset($corporations[$userCorporationId]))
                                        <option value="{{ $userCorporationId }}" {{ ($corporationId ?? null) == $userCorporationId ? 'selected' : '' }}>
                                            {{ $corporations[$userCorporationId] }} (My Corp)
                                        </option>
                                    @endif
                                    <option value="" {{ empty($corporationId) ? 'selected' : '' }}>All Corporations</option>
                                    @foreach($corporations as $corpId => $corpName)
                                        @if(!isset($userCorporationId) || $corpId != $userCorporationId)
                                            <option value="{{ $corpId }}" {{ ($corporationId ?? null) == $corpId ? 'selected' : '' }}>
                                                {{ $corpName }}
                                            </option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- Period Comparison Settings --}}
                        <div id="periodSettings" class="comparison-settings" style="{{ (request('comparison_type', 'periods') === 'periods') ? '' : 'display: none;' }}">
                            <div class="row">
                                <div class="col-md-5">
                                    <div class="card period-1">
                                        <div class="card-header bg-primary">
                                            <h5 class="card-title mb-0">
                                                <i class="fas fa-calendar"></i> {{ trans('mining-manager::analytics.period_1') }}
                                            </h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="form-group">
                                                <label>{{ trans('mining-manager::analytics.start_date') }}</label>
                                                <input type="date" name="period1_start" class="form-control"
                                                       value="{{ request('period1_start', now()->subDays(30)->format('Y-m-d')) }}">
                                            </div>
                                            <div class="form-group">
                                                <label>{{ trans('mining-manager::analytics.end_date') }}</label>
                                                <input type="date" name="period1_end" class="form-control"
                                                       value="{{ request('period1_end', now()->format('Y-m-d')) }}">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-2 d-flex align-items-center">
                                    <div class="vs-divider w-100">VS</div>
                                </div>

                                <div class="col-md-5">
                                    <div class="card period-2">
                                        <div class="card-header bg-success">
                                            <h5 class="card-title mb-0">
                                                <i class="fas fa-calendar"></i> {{ trans('mining-manager::analytics.period_2') }}
                                            </h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="form-group">
                                                <label>{{ trans('mining-manager::analytics.start_date') }}</label>
                                                <input type="date" name="period2_start" class="form-control"
                                                       value="{{ request('period2_start', now()->subDays(60)->format('Y-m-d')) }}">
                                            </div>
                                            <div class="form-group">
                                                <label>{{ trans('mining-manager::analytics.end_date') }}</label>
                                                <input type="date" name="period2_end" class="form-control"
                                                       value="{{ request('period2_end', now()->subDays(30)->format('Y-m-d')) }}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Miner Comparison Settings --}}
                        <div id="minerSettings" class="comparison-settings" style="{{ (request('comparison_type') === 'miners') ? '' : 'display: none;' }}">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label>{{ trans('mining-manager::analytics.time_period') }}</label>
                                        <p class="text-muted small mb-2">Shows top miners by quantity and value for the selected date range.</p>
                                        <div class="row">
                                            <div class="col-4">
                                                <label>{{ trans('mining-manager::analytics.start_date') }}</label>
                                                <input type="date" name="miner_start" class="form-control"
                                                       value="{{ request('miner_start', now()->subDays(30)->format('Y-m-d')) }}">
                                            </div>
                                            <div class="col-4">
                                                <label>{{ trans('mining-manager::analytics.end_date') }}</label>
                                                <input type="date" name="miner_end" class="form-control"
                                                       value="{{ request('miner_end', now()->format('Y-m-d')) }}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- System Comparison Settings --}}
                        <div id="systemSettings" class="comparison-settings" style="{{ (request('comparison_type') === 'systems') ? '' : 'display: none;' }}">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label>{{ trans('mining-manager::analytics.time_period') }}</label>
                                        <p class="text-muted small mb-2">Shows most productive solar systems for the selected date range.</p>
                                        <div class="row">
                                            <div class="col-4">
                                                <label>{{ trans('mining-manager::analytics.start_date') }}</label>
                                                <input type="date" name="system_start" class="form-control"
                                                       value="{{ request('system_start', now()->subDays(30)->format('Y-m-d')) }}">
                                            </div>
                                            <div class="col-4">
                                                <label>{{ trans('mining-manager::analytics.end_date') }}</label>
                                                <input type="date" name="system_end" class="form-control"
                                                       value="{{ request('system_end', now()->format('Y-m-d')) }}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Ore Comparison Settings --}}
                        <div id="oreSettings" class="comparison-settings" style="{{ (request('comparison_type') === 'ores') ? '' : 'display: none;' }}">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label>{{ trans('mining-manager::analytics.time_period') }}</label>
                                        <p class="text-muted small mb-2">Shows most valuable and most mined ore types for the selected date range.</p>
                                        <div class="row">
                                            <div class="col-4">
                                                <label>{{ trans('mining-manager::analytics.start_date') }}</label>
                                                <input type="date" name="ore_start" class="form-control"
                                                       value="{{ request('ore_start', now()->subDays(30)->format('Y-m-d')) }}">
                                            </div>
                                            <div class="col-4">
                                                <label>{{ trans('mining-manager::analytics.end_date') }}</label>
                                                <input type="date" name="ore_end" class="form-control"
                                                       value="{{ request('ore_end', now()->format('Y-m-d')) }}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-12 text-right">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-chart-bar"></i> {{ trans('mining-manager::analytics.generate_comparison') }}
                                </button>
                                <a href="{{ route('mining-manager.analytics.index') }}" class="btn btn-secondary btn-lg">
                                    <i class="fas fa-arrow-left"></i> {{ trans('mining-manager::analytics.back_to_overview') }}
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- COMPARISON RESULTS --}}
    @if(isset($comparisonData) && !empty($comparisonData))

    {{-- ============================================================ --}}
    {{-- PERIOD COMPARISON RESULTS                                     --}}
    {{-- ============================================================ --}}
    @if(($comparisonType ?? '') === 'periods')

    {{-- KEY METRICS COMPARISON --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-tachometer-alt"></i>
                        {{ trans('mining-manager::analytics.key_metrics_comparison') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        @foreach($comparisonData['metrics'] ?? [] as $metric)
                        <div class="col-lg-3 col-md-6 mb-3">
                            <div class="metric-comparison flex-column text-center">
                                <div class="metric-label mb-2">{{ $metric['label'] }}</div>
                                <div class="d-flex justify-content-around w-100 mb-2">
                                    <div>
                                        <small class="text-primary">P1</small>
                                        <div class="metric-value" style="font-size: 1.1rem;">{{ $metric['value_1'] }}</div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-arrows-alt-h text-muted"></i>
                                    </div>
                                    <div>
                                        <small class="text-success">P2</small>
                                        <div class="metric-value" style="font-size: 1.1rem;">{{ $metric['value_2'] }}</div>
                                    </div>
                                </div>
                                @if(isset($metric['change']))
                                <div>
                                    <span class="change-indicator change-{{ $metric['change_type'] }}">
                                        <i class="fas fa-{{ $metric['change_type'] == 'positive' ? 'arrow-up' : ($metric['change_type'] == 'negative' ? 'arrow-down' : 'minus') }}"></i>
                                        {{ $metric['change'] }}
                                    </span>
                                </div>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- COMPARISON CHARTS --}}
    <div class="row">
        {{-- Quantity Comparison Chart --}}
        <div class="col-lg-6">
            <div class="card card-info card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-bar"></i>
                        {{ trans('mining-manager::analytics.total_quantity_units') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="volumeComparisonChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_volume_comparison') }}</small>
                </div>
            </div>
        </div>

        {{-- Value Comparison Chart --}}
        <div class="col-lg-6">
            <div class="card card-success card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-line"></i>
                        {{ trans('mining-manager::analytics.value_comparison') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="valueComparisonChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_value_comparison') }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- TREND COMPARISON --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-area"></i>
                        {{ trans('mining-manager::analytics.trend_comparison') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="height: 400px;">
                        <canvas id="trendComparisonChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_trend_comparison') }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- DETAILED COMPARISON TABLE --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-warning card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-table"></i>
                        {{ trans('mining-manager::analytics.detailed_comparison') }}
                    </h3>
                    <div class="card-tools">
                        @if($features['allow_export_data'] ?? true)
                        <button type="button" class="btn btn-sm btn-primary" id="exportComparison">
                            <i class="fas fa-download"></i> {{ trans('mining-manager::analytics.export') }}
                        </button>
                        @endif
                    </div>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-striped table-hover comparison-table">
                        <thead>
                            <tr>
                                <th>{{ trans('mining-manager::analytics.metric') }}</th>
                                @foreach($comparisonData['labels'] ?? [] as $label)
                                <th class="period-column">{{ $label }}</th>
                                @endforeach
                                <th>{{ trans('mining-manager::analytics.difference') }}</th>
                                <th>{{ trans('mining-manager::analytics.change_percent') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($comparisonData['detailed'] ?? [] as $row)
                            <tr>
                                <td class="font-weight-bold">{{ $row['metric'] }}</td>
                                @foreach($row['values'] as $value)
                                <td>{{ $value }}</td>
                                @endforeach
                                <td class="text-{{ $row['diff_color'] ?? 'muted' }}">
                                    {{ $row['difference'] }}
                                </td>
                                <td>
                                    <span class="badge badge-{{ $row['change_color'] ?? 'secondary' }}">
                                        {{ $row['change_percent'] }}
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- TOP PERFORMERS COMPARISON --}}
    <div class="row">
        {{-- First Period Top Performers --}}
        <div class="col-md-6">
            <div class="card card-primary card-outline comparison-card period-1" style="position: relative;">
                @if(($comparisonData['top_performer_period'] ?? 1) == 1)
                <span class="top-performer-badge">
                    <i class="fas fa-trophy"></i> {{ trans('mining-manager::analytics.best_period') }}
                </span>
                @endif
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-medal"></i>
                        {{ trans('mining-manager::analytics.top_performers') }} - {{ $comparisonData['labels'][0] ?? trans('mining-manager::analytics.period_1') }}
                    </h3>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>{{ trans('mining-manager::analytics.name') }}</th>
                                <th class="text-right">{{ trans('mining-manager::analytics.value') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($comparisonData['top_period_1'] ?? [] as $index => $item)
                            <tr>
                                <td>{{ $index + 1 }}</td>
                                <td>
                                    <img src="https://images.evetech.net/characters/{{ $item['character_id'] }}/portrait?size=32"
                                         class="img-circle" style="width: 24px; height: 24px;">
                                    {{ $item['name'] }}
                                </td>
                                <td class="text-right text-success">{{ number_format(($item['total_value'] ?? 0) / 1000000, 2) }}M ISK</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Second Period Top Performers --}}
        <div class="col-md-6">
            <div class="card card-success card-outline comparison-card period-2" style="position: relative;">
                @if(($comparisonData['top_performer_period'] ?? 1) == 2)
                <span class="top-performer-badge">
                    <i class="fas fa-trophy"></i> {{ trans('mining-manager::analytics.best_period') }}
                </span>
                @endif
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-medal"></i>
                        {{ trans('mining-manager::analytics.top_performers') }} - {{ $comparisonData['labels'][1] ?? trans('mining-manager::analytics.period_2') }}
                    </h3>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>{{ trans('mining-manager::analytics.name') }}</th>
                                <th class="text-right">{{ trans('mining-manager::analytics.value') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($comparisonData['top_period_2'] ?? [] as $index => $item)
                            <tr>
                                <td>{{ $index + 1 }}</td>
                                <td>
                                    <img src="https://images.evetech.net/characters/{{ $item['character_id'] }}/portrait?size=32"
                                         class="img-circle" style="width: 24px; height: 24px;">
                                    {{ $item['name'] }}
                                </td>
                                <td class="text-right text-success">{{ number_format(($item['total_value'] ?? 0) / 1000000, 2) }}M ISK</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- INSIGHTS --}}
    @if(!empty($comparisonData['insights'] ?? []))
    <div class="row">
        <div class="col-12">
            <div class="card card-info card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-lightbulb"></i>
                        {{ trans('mining-manager::analytics.insights') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        @foreach($comparisonData['insights'] ?? [] as $insight)
                        <div class="col-md-6 mb-3">
                            <div class="alert alert-{{ $insight['type'] ?? 'info' }} mb-0">
                                <h5>
                                    <i class="fas fa-{{ $insight['icon'] ?? 'info-circle' }}"></i>
                                    {{ $insight['title'] }}
                                </h5>
                                <p class="mb-0">{{ $insight['message'] }}</p>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ============================================================ --}}
    {{-- MINER COMPARISON RESULTS                                      --}}
    {{-- ============================================================ --}}
    @elseif(($comparisonType ?? '') === 'miners')

    <div class="row">
        <div class="col-12">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                {{ trans('mining-manager::analytics.date_range') }}:
                <strong>{{ $comparisonData['date_range']['start'] ?? '' }}</strong>
                {{ trans('mining-manager::analytics.to') }}
                <strong>{{ $comparisonData['date_range']['end'] ?? '' }}</strong>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- Top Miners by Quantity --}}
        <div class="col-lg-6">
            <div class="card card-success card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-sort-amount-down"></i>
                        {{ trans('mining-manager::analytics.top_miners_by_quantity') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table class="table table-dark table-striped table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{ trans('mining-manager::analytics.character') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.total_quantity_units') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.total_value') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.days_active') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.avg_per_day') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($comparisonData['by_quantity'] ?? [] as $index => $miner)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $miner['character_id'] }}/portrait?size=32"
                                             class="img-circle" style="width: 24px;">
                                        {{ $miner['character_name'] }}
                                    </td>
                                    <td class="text-right">{{ number_format($miner['total_quantity'], 0) }}</td>
                                    <td class="text-right text-success">{{ number_format(($miner['total_value'] ?? 0) / 1000000, 2) }}M</td>
                                    <td class="text-right">{{ $miner['days_active'] }}</td>
                                    <td class="text-right">{{ number_format($miner['avg_per_day'], 0) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Top Miners by Value --}}
        <div class="col-lg-6">
            <div class="card card-warning card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-coins"></i>
                        {{ trans('mining-manager::analytics.top_miners_by_value') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table class="table table-dark table-striped table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{ trans('mining-manager::analytics.character') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.total_value') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.total_quantity_units') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.days_active') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.avg_value_per_day') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($comparisonData['by_value'] ?? [] as $index => $miner)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $miner['character_id'] }}/portrait?size=32"
                                             class="img-circle" style="width: 24px;">
                                        {{ $miner['character_name'] }}
                                    </td>
                                    <td class="text-right text-success">{{ number_format(($miner['total_value'] ?? 0) / 1000000, 2) }}M</td>
                                    <td class="text-right">{{ number_format($miner['total_quantity'], 0) }}</td>
                                    <td class="text-right">{{ $miner['days_active'] }}</td>
                                    <td class="text-right text-success">{{ number_format(($miner['avg_value_per_day'] ?? 0) / 1000000, 2) }}M</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================ --}}
    {{-- SYSTEM COMPARISON RESULTS                                     --}}
    {{-- ============================================================ --}}
    @elseif(($comparisonType ?? '') === 'systems')

    <div class="row">
        <div class="col-12">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                {{ trans('mining-manager::analytics.date_range') }}:
                <strong>{{ $comparisonData['date_range']['start'] ?? '' }}</strong>
                {{ trans('mining-manager::analytics.to') }}
                <strong>{{ $comparisonData['date_range']['end'] ?? '' }}</strong>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card card-info card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-globe"></i>
                        {{ trans('mining-manager::analytics.system_comparison_results') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-info">{{ count($comparisonData['systems'] ?? []) }} {{ trans('mining-manager::analytics.systems') }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table class="table table-dark table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{ trans('mining-manager::analytics.system') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.total_value') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.total_quantity_units') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.unique_miners') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.days_active') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.avg_value_per_day') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($comparisonData['systems'] ?? [] as $index => $system)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>
                                        <i class="fas fa-map-marker-alt text-warning"></i>
                                        {{ $system['system_name'] }}
                                    </td>
                                    <td class="text-right text-success">{{ number_format(($system['total_value'] ?? 0) / 1000000, 2) }}M ISK</td>
                                    <td class="text-right">{{ number_format($system['total_quantity'], 0) }}</td>
                                    <td class="text-right">{{ $system['unique_miners'] }}</td>
                                    <td class="text-right">{{ $system['days_active'] }}</td>
                                    <td class="text-right text-success">{{ number_format(($system['avg_value_per_day'] ?? 0) / 1000000, 2) }}M</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================ --}}
    {{-- ORE COMPARISON RESULTS                                        --}}
    {{-- ============================================================ --}}
    @elseif(($comparisonType ?? '') === 'ores')

    <div class="row">
        <div class="col-12">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                {{ trans('mining-manager::analytics.date_range') }}:
                <strong>{{ $comparisonData['date_range']['start'] ?? '' }}</strong>
                {{ trans('mining-manager::analytics.to') }}
                <strong>{{ $comparisonData['date_range']['end'] ?? '' }}</strong>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- Ores by Value --}}
        <div class="col-lg-6">
            <div class="card card-warning card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-coins"></i>
                        {{ trans('mining-manager::analytics.ores_by_value') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table class="table table-dark table-striped table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{ trans('mining-manager::analytics.ore_type') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.total_value') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.quantity') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.unique_miners') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.avg_value_per_unit') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($comparisonData['by_value'] ?? [] as $index => $ore)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>
                                        <i class="fas fa-gem text-info"></i>
                                        {{ $ore['ore_name'] }}
                                    </td>
                                    <td class="text-right text-success">{{ number_format(($ore['total_value'] ?? 0) / 1000000, 2) }}M</td>
                                    <td class="text-right">{{ number_format($ore['total_quantity'], 0) }}</td>
                                    <td class="text-right">{{ $ore['miners_count'] }}</td>
                                    <td class="text-right">{{ number_format($ore['avg_value_per_unit'] ?? 0, 0) }} ISK</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Ores by Quantity --}}
        <div class="col-lg-6">
            <div class="card card-success card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-sort-amount-down"></i>
                        {{ trans('mining-manager::analytics.ores_by_quantity') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table class="table table-dark table-striped table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{ trans('mining-manager::analytics.ore_type') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.total_quantity_units') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($comparisonData['by_quantity'] ?? [] as $index => $ore)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>
                                        <i class="fas fa-cube text-info"></i>
                                        {{ $ore['ore_name'] }}
                                    </td>
                                    <td class="text-right">{{ number_format($ore['total_quantity'], 0) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @endif

    @else

    {{-- NO COMPARISON GENERATED YET --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-info">
                <div class="card-body text-center py-5">
                    <i class="fas fa-chart-bar fa-5x text-muted mb-3"></i>
                    <h3>{{ trans('mining-manager::analytics.no_comparison_generated') }}</h3>
                    <p class="text-muted">{{ trans('mining-manager::analytics.select_comparison_settings') }}</p>
                </div>
            </div>
        </div>
    </div>

    @endif

</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/chart.min.js') }}"></script>
<script>
$(document).ready(function() {
    // Toggle comparison settings based on type selection
    $('input[name="comparison_type"]').on('change', function() {
        $('.comparison-settings').hide();
        const type = $(this).val();
        $(`#${type}Settings`).show();
    });

    // Quick period buttons
    $('.quick-period').on('click', function() {
        const period = $(this).data('period');
        const endDate = new Date();
        const startDate = new Date();

        switch(period) {
            case 'last_week':
                startDate.setDate(startDate.getDate() - 7);
                break;
            case 'last_month':
                startDate.setMonth(startDate.getMonth() - 1);
                break;
            case 'last_quarter':
                startDate.setMonth(startDate.getMonth() - 3);
                break;
        }

        $('input[name$="_start"]').val(startDate.toISOString().split('T')[0]);
        $('input[name$="_end"]').val(endDate.toISOString().split('T')[0]);
    });

    @if(isset($comparisonData) && ($comparisonType ?? '') === 'periods')

    // Quantity Comparison Chart (units)
    const volumeCtx = document.getElementById('volumeComparisonChart');
    if (volumeCtx) {
        new Chart(volumeCtx, {
            type: 'bar',
            data: {
                labels: @json($comparisonData['labels'] ?? []),
                datasets: [{
                    label: '{{ trans("mining-manager::analytics.total_quantity_units") }}',
                    data: @json($comparisonData['volume_data'] ?? []),
                    backgroundColor: [
                        'rgba(78, 115, 223, 0.6)',
                        'rgba(28, 200, 138, 0.6)',
                        'rgba(246, 194, 62, 0.6)'
                    ],
                    borderColor: [
                        'rgba(78, 115, 223, 1)',
                        'rgba(28, 200, 138, 1)',
                        'rgba(246, 194, 62, 1)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        labels: { color: '#e0e0e0' }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' +
                                       Number(context.parsed.y).toLocaleString() + ' units';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#a0a0a0',
                            callback: function(value) {
                                return value.toLocaleString();
                            }
                        },
                        grid: { color: 'rgba(255, 255, 255, 0.1)' }
                    },
                    x: {
                        ticks: { color: '#a0a0a0' },
                        grid: { color: 'rgba(255, 255, 255, 0.05)' }
                    }
                }
            }
        });
    }

    // Value Comparison Chart
    const valueCtx = document.getElementById('valueComparisonChart');
    if (valueCtx) {
        new Chart(valueCtx, {
            type: 'bar',
            data: {
                labels: @json($comparisonData['labels'] ?? []),
                datasets: [{
                    label: '{{ trans("mining-manager::analytics.total_value") }}',
                    data: @json($comparisonData['value_data'] ?? []),
                    backgroundColor: [
                        'rgba(28, 200, 138, 0.6)',
                        'rgba(78, 115, 223, 0.6)',
                        'rgba(246, 194, 62, 0.6)'
                    ],
                    borderColor: [
                        'rgba(28, 200, 138, 1)',
                        'rgba(78, 115, 223, 1)',
                        'rgba(246, 194, 62, 1)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        labels: { color: '#e0e0e0' }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' +
                                       (context.parsed.y / 1000000).toFixed(2) + 'M ISK';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#a0a0a0',
                            callback: function(value) {
                                if (value >= 1000000000) return (value / 1000000000).toFixed(1) + 'B';
                                return (value / 1000000).toFixed(0) + 'M';
                            }
                        },
                        grid: { color: 'rgba(255, 255, 255, 0.1)' }
                    },
                    x: {
                        ticks: { color: '#a0a0a0' },
                        grid: { color: 'rgba(255, 255, 255, 0.05)' }
                    }
                }
            }
        });
    }

    // Trend Comparison Chart
    const trendCtx = document.getElementById('trendComparisonChart');
    if (trendCtx) {
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: @json($comparisonData['trend_labels'] ?? []),
                datasets: @json($comparisonData['trend_datasets'] ?? [])
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        labels: { color: '#e0e0e0' }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + 'M ISK';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Value (M ISK)',
                            color: '#a0a0a0'
                        },
                        ticks: {
                            color: '#a0a0a0',
                            callback: function(value) {
                                return value.toFixed(0) + 'M';
                            }
                        },
                        grid: { color: 'rgba(255, 255, 255, 0.1)' }
                    },
                    x: {
                        ticks: {
                            color: '#a0a0a0',
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: { color: 'rgba(255, 255, 255, 0.05)' }
                    }
                }
            }
        });
    }

    // Export comparison data
    $('#exportComparison').on('click', function() {
        window.location.href = '{{ route("mining-manager.analytics.export") }}?' +
                              $('#comparisonForm').serialize() +
                              '&format=csv&type=comparison';
    });

    @endif
});
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
