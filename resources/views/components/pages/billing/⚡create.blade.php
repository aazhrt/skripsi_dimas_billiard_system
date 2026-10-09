<?php

use App\Models\Addon;
use App\Models\Billing;
use App\Models\BillingAddon;
use App\Models\Package;
use App\Models\Pricing;
use App\Models\Table;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Tambah Billing Walk-In', 'breadcrumbs' => [
    ['title' => 'Monitoring'],
    ['title' => 'Billing', 'url' => null],
    ['title' => 'Tambah Walk-In', 'url' => '#'],
]])] class extends Component {

    // ── Step wizard ──────────────────────────────────────────
    public int $step = 1; // 1 = Pelanggan & Meja, 2 = Paket & Addon, 3 = Konfirmasi

    // ── Step 1 ───────────────────────────────────────────────
    public string $guest_name = '';
    public string $table_id   = '';
    public string $notes      = '';

    // ── Step 2 ───────────────────────────────────────────────
    public string $package_id = '';
    public string $pricing_id = '';

    // ── Preview kalkulasi harga (reaktif) ────────────────────
    public ?float  $previewPrice    = null;
    public ?string $previewType     = null;   // 'normal' | 'loss' | 'per_jam'
    public ?float  $previewDuration = null;

    // ── Addon sementara (sebelum billing dibuat) ─────────────
    public array $selectedAddons = [];  // [addon_id => qty]

    // ── COMPUTED ─────────────────────────────────────────────

    #[Computed]
    public function availableTables()
    {
        return Table::where('is_active', true)
            ->where('status', 'available')
            ->orderBy('table_number')
            ->get();
    }

    #[Computed]
    public function packages()
    {
        return Package::where('is_active', true)
            ->with('pricing')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function pricings()
    {
        return Pricing::where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function addons()
    {
        return Addon::where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selectedTable()
    {
        if (!$this->table_id) return null;
        return Table::find($this->table_id);
    }

    #[Computed]
    public function selectedPackage()
    {
        if (!$this->package_id) return null;
        return Package::with('pricing')->find($this->package_id);
    }

    #[Computed]
    public function selectedPricing()
    {
        if (!$this->pricing_id) return null;
        return Pricing::find($this->pricing_id);
    }

    #[Computed]
    public function addonSummary(): array
    {
        $result = [];
        $addons = $this->addons;
        foreach ($this->selectedAddons as $addonId => $qty) {
            if ($qty < 1) continue;
            $addon = $addons->firstWhere('id', (int) $addonId);
            if (!$addon) continue;
            $result[] = [
                'id'       => $addon->id,
                'name'     => $addon->name,
                'category' => $addon->category,
                'qty'      => $qty,
                'price'    => (float) $addon->price,
                'subtotal' => (float) $addon->price * $qty,
            ];
        }
        return $result;
    }

    #[Computed]
    public function addonTotal(): float
    {
        return array_sum(array_column($this->addonSummary, 'subtotal'));
    }

    // ── STEP NAVIGATION ──────────────────────────────────────

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'guest_name' => 'required|string|min:2|max:100',
                'table_id'   => 'required|exists:tables,id',
            ], [
                'guest_name.required' => 'Nama pelanggan harus diisi.',
                'guest_name.min'      => 'Nama minimal 2 karakter.',
                'table_id.required'   => 'Pilih meja terlebih dahulu.',
                'table_id.exists'     => 'Meja tidak ditemukan.',
            ]);
        }

        if ($this->step === 2) {
            if (empty($this->package_id) && empty($this->pricing_id)) {
                $this->addError('package_id', 'Pilih paket atau tarif per jam terlebih dahulu.');
                return;
            }
            $this->updatePreview();
        }

        if ($this->step < 3) $this->step++;
    }

    public function prevStep(): void
    {
        if ($this->step > 1) $this->step--;
    }

    // ── REACTIVE PREVIEW ─────────────────────────────────────

    public function updatedPackageId(): void
    {
        if (!empty($this->package_id)) $this->pricing_id = '';
        $this->updatePreview();
    }

    public function updatedPricingId(): void
    {
        if (!empty($this->pricing_id)) $this->package_id = '';
        $this->updatePreview();
    }

    private function updatePreview(): void
    {
        $this->resetErrorBag(['package_id', 'pricing_id']);

        if (in_array($this->package_id, ['test_10s', 'test_1', 'test_5', 'test_10'])) {
            $pkg1h = Package::where('type', 'normal')->where('duration_hours', 1)->first();
            $this->previewType     = 'test';
            $this->previewPrice    = $pkg1h ? (float) $pkg1h->price : 0;
            if ($this->package_id === 'test_10s') {
                $this->previewDuration = 10 / 3600; // 10 seconds in hours
            } else {
                $this->previewDuration = (float) (str_replace('test_', '', $this->package_id) / 60); 
            }
            return;
        }

        if (!empty($this->package_id)) {
            $pkg = Package::with('pricing')->find($this->package_id);
            if (!$pkg) return;
            if ($pkg->isNormal()) {
                $this->previewType     = 'normal';
                $this->previewPrice    = (float) $pkg->price;
                $this->previewDuration = (float) $pkg->duration_hours;
            } else {
                $this->previewType     = 'loss';
                $this->previewPrice    = $pkg->pricing ? (float) $pkg->pricing->price_per_hour : null;
                $this->previewDuration = null;
            }
        } elseif (!empty($this->pricing_id)) {
            $pricing = Pricing::find($this->pricing_id);
            if (!$pricing) return;
            $this->previewType     = 'per_jam';
            $this->previewPrice    = (float) $pricing->price_per_hour;
            $this->previewDuration = null;
        } else {
            $this->previewType = $this->previewPrice = $this->previewDuration = null;
        }
    }

    // ── ADDON MANAGEMENT ─────────────────────────────────────

    public function incrementAddon(int $addonId): void
    {
        $this->selectedAddons[$addonId] = ($this->selectedAddons[$addonId] ?? 0) + 1;
    }

    public function decrementAddon(int $addonId): void
    {
        $current = $this->selectedAddons[$addonId] ?? 0;
        if ($current <= 1) {
            unset($this->selectedAddons[$addonId]);
        } else {
            $this->selectedAddons[$addonId] = $current - 1;
        }
    }

    public function removeAddon(int $addonId): void
    {
        unset($this->selectedAddons[$addonId]);
    }

    // ── SIMPAN BILLING ────────────────────────────────────────

    public function save(?\App\Services\BillingSessionManager $sessionManager = null): void
    {
        $sessionManager = $sessionManager ?? app(\App\Services\BillingSessionManager::class);
        $this->validate([
            'guest_name' => 'required|string|min:2|max:100',
            'table_id'   => 'required|exists:tables,id',
        ], [
            'guest_name.required' => 'Nama pelanggan harus diisi.',
            'table_id.required'   => 'Meja harus dipilih.',
        ]);

        if (empty($this->package_id) && empty($this->pricing_id)) {
            $this->addError('package_id', 'Paket atau tarif harus dipilih.');
            $this->step = 2;
            return;
        }

        try {
            $sessionManager->start([
                'guest_name'     => trim($this->guest_name),
                'table_id'       => $this->table_id,
                'package_id'     => $this->package_id ?: null,
                'pricing_id'     => $this->pricing_id ?: null,
                'notes'          => trim($this->notes) ?: null,
                'selectedAddons' => $this->addonSummary,
            ]);

            $this->dispatch('notify', message: 'Billing walk-in berhasil dibuat! Permainan dimulai.', type: 'success');

            $this->redirectRoute(
                auth()->user()->hasRole('owner') ? 'owner.billing.index' : 'kasir.billing.index',
                navigate: true
            );
        } catch (\App\Exceptions\DomainException $e) {
            $this->addError('table_id', $e->getMessage());
            $this->step = 1;
        }
    }
};
?>

<div>
    {{-- ── PROGRESS STEPS ───────────────────────────────────── --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-body py-3 px-4">
                    <div class="d-flex align-items-center justify-content-between">

                        {{-- Step 1 --}}
                        <div class="d-flex align-items-center gap-2 flex-shrink-0">
                            <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold flex-shrink-0"
                                style="width:36px;height:36px;font-size:14px;
                                       background:{{ $step >= 1 ? '#0d6efd' : '#e9ecef' }};
                                       color:{{ $step >= 1 ? '#fff' : '#6c757d' }};">
                                @if($step > 1)
                                    <i class="fa-solid fa-check" style="font-size:13px;"></i>
                                @else
                                    1
                                @endif
                            </div>
                            <span class="fw-semibold d-none d-sm-inline
                                {{ $step === 1 ? 'text-primary' : ($step > 1 ? 'text-success' : 'text-muted') }}">
                                Pelanggan &amp; Meja
                            </span>
                        </div>

                        <div class="flex-grow-1 mx-2"
                            style="height:2px;background:{{ $step >= 2 ? '#0d6efd' : '#dee2e6' }};border-radius:2px;"></div>

                        {{-- Step 2 --}}
                        <div class="d-flex align-items-center gap-2 flex-shrink-0">
                            <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold flex-shrink-0"
                                style="width:36px;height:36px;font-size:14px;
                                       background:{{ $step >= 2 ? '#0d6efd' : '#e9ecef' }};
                                       color:{{ $step >= 2 ? '#fff' : '#6c757d' }};">
                                @if($step > 2)
                                    <i class="fa-solid fa-check" style="font-size:13px;"></i>
                                @else
                                    2
                                @endif
                            </div>
                            <span class="fw-semibold d-none d-sm-inline
                                {{ $step === 2 ? 'text-primary' : ($step > 2 ? 'text-success' : 'text-muted') }}">
                                Paket &amp; Addon
                            </span>
                        </div>

                        <div class="flex-grow-1 mx-2"
                            style="height:2px;background:{{ $step >= 3 ? '#0d6efd' : '#dee2e6' }};border-radius:2px;"></div>

                        {{-- Step 3 --}}
                        <div class="d-flex align-items-center gap-2 flex-shrink-0">
                            <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold flex-shrink-0"
                                style="width:36px;height:36px;font-size:14px;
                                       background:{{ $step >= 3 ? '#0d6efd' : '#e9ecef' }};
                                       color:{{ $step >= 3 ? '#fff' : '#6c757d' }};">
                                3
                            </div>
                            <span class="fw-semibold d-none d-sm-inline {{ $step === 3 ? 'text-primary' : 'text-muted' }}">
                                Konfirmasi
                            </span>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>


    {{-- ════════════════════════════════════════════════════════ --}}
    {{-- STEP 1 — Pelanggan & Pilih Meja                        --}}
    {{-- ════════════════════════════════════════════════════════ --}}
    @if($step === 1)
    <div class="row g-4">

        {{-- Form Pelanggan --}}
        <div class="col-lg-5">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="fa-solid fa-user-circle me-2 text-primary"></i>
                        Data Pelanggan Walk-In
                    </h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info d-flex align-items-start gap-2 mb-4 py-2 border-0 bg-info-subtle">
                        <i class="fa-solid fa-circle-info mt-1 flex-shrink-0 text-info"></i>
                        <div class="small text-info-emphasis">
                            Billing ini untuk <strong>pelanggan tanpa akun</strong> (walk-in).
                            Cukup isi nama sebagai identifikasi sesi bermain.
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-medium">
                            Nama Pelanggan <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fa-solid fa-user text-muted"></i>
                            </span>
                            <input type="text"
                                wire:model.live="guest_name"
                                class="form-control border-start-0 @error('guest_name') is-invalid @enderror"
                                placeholder="Contoh: Budi, Pak Andi, Tamu 1..."
                                maxlength="100"
                                autofocus>
                            @error('guest_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-text">Nama ini hanya untuk identifikasi sesi billing.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">
                            Catatan <span class="text-muted small fw-normal">(opsional)</span>
                        </label>
                        <textarea wire:model.live="notes"
                            class="form-control"
                            rows="3"
                            placeholder="Catatan khusus, permintaan, dll..."></textarea>
                    </div>

                    {{-- Preview identitas pelanggan --}}
                    @if(strlen(trim($guest_name)) >= 2)
                        <div class="p-3 rounded-3 bg-light border mt-4">
                            <div class="d-flex align-items-center gap-3">
                                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold flex-shrink-0"
                                    style="width:44px;height:44px;font-size:18px;">
                                    {{ strtoupper(substr(trim($guest_name), 0, 1)) }}
                                </div>
                                <div>
                                    <div class="fw-semibold fs-6">{{ trim($guest_name) }}</div>
                                    <div class="small text-muted">
                                        <i class="fa-solid fa-person-walking me-1"></i>Walk-In (Tanpa Akun)
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Pilih Meja --}}
        <div class="col-lg-7">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-semibold">
                        <i class="fa-solid fa-table me-2 text-success"></i>
                        Pilih Meja
                    </h5>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3">
                        <i class="fa-solid fa-circle-check me-1"></i>
                        {{ $this->availableTables->count() }} Tersedia
                    </span>
                </div>
                <div class="card-body">
                    @error('table_id')
                        <div class="alert alert-danger py-2 mb-3 border-0">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i> {{ $message }}
                        </div>
                    @enderror

                    @if($this->availableTables->isEmpty())
                        <div class="text-center py-5 text-muted">
                            <i class="fa-solid fa-circle-exclamation fa-3x mb-3 text-warning d-block"></i>
                            <p class="fw-semibold mb-1">Tidak ada meja yang tersedia</p>
                            <p class="small mb-0">Semua meja sedang terpakai atau dalam perawatan.</p>
                        </div>
                    @else
                        <div class="row g-3">
                            @foreach($this->availableTables as $tbl)
                                <div class="col-6 col-md-4">
                                    <div wire:click="$set('table_id', '{{ $tbl->id }}')"
                                        class="card mb-0 text-center border-2 h-100"
                                        style="cursor:pointer;transition:all .15s;
                                            border-color:{{ $table_id == $tbl->id ? '#0d6efd' : '#dee2e6' }} !important;
                                            background:{{ $table_id == $tbl->id ? '#eef3ff' : '#fff' }};">
                                        <div class="card-body py-3 px-2">
                                            <div class="mb-2">
                                                <i class="fa-solid fa-table fa-2x"
                                                   style="color:{{ $table_id == $tbl->id ? '#0d6efd' : '#adb5bd' }};"></i>
                                            </div>
                                            <div class="fw-bold"
                                                style="color:{{ $table_id == $tbl->id ? '#0d6efd' : '#212529' }};">
                                                {{ $tbl->name }}
                                            </div>
                                            <div class="small text-muted">{{ $tbl->table_number }}</div>
                                            <div class="mt-2">
                                                @if($table_id == $tbl->id)
                                                    <span class="badge bg-primary">
                                                        <i class="fa-solid fa-check me-1"></i>Dipilih
                                                    </span>
                                                @else
                                                    <span class="badge bg-success">Tersedia</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

    </div>

    {{-- Tombol Step 1 --}}
    <div class="d-flex justify-content-between align-items-center mt-4">
        <a href="{{ auth()->user()->hasRole('owner') ? route('owner.billing.index') : route('kasir.billing.index') }}"
           class="btn btn-outline-secondary" wire:navigate>
            <i class="fa-solid fa-arrow-left me-1"></i> Batal
        </a>
        <button wire:click="nextStep" class="btn btn-primary px-4"
            wire:loading.attr="disabled" wire:target="nextStep">
            <span wire:loading.remove wire:target="nextStep">
                Lanjut <i class="fa-solid fa-arrow-right ms-1"></i>
            </span>
            <span wire:loading wire:target="nextStep">
                <span class="spinner-border spinner-border-sm me-1"></span> Memproses...
            </span>
        </button>
    </div>
    @endif


    {{-- ════════════════════════════════════════════════════════ --}}
    {{-- STEP 2 — Pilih Paket / Tarif + Addon                   --}}
    {{-- ════════════════════════════════════════════════════════ --}}
    @if($step === 2)
    <div class="row g-4">

        {{-- Kiri: Pilih Paket & Tarif --}}
        <div class="col-lg-7">

            @error('package_id')
                <div class="alert alert-danger py-2 mb-3 border-0">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> {{ $message }}
                </div>
            @enderror

            {{-- PAKET --}}
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="fa-solid fa-box-open me-2 text-primary"></i>
                        Pilih Paket
                    </h5>
                </div>
                <div class="card-body">
                    @if($this->packages->isEmpty())
                        <p class="text-muted small text-center py-3 mb-0">Tidak ada paket aktif.</p>
                    @else
                        <div class="row g-3">
                            @foreach($this->packages as $pkg)
                                <div class="col-md-6">
                                    <div wire:click="$set('package_id', '{{ $pkg->id }}')"
                                        class="card mb-0 border-2 h-100"
                                        style="cursor:pointer;transition:all .15s;
                                            border-color:{{ $package_id == $pkg->id ? '#0d6efd' : '#dee2e6' }} !important;
                                            background:{{ $package_id == $pkg->id ? '#eef3ff' : '#fff' }};">
                                        <div class="card-body py-3">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <span class="fw-semibold"
                                                    style="color:{{ $package_id == $pkg->id ? '#0d6efd' : '#212529' }};">
                                                    {{ $pkg->name }}
                                                </span>
                                                @if($package_id == $pkg->id)
                                                    <span class="badge bg-primary ms-1 flex-shrink-0">
                                                        <i class="fa-solid fa-check"></i>
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="mb-2">
                                                @if($pkg->isNormal())
                                                    <span class="badge bg-info-subtle text-info border border-info-subtle">
                                                        <i class="fa-solid fa-clock me-1"></i>{{ (float) $pkg->duration_hours }} Jam Fix
                                                    </span>
                                                @else
                                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                                                        <i class="fa-solid fa-infinity me-1"></i>Waktu Bebas (Loss)
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="fw-bold text-success fs-6">
                                                @if($pkg->isNormal())
                                                    Rp {{ number_format((float)$pkg->price, 0, ',', '.') }}
                                                @else
                                                    Rp {{ number_format((float)($pkg->pricing?->price_per_hour ?? 0), 0, ',', '.') }}/jam
                                                @endif
                                            </div>
                                            @if($pkg->description)
                                                <div class="small text-muted mt-1">{{ $pkg->description }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- PAKET TESTING (SMENTARA UNTUK PRODUCTION DIHAPUS) --}}
                    <div class="mt-4 p-3 rounded-3 border" style="border-color: #dc3545 !important; background: #fff5f5;">
                        <h6 class="text-danger fw-bold mb-3">
                            <i class="fa-solid fa-flask me-1"></i> Testing Packages (1 Jam Normal Price, +3s End Time)
                        </h6>
                        <div class="row g-3">
                            @foreach(['test_10s' => '10 Detik', 'test_1' => '1 Menit', 'test_5' => '5 Menit', 'test_10' => '10 Menit'] as $tId => $tLabel)
                                <div class="col-md-6 col-lg-3">
                                    <div wire:click="$set('package_id', '{{ $tId }}')"
                                        class="card mb-0 border-2 h-100"
                                        style="cursor:pointer;transition:all .15s;
                                            border-color:{{ $package_id == $tId ? '#dc3545' : '#dee2e6' }} !important;
                                            background:{{ $package_id == $tId ? '#ffe3e3' : '#fff' }};">
                                        <div class="card-body py-3">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <span class="fw-bold text-danger">{{ $tLabel }}</span>
                                                @if($package_id == $tId)
                                                    <span class="badge bg-danger ms-1 flex-shrink-0">
                                                        <i class="fa-solid fa-check"></i>
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="small text-muted">+3 detik end time</div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    {{-- END TESTING --}}
                </div>
            </div>

            {{-- TARIF PER JAM (TANPA PAKET) --}}
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="fa-solid fa-tags me-2 text-warning"></i>
                        Atau — Tarif Per Jam (Tanpa Paket)
                    </h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Pilih tarif ini jika pelanggan bermain tanpa paket.
                        Biaya dihitung per jam di akhir sesi.
                    </p>
                    @if($this->pricings->isEmpty())
                        <p class="text-muted small text-center py-2 mb-0">Tidak ada tarif aktif.</p>
                    @else
                        @php
                            $dayLabelsMap = [
                                'senin'  => 'Sen', 'selasa' => 'Sel', 'rabu'   => 'Rab',
                                'kamis'  => 'Kam', 'jumat'  => 'Jum', 'sabtu'  => 'Sab',
                                'minggu' => 'Min',
                            ];
                        @endphp
                        <div class="row g-3">
                            @foreach($this->pricings as $pr)
                                <div class="col-md-6">
                                    <div wire:click="$set('pricing_id', '{{ $pr->id }}')"
                                        class="card mb-0 border-2 h-100"
                                        style="cursor:pointer;transition:all .15s;
                                            border-color:{{ $pricing_id == $pr->id ? '#fd7e14' : '#dee2e6' }} !important;
                                            background:{{ $pricing_id == $pr->id ? '#fff8f0' : '#fff' }};">
                                        <div class="card-body py-3">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <span class="fw-semibold"
                                                    style="color:{{ $pricing_id == $pr->id ? '#fd7e14' : '#212529' }};">
                                                    {{ $pr->name }}
                                                </span>
                                                @if($pricing_id == $pr->id)
                                                    <span class="badge bg-warning text-dark ms-1 flex-shrink-0">
                                                        <i class="fa-solid fa-check"></i>
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="fw-bold text-success fs-6">
                                                Rp {{ number_format((float)$pr->price_per_hour, 0, ',', '.') }}/jam
                                            </div>
                                            <div class="mt-2 d-flex flex-wrap gap-1">
                                                @if(empty($pr->apply_days))
                                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Semua Hari</span>
                                                @else
                                                    @foreach($pr->apply_days as $day)
                                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                                            {{ $dayLabelsMap[$day] ?? ucfirst($day) }}
                                                        </span>
                                                    @endforeach
                                                @endif
                                                @if($pr->start_time && $pr->end_time)
                                                    <span class="badge bg-dark-subtle text-dark border border-dark-subtle">
                                                        {{ \Carbon\Carbon::parse($pr->start_time)->format('H:i') }}–{{ \Carbon\Carbon::parse($pr->end_time)->format('H:i') }}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

        </div>

        {{-- Kanan: Addon + Preview Harga --}}
        <div class="col-lg-5">

            {{-- PREVIEW HARGA --}}
            @if($previewType)
                <div class="card shadow-sm border-0 mb-4
                    {{ $previewType === 'normal' || $previewType === 'test' ? 'border-primary' : ($previewType === 'loss' ? 'border-warning' : 'border-success') }}"
                    style="border-left: 4px solid {{ $previewType === 'normal' || $previewType === 'test' ? '#0d6efd' : ($previewType === 'loss' ? '#fd7e14' : '#198754') }} !important;">
                    <div class="card-body py-3">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="fa-solid fa-calculator text-primary"></i>
                            <span class="fw-semibold">Estimasi Biaya</span>
                        </div>
                        @if($previewType === 'normal' || $previewType === 'test')
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-white small" style="{{ $previewType === 'test' ? 'color:#dc3545!important;font-weight:bold;' : '' }}">
                                    @if($previewType === 'test')
                                        TESTING {{ $package_id === 'test_10s' ? '10 Detik' : ($previewDuration * 60) . ' Menit' }} (+3s)
                                    @else
                                        Paket {{ $previewDuration }} Jam Fix
                                    @endif
                                </span>
                                <span class="fw-bold text-primary fs-5">
                                    Rp {{ number_format($previewPrice, 0, ',', '.') }}
                                </span>
                            </div>
                            <div class="text-white small mt-1">
                                <i class="fa-solid fa-clock me-1"></i>
                                Waktu berakhir pukul
                                <strong>{{ $previewType === 'test' ? ($package_id === 'test_10s' ? now()->addSeconds(13)->format('H:i:s') : now()->addMinutes($previewDuration * 60)->addSeconds(3)->format('H:i:s')) : now()->addHours((float) $previewDuration)->format('H:i') }}</strong>
                            </div>
                        @elseif($previewType === 'loss')
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-white small">Tarif Loss (per jam)</span>
                                <span class="fw-bold text-white fs-5">
                                    Rp {{ number_format($previewPrice, 0, ',', '.') }}/jam
                                </span>
                            </div>
                            <div class="text-white small mt-1">
                                <i class="fa-solid fa-infinity me-1"></i>
                                Biaya dihitung di akhir sesi
                            </div>
                        @else
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-white small">Tarif Per Jam</span>
                                <span class="fw-bold text-white fs-5">
                                    Rp {{ number_format($previewPrice, 0, ',', '.') }}/jam
                                </span>
                            </div>
                            <div class="text-white small mt-1">
                                <i class="fa-solid fa-infinity me-1"></i>
                                Biaya dihitung di akhir sesi
                            </div>
                        @endif

                        @if($this->addonTotal > 0)
                            <hr class="my-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">+ Addon Awal</span>
                                <span class="fw-semibold text-info">
                                    + Rp {{ number_format($this->addonTotal, 0, ',', '.') }}
                                </span>
                            </div>
                        @endif
                    </div>
                </div>
            @else
                <div class="card shadow-sm border-0 mb-4 border-dashed" style="border: 2px dashed #dee2e6 !important;">
                    <div class="card-body py-4 text-center text-muted">
                        <i class="fa-solid fa-calculator fa-2x mb-2 d-block text-muted opacity-50"></i>
                        <p class="small mb-0">Pilih paket atau tarif untuk melihat estimasi biaya</p>
                    </div>
                </div>
            @endif

            {{-- ADDON AWAL --}}
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-semibold">
                        <i class="fa-solid fa-shopping-basket me-2 text-info"></i>
                        Addon Awal
                    </h5>
                    <span class="badge bg-info-subtle text-info border border-info-subtle">
                        <span class="text-muted small fw-normal">opsional</span>
                    </span>
                </div>
                <div class="card-body" style="max-height:420px;overflow-y:auto;">

                    {{-- Keranjang addon yang dipilih --}}
                    @if(count($this->addonSummary) > 0)
                        <div class="mb-3">
                            <div class="small fw-semibold text-muted mb-2">
                                <i class="fa-solid fa-cart-shopping me-1"></i> Dipilih
                            </div>
                            @foreach($this->addonSummary as $item)
                                <div class="d-flex align-items-center justify-content-between mb-2 p-2 rounded bg-light border">
                                    <div class="flex-grow-1 me-2">
                                        <div class="fw-medium small">{{ $item['name'] }}</div>
                                        <div class="text-muted" style="font-size:11px;">
                                            Rp {{ number_format($item['price'], 0, ',', '.') }} x{{ $item['qty'] }}
                                            = <strong>Rp {{ number_format($item['subtotal'], 0, ',', '.') }}</strong>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-1">
                                        <button type="button" wire:click="decrementAddon({{ $item['id'] }})"
                                            class="btn btn-sm btn-outline-secondary p-0"
                                            style="width:26px;height:26px;line-height:1;">
                                            <i class="fa-solid fa-minus" style="font-size:10px;"></i>
                                        </button>
                                        <span class="fw-bold mx-1" style="min-width:18px;text-align:center;">{{ $item['qty'] }}</span>
                                        <button type="button" wire:click="incrementAddon({{ $item['id'] }})"
                                            class="btn btn-sm btn-outline-primary p-0"
                                            style="width:26px;height:26px;line-height:1;">
                                            <i class="fa-solid fa-plus" style="font-size:10px;"></i>
                                        </button>
                                        <button type="button" wire:click="removeAddon({{ $item['id'] }})"
                                            class="btn btn-sm btn-outline-danger p-0 ms-1"
                                            style="width:26px;height:26px;line-height:1;">
                                            <i class="fa-solid fa-xmark" style="font-size:10px;"></i>
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                            <div class="text-end small fw-semibold text-success">
                                Total Addon: Rp {{ number_format($this->addonTotal, 0, ',', '.') }}
                            </div>
                            <hr class="my-3">
                        </div>
                    @endif

                    {{-- Daftar addon tersedia --}}
                    @php $groupedAddons = $this->addons->groupBy('category'); @endphp
                    @foreach($groupedAddons as $category => $items)
                        <div class="mb-3">
                            <div class="small fw-semibold text-muted text-uppercase mb-2">
                                <i class="fa-solid fa-tag me-1"></i>{{ $category }}
                            </div>
                            @foreach($items as $addon)
                                @php $qty = $selectedAddons[$addon->id] ?? 0; @endphp
                                <div class="d-flex align-items-center justify-content-between mb-2 p-2 rounded border
                                    {{ $qty > 0 ? 'border-info bg-info-subtle' : 'border-light bg-white' }}">
                                    <div class="flex-grow-1 me-2">
                                        <div class="fw-medium small">{{ $addon->name }}</div>
                                        <div class="text-success" style="font-size:12px;">
                                            Rp {{ number_format((float)$addon->price, 0, ',', '.') }}
                                        </div>
                                    </div>
                                    @if($qty > 0)
                                        <div class="d-flex align-items-center gap-1">
                                            <button type="button" wire:click="decrementAddon({{ $addon->id }})"
                                                class="btn btn-sm btn-outline-secondary p-0"
                                                style="width:26px;height:26px;line-height:1;">
                                                <i class="fa-solid fa-minus" style="font-size:10px;"></i>
                                            </button>
                                            <span class="fw-bold mx-1" style="min-width:18px;text-align:center;">{{ $qty }}</span>
                                            <button type="button" wire:click="incrementAddon({{ $addon->id }})"
                                                class="btn btn-sm btn-outline-primary p-0"
                                                style="width:26px;height:26px;line-height:1;">
                                                <i class="fa-solid fa-plus" style="font-size:10px;"></i>
                                            </button>
                                        </div>
                                    @else
                                        <button type="button" wire:click="incrementAddon({{ $addon->id }})"
                                            class="btn btn-sm btn-outline-info px-2 py-1">
                                            <i class="fa-solid fa-plus me-1" style="font-size:11px;"></i>
                                            <span style="font-size:11px;">Tambah</span>
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach

                </div>
            </div>

        </div>

    </div>

    {{-- Tombol Step 2 --}}
    <div class="d-flex justify-content-between align-items-center mt-4">
        <button wire:click="prevStep" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> Kembali
        </button>
        <button wire:click="nextStep" class="btn btn-primary px-4"
            wire:loading.attr="disabled" wire:target="nextStep">
            <span wire:loading.remove wire:target="nextStep">
                Lanjut ke Konfirmasi <i class="fa-solid fa-arrow-right ms-1"></i>
            </span>
            <span wire:loading wire:target="nextStep">
                <span class="spinner-border spinner-border-sm me-1"></span> Memproses...
            </span>
        </button>
    </div>
    @endif


    {{-- ════════════════════════════════════════════════════════ --}}
    {{-- STEP 3 — Konfirmasi & Simpan                           --}}
    {{-- ════════════════════════════════════════════════════════ --}}
    @if($step === 3)
    <div class="row g-4 justify-content-center">
        <div class="col-lg-8">

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="fa-solid fa-clipboard-check me-2 text-success"></i>
                        Ringkasan Billing Walk-In
                    </h5>
                </div>
                <div class="card-body">

                    {{-- Info Pelanggan & Meja --}}
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 bg-light border h-100">
                                <div class="text-muted small mb-2 fw-semibold">
                                    <i class="fa-solid fa-user me-1"></i> PELANGGAN
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold flex-shrink-0"
                                        style="width:40px;height:40px;font-size:16px;">
                                        {{ strtoupper(substr(trim($guest_name), 0, 1)) }}
                                    </div>
                                    <div>
                                        <div class="fw-semibold">{{ trim($guest_name) }}</div>
                                        <div class="small text-muted">
                                            <i class="fa-solid fa-person-walking me-1"></i>Walk-In (Tanpa Akun)
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 bg-light border h-100">
                                <div class="text-muted small mb-2 fw-semibold">
                                    <i class="fa-solid fa-table me-1"></i> MEJA
                                </div>
                                @if($this->selectedTable)
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="rounded bg-success text-white d-flex align-items-center justify-content-center flex-shrink-0"
                                            style="width:40px;height:40px;">
                                            <i class="fa-solid fa-table"></i>
                                        </div>
                                        <div>
                                            <div class="fw-semibold">{{ $this->selectedTable->name }}</div>
                                            <div class="small text-muted">{{ $this->selectedTable->table_number }}</div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <hr>

                    {{-- Paket / Tarif --}}
                    <div class="mb-4">
                        <div class="text-muted small fw-semibold mb-3">
                            <i class="fa-solid fa-box-open me-1"></i> PAKET / TARIF
                        </div>
                        @if($this->selectedPackage)
                            <div class="d-flex justify-content-between align-items-center p-3 rounded-3 bg-primary bg-opacity-10 border border-primary border-opacity-25">
                                <div>
                                    <div class="fw-semibold text-white">{{ $this->selectedPackage->name }}</div>
                                    <div class="small text-white mt-1">
                                        @if($this->selectedPackage->isNormal())
                                            <span class="badge bg-info-subtle text-white border border-info-subtle me-1">
                                                <i class="fa-solid fa-clock me-1"></i>{{ (float) $this->selectedPackage->duration_hours }} Jam Fix
                                            </span>
                                            Waktu berakhir pukul
                                            <strong class="text-white">{{ now()->addHours((float) $this->selectedPackage->duration_hours)->format('H:i') }}</strong>
                                        @else
                                            <span class="badge bg-warning-subtle text-white border border-warning-subtle">
                                                <i class="fa-solid fa-infinity me-1"></i>Waktu Bebas (Loss)
                                            </span>
                                        @endif
                                    </div>
                                </div>
                                <div class="text-end">
                                    @if($this->selectedPackage->isNormal())
                                        <div class="fw-bold text-white fs-5">
                                            Rp {{ number_format((float)$this->selectedPackage->price, 0, ',', '.') }}
                                        </div>
                                        <div class="small text-white">harga paket</div>
                                    @else
                                        <div class="fw-bold text-white fs-5">
                                            Rp {{ number_format((float)($this->selectedPackage->pricing?->price_per_hour ?? 0), 0, ',', '.') }}/jam
                                        </div>
                                        <div class="small text-white">dihitung di akhir</div>
                                    @endif
                                </div>
                            </div>
                        @elseif($this->selectedPricing)
                            <div class="d-flex justify-content-between align-items-center p-3 rounded-3 bg-warning bg-opacity-10 border border-warning border-opacity-25">
                                <div>
                                    <div class="fw-semibold text-warning-emphasis">{{ $this->selectedPricing->name }}</div>
                                    <div class="small text-muted mt-1">
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                            Tarif Per Jam (Tanpa Paket)
                                        </span>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold text-success fs-5">
                                        Rp {{ number_format((float)$this->selectedPricing->price_per_hour, 0, ',', '.') }}/jam
                                    </div>
                                    <div class="small text-muted">dihitung di akhir</div>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Addon --}}
                    @if(count($this->addonSummary) > 0)
                        <hr>
                        <div class="mb-4">
                            <div class="text-muted small fw-semibold mb-3">
                                <i class="fa-solid fa-shopping-basket me-1"></i> ADDON AWAL
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Nama</th>
                                            <th class="text-center">Qty</th>
                                            <th class="text-end">Harga</th>
                                            <th class="text-end">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($this->addonSummary as $item)
                                            <tr>
                                                <td>{{ $item['name'] }}</td>
                                                <td class="text-center">{{ $item['qty'] }}</td>
                                                <td class="text-end">Rp {{ number_format($item['price'], 0, ',', '.') }}</td>
                                                <td class="text-end fw-medium">Rp {{ number_format($item['subtotal'], 0, ',', '.') }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-info">
                                            <td colspan="3" class="fw-semibold">Total Addon</td>
                                            <td class="text-end fw-bold">Rp {{ number_format($this->addonTotal, 0, ',', '.') }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    @endif

                    {{-- Catatan --}}
                    @if(trim($notes))
                        <hr>
                        <div class="mb-3">
                            <div class="text-muted small fw-semibold mb-1">
                                <i class="fa-solid fa-note-sticky me-1"></i> CATATAN
                            </div>
                            <div class="p-3 rounded-3 bg-light border small">{{ trim($notes) }}</div>
                        </div>
                    @endif

                    <hr>

                    {{-- Waktu Mulai & Operator --}}
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="text-muted small fw-semibold mb-1">
                                <i class="fa-solid fa-clock me-1"></i> WAKTU MULAI
                            </div>
                            <div class="fw-medium">{{ now()->format('d M Y, H:i') }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small fw-semibold mb-1">
                                <i class="fa-solid fa-user-shield me-1"></i> OPERATOR
                            </div>
                            <div class="fw-medium">{{ auth()->user()->name }}</div>
                            <div class="small text-muted">{{ ucfirst(auth()->user()->getRoleNames()->first()) }}</div>
                        </div>
                    </div>

                </div>

                {{-- CTA Footer --}}
                <div class="card-footer bg-white border-top">
                    <div class="alert alert-warning d-flex align-items-center gap-2 mb-3 py-2 border-0 bg-warning-subtle">
                        <i class="fa-solid fa-triangle-exclamation text-warning flex-shrink-0"></i>
                        <div class="small text-warning-emphasis">
                            Setelah billing dibuat, <strong>permainan langsung dimulai</strong>
                            dan status meja akan berubah menjadi <strong>Terpakai</strong>.
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <button wire:click="prevStep" class="btn btn-outline-secondary">
                            <i class="fa-solid fa-arrow-left me-1"></i> Kembali
                        </button>
                        <button wire:click="save" class="btn btn-success px-5 fw-semibold"
                            wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">
                                <i class="fa-solid fa-play me-2"></i> Mulai Permainan!
                            </span>
                            <span wire:loading wire:target="save">
                                <span class="spinner-border spinner-border-sm me-1"></span> Menyimpan...
                            </span>
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>
    @endif

</div>
