<?php

use App\Models\Billing;
use App\Models\Booking;
use App\Models\BillingAddon;
use App\Models\Addon;
use App\Models\Payment;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Detail Billing', 'breadcrumbs' => [
    ['title' => 'Monitoring'],
    ['title' => 'Billing', 'url' => null],
    ['title' => 'Detail', 'url' => '#'],
]])] class extends Component {

    public Billing $billing;

    public bool $showAddonModal    = false;
    public bool $showExtendModal   = false;
    public bool $showFinishModal   = false;
    public bool $isTimeUp          = false;
    public float $extendHours      = 1;

    // Pembayaran
    public string $paymentMethod   = 'cash';
    public float  $amountPaid      = 0;

    // ── Mount ────────────────────────────────────────────────

    public function mount(int $id): void
    {
        $this->billing = Billing::with([
            'booking.customer',
            'booking.confirmedBy',
            'customer',
            'table',
            'package.pricing',
            'pricing',
            'startedBy',
            'endedBy',
            'payment',
            'addons.addon',
            'timeExtensions.extendedByUser',
        ])->findOrFail($id);

        $this->checkTime();
    }

    // ── Polling: cek waktu setiap 10 detik ──────────────────

    public function checkTime(): void
    {
        if ($this->billing->isActive() && $this->billing->scheduled_end_at) {
            $wasTimeUp = $this->isTimeUp;
            $this->isTimeUp = now()->greaterThanOrEqualTo($this->billing->scheduled_end_at);

            // Matikan lampu segera jika waktu habis (meskipun belum dibayar)
            if ($this->isTimeUp && $this->billing->table && $this->billing->table->device_status) {
                $this->billing->table->update(['device_status' => false]);
                try {
                    broadcast(new \App\Events\TableStatusUpdated($this->billing->table->id));
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            // Auto-finish jika melewati 5 menit grace period
            if (now()->greaterThanOrEqualTo($this->billing->scheduled_end_at->copy()->addMinutes(5))) {
                $this->finishBilling(auto: true);
            }
        } else {
            $this->isTimeUp = false;
        }
    }

    // ── REVERB LISTENER ──────────────────────────────────────

    #[On('echo:billiard-updates,BillingUpdated')]
    public function refreshBilling($event): void
    {
        $bId = is_array($event) ? ($event['billingId'] ?? null) : $event;
        if ($this->billing->id == $bId) {
            $this->billing->refresh();
            $this->checkTime(); // Re-evaluate time up status
        }
    }

    // ── COMPUTED ─────────────────────────────────────────────

    #[Computed]
    public function availableAddons()
    {
        return Addon::where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function changeAmount(): float
    {
        if ($this->paymentMethod !== 'cash') return 0;
        return max(0, $this->amountPaid - $this->billing->current_total);
    }

    #[Computed]
    public function isAmountSufficient(): bool
    {
        if ($this->paymentMethod !== 'cash') return true;
        return $this->amountPaid >= $this->billing->current_total;
    }

    #[Computed]
    public function isWalkIn(): bool
    {
        return is_null($this->billing->booking_id);
    }

    #[Computed]
    public function confirmedAddons()
    {
        return $this->billing->addons->where('status', 'confirmed');
    }

    #[Computed]
    public function pendingAddons()
    {
        return $this->billing->addons->where('status', 'pending');
    }

    // ── FINISH BILLING ───────────────────────────────────────

    public function finishBilling(?\App\Services\BillingSessionManager $sessionManager = null, bool $auto = false): void
    {
        $sessionManager = $sessionManager ?? app(\App\Services\BillingSessionManager::class);
        if (!$this->billing->isActive()) return;

        // Validasi pembayaran (hanya jika bukan auto-finish)
        if (!$auto) {
            $rules = [
                'paymentMethod' => 'required|in:cash,transfer,qris',
            ];
            $messages = [
                'paymentMethod.required' => 'Metode pembayaran harus dipilih.',
                'paymentMethod.in'       => 'Metode pembayaran tidak valid.',
            ];

            if ($this->paymentMethod === 'cash') {
                $rules['amountPaid'] = 'required|numeric|min:0';
                $messages['amountPaid.required'] = 'Nominal uang yang diterima harus diisi.';
                $messages['amountPaid.min']       = 'Nominal tidak boleh negatif.';
            }

            $this->validate($rules, $messages);

            // Cek kecukupan uang (cash)
            if ($this->paymentMethod === 'cash' && $this->amountPaid < $this->billing->current_total) {
                $this->addError('amountPaid', 'Uang yang diterima kurang dari total tagihan.');
                return;
            }
        }

        try {
            $amountPaidVal = ($this->paymentMethod === 'cash') ? (float)$this->amountPaid : null;
            $sessionManager->finish($this->billing, $this->paymentMethod ?: 'cash', $amountPaidVal, $auto);

            $this->isTimeUp        = false;
            $this->showExtendModal = false;
            $this->showFinishModal = false;
            $this->billing->refresh();

            $msg = $auto
                ? 'Waktu lewat 5 menit. Billing otomatis diselesaikan!'
                : 'Permainan berhasil diselesaikan & pembayaran tercatat!';
            $this->dispatch('notify', message: $msg, type: $auto ? 'info' : 'success');
        } catch (\App\Exceptions\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');
        }
    }

    // ── PERPANJANG WAKTU ─────────────────────────────────────

    public function extendBilling(?\App\Services\BillingSessionManager $sessionManager = null): void
    {
        $sessionManager = $sessionManager ?? app(\App\Services\BillingSessionManager::class);
        if (!$this->billing->isActive() || !$this->billing->scheduled_end_at) return;

        $this->validate(['extendHours' => 'required|integer|min:1'], [
            'extendHours.required' => 'Durasi perpanjangan harus diisi.',
            'extendHours.integer'  => 'Perpanjangan harus dalam satuan jam penuh.',
            'extendHours.min'      => 'Minimal perpanjangan 1 jam.',
        ]);

        try {
            $sessionManager->extend($this->billing, (int)$this->extendHours);

            $this->showExtendModal = false;
            $this->isTimeUp        = false;
            $this->billing->refresh();
            $this->dispatch('notify', message: 'Waktu berhasil diperpanjang ' . $this->extendHours . ' jam!', type: 'success');
        } catch (\App\Exceptions\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');
        }
    }

    // ── TAMBAH ADDON ─────────────────────────────────────────

    public function addAddon(int $addonId): void
    {
        if (!$this->billing->isActive()) return;

        $addon = Addon::find($addonId);
        if (!$addon) return;

        // Jika addon sudah confirmed, tambah qty saja
        $existing = BillingAddon::where('billing_id', $this->billing->id)
            ->where('addon_id', $addonId)
            ->where('status', 'confirmed')
            ->first();

        if ($existing) {
            $existing->update([
                'quantity' => $existing->quantity + 1,
                'subtotal' => $addon->price * ($existing->quantity + 1),
            ]);
        } else {
            BillingAddon::create([
                'billing_id'        => $this->billing->id,
                'addon_id'          => $addonId,
                'quantity'          => 1,
                'unit_price'        => $addon->price,
                'subtotal'          => $addon->price,
                'status'            => 'confirmed',
                'requested_by'      => auth()->id(),
                'requested_by_role' => 'kasir',
                'confirmed_by'      => auth()->id(),
                'confirmed_at'      => now(),
            ]);
        }

        // Update addon_total di billing
        $this->billing->update([
            'addon_total' => $this->billing->confirmedAddons()->sum('subtotal'),
        ]);

        $this->billing->refresh();
        $this->showAddonModal = false;
        $this->dispatch('notify', message: '1x ' . $addon->name . ' ditambahkan ke tagihan.', type: 'success');
    }

    // ── HAPUS / KURANGI ADDON ────────────────────────────────

    public function removeAddon(int $billingAddonId): void
    {
        if (!$this->billing->isActive()) return;

        $ba = BillingAddon::where('billing_id', $this->billing->id)
            ->where('id', $billingAddonId)
            ->where('status', 'confirmed')
            ->first();

        if (!$ba) return;

        $ba->delete();

        $this->billing->update([
            'addon_total' => $this->billing->confirmedAddons()->sum('subtotal'),
        ]);

        $this->billing->refresh();
        $this->dispatch('notify', message: 'Addon berhasil dihapus dari tagihan.', type: 'success');
    }

    public function decrementAddon(int $billingAddonId): void
    {
        if (!$this->billing->isActive()) return;

        $ba = BillingAddon::where('billing_id', $this->billing->id)
            ->where('id', $billingAddonId)
            ->where('status', 'confirmed')
            ->first();

        if (!$ba) return;

        if ($ba->quantity <= 1) {
            $ba->delete();
        } else {
            $ba->update([
                'quantity' => $ba->quantity - 1,
                'subtotal' => $ba->unit_price * ($ba->quantity - 1),
            ]);
        }

        $this->billing->update([
            'addon_total' => $this->billing->confirmedAddons()->sum('subtotal'),
        ]);

        $this->billing->refresh();
    }

    public function incrementAddon(int $billingAddonId): void
    {
        if (!$this->billing->isActive()) return;

        $ba = BillingAddon::where('billing_id', $this->billing->id)
            ->where('id', $billingAddonId)
            ->where('status', 'confirmed')
            ->first();

        if (!$ba) return;

        $ba->update([
            'quantity' => $ba->quantity + 1,
            'subtotal' => $ba->unit_price * ($ba->quantity + 1),
        ]);

        $this->billing->update([
            'addon_total' => $this->billing->confirmedAddons()->sum('subtotal'),
        ]);

        $this->billing->refresh();
    }
};
?>

<div wire:poll.10s="checkTime">

    {{-- ── ALERT WAKTU HABIS ───────────────────────────────── --}}
    @if($isTimeUp && $billing->isActive())
        <div class="alert alert-danger d-flex flex-column flex-md-row align-items-center justify-content-between gap-3 mb-4 shadow-sm">
            <div class="d-flex align-items-center gap-3">
                <i class="fa-solid fa-hourglass-end fa-2x text-danger flex-shrink-0"></i>
                <div>
                    <div class="fw-bold fs-5">Waktu Permainan Telah Habis!</div>
                    <div class="small">Billing telah melewati batas waktu yang dijadwalkan. Sistem akan menyelesaikan billing otomatis dalam beberapa menit.</div>
                </div>
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                @if($billing->scheduled_end_at)
                    <button class="btn btn-outline-danger btn-sm" @click="$wire.set('showExtendModal', true)">
                        <i class="fa-solid fa-clock-rotate-left me-1"></i> Perpanjang
                    </button>
                @endif
                <button class="btn btn-danger btn-sm fw-bold" @click="$wire.set('showFinishModal', true)">
                    <i class="fa-solid fa-stop me-1"></i> Selesaikan Sekarang
                </button>
            </div>
        </div>
    @endif

    <div class="row g-4">

        {{-- ════════════════════════════════════════════════════ --}}
        {{-- KOLOM KIRI                                          --}}
        {{-- ════════════════════════════════════════════════════ --}}
        <div class="col-lg-8">

            {{-- ── CARD: HEADER BILLING ──────────────────────── --}}
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">

                        {{-- Kode & Tipe --}}
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                @if($this->isWalkIn)
                                    <span class="badge bg-warning text-dark">
                                        <i class="fa-solid fa-person-walking me-1"></i>Walk-In
                                    </span>
                                @else
                                    <span class="badge bg-info">
                                        <i class="fa-solid fa-calendar-check me-1"></i>Dari Booking
                                    </span>
                                @endif
                                <span class="badge {{ $billing->isActive() ? 'bg-success' : 'bg-primary' }} fs-6 px-3">
                                    {{ $billing->isActive() ? 'Aktif' : 'Selesai' }}
                                </span>
                            </div>
                            <h4 class="fw-bold font-monospace mb-0">{{ $billing->billing_code }}</h4>
                            <div class="text-muted small mt-1">
                                <i class="fa-solid fa-clock me-1"></i>
                                Dibuat {{ $billing->created_at->diffForHumans() }} — {{ $billing->created_at->format('d M Y, H:i') }}
                            </div>
                        </div>

                        {{-- Total Saat Ini --}}
                        <div class="text-end">
                            <div class="text-muted small mb-1">Total {{ $billing->isActive() ? 'Sementara' : 'Final' }}</div>
                            <div class="fw-bold fs-3 text-success">
                                {{ $billing->formatted_current_total }}
                            </div>
                            @if($billing->isActive())
                                <div class="font-monospace fw-bold text-primary mt-1" style="font-size:1.1rem;"
                                    id="elapsed-timer"
                                    data-elapsed-seconds="{{ $billing->elapsed_seconds }}"
                                    data-capped="{{ $billing->scheduled_end_at && now()->greaterThanOrEqualTo($billing->scheduled_end_at) ? '1' : '0' }}">
                                    {{ $billing->elapsed_formatted }}
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── CARD: INFO BILLING ─────────────────────────── --}}
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="fa-solid fa-receipt me-2 text-primary"></i>
                        Detail Billing
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">

                        {{-- Meja --}}
                        <div class="col-md-4">
                            <div class="p-3 rounded-3 bg-light h-100">
                                <div class="text-muted small mb-1"><i class="fa-solid fa-table me-1"></i>MEJA</div>
                                <div class="fw-bold fs-5">{{ $billing->table?->name ?? '-' }}</div>
                                <div class="small text-muted">{{ $billing->table?->table_number ?? '' }}</div>
                            </div>
                        </div>

                        {{-- Paket --}}
                        <div class="col-md-4">
                            <div class="p-3 rounded-3 bg-light h-100">
                                <div class="text-muted small mb-1"><i class="fa-solid fa-box-open me-1"></i>PAKET</div>
                                @if($billing->package)
                                    <div class="fw-semibold">{{ $billing->package->name }}</div>
                                    <div class="mt-1">
                                        @if($billing->package->isNormal())
                                            <span class="badge bg-info-subtle text-info border border-info-subtle">
                                                <i class="fa-solid fa-clock me-1"></i>{{ (int)$billing->package->duration_hours }} Jam Fix
                                            </span>
                                        @else
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                                                <i class="fa-solid fa-infinity me-1"></i>Waktu Bebas
                                            </span>
                                        @endif
                                    </div>
                                @else
                                    <div class="fw-semibold">Tanpa Paket</div>
                                    <div class="small text-muted">Tarif per jam</div>
                                @endif
                            </div>
                        </div>

                        {{-- Tarif --}}
                        <div class="col-md-4">
                            <div class="p-3 rounded-3 bg-light h-100">
                                <div class="text-muted small mb-1"><i class="fa-solid fa-tags me-1"></i>TARIF</div>
                                @if($billing->pricing)
                                    <div class="fw-semibold">{{ $billing->pricing->name }}</div>
                                    <div class="text-success fw-bold">
                                        Rp {{ number_format((float)$billing->pricing->price_per_hour, 0, ',', '.') }}/jam
                                    </div>
                                @elseif($billing->package?->isNormal())
                                    <div class="fw-semibold text-primary">
                                        Rp {{ number_format((float)$billing->package->price, 0, ',', '.') }}
                                    </div>
                                    <div class="small text-muted">Harga paket flat</div>
                                @else
                                    <div class="text-muted small">—</div>
                                @endif
                            </div>
                        </div>

                        {{-- Waktu Mulai --}}
                        <div class="col-md-4">
                            <div class="text-muted small mb-1"><i class="fa-solid fa-play me-1"></i>MULAI</div>
                            <div class="fw-medium">{{ $billing->started_at->format('d M Y') }}</div>
                            <div class="fw-bold text-success fs-5">{{ $billing->started_at->format('H:i') }}</div>
                        </div>

                        {{-- Jadwal Selesai --}}
                        <div class="col-md-4">
                            <div class="text-muted small mb-1"><i class="fa-solid fa-flag-checkered me-1"></i>JADWAL SELESAI</div>
                            @if($billing->scheduled_end_at)
                                <div class="fw-medium">{{ $billing->scheduled_end_at->format('d M Y') }}</div>
                                <div class="fw-bold fs-5 {{ $isTimeUp ? 'text-danger' : 'text-warning' }}">
                                    {{ $billing->scheduled_end_at->format('H:i') }}
                                </div>
                                @if($billing->isActive() && !$isTimeUp)
                                    <div class="small text-muted">
                                        Sisa: {{ now()->diffInMinutes($billing->scheduled_end_at, false) > 0 ? now()->diff($billing->scheduled_end_at)->format('%H:%I') . ' jam' : '0 menit' }}
                                    </div>
                                @endif
                            @else
                                <div class="small text-muted mt-1">Tidak ada batas waktu</div>
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Waktu Bebas</span>
                            @endif
                        </div>

                        {{-- Realisasi Selesai --}}
                        <div class="col-md-4">
                            <div class="text-muted small mb-1"><i class="fa-solid fa-stop me-1"></i>SELESAI AKTUAL</div>
                            @if($billing->isCompleted())
                                <div class="fw-medium">{{ $billing->ended_at->format('d M Y') }}</div>
                                <div class="fw-bold fs-5 text-primary">{{ $billing->ended_at->format('H:i') }}</div>
                                <div class="small text-muted">Durasi: {{ $billing->actual_duration_hours }} jam</div>
                            @else
                                <div class="small text-muted mt-1">Masih berlangsung...</div>
                            @endif
                        </div>

                    </div>

                    {{-- Progress Bar (paket normal aktif) --}}
                    @if($billing->isActive() && $billing->scheduled_end_at)
                        @php
                            $totalSec   = $billing->started_at->diffInSeconds($billing->scheduled_end_at);
                            $elapsedSec = min($billing->started_at->diffInSeconds(now()), $totalSec);
                            $pct        = $totalSec > 0 ? min(100, round(($elapsedSec / $totalSec) * 100)) : 100;
                            $barColor   = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
                        @endphp
                        <div class="mt-4">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>{{ $billing->started_at->format('H:i') }}</span>
                                <span class="fw-semibold {{ $pct >= 90 ? 'text-danger' : '' }}">{{ $pct }}% terpakai</span>
                                <span>{{ $billing->scheduled_end_at->format('H:i') }}</span>
                            </div>
                            <div class="progress" style="height:10px;border-radius:8px;">
                                <div class="progress-bar {{ $barColor }}" style="width:{{ $pct }}%;border-radius:8px;"></div>
                            </div>
                        </div>
                    @endif

                    {{-- Catatan --}}
                    @if($billing->notes)
                        <div class="mt-4 p-3 rounded-3 bg-light border">
                            <div class="text-muted small fw-semibold mb-1">
                                <i class="fa-solid fa-note-sticky me-1"></i>CATATAN
                            </div>
                            <div class="small">{{ $billing->notes }}</div>
                        </div>
                    @endif
                </div>

                {{-- Footer Aksi --}}
                @if($billing->isActive())
                    <div class="card-footer bg-white border-top d-flex flex-wrap justify-content-end gap-2">
                        @if($billing->scheduled_end_at)
                            <button class="btn btn-outline-primary btn-sm" @click="$wire.set('showExtendModal', true)">
                                <i class="fa-solid fa-clock-rotate-left me-1"></i> Perpanjang Waktu
                            </button>
                        @endif
                        <button class="btn btn-outline-info btn-sm" @click="$wire.set('showAddonModal', true)">
                            <i class="fa-solid fa-plus me-1"></i> Tambah Addon F&B
                        </button>
                        <button class="btn btn-danger btn-sm fw-semibold"
                            @click="$wire.set('showFinishModal', true)">
                            <i class="fa-solid fa-cash-register me-1"></i> Selesaikan & Bayar
                        </button>
                    </div>
                @endif
            </div>

            {{-- ── CARD: INFO BOOKING (jika dari booking) ─────── --}}
            @if(!$this->isWalkIn && $billing->booking)
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-semibold">
                            <i class="fa-solid fa-calendar-check me-2 text-info"></i>
                            Detail Booking Terkait
                        </h5>
                        @php
                            $bookingBadge = [
                                'pending'   => ['bg-warning text-dark', 'Menunggu'],
                                'confirmed' => ['bg-success',           'Dikonfirmasi'],
                                'rejected'  => ['bg-danger',            'Ditolak'],
                                'cancelled' => ['bg-secondary',         'Dibatalkan'],
                                'completed' => ['bg-primary',           'Selesai'],
                            ][$billing->booking->status] ?? ['bg-secondary', $billing->booking->status];
                        @endphp
                        <span class="badge {{ $bookingBadge[0] }}">{{ $bookingBadge[1] }}</span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="text-muted small mb-1">Kode Booking</div>
                                <div class="fw-bold font-monospace">{{ $billing->booking->booking_code }}</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small mb-1">Tanggal Booking</div>
                                <div class="fw-medium">{{ $billing->booking->scheduled_date?->format('d M Y') ?? '-' }}</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small mb-1">Jam Booking</div>
                                <div class="fw-medium">
                                    {{ $billing->booking->scheduled_start ? \Carbon\Carbon::parse($billing->booking->scheduled_start)->format('H:i') : '-' }}
                                    –
                                    {{ $billing->booking->scheduled_end ? \Carbon\Carbon::parse($billing->booking->scheduled_end)->format('H:i') : '-' }}
                                </div>
                            </div>
                            @if($billing->booking->notes)
                                <div class="col-12">
                                    <div class="text-muted small mb-1">Catatan Booking</div>
                                    <div class="p-2 bg-light rounded small">{{ $billing->booking->notes }}</div>
                                </div>
                            @endif
                            @if($billing->booking->confirmedBy)
                                <div class="col-12">
                                    <div class="alert alert-success d-flex align-items-center gap-2 mb-0 py-2">
                                        <i class="fa-solid fa-circle-check flex-shrink-0"></i>
                                        <div class="small">
                                            Dikonfirmasi oleh <strong>{{ $billing->booking->confirmedBy->name }}</strong>
                                            pada {{ $billing->booking->confirmed_at?->format('d M Y, H:i') }}
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="card-footer bg-white">
                        <a href="{{ auth()->user()->hasRole('owner')
                                ? route('owner.booking.show', $billing->booking_id)
                                : route('kasir.booking.show', $billing->booking_id) }}"
                            class="btn btn-sm btn-outline-info" wire:navigate>
                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Buka Detail Booking
                        </a>
                    </div>
                </div>
            @endif


            {{-- ── CARD: DAFTAR ADDON ─────────────────────────── --}}
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-semibold">
                        <i class="fa-solid fa-burger me-2 text-info"></i>
                        Pesanan Addon F&B
                    </h5>
                    @if($billing->isActive())
                        <button class="btn btn-sm btn-outline-info" @click="$wire.set('showAddonModal', true)">
                            <i class="fa-solid fa-plus me-1"></i> Tambah
                        </button>
                    @endif
                </div>
                @if($this->confirmedAddons->isEmpty())
                    <div class="card-body text-center py-4 text-muted">
                        <i class="fa-solid fa-basket-shopping fa-2x mb-2 d-block opacity-50"></i>
                        <p class="small mb-0">Belum ada pesanan addon.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center">#</th>
                                    <th>Item</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Harga Satuan</th>
                                    <th class="text-end">Subtotal</th>
                                    @if($billing->isActive())
                                        <th class="text-center">Aksi</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($this->confirmedAddons as $i => $ba)
                                    <tr>
                                        <td class="text-center text-muted">{{ $i + 1 }}</td>
                                        <td>
                                            <div class="fw-medium">{{ $ba->addon->name ?? 'Item Terhapus' }}</div>
                                            @if($ba->addon)
                                                <div class="small text-muted">{{ $ba->addon->category }}</div>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if($billing->isActive())
                                                <div class="d-flex align-items-center justify-content-center gap-1">
                                                    <button type="button" wire:click="decrementAddon({{ $ba->id }})"
                                                        class="btn btn-sm btn-outline-secondary p-0"
                                                        style="width:24px;height:24px;line-height:1;">
                                                        <i class="fa-solid fa-minus" style="font-size:9px;"></i>
                                                    </button>
                                                    <span class="fw-bold mx-1" style="min-width:20px;text-align:center;">{{ $ba->quantity }}</span>
                                                    <button type="button" wire:click="incrementAddon({{ $ba->id }})"
                                                        class="btn btn-sm btn-outline-primary p-0"
                                                        style="width:24px;height:24px;line-height:1;">
                                                        <i class="fa-solid fa-plus" style="font-size:9px;"></i>
                                                    </button>
                                                </div>
                                            @else
                                                <span class="fw-medium">{{ $ba->quantity }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end text-muted">Rp {{ number_format($ba->unit_price, 0, ',', '.') }}</td>
                                        <td class="text-end fw-semibold text-success">{{ $ba->formatted_subtotal }}</td>
                                        @if($billing->isActive())
                                            <td class="text-center">
                                                <button type="button"
                                                    wire:click="removeAddon({{ $ba->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="removeAddon({{ $ba->id }})"
                                                    class="btn btn-sm btn-icon btn-danger rounded-circle"
                                                    title="Hapus">
                                                    <span class="btn-inner">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </span>
                                                </button>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="{{ $billing->isActive() ? 4 : 3 }}" class="text-end fw-bold pe-3">
                                        Total Addon F&B:
                                    </td>
                                    <td class="text-end fw-bold text-primary">
                                        Rp {{ number_format($billing->addon_total, 0, ',', '.') }}
                                    </td>
                                    @if($billing->isActive())<td></td>@endif
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── CARD: RIWAYAT PERPANJANGAN WAKTU ──────────── --}}
            @if($billing->timeExtensions->isNotEmpty())
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="mb-0 fw-semibold">
                            <i class="fa-solid fa-clock-rotate-left me-2 text-warning"></i>
                            Riwayat Perpanjangan Waktu
                        </h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center">#</th>
                                    <th>Tambah Durasi</th>
                                    <th>Batas Waktu Baru</th>
                                    <th>Oleh</th>
                                    <th>Waktu Proses</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($billing->timeExtensions as $idx => $ext)
                                    <tr>
                                        <td class="text-center text-muted">{{ $idx + 1 }}</td>
                                        <td>
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                                                +{{ $ext->added_hours }} jam
                                            </span>
                                        </td>
                                        <td class="fw-medium">
                                            {{ $ext->new_scheduled_at
                                                ? \Carbon\Carbon::parse($ext->new_scheduled_at)->format('d M Y, H:i')
                                                : '-' }}
                                        </td>
                                        <td>{{ $ext->extendedByUser?->name ?? '-' }}</td>
                                        <td class="text-muted small">{{ $ext->created_at->format('d M Y, H:i') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

        </div>
        {{-- /col-lg-8 --}}


        {{-- ════════════════════════════════════════════════════ --}}
        {{-- KOLOM KANAN                                         --}}
        {{-- ════════════════════════════════════════════════════ --}}
        <div class="col-lg-4">

            {{-- ── CARD: INFORMASI PELANGGAN ─────────────────── --}}
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="fa-solid fa-user me-2 text-primary"></i>
                        Informasi Pelanggan
                    </h5>
                </div>
                <div class="card-body">
                    @if($this->isWalkIn)
                        {{-- Pelanggan Walk-In --}}
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="rounded-circle bg-warning text-dark d-flex align-items-center justify-content-center fw-bold flex-shrink-0"
                                style="width:52px;height:52px;font-size:20px;">
                                {{ strtoupper(substr($billing->guest_name ?? 'T', 0, 1)) }}
                            </div>
                            <div>
                                <div class="fw-semibold fs-6">{{ $billing->guest_name ?? '-' }}</div>
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle mt-1">
                                    <i class="fa-solid fa-person-walking me-1"></i>Walk-In
                                </span>
                            </div>
                        </div>
                        <div class="alert alert-warning py-2 border-0 bg-warning-subtle small mb-0">
                            <i class="fa-solid fa-circle-info me-1 text-warning"></i>
                            Pelanggan tanpa akun. Billing dibuat manual oleh kasir/owner.
                        </div>
                    @elseif($billing->customer)
                        {{-- Member terdaftar --}}
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="flex-shrink-0">
                                @if($billing->customer->avatar)
                                    <img src="{{ Storage::url($billing->customer->avatar) }}"
                                        class="rounded-circle" width="52" height="52"
                                        style="object-fit:cover;" alt="{{ $billing->customer->name }}">
                                @else
                                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold"
                                        style="width:52px;height:52px;font-size:20px;">
                                        {{ strtoupper(substr($billing->customer->name, 0, 1)) }}
                                    </div>
                                @endif
                            </div>
                            <div>
                                <div class="fw-semibold fs-6">{{ $billing->customer->name }}</div>
                                <div class="small text-muted">{{ $billing->customer->email }}</div>
                                <span class="badge bg-info-subtle text-info border border-info-subtle mt-1">
                                    <i class="fa-solid fa-id-card me-1"></i>Member
                                </span>
                            </div>
                        </div>
                        @if($billing->customer->phone)
                            <div class="text-muted small mb-1">No. HP</div>
                            <div class="fw-medium">{{ $billing->customer->phone }}</div>
                        @endif
                    @else
                        <p class="text-muted small mb-0">Data pelanggan tidak tersedia.</p>
                    @endif
                </div>
            </div>

            {{-- ── CARD: RINGKASAN BIAYA ─────────────────────── --}}
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="fa-solid fa-calculator me-2 text-success"></i>
                        Ringkasan Biaya
                    </h5>
                </div>
                <div class="card-body">
                    @php
                        // Hitung komponen harga live (aktif maupun selesai)
                        $pkg     = $billing->package;
                        // Fallback: pakai pricing dari package jika billing tidak punya pricing_id langsung
                        $pricing = $billing->pricing ?? $pkg?->pricing;

                        if ($billing->isCompleted()) {
                            $liveElapsedHours = (int) $billing->actual_duration_hours;
                            $liveBase         = (float) $billing->base_price;
                            $liveExtra        = (float) $billing->extra_price;
                        } else {
                            $liveElapsedHours = max(1, (int) floor($billing->elapsed_seconds / 3600));
                            if (!$pkg) {
                                $liveBase  = $liveElapsedHours * (float)($pricing?->price_per_hour ?? 0);
                                $liveExtra = 0;
                            } elseif ($pkg->isNormal()) {
                                $liveBase = (float) $pkg->price;
                                // Gunakan scheduled_end_at (bukan elapsed) agar extra langsung muncul setelah perpanjangan
                                if ($billing->scheduled_end_at) {
                                    $plannedHrs = (int) $billing->started_at->diffInHours($billing->scheduled_end_at);
                                    $extraHrs   = max(0, $plannedHrs - (int) $pkg->duration_hours);
                                } else {
                                    $extraHrs = max(0, $liveElapsedHours - (int) $pkg->duration_hours);
                                }
                                $liveExtra = $extraHrs * (float)($pricing?->price_per_hour ?? 0);
                            } else {
                                // Loss: actual elapsed
                                $liveBase  = $liveElapsedHours * (float)($pricing?->price_per_hour ?? 0);
                                $liveExtra = 0;
                            }
                        }
                    @endphp

                    {{-- Label harga dasar --}}
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">
                            @if(!$pkg)
                                Tarif per Jam
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle ms-1">{{ $liveElapsedHours }} jam</span>
                            @elseif($pkg->isNormal())
                                Paket {{ $pkg->name }}
                                <span class="badge bg-info-subtle text-info border border-info-subtle ms-1">{{ (int)$pkg->duration_hours }} jam</span>
                            @else
                                Tarif Loss per Jam
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle ms-1">{{ $liveElapsedHours }} jam</span>
                            @endif
                        </span>
                        <span class="fw-medium">
                            Rp {{ number_format($liveBase, 0, ',', '.') }}
                        </span>
                    </div>

                    {{-- Extra Waktu (paket normal melebihi durasi) --}}
                    @if($liveExtra > 0)
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small">
                                Extra Waktu
                                @if(!$billing->isCompleted())
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle ms-1">
                                        +{{ max(0, $liveElapsedHours - (int)$pkg->duration_hours) }} jam
                                    </span>
                                @endif
                            </span>
                            <span class="fw-medium text-warning">
                                + Rp {{ number_format($liveExtra, 0, ',', '.') }}
                            </span>
                        </div>
                    @endif

                    {{-- Addon --}}
                    @if($billing->addon_total > 0)
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small">Total Addon F&B</span>
                            <span class="fw-medium text-info">
                                + Rp {{ number_format($billing->addon_total, 0, ',', '.') }}
                            </span>
                        </div>
                    @endif

                    <hr class="my-2">

                    {{-- Grand Total --}}
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold">
                            {{ $billing->isActive() ? 'Total Tagihan' : 'Total Final' }}
                        </span>
                        <span class="fw-bold fs-5 text-success">
                            {{ $billing->formatted_current_total }}
                        </span>
                    </div>

                    {{-- Durasi aktual --}}
                    @if($billing->isCompleted() && $billing->actual_duration_hours)
                        <div class="mt-2 text-muted small text-end">
                            Durasi: {{ $billing->actual_duration_hours }} jam
                        </div>
                    @endif

                    {{-- Info Pembayaran --}}
                    @if($billing->payment)
                        <hr class="my-2">

                        {{-- Header status --}}
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-semibold">Status Pembayaran</span>
                            <span class="badge {{ $billing->payment->isPaid() ? 'bg-success' : 'bg-warning text-dark' }}">
                                {{ $billing->payment->isPaid() ? 'Lunas' : 'Belum Dibayar' }}
                            </span>
                        </div>

                        @if($billing->payment->isPaid())
                            {{-- Kode Payment --}}
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small">Kode Bayar</span>
                                <span class="fw-medium font-monospace small">{{ $billing->payment->payment_code }}</span>
                            </div>

                            {{-- Metode --}}
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small">Metode</span>
                                <span>
                                    @php
                                        $methodIcon = match($billing->payment->method) {
                                            'cash'     => ['fa-money-bill-wave', 'text-success', 'Cash'],
                                            'transfer' => ['fa-building-columns', 'text-primary', 'Transfer'],
                                            'qris'     => ['fa-qrcode', 'text-info', 'QRIS'],
                                            default    => ['fa-credit-card', 'text-secondary', ucfirst($billing->payment->method)],
                                        };
                                    @endphp
                                    <span class="badge bg-light border text-dark fw-medium">
                                        <i class="fa-solid {{ $methodIcon[0] }} {{ $methodIcon[1] }} me-1"></i>
                                        {{ $methodIcon[2] }}
                                    </span>
                                </span>
                            </div>

                            {{-- Total Tagihan --}}
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small">Total Tagihan</span>
                                <span class="fw-semibold text-success">Rp {{ number_format((float)$billing->payment->amount, 0, ',', '.') }}</span>
                            </div>

                            {{-- Uang Diterima & Kembalian (hanya Cash) --}}
                            @if($billing->payment->method === 'cash')
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted small">Uang Diterima</span>
                                    <span class="fw-medium">Rp {{ number_format((float)$billing->payment->amount_paid, 0, ',', '.') }}</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted small">Kembalian</span>
                                    <span class="fw-bold text-warning">Rp {{ number_format((float)$billing->payment->change_amount, 0, ',', '.') }}</span>
                                </div>
                            @endif

                            {{-- Waktu bayar --}}
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small">Dibayar Pada</span>
                                <span class="fw-medium small">{{ $billing->payment->paid_at?->format('d M Y, H:i') ?? '-' }}</span>
                            </div>

                            {{-- Diproses oleh --}}
                            @if($billing->payment->processedBy)
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted small">Diproses oleh</span>
                                    <span class="fw-medium small">{{ $billing->payment->processedBy->name }}</span>
                                </div>
                            @endif
                        @endif
                    @endif
                </div>
            </div>

            {{-- ── CARD: OPERATOR ────────────────────────────── --}}
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="fa-solid fa-user-shield me-2 text-secondary"></i>
                        Operator
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="text-muted small mb-1">
                            <i class="fa-solid fa-play me-1 text-success"></i>Dibuka oleh
                        </div>
                        <div class="fw-semibold">{{ $billing->startedBy?->name ?? '-' }}</div>
                        <div class="small text-muted">{{ $billing->started_at->format('d M Y, H:i') }}</div>
                    </div>
                    @if($billing->isCompleted() && $billing->endedBy)
                        <div>
                            <div class="text-muted small mb-1">
                                <i class="fa-solid fa-stop me-1 text-danger"></i>Diselesaikan oleh
                            </div>
                            <div class="fw-semibold">{{ $billing->endedBy->name }}</div>
                            <div class="small text-muted">
                                {{ $billing->ended_at?->format('d M Y, H:i') ?? '-' }}
                            </div>
                        </div>
                    @else
                        <div class="text-muted small fst-italic">
                            <i class="fa-solid fa-spinner fa-spin me-1"></i>Masih berlangsung...
                        </div>
                    @endif
                </div>
            </div>

        </div>
        {{-- /col-lg-4 --}}

    </div>
    {{-- /row --}}

    {{-- ── TOMBOL KEMBALI ───────────────────────────────────── --}}
    <div class="mt-3">
        <a href="{{ auth()->user()->hasRole('owner') ? route('owner.billing.index') : route('kasir.billing.index') }}"
            class="btn btn-outline-secondary btn-sm" wire:navigate>
            <i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Daftar Billing
        </a>
    </div>


    {{-- ══════════════════════════════════════════════════════════ --}}
    {{-- MODAL: TAMBAH ADDON                                       --}}
    {{-- ══════════════════════════════════════════════════════════ --}}
    @if($showAddonModal)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.6);">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header bg-light border-bottom">
                        <h5 class="modal-title fw-semibold">
                            <i class="fa-solid fa-boxes-stacked me-2 text-info"></i>
                            Katalog Addon F&B
                        </h5>
                        <button type="button" class="btn-close" @click="$wire.set('showAddonModal', false)"></button>
                    </div>
                    <div class="modal-body p-4">

                        {{-- Banner Walk-In --}}
                        @if($this->isWalkIn)
                            <div class="alert border-0 bg-warning-subtle py-2 mb-3 d-flex align-items-center gap-2">
                                <i class="fa-solid fa-person-walking text-warning flex-shrink-0"></i>
                                <div class="small text-warning-emphasis">
                                    Billing Walk-In — <strong>{{ $billing->guest_name }}</strong>.
                                    Addon akan langsung dikonfirmasi ke tagihan.
                                </div>
                            </div>
                        @else
                            <div class="alert border-0 bg-info-subtle py-2 mb-3 d-flex align-items-center gap-2">
                                <i class="fa-solid fa-id-card text-info flex-shrink-0"></i>
                                <div class="small text-info-emphasis">
                                    Member — <strong>{{ $billing->customer?->name ?? '-' }}</strong>.
                                    Addon langsung dikonfirmasi oleh kasir/owner.
                                </div>
                            </div>
                        @endif

                        <p class="text-muted small text-center mb-3">
                            Klik produk untuk langsung menambahkan ke tagihan.
                        </p>

                        <div style="max-height:450px;overflow-y:auto;overflow-x:hidden;" class="pe-1">
                            @php $groupedAddons = $this->availableAddons->groupBy('category'); @endphp

                            @forelse($groupedAddons as $cat => $catItems)
                                <div class="mb-4">
                                    <div class="text-muted small fw-semibold text-uppercase mb-2 border-bottom pb-1">
                                        <i class="fa-solid fa-tag me-1"></i>{{ $cat }}
                                    </div>
                                    <div class="row g-2">
                                        @foreach($catItems as $ad)
                                            <div class="col-6 col-md-4 col-lg-3">
                                                <div class="card h-100 border-0 shadow-sm text-center user-select-none position-relative"
                                                    style="cursor:pointer;transition:transform .15s,box-shadow .15s;"
                                                    onmouseover="this.style.transform='scale(1.04)';this.style.boxShadow='0 4px 16px rgba(0,0,0,.12)';"
                                                    onmouseout="this.style.transform='scale(1)';this.style.boxShadow='';"
                                                    wire:click="addAddon({{ $ad->id }})"
                                                    wire:loading.class="opacity-50"
                                                    wire:target="addAddon({{ $ad->id }})">

                                                    {{-- Gambar --}}
                                                    <div class="ratio ratio-1x1 bg-light border-bottom" style="border-radius:inherit;">
                                                        <div style="background-image:url('{{ $ad->image_url }}');background-size:cover;background-position:center;border-radius:inherit;"></div>
                                                    </div>

                                                    {{-- Info --}}
                                                    <div class="card-body p-2">
                                                        <div class="fw-semibold text-truncate mb-1"
                                                            style="font-size:.82rem;"
                                                            title="{{ $ad->name }}">
                                                            {{ $ad->name }}
                                                        </div>
                                                        <div class="text-success fw-bold" style="font-size:.85rem;">
                                                            {{ $ad->formatted_price }}
                                                        </div>
                                                    </div>

                                                    {{-- Badge + ikon --}}
                                                    <div class="position-absolute d-flex align-items-center justify-content-center bg-white shadow-sm border border-primary rounded-circle"
                                                        style="top:5px;right:5px;width:24px;height:24px;">
                                                        <i class="fa-solid fa-plus text-primary" style="font-size:10px;"></i>
                                                    </div>

                                                    {{-- Loading overlay --}}
                                                    <div wire:loading wire:target="addAddon({{ $ad->id }})"
                                                        class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center bg-white bg-opacity-75"
                                                        style="border-radius:inherit;">
                                                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @empty
                                <div class="text-center text-muted py-5">
                                    <i class="fa-solid fa-box-open fa-2x mb-2 d-block opacity-50"></i>
                                    <p class="small mb-0">Tidak ada addon aktif.</p>
                                </div>
                            @endforelse
                        </div>
                    </div>

                    <div class="modal-footer bg-light border-top">
                        <div class="me-auto small text-muted">
                            Total Addon saat ini:
                            <strong class="text-success">
                                Rp {{ number_format($billing->addon_total, 0, ',', '.') }}
                            </strong>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm"
                            @click="$wire.set('showAddonModal', false)">
                            <i class="fa-solid fa-xmark me-1"></i> Tutup
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif


    {{-- ══════════════════════════════════════════════════════════ --}}
    {{-- MODAL: PERPANJANG WAKTU                                   --}}
    {{-- ══════════════════════════════════════════════════════════ --}}
    @if($showExtendModal)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);">
            <div class="modal-dialog modal-dialog-centered" style="max-width:360px;">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header border-bottom">
                        <h5 class="modal-title fw-semibold">
                            <i class="fa-solid fa-clock-rotate-left me-2 text-primary"></i>
                            Perpanjang Waktu
                        </h5>
                        <button type="button" class="btn-close" @click="$wire.set('showExtendModal', false)"></button>
                    </div>

                    <div class="modal-body py-4 px-4">
                        <label class="form-label text-muted small fw-semibold mb-2">
                            Tambahan Durasi (Jam)
                        </label>
                        {{-- Tombol pilih jam --}}
                        <div class="d-flex gap-2 flex-wrap mb-2">
                            @foreach([1,2,3,4,5] as $h)
                                <button type="button"
                                    class="btn flex-fill {{ (int)$extendHours === $h ? 'btn-primary' : 'btn-outline-secondary' }}"
                                    wire:click="$set('extendHours', {{ $h }})">
                                    {{ $h }} Jam
                                </button>
                            @endforeach
                        </div>
                        <div class="input-group mb-1">
                            <button class="btn btn-outline-secondary" type="button"
                                @click="$wire.set('extendHours', Math.max(1, {{ (int)$extendHours }} - 1))">
                                <i class="fa-solid fa-minus"></i>
                            </button>
                            <input type="number" class="form-control text-center fw-bold fs-4"
                                wire:model.live="extendHours" step="1" min="1" readonly>
                            <button class="btn btn-outline-secondary" type="button"
                                @click="$wire.set('extendHours', {{ (int)$extendHours }} + 1)">
                                <i class="fa-solid fa-plus"></i>
                            </button>
                        </div>
                        @error('extendHours')
                            <div class="text-danger small mb-2">{{ $message }}</div>
                        @enderror

                        @if($billing->scheduled_end_at)
                            @php
                                $pkg2     = $billing->package;
                                $pricing2 = $billing->pricing;
                                $extraCost = (int)$extendHours * (float)($pricing2?->price_per_hour ?? 0);
                            @endphp
                            <div class="text-center bg-light rounded-3 p-3 mt-3 border">
                                <div class="text-muted small mb-1">Batas Waktu Baru</div>
                                <div class="fw-bold fs-2 text-primary lh-1">
                                    {{ $billing->scheduled_end_at->copy()->addHours((int)$extendHours)->format('H:i') }}
                                </div>
                                <div class="text-muted small mt-1">
                                    {{ $billing->scheduled_end_at->copy()->addHours((int)$extendHours)->format('d M Y') }}
                                </div>
                                @if($extraCost > 0)
                                    <div class="mt-2 small fw-semibold text-warning">
                                        <i class="fa-solid fa-plus me-1"></i>
                                        Biaya extra: Rp {{ number_format($extraCost, 0, ',', '.') }}
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="modal-footer border-0 pt-0 px-4 pb-4">
                        <button class="btn btn-light flex-fill"
                            @click="$wire.set('showExtendModal', false)">
                            Batal
                        </button>
                        <button class="btn btn-primary flex-fill fw-semibold"
                            wire:click="extendBilling"
                            wire:loading.attr="disabled"
                            wire:target="extendBilling">
                            <span wire:loading.remove wire:target="extendBilling">
                                <i class="fa-solid fa-check me-1"></i> Terapkan
                            </span>
                            <span wire:loading wire:target="extendBilling">
                                <span class="spinner-border spinner-border-sm me-1"></span> Memproses...
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif


    {{-- ══════════════════════════════════════════════════════════ --}}
    {{-- MODAL: KONFIRMASI SELESAIKAN PERMAINAN                    --}}
    {{-- ══════════════════════════════════════════════════════════ --}}
    @if($showFinishModal)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.55);">
            <div class="modal-dialog modal-dialog-centered" style="max-width:480px;">
                <div class="modal-content border-0 shadow-lg">

                    {{-- Header --}}
                    <div class="modal-header border-0 pb-0">
                        <h5 class="modal-title text-danger fw-semibold">
                            <i class="fa-solid fa-cash-register me-2"></i>
                            Selesaikan & Proses Pembayaran
                        </h5>
                        <button type="button" class="btn-close"
                            @click="$wire.set('showFinishModal', false)"></button>
                    </div>

                    <div class="modal-body pt-2 px-4">

                        {{-- Ringkasan tagihan --}}
                        <div class="p-3 rounded-3 bg-success bg-opacity-10 border border-success-subtle text-center mb-4">
                            <div class="text-muted small mb-1">Total Tagihan Final</div>
                            <div class="fw-bold fs-2 text-success lh-1">
                                {{ $billing->formatted_current_total }}
                            </div>
                            @if($billing->elapsed_formatted)
                                <div class="text-muted small mt-2">
                                    Durasi: <strong>{{ $billing->elapsed_formatted }}</strong>
                                </div>
                            @endif
                        </div>

                        {{-- Pilih Metode Pembayaran --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-muted mb-2">
                                <i class="fa-solid fa-wallet me-1"></i> METODE PEMBAYARAN
                            </label>
                            <div class="d-flex gap-2">

                                {{-- Cash --}}
                                <label class="flex-fill" style="cursor:pointer;">
                                    <input type="radio" wire:model.live="paymentMethod" value="cash" class="d-none">
                                    <div class="border rounded-3 text-center py-2 px-1 h-100 d-flex flex-column align-items-center justify-content-center gap-1
                                        {{ $paymentMethod === 'cash' ? 'border-success bg-success-subtle' : 'bg-light' }}"
                                        style="transition:all .15s;">
                                        <i class="fa-solid fa-money-bill-wave fs-5 {{ $paymentMethod === 'cash' ? 'text-success' : 'text-muted' }}"></i>
                                        <div class="small fw-semibold {{ $paymentMethod === 'cash' ? 'text-success' : 'text-muted' }}">Cash</div>
                                    </div>
                                </label>

                                {{-- Transfer --}}
                                <label class="flex-fill" style="cursor:pointer;">
                                    <input type="radio" wire:model.live="paymentMethod" value="transfer" class="d-none">
                                    <div class="border rounded-3 text-center py-2 px-1 h-100 d-flex flex-column align-items-center justify-content-center gap-1
                                        {{ $paymentMethod === 'transfer' ? 'border-primary bg-primary-subtle' : 'bg-light' }}"
                                        style="transition:all .15s;">
                                        <i class="fa-solid fa-building-columns fs-5 {{ $paymentMethod === 'transfer' ? 'text-primary' : 'text-muted' }}"></i>
                                        <div class="small fw-semibold {{ $paymentMethod === 'transfer' ? 'text-primary' : 'text-muted' }}">Transfer</div>
                                    </div>
                                </label>

                                {{-- QRIS --}}
                                <label class="flex-fill" style="cursor:pointer;">
                                    <input type="radio" wire:model.live="paymentMethod" value="qris" class="d-none">
                                    <div class="border rounded-3 text-center py-2 px-1 h-100 d-flex flex-column align-items-center justify-content-center gap-1
                                        {{ $paymentMethod === 'qris' ? 'border-info bg-info-subtle' : 'bg-light' }}"
                                        style="transition:all .15s;">
                                        <i class="fa-solid fa-qrcode fs-5 {{ $paymentMethod === 'qris' ? 'text-info' : 'text-muted' }}"></i>
                                        <div class="small fw-semibold {{ $paymentMethod === 'qris' ? 'text-info' : 'text-muted' }}">QRIS</div>
                                    </div>
                                </label>

                            </div>
                            @error('paymentMethod')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Input Nominal (hanya Cash) --}}
                        @if($paymentMethod === 'cash')
                            <div class="mb-3">
                                <label class="form-label fw-semibold small text-muted mb-1">
                                    <i class="fa-solid fa-hand-holding-dollar me-1"></i> UANG DITERIMA
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text fw-semibold">Rp</span>
                                    <input type="number"
                                        wire:model.live="amountPaid"
                                        class="form-control form-control-lg fw-bold text-end {{ $errors->has('amountPaid') ? 'is-invalid' : '' }}"
                                        placeholder="0"
                                        min="0"
                                        step="1000"
                                        id="amountPaidInput">
                                </div>
                                @error('amountPaid')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror

                                {{-- Tombol cepat nominal --}}
                                @php
                                    $total = (int) $billing->current_total;
                                    $quickAmounts = [
                                        $total,
                                        (int)(ceil($total / 10000) * 10000),
                                        (int)(ceil($total / 50000) * 50000),
                                        (int)(ceil($total / 100000) * 100000),
                                    ];
                                    $quickAmounts = array_unique(array_filter($quickAmounts, fn($v) => $v >= $total));
                                    sort($quickAmounts);
                                    $quickAmounts = array_slice($quickAmounts, 0, 4);
                                @endphp
                                <div class="d-flex gap-1 flex-wrap mt-2">
                                    @foreach($quickAmounts as $qa)
                                        <button type="button"
                                            class="btn btn-sm {{ (int)$amountPaid === $qa ? 'btn-success' : 'btn-outline-secondary' }}"
                                            wire:click="$set('amountPaid', {{ $qa }})">
                                            Rp {{ number_format($qa, 0, ',', '.') }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Kembalian --}}
                            <div class="p-3 rounded-3 {{ $this->isAmountSufficient ? 'bg-warning bg-opacity-10 border border-warning-subtle' : 'bg-danger bg-opacity-10 border border-danger-subtle' }} text-center">
                                @if($this->isAmountSufficient)
                                    <div class="text-muted small mb-1">Kembalian</div>
                                    <div class="fw-bold fs-3 text-warning">
                                        Rp {{ number_format($this->changeAmount, 0, ',', '.') }}
                                    </div>
                                @else
                                    <div class="text-danger small fw-semibold">
                                        <i class="fa-solid fa-triangle-exclamation me-1"></i>
                                        Uang kurang
                                        Rp {{ number_format($billing->current_total - $amountPaid, 0, ',', '.') }}
                                    </div>
                                @endif
                            </div>
                        @else
                            {{-- Transfer/QRIS: langsung lunas --}}
                            <div class="p-3 rounded-3 bg-primary bg-opacity-10 border border-primary-subtle text-center">
                                <i class="fa-solid fa-circle-check text-primary fs-4 mb-1 d-block"></i>
                                <div class="text-primary small fw-semibold">
                                    {{ $paymentMethod === 'qris' ? 'QRIS' : 'Transfer' }} — Pembayaran dianggap lunas otomatis.
                                </div>
                            </div>
                        @endif

                    </div>

                    <div class="modal-footer border-0 px-4 pb-4 gap-2 mt-2">
                        <button class="btn btn-light flex-fill"
                            @click="$wire.set('showFinishModal', false)">
                            Batal
                        </button>
                        <button class="btn btn-danger flex-fill fw-semibold"
                            wire:click="finishBilling"
                            wire:loading.attr="disabled"
                            wire:target="finishBilling"
                            {{ ($paymentMethod === 'cash' && !$this->isAmountSufficient && $amountPaid > 0) ? 'disabled' : '' }}>
                            <span wire:loading.remove wire:target="finishBilling">
                                <i class="fa-solid fa-check me-1"></i> Selesaikan & Bayar
                            </span>
                            <span wire:loading wire:target="finishBilling">
                                <span class="spinner-border spinner-border-sm me-1"></span> Memproses...
                            </span>
                        </button>
                    </div>

                </div>
            </div>
        </div>
    @endif


</div>

{{-- ── ELAPSED TIMER (per-detik, client-side) ────────────────── --}}
@if($billing->isActive())
<script>
(function () {
    var el = document.getElementById('elapsed-timer');
    if (!el) return;

    var seconds = parseInt(el.getAttribute('data-elapsed-seconds') || '0', 10);
    var capped   = el.getAttribute('data-capped') === '1';

    function pad(n) { return String(n).padStart(2, '0'); }
    function fmt(s) {
        return pad(Math.floor(s / 3600)) + ':' + pad(Math.floor((s % 3600) / 60)) + ':' + pad(s % 60);
    }

    // Jika waktu sudah dikunci (melewati scheduled_end_at), jangan tambah detik
    if (!capped) {
        var timer = setInterval(function () {
            seconds++;
            if (el) {
                el.textContent = fmt(seconds);
            } else {
                clearInterval(timer);
            }
        }, 1000);

        // Bersihkan interval saat Livewire merender ulang komponen
        document.addEventListener('livewire:navigating', function () { clearInterval(timer); });
        Livewire.hook('commit', function ({ component, commit, respond, succeed, fail }) {
            succeed(function ({ snapshot, effect }) {
                clearInterval(timer);
                // Re-sync seconds dari server setelah Livewire update
                el = document.getElementById('elapsed-timer');
                if (!el) return;
                seconds = parseInt(el.getAttribute('data-elapsed-seconds') || seconds, 10);
                capped  = el.getAttribute('data-capped') === '1';
                if (!capped) {
                    timer = setInterval(function () {
                        seconds++;
                        if (el) el.textContent = fmt(seconds);
                        else clearInterval(timer);
                    }, 1000);
                } else {
                    if (el) el.textContent = fmt(seconds);
                }
            });
        });
    }
})();
</script>
@endif
