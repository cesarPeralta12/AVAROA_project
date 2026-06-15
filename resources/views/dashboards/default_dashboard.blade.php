@extends('layout.master')

@section('title', 'Panel de Administración')

@section('css')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        --danger-gradient:  linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        --info-gradient:    linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        --dark-gradient:    linear-gradient(135deg, #334155 0%, #1e293b 100%);
    }

    /* ── KPI cards ──────────────────────────────────── */
    .dashboard-card {
        background: var(--av-card-bg, #ffffff);
        border-radius: 16px;
        padding: 20px;
        box-shadow: var(--av-shadow, 0 4px 15px rgba(0,0,0,0.06));
        transition: all 0.3s ease;
        border: 1px solid var(--av-border, #e2e8f0);
        position: relative;
        overflow: hidden;
    }
    .dashboard-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0;
        width: 4px; height: 100%;
        background: var(--primary-gradient);
    }
    .dashboard-card:hover { transform: translateY(-3px); box-shadow: 0 8px 30px rgba(0,0,0,0.12); }
    .dashboard-card.success::before { background: var(--success-gradient); }
    .dashboard-card.warning::before { background: var(--warning-gradient); }
    .dashboard-card.danger::before  { background: var(--danger-gradient); }
    .dashboard-card.info::before    { background: var(--info-gradient); }
    .dashboard-card.dark::before    { background: var(--dark-gradient); }

    .stat-icon {
        width: 52px; height: 52px;
        border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        font-size: 24px; margin-bottom: 14px;
    }
    .stat-icon.primary { background: rgba(102,126,234,.15); color: #667eea; }
    .stat-icon.success { background: rgba(17,153,142,.15);  color: #11998e; }
    .stat-icon.warning { background: rgba(240,147,251,.15); color: #f5576c; }
    .stat-icon.danger  { background: rgba(250,112,154,.15); color: #fa709a; }
    .stat-icon.info    { background: rgba(79,172,254,.15);  color: #4facfe; }
    .stat-icon.dark    { background: var(--av-surface, rgba(0,0,0,.06)); color: var(--av-text-muted, #64748b); }

    .stat-value {
        font-size: 28px; font-weight: 800;
        color: var(--av-text, #0f172a);
        margin-bottom: 2px; line-height: 1.2;
    }
    .stat-label  { color: var(--av-text-muted, #64748b); font-size: 13px; font-weight: 500; }
    .stat-change { font-size: 12px; font-weight: 600; margin-top: 6px; display: inline-flex; align-items: center; gap: 4px; }
    .stat-change.positive { color: #10b981; }
    .stat-change.negative { color: #ef4444; }

    /* ── Chart / panel containers ───────────────────── */
    .chart-container {
        background: var(--av-card-bg, #ffffff);
        border-radius: 16px; padding: 20px;
        box-shadow: var(--av-shadow, 0 4px 15px rgba(0,0,0,0.06));
        border: 1px solid var(--av-border, #e2e8f0);
    }
    .chart-wrapper    { position: relative; height: 300px; width: 100%; }
    .chart-wrapper-sm { position: relative; height: 260px; width: 100%; }

    .chart-header {
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 16px; padding-bottom: 14px;
        border-bottom: 1px solid var(--av-border, #e2e8f0);
    }
    .chart-title   { font-size: 16px; font-weight: 700; color: var(--av-text, #0f172a); }
    .section-title {
        font-size: 18px; font-weight: 700;
        color: var(--av-text, #0f172a);
        margin-bottom: 16px;
        display: flex; align-items: center; gap: 10px;
    }

    /* ── Activity / trips list ──────────────────────── */
    .activity-item {
        display: flex; align-items: center;
        padding: 14px;
        border-bottom: 1px solid var(--av-border, #e2e8f0);
        transition: background 0.2s;
    }
    .activity-item:hover { background: var(--av-surface, #f8fafc); }

    .activity-icon {
        width: 36px; height: 36px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        margin-right: 14px; font-size: 16px; flex-shrink: 0;
    }
    .activity-content { flex: 1; min-width: 0; }
    .activity-title {
        font-weight: 600; color: var(--av-text, #0f172a);
        font-size: 13px;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .activity-meta  { color: var(--av-text-muted, #64748b); font-size: 12px; margin-top: 2px; }
    .activity-status {
        padding: 5px 10px; border-radius: 20px;
        font-size: 11px; font-weight: 600;
        flex-shrink: 0; margin-left: 8px;
    }

    /* Status badges — kept vivid so they read in both modes */
    .status-completed { background: #d1fae5; color: #065f46; }
    .status-active    { background: #dbeafe; color: #1e40af; }
    .status-pending   { background: #fef3c7; color: #92400e; }
    .status-cancelled { background: #fee2e2; color: #7f1d1d; }
    .status-searching { background: #ede9fe; color: #5b21b6; }

    body.dark-only .status-completed { background: #065f46; color: #34d399; }
    body.dark-only .status-active    { background: #1e3a8a; color: #60a5fa; }
    body.dark-only .status-pending   { background: #92400e; color: #fbbf24; }
    body.dark-only .status-cancelled { background: #7f1d1d; color: #f87171; }
    body.dark-only .status-searching { background: #581c87; color: #c084fc; }

    /* ── Topup / wallet row ─────────────────────────── */
    .topup-item {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px;
        border-bottom: 1px solid var(--av-border, #e2e8f0);
    }
    .topup-amount { font-size: 16px; font-weight: 700; color: var(--av-text, #0f172a); }

    /* ── Misc ───────────────────────────────────────── */
    .btn-quick {
        padding: 10px 20px; border-radius: 10px; font-weight: 600;
        display: inline-flex; align-items: center; gap: 6px;
        transition: all 0.3s; font-size: 13px;
    }
    .live-indicator {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 6px 12px;
        background: #d1fae5; color: #065f46;
        border-radius: 20px; font-size: 12px; font-weight: 600;
    }
    body.dark-only .live-indicator { background: #064e3b; color: #34d399; }

    .live-dot {
        width: 8px; height: 8px;
        background: #22c55e; border-radius: 50%;
        animation: pulse 2s infinite;
    }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }

    /* ── Driver ranking ─────────────────────────────── */
    .driver-rank {
        display: flex; align-items: center;
        padding: 10px 14px;
        border-bottom: 1px solid var(--av-border, #e2e8f0);
    }
    .rank-number {
        width: 28px; height: 28px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 12px;
        margin-right: 12px; flex-shrink: 0;
    }
    .rank-1     { background: #fef3c7; color: #92400e; }
    .rank-2     { background: #f1f5f9; color: #475569; }
    .rank-3     { background: #ffedd5; color: #9a3412; }
    .rank-other { background: var(--av-surface, #f1f5f9); color: var(--av-text-muted, #64748b); }

    /* ── Scrollbar ──────────────────────────────────── */
    .activity-list::-webkit-scrollbar       { width: 6px; }
    .activity-list::-webkit-scrollbar-track { background: var(--av-surface, #f8fafc); }
    .activity-list::-webkit-scrollbar-thumb { background: var(--av-border, #cbd5e1); border-radius: 3px; }
</style>
@endsection

@section('main_content')

{{-- Fix for startTime console error from master layout --}}
<script>window.startTime = window.startTime || Date.now();</script>

<div class="container-fluid">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1 text-white">Panel de Administración</h2>
            <p class="text-muted mb-0">Bienvenido, {{ $user_session->name }}</p>
        </div>
        <div class="d-flex gap-3">
            <div class="live-indicator">
                <span class="live-dot"></span>
                En vivo
            </div>
            <button class="btn btn-primary btn-quick" onclick="refreshData()">
                <i class="fas fa-sync-alt"></i>
                Actualizar
            </button>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="row g-3 mb-4">
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="dashboard-card dark">
                <div class="stat-icon dark"><i class="fas fa-users"></i></div>
                <div class="stat-value">{{ number_format($totalUsers) }}</div>
                <div class="stat-label">Total Usuarios</div>
                <div class="stat-change positive"><i class="fas fa-arrow-up"></i> +{{ $newUsersToday }} hoy</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="dashboard-card">
                <div class="stat-icon primary"><i class="fas fa-shopping-cart"></i></div>
                <div class="stat-value">{{ $todayOrders }}</div>
                <div class="stat-label">Pedidos Hoy</div>
                <div class="stat-change positive"><i class="fas fa-arrow-up"></i> {{ $weekOrders }} esta semana</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="dashboard-card info">
                <div class="stat-icon info"><i class="fas fa-truck-fast"></i></div>
                <div class="stat-value">{{ $activeDeliveries }}</div>
                <div class="stat-label">Entregas Activas</div>
                <div class="stat-change positive"><i class="fas fa-clock"></i> En progreso</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="dashboard-card success">
                <div class="stat-icon success"><i class="fas fa-user-check"></i></div>
                <div class="stat-value">{{ $onlineDrivers }}</div>
                <div class="stat-label">Conductores Online</div>
                <div class="stat-change"><span class="text-muted">{{ $busyDrivers }} ocupados</span></div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="dashboard-card warning">
                <div class="stat-icon warning"><i class="fas fa-dollar-sign"></i></div>
                <div class="stat-value">Bs{{ number_format($todayRevenue, 0) }}</div>
                <div class="stat-label">Ingresos Hoy</div>
                <div class="stat-change positive"><i class="fas fa-wallet"></i> Bs{{ number_format($totalWalletBalance, 0) }} en billeteras</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="dashboard-card danger">
                <div class="stat-icon danger"><i class="fas fa-chart-line"></i></div>
                <div class="stat-value">{{ $slaPercentage }}%</div>
                <div class="stat-label">Cumplimiento SLA</div>
                <div class="stat-change"><span class="text-muted">{{ round($avgDeliveryTime) }} min promedio</span></div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-3 mb-4">
        <!-- Orders Trend -->
        <div class="col-xl-8">
            <div class="chart-container">
                <div class="chart-header">
                    <h5 class="chart-title"><i class="fas fa-chart-area text-primary me-2"></i>Tendencia de Pedidos (Últimos 7 días)</h5>
                    <div class="btn-group btn-group-sm" id="period-btns">
                        <button class="btn btn-outline-light active" data-period="7">7D</button>
                        <button class="btn btn-outline-light" data-period="30">30D</button>
                        <button class="btn btn-outline-light" data-period="90">90D</button>
                    </div>
                </div>
                <div class="chart-wrapper">
                    <canvas id="ordersChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Delivery Status Doughnut -->
        <div class="col-xl-4">
            <div class="chart-container">
                <div class="chart-header">
                    <h5 class="chart-title"><i class="fas fa-chart-pie text-info me-2"></i>Estado de Entregas</h5>
                </div>
                <div class="chart-wrapper-sm">
                    <canvas id="deliveryChart"></canvas>
                </div>
                <div class="row mt-3 text-center">
                    <div class="col-6">
                        <div class="text-success fw-bold">{{ $deliveryStatus['completed'] }}</div>
                        <small class="text-muted">Completados</small>
                    </div>
                    <div class="col-6">
                        <div class="text-danger fw-bold">{{ $deliveryStatus['cancelled'] }}</div>
                        <small class="text-muted">Cancelados</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Second Charts Row -->
    <div class="row g-3 mb-4">
        <!-- Revenue Trend -->
        <div class="col-xl-4">
            <div class="chart-container">
                <div class="chart-header">
                    <h5 class="chart-title"><i class="fas fa-money-bill-trend-up text-success me-2"></i>Ingresos del viaje (7d)</h5>
                </div>
                <div class="chart-wrapper">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Commission Trend -->
        <div class="col-xl-4">
            <div class="chart-container">
                <div class="chart-header">
                    <h5 class="chart-title"><i class="fas fa-percent text-warning me-2"></i>Ganancias de la Plataforma (7d)</h5>
                </div>
                <div style="display:flex;gap:16px;padding:0 4px 12px;">
                    <div style="flex:1;background:rgba(245,158,11,0.12);border-radius:10px;padding:10px 14px;">
                        <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Últimos 7 días</div>
                        <div style="font-size:1.25rem;font-weight:700;color:#f59e0b;">Bs {{ number_format($totalCommission7d, 2) }}</div>
                    </div>
                    <div style="flex:1;background:rgba(245,158,11,0.08);border-radius:10px;padding:10px 14px;">
                        <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Total acumulado</div>
                        <div style="font-size:1.25rem;font-weight:700;color:#f59e0b;">Bs {{ number_format($totalCommissionAll, 2) }}</div>
                    </div>
                </div>
                <div class="chart-wrapper">
                    <canvas id="commissionChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Driver Performance -->
        <div class="col-xl-4">
            <div class="chart-container">
                <div class="chart-header">
                    <h5 class="chart-title"><i class="fas fa-star text-warning me-2"></i>Top Conductores</h5>
                    <a href="{{ route('drivers.index') }}" class="btn btn-sm btn-outline-light">Ver todos</a>
                </div>
                <div class="driver-rankings">
                    @forelse($topDrivers as $index => $driver)
                    <div class="driver-rank">
                        <div class="rank-number rank-{{ $index < 3 ? $index + 1 : 'other' }}">{{ $index + 1 }}</div>
                        @if($driver->user?->profile_photo)
    <img 
        src="{{ \App\Services\FileUploadService::getUrl($driver->user->profile_photo) }}"
        class="rounded-circle me-3"
        width="36"
        height="36"
        alt="Driver"
        style="object-fit: cover;"
    >
@else
    <img 
        src="https://i.pravatar.cc/150?img={{ $index + 1 }}"
        class="rounded-circle me-3"
        width="36"
        height="36"
        alt="Driver"
        style="object-fit: cover;"
    >
@endif
                        <div class="flex-grow-1 min-width-0">
                            <div class="fw-bold text-white text-truncate" style="font-size: 14px;">{{ $driver->user->name ?? 'Conductor #' . $driver->id }}</div>
                            <small class="text-muted">
                                <i class="fas fa-star text-warning"></i>
                                {{ number_format($driver->score ?? 0, 1) }} ·
                                {{ $driver->completed_trips }} entregas
                            </small>
                        </div>
                        <div class="text-end flex-shrink-0">
                            <div class="fw-bold text-success">{{ number_format($driver->acceptance_rate ?? 0, 0) }}%</div>
                            <small class="text-muted">aceptación</small>
                        </div>
                    </div>
                    @empty
                    <div class="text-center py-4 text-muted">No hay conductores con entregas aún</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Row -->
    <div class="row g-3">
        <!-- Recent Activity -->
        <div class="col-xl-6">
            <div class="chart-container">
                <div class="chart-header">
                    <h5 class="chart-title"><i class="fas fa-clock-rotate-left text-info me-2"></i>Actividad Reciente</h5>
                    <a href="{{ route('trips.index') }}" class="btn btn-sm btn-outline-light">Ver todos</a>
                </div>
                <div class="activity-list" style="max-height: 380px; overflow-y: auto;">
                    @forelse($recentTrips as $trip)
                    <div class="activity-item">
                        <div class="activity-icon
                            @if($trip->status == 'completed') bg-success text-white
                            @elseif($trip->status == 'cancelled') bg-danger text-white
                            @elseif(in_array($trip->status, ['accepted', 'picked_up', 'in_progress'])) bg-primary text-white
                            @else bg-warning text-dark @endif">
                            <i class="fas
                                @if($trip->status == 'completed') fa-check
                                @elseif($trip->status == 'cancelled') fa-xmark
                                @elseif(in_array($trip->status, ['accepted', 'picked_up'])) fa-truck
                                @else fa-search @endif">
                            </i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title">Envío #{{ $trip->id }} - {{ $trip->origin_address ?? 'Sin dirección' }}</div>
                            <div class="activity-meta">
                                <i class="fas fa-user"></i> {{ $trip->customer->name ?? 'Cliente #' . $trip->customer_id }}
                                @if($trip->driver)
                                    · <i class="fas fa-id-card"></i> {{ $trip->driver->user->name ?? 'Conductor #' . $trip->driver_id }}
                                @endif
                                · {{ $trip->created_at->diffForHumans() }}
                            </div>
                        </div>
                        <span class="activity-status status-{{ $trip->status }}">
                            @switch($trip->status)
                                @case('completed') Completado @break
                                @case('cancelled') Cancelado @break
                                @case('searching') Buscando @break
                                @case('accepted') Aceptado @break
                                @case('picked_up') Recogido @break
                                @case('in_progress') En camino @break
                                @default {{ $trip->status }}
                            @endswitch
                        </span>
                    </div>
                    @empty
                    <div class="text-center py-4 text-muted">No hay actividad reciente</div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Pending Topups -->
        <div class="col-xl-6">
            <div class="chart-container">
                <div class="chart-header">
                    <h5 class="chart-title">
                        <i class="fas fa-wallet text-warning me-2"></i>Recargas Pendientes
                        @if($pendingTopups > 0)
                            <span class="badge bg-danger ms-2">{{ $pendingTopups }}</span>
                        @endif
                    </h5>
                    <a href="{{ route('topup-requests.index') }}" class="btn btn-sm btn-outline-light">Gestionar</a>
                </div>
                <div class="topup-list">
                   @forelse($pendingTopupRequests as $topup)
<div class="topup-item">
    <div class="d-flex align-items-center">

        @php
            $user = $topup->driver?->user;

            $photo = $user?->profile_photo
                ? \App\Services\FileUploadService::getUrl($user->profile_photo)
                : null;

            $initial = strtoupper(substr($user?->name ?? 'D', 0, 1));
            $name = $user?->name ?? 'Conductor #' . $topup->driver_id;
        @endphp

        {{-- Avatar --}}
        @if($photo)
            <img 
                src="{{ $photo }}"
                class="rounded-circle me-3"
                width="40"
                height="40"
                alt="Driver"
                style="object-fit: cover;"
            >
        @else
            <div 
                class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white me-3"
                style="width: 40px; height: 40px;"
            >
                {{ $initial }}
            </div>
        @endif

        {{-- Info --}}
        <div>
            <div class="fw-bold text-white" style="font-size: 14px;">
                {{ $name }}
            </div>

            <small class="text-muted">
                <i class="fas fa-clock"></i> {{ $topup->created_at->diffForHumans() }}
                · {{ ucfirst($topup->method) }}
            </small>
        </div>
    </div>

    {{-- Right side --}}
    <div class="text-end flex-shrink-0">
        <div class="topup-amount text-success">
            +Bs{{ number_format($topup->amount, 2) }}
        </div>

        <div class="btn-group btn-group-sm mt-2">
            <button class="btn btn-success btn-sm" onclick="approveTopup({{ $topup->id }})">
                <i class="fas fa-check"></i>
            </button>
            <button class="btn btn-danger btn-sm" onclick="rejectTopup({{ $topup->id }})">
                <i class="fas fa-xmark"></i>
            </button>
        </div>
    </div>
</div>

@empty
<div class="text-center py-4 text-muted">
    No hay recargas pendientes
</div>
@endforelse
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ==========================================
// FIXED CHART CONFIGURATION
// Key changes:
// 1. maintainAspectRatio: false (prevents wild vertical resizing)
// 2. Fixed-height wrappers prevent "dirty" up/down bouncing
// 3. Dark-theme colors for grid/text (no more invisible muddy lines)
// 4. Reduced tension (0.3) for cleaner lines without artificial spikes
// 5. Grace padding on Y-axis so points don't touch edges
// 6. resizeDelay prevents flickering during scroll
// ==========================================

const commonOptions = {
    responsive: true,
    maintainAspectRatio: false, // CRITICAL FIX
    resizeDelay: 100,
    animation: {
        duration: 900,
        easing: 'easeOutQuart'
    },
    interaction: {
        mode: 'index',
        intersect: false,
    },
    plugins: {
        legend: {
            labels: {
                color: '#94a3b8',
                font: { size: 12, family: "'Segoe UI', sans-serif" },
                usePointStyle: true,
                padding: 20
            }
        },
        tooltip: {
            backgroundColor: 'rgba(15, 23, 42, 0.95)',
            titleColor: '#e2e8f0',
            bodyColor: '#cbd5e1',
            borderColor: '#334155',
            borderWidth: 1,
            padding: 12,
            cornerRadius: 8,
            displayColors: true
        }
    },
    scales: {
        x: {
            grid: {
                color: 'rgba(148, 163, 184, 0.08)',
                drawBorder: false
            },
            ticks: {
                color: '#64748b',
                font: { size: 11 }
            }
        },
        y: {
            beginAtZero: true,
            grace: '10%', // Prevents points touching the very top
            grid: {
                color: 'rgba(148, 163, 184, 0.08)',
                drawBorder: false
            },
            ticks: {
                color: '#64748b',
                font: { size: 11 },
                padding: 8
            }
        }
    }
};

// --- Orders Trend Chart (Line) ---
const ordersCtx = document.getElementById('ordersChart');
let ordersChartInstance = null;
if (ordersCtx) {
    ordersChartInstance = new Chart(ordersCtx, {
        type: 'line',
        data: {
            labels: {!! json_encode($labels) !!},
            datasets: [{
                label: 'Pedidos',
                data: {!! json_encode($ordersTrend) !!},
                borderColor: '#667eea',
                backgroundColor: 'rgba(102, 126, 234, 0.15)',
                borderWidth: 2.5,
                tension: 0.3, // REDUCED from 0.4 for cleaner curves
                fill: true,
                pointBackgroundColor: '#667eea',
                pointBorderColor: '#0f172a',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7,
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: '#667eea'
            }]
        },
        options: {
            ...commonOptions,
            plugins: {
                ...commonOptions.plugins,
                legend: { display: false }
            }
        }
    });
}

// --- Commission Chart (Bar) ---
const commissionCtx = document.getElementById('commissionChart');
let commissionChartInstance = null;
if (commissionCtx) {
    commissionChartInstance = new Chart(commissionCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($labels) !!},
            datasets: [{
                label: 'Comisiones (Bs)',
                data: {!! json_encode($commissionTrend) !!},
                backgroundColor: 'rgba(245, 158, 11, 0.75)',
                hoverBackgroundColor: '#f59e0b',
                borderColor: '#f59e0b',
                borderWidth: 0,
                borderRadius: 6,
                borderSkipped: false,
                barPercentage: 0.6,
                categoryPercentage: 0.7
            }]
        },
        options: {
            ...commonOptions,
            plugins: {
                ...commonOptions.plugins,
                legend: { display: false }
            },
            scales: {
                ...commonOptions.scales,
                y: {
                    ...commonOptions.scales.y,
                    ticks: {
                        ...commonOptions.scales.y.ticks,
                        callback: function(value) { return 'Bs' + value; }
                    }
                }
            }
        }
    });
}

// --- Revenue Chart (Bar) ---
const revenueCtx = document.getElementById('revenueChart');
if (revenueCtx) {
    new Chart(revenueCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($labels) !!},
            datasets: [{
                label: 'Ingresos (Bs)',
                data: {!! json_encode($revenueTrend) !!},
                backgroundColor: 'rgba(16, 185, 129, 0.75)',
                hoverBackgroundColor: '#10b981',
                borderColor: '#10b981',
                borderWidth: 0,
                borderRadius: 6,
                borderSkipped: false,
                barPercentage: 0.6,
                categoryPercentage: 0.7
            }]
        },
        options: {
            ...commonOptions,
            plugins: {
                ...commonOptions.plugins,
                legend: { display: false }
            },
            scales: {
                ...commonOptions.scales,
                y: {
                    ...commonOptions.scales.y,
                    ticks: {
                        ...commonOptions.scales.y.ticks,
                        callback: function(value) {
                            return 'Bs' + value;
                        }
                    }
                }
            }
        }
    });
}

// --- Delivery Status Doughnut ---
const deliveryCtx = document.getElementById('deliveryChart');
if (deliveryCtx) {
    const deliveryData = [
        {{ $deliveryStatus['active'] }},
        {{ $deliveryStatus['completed'] }},
        {{ $deliveryStatus['cancelled'] }},
        {{ $deliveryStatus['pending'] }},
        {{ $deliveryStatus['searching'] }}
    ];
    const total = deliveryData.reduce((a, b) => a + b, 0);

    new Chart(deliveryCtx, {
        type: 'doughnut',
        data: {
            labels: ['Activos', 'Completados', 'Cancelados', 'Pendientes', 'Buscando'],
            datasets: [{
                data: total === 0 ? [0, 0, 0, 0, 1] : deliveryData, // Prevent empty render
                backgroundColor: [
                    '#3b82f6',
                    '#10b981',
                    '#ef4444',
                    '#f59e0b',
                    '#8b5cf6'
                ],
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false, // CRITICAL FIX
            cutout: '68%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: '#94a3b8',
                        font: { size: 11 },
                        padding: 16,
                        usePointStyle: true
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            if (total === 0) return ' Sin datos';
                            let val = context.parsed;
                            let pct = ((val / total) * 100).toFixed(1);
                            return ` ${context.label}: ${val} (${pct}%)`;
                        }
                    }
                }
            }
        }
    });
}

// ==========================================
// FIXED Realtime Refresh
// Added: try/catch, response validation, visual feedback
// ==========================================
let isRefreshing = false;

function refreshData() {
    if (isRefreshing) return;
    isRefreshing = true;

    const btn = document.querySelector('.btn-quick i');
    if (btn) btn.classList.add('fa-spin');

    fetch('{{ route("dashboard.stats") }}', {
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(async response => {
        // FIXED: Handle HTML error pages gracefully
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Respuesta no válida del servidor');
        }
        if (!response.ok) {
            const err = await response.json().catch(() => ({ message: 'Error del servidor' }));
            throw new Error(err.message || `Error HTTP ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success === false) {
            throw new Error(data.message || 'Error desconocido');
        }
        console.log('Datos actualizados:', data);

        // Optional: Update DOM elements without reload if you add data-id attributes to stat-value elements
        document.querySelectorAll('.stat-value').forEach(el => {
            el.style.transform = 'scale(1.08)';
            setTimeout(() => el.style.transform = 'scale(1)', 200);
        });
    })
    .catch(error => {
        console.warn('No se pudieron actualizar los datos en tiempo real:', error.message);
        // Silently fail so the UI doesn't break
    })
    .finally(() => {
        isRefreshing = false;
        if (btn) btn.classList.remove('fa-spin');
    });
}

// Auto refresh every 60 seconds (reduced from 30s to avoid hammering a broken endpoint)
setInterval(refreshData, 60000);

// ==========================================
// Chart period switching (7D / 30D / 90D)
// ==========================================
document.querySelectorAll('#period-btns button').forEach(btn => {
    btn.addEventListener('click', function () {
        const period = this.dataset.period;
        document.querySelectorAll('#period-btns button').forEach(b => b.classList.remove('active'));
        this.classList.add('active');

        fetch(`{{ route('dashboard.chart-data') }}?period=${period}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            if (ordersChartInstance) {
                ordersChartInstance.data.labels = data.labels;
                ordersChartInstance.data.datasets[0].data = data.ordersTrend;
                ordersChartInstance.update();
            }
            if (commissionChartInstance && data.commissionTrend) {
                commissionChartInstance.data.labels = data.labels;
                commissionChartInstance.data.datasets[0].data = data.commissionTrend;
                commissionChartInstance.update();
            }
        })
        .catch(err => console.warn('Error al cargar datos del gráfico:', err));
    });
});

// ==========================================
// Topup Actions
// ==========================================
function approveTopup(id) {
    if(!confirm('¿Aprobar esta recarga?')) return;
    fetch(`/admin/topups/${id}/approve`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        }
    })
    .then(r => r.ok ? location.reload() : alert('Error al aprobar'))
    .catch(() => alert('Error de red'));
}

function rejectTopup(id) {
    const reason = prompt('Motivo del rechazo:');
    if(!reason) return;
    fetch(`/admin/topups/${id}/reject`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ reason })
    })
    .then(r => r.ok ? location.reload() : alert('Error al rechazar'))
    .catch(() => alert('Error de red'));
}
</script>
@endsection
