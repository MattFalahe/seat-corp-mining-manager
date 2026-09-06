@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::analytics.performance_charts'))
@section('page_header', trans('mining-manager::menu.analytics'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=2">
<style>
    .chart-card {
        height: 100%;
        min-height: 400px;
    }
    .chart-container {
        height: 350px;
        position: relative;
    }
    .chart-selector {
        margin-bottom: 15px;
    }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard analytics-charts-page">

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


<div class="analytics-charts">
    
    {{-- DATE RANGE FILTER --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="card card-primary card-outline">
                <div class="card-body">
                    <form method="GET" action="{{ route('mining-manager.analytics.charts') }}" class="form-inline flex-wrap">
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-2" for="analytics_start_date">{{ trans('mining-manager::analytics.start_date') }}</label>
                            <input type="date" name="start_date" id="analytics_start_date" class="form-control" value="{{ $startDate->format('Y-m-d') }}">
                        </div>
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-2" for="analytics_end_date">{{ trans('mining-manager::analytics.end_date') }}</label>
                            <input type="date" name="end_date" id="analytics_end_date" class="form-control" value="{{ $endDate->format('Y-m-d') }}">
                        </div>
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-2"><i class="fas fa-building"></i> Corporation</label>
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

                        {{-- Where the rock came from. The axis Overview cannot
                             answer, and the reason this page exists separately
                             from it. --}}
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-2" for="moon_source"><i class="fas fa-moon"></i> {{ trans('mining-manager::analytics.source') }}</label>
                            <select name="moon_source" id="moon_source" class="form-control">
                                @foreach(\MiningManager\Services\Analytics\ChartFilter::MOON_SOURCES as $sourceKey)
                                    <option value="{{ $sourceKey }}" {{ $filter->moonSource() === $sourceKey ? 'selected' : '' }}>
                                        {{ trans('mining-manager::analytics.source_' . $sourceKey) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group mr-3 mb-2">
                            <label class="mr-2" for="ore_category"><i class="fas fa-gem"></i> {{ trans('mining-manager::analytics.ore_type') }}</label>
                            <select name="ore_category" id="ore_category" class="form-control">
                                <option value="">{{ trans('mining-manager::analytics.ore_type_all') }}</option>
                                @foreach(\MiningManager\Services\Analytics\ChartFilter::ORE_CATEGORIES as $catKey => $catLabel)
                                    <option value="{{ $catKey }}" {{ $filter->oreCategory() === $catKey ? 'selected' : '' }}>
                                        {{ $catLabel }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Picked by main character, applied across every
                             character that player mines on. --}}
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-2" for="player_id"><i class="fas fa-user"></i> {{ trans('mining-manager::analytics.player') }}</label>
                            <select name="player_id" id="player_id" class="form-control">
                                <option value="">{{ trans('mining-manager::analytics.player_all') }}</option>
                                @foreach($playerOptions ?? [] as $option)
                                    <option value="{{ $option->main_character_id }}" {{ ($playerId ?? null) == $option->main_character_id ? 'selected' : '' }}>
                                        {{ $option->name }}@if($option->character_count > 1) ({{ $option->character_count }}){{ '' }}@endif
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary mr-2 mb-2">
                            <i class="fas fa-filter"></i> {{ trans('mining-manager::analytics.filter') }}
                        </button>
                        <div class="btn-group mr-2 mb-2">
                            <button type="button" class="btn btn-secondary quick-filter" data-days="7">7d</button>
                            <button type="button" class="btn btn-secondary quick-filter" data-days="30">30d</button>
                            <button type="button" class="btn btn-secondary quick-filter" data-days="90">90d</button>
                        </div>
                        <a href="{{ route('mining-manager.analytics.index') }}" class="btn btn-info mr-2">
                            <i class="fas fa-chart-pie"></i> {{ trans('mining-manager::analytics.overview') }}
                        </a>
                        <a href="{{ route('mining-manager.analytics.tables') }}" class="btn btn-success">
                            <i class="fas fa-table"></i> {{ trans('mining-manager::analytics.tables') }}
                        </a>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @php
        // Whether this slice returned anything at all. Five empty charts with
        // no explanation reads as a broken page; the combinations here are many
        // enough that plenty of them legitimately have no data.
        $sliceIsEmpty = empty($chartData['mining_trends'] ?? null)
            && empty($chartData['ore_distribution']['labels'] ?? [])
            && empty($chartData['miner_activity']['labels'] ?? [])
            && empty($chartData['system_activity']['labels'] ?? []);
    @endphp

    @if($filter->isActive() && $sliceIsEmpty)
    <div class="row">
        <div class="col-12">
            <div class="alert alert-warning">
                <h5><i class="fas fa-filter"></i> {{ trans('mining-manager::analytics.empty_slice_title') }}</h5>
                {{ trans('mining-manager::analytics.empty_slice_body') }}
                @if($filter->moonSource() === \MiningManager\Services\Analytics\ChartFilter::MOON_MINE)
                    <div class="mt-1">{{ trans('mining-manager::analytics.empty_slice_my_moons') }}</div>
                @endif
            </div>
        </div>
    </div>
    @endif

    @if($filter->moonSource() === \MiningManager\Services\Analytics\ChartFilter::MOON_OTHER)
    <div class="row">
        <div class="col-12">
            <div class="alert alert-info py-2">
                <i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.other_moons_inferred') }}
            </div>
        </div>
    </div>
    @endif

    @if($classificationCutover)
    <div class="row">
        <div class="col-12">
            <div class="alert alert-info py-2">
                <i class="fas fa-clock"></i>
                {{ trans('mining-manager::analytics.classification_cutover_note', [
                    'date' => $classificationCutover->format('j M Y'),
                ]) }}
            </div>
        </div>
    </div>
    @endif

    {{-- MINING TRENDS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark chart-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-line"></i>
                        {{ trans('mining-manager::analytics.mining_trends') }}
                    </h3>
                    <div class="card-tools"></div>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="miningTrendsChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_mining_trends') }}</small>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- ORE DISTRIBUTION (ISK) --}}
        <div class="col-lg-6">
            <div class="card card-success card-outline chart-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-gem"></i>
                        {{ trans('mining-manager::analytics.ore_distribution') }} (ISK)
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="oreDistributionChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_ore_distribution_isk') }}</small>
                </div>
            </div>
        </div>

        {{-- ORE DISTRIBUTION (QUANTITY) --}}
        <div class="col-lg-6">
            <div class="card card-success card-outline chart-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-cubes"></i>
                        {{ trans('mining-manager::analytics.ore_distribution') }} ({{ trans('mining-manager::analytics.total_quantity') }})
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="oreDistributionQtyChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_ore_distribution_qty') }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- MINER ACTIVITY --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-warning card-outline chart-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-users"></i>
                        {{ trans('mining-manager::analytics.miner_activity') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="minerActivityChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_miner_activity') }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- SYSTEM ACTIVITY --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-info card-outline chart-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-globe"></i>
                        {{ trans('mining-manager::analytics.system_activity') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="systemActivityChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_system_activity') }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- WEEKLY ACTIVITY HEATMAP --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-alt"></i>
                        {{ trans('mining-manager::analytics.weekly_activity') ?? 'Weekly Activity' }}
                    </h3>
                    <div class="card-tools">
                        <div class="btn-group btn-group-sm" id="heatmapViewToggle">
                            <button type="button" class="btn btn-outline-light active" data-view="summary">{{ trans('mining-manager::analytics.summary') ?? 'Summary' }}</button>
                            <button type="button" class="btn btn-outline-light" data-view="account">{{ trans('mining-manager::analytics.by_account') ?? 'By Account' }}</button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <small class="text-muted d-block mb-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_weekly_activity') }}</small>
                    <div class="chart-container" style="height: 500px;">
                        <canvas id="heatmapChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- EXPORT OPTIONS --}}
    @php
        // The export repeats the page's own slice, so a downloaded file matches
        // the charts it came from. Empty values are dropped rather than sent as
        // blanks, which would fail the controller's validation.
        $exportParams = array_filter([
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'corporation_id' => $corporationId,
            'moon_source' => $filter->moonSource(),
            'ore_category' => $filter->oreCategory(),
            'player_id' => $playerId ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    @endphp
    @if($features['allow_export_data'] ?? true)
    <div class="row">
        <div class="col-12">
            <div class="card card-secondary">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-download"></i>
                        {{ trans('mining-manager::analytics.export_charts') }}
                    </h3>
                </div>
                <div class="card-body">
                    <p>{{ trans('mining-manager::analytics.export_description') }}</p>
                    <div class="btn-group">
                        <button class="btn btn-primary export-chart" data-chart="all">
                            <i class="fas fa-download"></i> {{ trans('mining-manager::analytics.export_all_png') }}
                        </button>
                        <a href="{{ route('mining-manager.analytics.export', array_merge($exportParams, ['format' => 'csv'])) }}" 
                           class="btn btn-success">
                            <i class="fas fa-file-csv"></i> {{ trans('mining-manager::analytics.export_csv') }}
                        </a>
                        <a href="{{ route('mining-manager.analytics.export', array_merge($exportParams, ['format' => 'json'])) }}" 
                           class="btn btn-info">
                            <i class="fas fa-file-code"></i> {{ trans('mining-manager::analytics.export_json') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/chart.min.js') }}"></script>
<script>
// Chart data
const miningTrendsData = @json($chartData['mining_trends'] ?? []);
const oreDistributionData = @json($chartData['ore_distribution'] ?? []);
const minerActivityData = @json($chartData['miner_activity'] ?? []);
const systemActivityData = @json($chartData['system_activity'] ?? []);

// Color palettes
const colors = {
    primary: ['#667eea', '#764ba2', '#f093fb', '#f5576c', '#4facfe', '#00f2fe', '#43e97b', '#38f9d7'],
    success: ['#28a745', '#20c997', '#17a2b8', '#6610f2', '#e83e8c', '#fd7e14', '#ffc107', '#6c757d']
};

let charts = {};

/**
 * Show a "no data" message on a chart canvas when data is empty.
 */
function showNoDataMessage(canvasId) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const parent = canvas.parentElement;
    const msg = document.createElement('div');
    msg.className = 'text-center text-muted py-4';
    msg.innerHTML = '<i class="fas fa-chart-bar fa-2x mb-2"></i><br>{{ trans("mining-manager::analytics.no_data_available") }}';
    parent.replaceChild(msg, canvas);
}

// Mining Trends Chart
if (!miningTrendsData || miningTrendsData.length === 0) {
    showNoDataMessage('miningTrendsChart');
} else {
charts.miningTrends = new Chart(document.getElementById('miningTrendsChart'), {
    type: 'line',
    data: {
        labels: miningTrendsData.map(d => d.date ? d.date.substring(0, 10) : ''),
        datasets: [{
            label: '{{ trans("mining-manager::analytics.total_quantity") }}',
            data: miningTrendsData.map(d => d.total_quantity),
            borderColor: colors.primary[0],
            backgroundColor: 'rgba(102, 126, 234, 0.15)',
            borderWidth: 2,
            fill: true,
            tension: 0.4,
            yAxisID: 'y',
            pointRadius: 3,
            pointBackgroundColor: colors.primary[0]
        }, {
            label: '{{ trans("mining-manager::analytics.value") }}',
            data: miningTrendsData.map(d => d.total_value),
            borderColor: '#f5576c',
            backgroundColor: 'rgba(245, 87, 108, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4,
            yAxisID: 'y1',
            pointRadius: 3,
            pointBackgroundColor: '#f5576c'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { labels: { color: '#fff' } },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) label += ': ';
                        if (context.dataset.yAxisID === 'y1') {
                            label += (context.parsed.y / 1000000).toFixed(1) + ' M ISK';
                        } else {
                            label += context.parsed.y.toLocaleString();
                        }
                        return label;
                    }
                }
            }
        },
        scales: {
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                title: { display: true, text: '{{ trans("mining-manager::analytics.total_quantity") }}', color: '#fff' },
                ticks: { color: colors.primary[0] },
                grid: { color: 'rgba(255, 255, 255, 0.1)' }
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'right',
                title: { display: true, text: '{{ trans("mining-manager::analytics.value") }} (ISK)', color: '#fff' },
                ticks: {
                    color: '#f5576c',
                    callback: function(value) { return (value / 1000000).toFixed(0) + 'M'; }
                },
                grid: { drawOnChartArea: false }
            },
            x: { ticks: { color: '#fff' }, grid: { color: 'rgba(255, 255, 255, 0.1)' } }
        }
    }
});
}

// Ore Distribution Chart (ISK)
if (!oreDistributionData || !oreDistributionData.labels || oreDistributionData.labels.length === 0) {
    showNoDataMessage('oreDistributionChart');
    showNoDataMessage('oreDistributionQtyChart');
} else {
charts.oreDistribution = new Chart(document.getElementById('oreDistributionChart'), {
    type: 'doughnut',
    data: {
        labels: oreDistributionData.labels,
        datasets: [{
            data: oreDistributionData.values,
            backgroundColor: colors.primary,
            borderColor: '#343a40',
            borderWidth: 2
        }]
    },
    options: getDoughnutChartOptions()
});

// Ore Distribution Chart (Quantity)
charts.oreDistributionQty = new Chart(document.getElementById('oreDistributionQtyChart'), {
    type: 'doughnut',
    data: {
        labels: oreDistributionData.labels,
        datasets: [{
            data: oreDistributionData.data,
            backgroundColor: colors.primary,
            borderColor: '#343a40',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'right', labels: { color: '#fff' } },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.label + ': ' + context.parsed.toLocaleString() + ' units';
                    }
                }
            }
        }
    }
});
}

// Miner Activity Chart
if (!minerActivityData || !minerActivityData.labels || minerActivityData.labels.length === 0) {
    showNoDataMessage('minerActivityChart');
} else {
charts.minerActivity = new Chart(document.getElementById('minerActivityChart'), {
    type: 'bar',
    data: {
        labels: minerActivityData.labels,
        datasets: [{
            label: '{{ trans("mining-manager::analytics.value") }} (M ISK)',
            data: minerActivityData.values.map(v => v / 1000000),
            backgroundColor: colors.primary[3],
            borderColor: colors.primary[3],
            borderWidth: 1
        }]
    },
    options: getBarChartOptions()
});
}

// System Activity Chart
if (!systemActivityData || !systemActivityData.labels || systemActivityData.labels.length === 0) {
    showNoDataMessage('systemActivityChart');
} else {
charts.systemActivity = new Chart(document.getElementById('systemActivityChart'), {
    type: 'bar',
    data: {
        labels: systemActivityData.labels,
        datasets: [{
            label: '{{ trans("mining-manager::analytics.value") }} (M ISK)',
            data: systemActivityData.values.map(v => v / 1000000),
            backgroundColor: colors.primary[4],
            borderColor: colors.primary[4],
            borderWidth: 1
        }]
    },
    options: getBarChartOptions()
});
}

// Weekly Activity Heatmap
const heatmapData = @json($chartData['heatmap'] ?? []);
const heatmapColors = ['#667eea', '#764ba2', '#f093fb', '#f5576c', '#4facfe', '#00f2fe', '#43e97b', '#38f9d7', '#fbbf24', '#ef4444', '#10b981', '#8b5cf6'];

function buildHeatmapChart(view) {
    if (charts.heatmap) charts.heatmap.destroy();

    let datasets = [];
    let labels = heatmapData.day_labels || ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    if (view === 'summary') {
        datasets = [{
            label: 'Avg Value (M ISK)',
            data: (heatmapData.summary || []).map(d => d.avg_value / 1000000),
            backgroundColor: labels.map((_, i) => {
                const values = (heatmapData.summary || []).map(d => d.avg_value);
                const max = Math.max(...values);
                const val = values[i] || 0;
                const intensity = max > 0 ? 0.3 + (val / max) * 0.7 : 0.3;
                return `rgba(102, 126, 234, ${intensity})`;
            }),
            borderColor: '#667eea',
            borderWidth: 1
        }];
    } else {
        const sourceData = heatmapData.by_account || [];
        sourceData.forEach((entry, i) => {
            datasets.push({
                label: entry.label,
                data: entry.data.map(v => v / 1000000),
                backgroundColor: heatmapColors[i % heatmapColors.length],
                borderColor: heatmapColors[i % heatmapColors.length],
                borderWidth: 1
            });
        });
    }

    charts.heatmap = new Chart(document.getElementById('heatmapChart'), {
        type: 'bar',
        data: { labels: labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: view !== 'summary',
                    labels: { color: '#fff' }
                },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            return ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(1) + ' M ISK';
                        }
                    }
                }
            },
            scales: {
                y: {
                    stacked: view !== 'summary',
                    title: { display: true, text: view === 'summary' ? 'Avg Value (M ISK)' : 'Total Value (M ISK)', color: '#fff' },
                    ticks: { color: '#fff' },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                },
                x: {
                    stacked: view !== 'summary',
                    ticks: { color: '#fff' },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                }
            }
        }
    });
}

if (heatmapData && heatmapData.summary) {
    buildHeatmapChart('summary');
} else {
    showNoDataMessage('heatmapChart');
}

// Heatmap view toggle
$('#heatmapViewToggle .btn').on('click', function() {
    $('#heatmapViewToggle .btn').removeClass('active');
    $(this).addClass('active');
    buildHeatmapChart($(this).data('view'));
});

// Chart options functions
function getLineChartOptions() {
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { labels: { color: '#fff' } },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) label += ': ';
                        label += context.parsed.y.toLocaleString();
                        return label;
                    }
                }
            }
        },
        scales: {
            y: { ticks: { color: '#fff' }, grid: { color: 'rgba(255, 255, 255, 0.1)' } },
            x: { ticks: { color: '#fff' }, grid: { color: 'rgba(255, 255, 255, 0.1)' } }
        }
    };
}

function getDoughnutChartOptions() {
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'right', labels: { color: '#fff' } },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const value = context.parsed || 0;
                        return context.label + ': ' + (value / 1000000).toFixed(1) + 'M ISK';
                    }
                }
            }
        }
    };
}

function getBarChartOptions() {
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { labels: { color: '#fff' } }
        },
        scales: {
            y: { ticks: { color: '#fff' }, grid: { color: 'rgba(255, 255, 255, 0.1)' } },
            x: { ticks: { color: '#fff', maxRotation: 45, minRotation: 45 }, grid: { color: 'rgba(255, 255, 255, 0.1)' } }
        }
    };
}

// Chart type selectors removed — fixed chart types

// Export chart as image
$('.export-chart').on('click', function() {
    const chartId = $(this).data('chart');
    
    if (chartId === 'all') {
        Object.keys(charts).forEach(key => {
            downloadChart(charts[key], key);
        });
    } else if (charts[chartId]) {
        downloadChart(charts[chartId], chartId);
    }
});

function downloadChart(chart, name) {
    const url = chart.toBase64Image();
    const link = document.createElement('a');
    link.href = url;
    link.download = name + '_chart.png';
    link.click();
}

// Quick filter buttons
$('.quick-filter').on('click', function() {
    const days = $(this).data('days');
    const endDate = new Date();
    const startDate = new Date();
    startDate.setDate(startDate.getDate() - days);
    
    $('input[name="start_date"]').val(startDate.toISOString().split('T')[0]);
    $('input[name="end_date"]').val(endDate.toISOString().split('T')[0]);
    $(this).closest('form').submit();
});
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
