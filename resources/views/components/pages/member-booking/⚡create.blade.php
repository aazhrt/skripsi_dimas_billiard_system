<?php

use App\Models\Booking;
use App\Models\Package;
use App\Models\Pricing;
use App\Models\Table;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Carbon\Carbon;

new #[Layout('layouts.member-booking', ['title' => 'Buat Booking'])] class extends Component {

    // Step wizard: 1=Pilih Meja, 2=Pilih Paket, 3=Pilih Jadwal, 4=Konfirmasi
    public int $step = 1;

    // Step 1: Meja
    public $table_id = '';

    // Step 2: Paket
    public $package_id  = '';
    public $pricing_id  = '';
    public $packageType = 'tanpa_paket'; // normal, loss, tanpa_paket

    // Step 3: Jadwal
    public $scheduled_date  = '';
    public $scheduled_start = '';
    public $duration        = 1; // dalam jam
    public $notes           = '';

    // Data untuk tampilan — disimpan sebagai array scalar (bukan Eloquent model)
    // Livewire v4 tidak bisa serialize Eloquent model di public property dengan andal
    public array $selectedTable   = [];
    public array $selectedPackage = [];

    public function mount()
    {
        $this->scheduled_date = now()->addDay()->format('Y-m-d');
    }

    public function getAvailableTablesProperty()
    {
        return Table::where('is_active', true)
            ->where('status', 'available')
            ->orderBy('table_number')
            ->get();
    }

    /**
     * Semua meja aktif beserta status realtime untuk ditampilkan di step 1.
     * Status realtime ditentukan dari billing aktif ATAU booking confirmed hari ini.
     */
    public function getAllTablesProperty()
    {
        return Table::where('is_active', true)
            ->with([
                'activeBilling.package',
                'bookings' => fn($q) => $q->where('status', 'confirmed')->whereDate('scheduled_date', today()),
            ])
            ->orderBy('table_number')
            ->get()
            ->map(function ($tbl) {
                // Sumber kebenaran:
                // 1. Ada billing aktif → pasti occupied
                // 2. Ada booking confirmed hari ini → occupied (meja sudah dipesan untuk hari ini)
                // 3. maintenance → maintenance
                // 4. lainnya → available
                $hasActiveBilling    = $tbl->activeBilling !== null;
                $hasConfirmedToday   = $tbl->bookings->isNotEmpty();

                $realtimeStatus = ($hasActiveBilling || $hasConfirmedToday)
                    ? 'occupied'
                    : $tbl->status;

                $tbl->realtime_status = $realtimeStatus;
                $tbl->is_bookable     = $realtimeStatus === 'available';
                return $tbl;
            });
    }

    public function getAvailablePackagesProperty()
    {
        return Package::where('is_active', true)
            ->with('pricing')
            ->orderByRaw("FIELD(type, 'normal', 'loss')")
            ->orderBy('price')
            ->get();
    }

    public function selectTable($tableId)
    {
        $table = Table::find($tableId);

        // Guard server-side: cek ulang status realtime meja
        if (!$table || !$table->is_active) {
            $this->addError('table_id', 'Meja tidak ditemukan atau sudah tidak aktif.');
            return;
        }

        // Periksa billing aktif realtime (sumber kebenaran)
        $hasActiveBilling = \App\Models\Billing::where('table_id', $tableId)
            ->where('status', 'active')
            ->exists();

        if ($hasActiveBilling || $table->status !== 'available') {
            $this->addError('table_id', 'Meja ini sedang terpakai atau dalam perbaikan. Silakan pilih meja lain.');
            return;
        }

        $this->table_id = $tableId;
        // Simpan sebagai array scalar — Livewire v4 tidak bisa serialize Eloquent model
        $this->selectedTable = [
            'id'           => $table->id,
            'table_number' => $table->table_number,
            'name'         => $table->name,
        ];
        $this->step = 2;
    }

    public function selectPackage($packageId)
    {
        $pkg = Package::with('pricing')->find($packageId);

        if (!$pkg) {
            $this->addError('package_id', 'Paket tidak ditemukan.');
            return;
        }

        $this->package_id  = $packageId;
        $this->packageType = $pkg->type;

        // Ambil pricing_id dari paket; jika kosong, fallback ke pricing aktif
        $this->pricing_id = $pkg->pricing_id
            ?? Pricing::where('is_active', true)->value('id');

        if ($this->packageType === 'normal') {
            $this->duration = (float) $pkg->duration_hours;
        } elseif ($this->packageType === 'loss') {
            $this->duration = null;
        } else {
            $this->duration = 1;
        }

        // Simpan sebagai array scalar — Livewire v4 tidak bisa serialize Eloquent model
        $this->selectedPackage = [
            'id'             => $pkg->id,
            'name'           => $pkg->name,
            'type'           => $pkg->type,
            'formatted_price' => $pkg->formatted_price,
            'duration_hours' => $pkg->duration_hours,
        ];

        $this->step = 3;
    }

    public function skipPackage()
    {
        $pricing = Pricing::where('is_active', true)->first();
        $this->package_id      = null;
        $this->selectedPackage = [];  // array kosong
        $this->pricing_id      = $pricing?->id;
        $this->packageType     = 'tanpa_paket';
        $this->duration        = 1;
        $this->step            = 3;
    }

    public function goToStep4()
    {
        // min input di form = besok, jadi validasi server harus konsisten
        $tomorrow = now()->addDay()->format('Y-m-d');

        $rules = [
            'scheduled_date'  => ['required', 'date', 'after_or_equal:' . $tomorrow],
            'scheduled_start' => ['required'],
        ];

        if ($this->packageType !== 'loss') {
            $rules['duration'] = ['required', 'numeric', 'min:0.5'];
        }

        $this->validate($rules, [
            'scheduled_date.required'       => 'Tanggal harus diisi',
            'scheduled_date.after_or_equal' => 'Tanggal booking minimal besok',
            'scheduled_start.required'      => 'Jam mulai harus diisi',
            'duration.required'             => 'Durasi harus diisi',
            'duration.min'                  => 'Durasi minimal 0.5 jam',
            'duration.numeric'              => 'Durasi harus berupa angka',
        ]);

        // --- VALIDASI KONFLIK BOOKING ---
        
        // 1. Cek apakah user ini masih punya booking (pending/confirmed) di meja yang sama
        $hasActiveBookingForThisTable = Booking::where('customer_id', auth()->id())
            ->where('table_id', $this->table_id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists();
            
        if ($hasActiveBookingForThisTable) {
            $this->addError('scheduled_date', 'Anda masih memiliki booking bertatus "pending" atau "confirmed" untuk meja ini. Selesaikan atau batalkan dulu.');
            return;
        }

        // 2. Cek apakah jadwal (jam) tumpang tindih dengan booking orang lain (maupun diri sendiri jika rule di atas dicabut)
        $newStart = $this->scheduled_start;
        $newEnd   = $this->calculated_scheduled_end ?: '23:59:59'; // Jika loss, kita anggap di-booking sampai tengah malam

        $conflict = Booking::where('table_id', $this->table_id)
            ->where('scheduled_date', $this->scheduled_date)
            ->whereIn('status', ['pending', 'confirmed'])
            ->get()
            ->filter(function ($existing) use ($newStart, $newEnd) {
                // Jam dari database biasanya format H:i:s
                $existStart = Carbon::parse($existing->scheduled_start)->format('H:i');
                $existEnd   = $existing->scheduled_end ? Carbon::parse($existing->scheduled_end)->format('H:i:s') : '23:59:59';
                
                // Cek irisan waktu (overlap)
                return ($existStart < $newEnd && $existEnd > $newStart);
            })->isNotEmpty();

        if ($conflict) {
            $this->addError('scheduled_start', 'Meja ini sudah dibooking oleh pelanggan lain pada jam tersebut. Silakan pilih jam atau meja lain.');
            return;
        }

        $this->step = 4;
    }

    public function prevStep()
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    public function getCalculatedScheduledEndProperty()
    {
        if (!$this->scheduled_start) return null;
        if ($this->packageType === 'loss' || !$this->duration) return null;

        return Carbon::parse($this->scheduled_start)
                     ->addMinutes($this->duration * 60)
                     ->format('H:i');
    }

    public function submitBooking()
    {
        // Validasi ekstra di submit hanya untuk keamanan pengisian property internal
        $rules = [
            'table_id'        => ['required', 'exists:tables,id'],
            'scheduled_date'  => ['required', 'date'],
            'scheduled_start' => ['required'],
            'pricing_id'      => ['required', 'exists:pricings,id'],
        ];

        if ($this->packageType !== 'loss') {
            $rules['duration'] = ['required', 'numeric', 'min:0.5'];
        }

        $this->validate($rules, [
            'table_id.required'   => 'Pilih meja terlebih dahulu',
            'pricing_id.required' => 'Tarif tidak ditemukan. Hubungi admin.',
        ]);

        Booking::create([
            'customer_id'     => auth()->id(),
            'table_id'        => $this->table_id,
            'package_id'      => $this->package_id ?: null,
            'pricing_id'      => $this->pricing_id,
            'scheduled_date'  => $this->scheduled_date,
            'scheduled_start' => $this->scheduled_start,
            'scheduled_end'   => $this->calculated_scheduled_end,
            'notes'           => $this->notes ?: null,
            'status'          => 'pending',
        ]);

        session()->flash('success', 'Booking berhasil dibuat! Tunggu konfirmasi dari kami.');
        return $this->redirectRoute('member.booking.index', navigate: true);
    }
};
?>

<div>
    {{-- Progress Bar --}}
    <div class="booking-progress mb-4">
        @php
            $steps = ['Pilih Meja', 'Pilih Paket', 'Jadwal', 'Konfirmasi'];
        @endphp
        <div class="d-flex align-items-center justify-content-between position-relative">
            <div class="progress-line"></div>
            @foreach ($steps as $i => $label)
                @php $n = $i + 1; @endphp
                <div class="step-indicator {{ $step >= $n ? 'active' : '' }} {{ $step > $n ? 'done' : '' }}">
                    <div class="step-bubble">
                        @if ($step > $n)
                            <i class="fa-solid fa-check"></i>
                        @else
                            {{ $n }}
                        @endif
                    </div>
                    <span class="step-label">{{ $label }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ═══ STEP 1: Pilih Meja ═══ --}}
    @if ($step === 1)
        <div class="booking-card">
            <div class="booking-card-header">
                <h5 class="mb-0"><i class="fa-solid fa-circle-dot me-2 text-success"></i>Pilih Meja</h5>
                <small class="text-white">Pilih meja yang <span class="text-success fw-semibold">Tersedia</span> untuk dibooking</small>
            </div>
            <div class="booking-card-body">
                @error('table_id')
                    <div class="alert alert-danger py-2 mb-3" style="border-radius:8px;font-size:.85rem;">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i> {{ $message }}
                    </div>
                @enderror

                @if ($this->allTables->isEmpty())
                    <div class="text-center py-5 text-muted">
                        <i class="fa-solid fa-circle-xmark fa-2x mb-3" style="color:#ff4444;"></i>
                        <p>Tidak ada meja yang terdaftar saat ini.<br>Silakan hubungi admin.</p>
                    </div>
                @else
                    {{-- Legend status --}}
                    <div class="d-flex gap-3 mb-3 flex-wrap">
                        <span class="d-flex align-items-center gap-1" style="font-size:.78rem;">
                            <span style="width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block;"></span>
                            <span class="text-success">Tersedia</span>
                        </span>
                        <span class="d-flex align-items-center gap-1" style="font-size:.78rem;">
                            <span style="width:8px;height:8px;border-radius:50%;background:#ef4444;display:inline-block;"></span>
                            <span class="text-danger">Sedang Terpakai</span>
                        </span>
                        <span class="d-flex align-items-center gap-1" style="font-size:.78rem;">
                            <span style="width:8px;height:8px;border-radius:50%;background:#f59e0b;display:inline-block;"></span>
                            <span class="text-warning">Dalam Perbaikan</span>
                        </span>
                    </div>

                    <div class="row g-3">
                        @foreach ($this->allTables as $tbl)
                            @php
                                $realtimeStatus = $tbl->realtime_status;  // 'available' | 'occupied' | 'maintenance'
                                $isAvailable    = $tbl->is_bookable;      // true hanya jika benar-benar bisa dipilih
                                $isOccupied     = $realtimeStatus === 'occupied';
                                $isMaintenance  = $realtimeStatus === 'maintenance';
                                $activeBilling  = $tbl->activeBilling;
                            @endphp
                            <div class="col-md-6 col-lg-4">
                                <div class="table-option-card
                                    {{ $table_id == $tbl->id ? 'selected' : '' }}
                                    {{ !$isAvailable ? 'table-card-disabled' : '' }}"
                                    @if($isAvailable) wire:click="selectTable({{ $tbl->id }})" style="cursor:pointer;" @else style="cursor:not-allowed; opacity:.65;" @endif>

                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div class="table-number-badge text-success">{{ $tbl->table_number }}</div>

                                        {{-- Badge status --}}
                                        @if($isAvailable)
                                            <span class="status-available">
                                                <i class="fa-solid fa-circle" style="font-size:.45rem;vertical-align:middle;"></i>
                                                Tersedia
                                            </span>
                                        @elseif($isOccupied)
                                            <span style="
                                                background:rgba(239,68,68,.12);
                                                color:var(--red);
                                                border:1px solid rgba(239,68,68,.25);
                                                font-size:.7rem;
                                                font-weight:600;
                                                padding:.2rem .6rem;
                                                border-radius:100px;
                                                display:flex;align-items:center;gap:.3rem;
                                            ">
                                                <i class="fa-solid fa-circle" style="font-size:.45rem;"></i>
                                                Terpakai
                                            </span>
                                        @else
                                            <span style="
                                                background:rgba(183,121,0,.08);
                                                color:var(--amber);
                                                border:1px solid rgba(245,158,11,.25);
                                                font-size:.7rem;
                                                font-weight:600;
                                                padding:.2rem .6rem;
                                                border-radius:100px;
                                                display:flex;align-items:center;gap:.3rem;
                                            ">
                                                <i class="fa-solid fa-wrench" style="font-size:.65rem;"></i>
                                                Perbaikan
                                            </span>
                                        @endif
                                    </div>

                                    <div class="fw-semibold">{{ $tbl->name ?? 'Meja ' . $tbl->table_number }}</div>
                                    @if ($tbl->description)
                                        <div class="text-white small mt-1">{{ $tbl->description }}</div>
                                    @endif

                                    {{-- Info tambahan jika meja sedang terpakai --}}
                                    @if($isOccupied && $activeBilling)
                                        <div class="mt-2 pt-2" style="border-top:1px solid rgba(255,255,255,.07);">
                                            <div class="text-white" style="font-size:.72rem;">
                                                <i class="fa-solid fa-clock me-1" style="color:var(--red);"></i>
                                                Mulai: {{ $activeBilling->started_at->format('H:i') }}
                                                @if($activeBilling->scheduled_end_at)
                                                    &nbsp;·&nbsp;
                                                    <i class="fa-solid fa-flag-checkered me-1"></i>
                                                    Selesai ±{{ $activeBilling->scheduled_end_at->format('H:i') }}
                                                @endif
                                            </div>
                                        </div>
                                    @elseif($isMaintenance)
                                        <div class="mt-2" style="font-size:.72rem;color:var(--amber);">
                                            <i class="fa-solid fa-triangle-exclamation me-1"></i>
                                            Sedang dalam perbaikan
                                        </div>
                                    @endif

                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ═══ STEP 2: Pilih Paket ═══ --}}
    @if ($step === 2)
        <div class="booking-card">
            <div class="booking-card-header">
                <h5 class="mb-0"><i class="fa-solid fa-box-open me-2 text-warning"></i>Pilih Paket</h5>
                <small class="text-white">
                    Meja dipilih: <span class="text-white fw-bold">{{ $selectedTable['table_number'] ?? '-' }}</span>
                </small>
            </div>
            <div class="booking-card-body">
                <div class="row g-3">
                    @foreach ($this->availablePackages as $pkg)
                        <div class="col-md-6">
                            <div class="package-option-card" wire:click="selectPackage({{ $pkg->id }})" style="cursor:pointer;">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="pkg-type-badge {{ $pkg->type }}">
                                        {{ $pkg->type === 'normal' ? 'Paket Fix' : 'Bayar / Jam' }}
                                    </span>
                                </div>
                                <div class="pkg-name">{{ $pkg->name }}</div>
                                <div class="pkg-price">{{ $pkg->formatted_price }}</div>
                                @if ($pkg->duration_hours)
                                    <div class="pkg-duration">
                                        <i class="fa-solid fa-clock me-1"></i>
                                        {{ (int) $pkg->duration_hours }} Jam
                                    </div>
                                @endif
                                @if ($pkg->description)
                                    <div class="pkg-desc">{{ $pkg->description }}</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                    {{-- Opsi tanpa paket --}}
                    <div class="col-12">
                        <div class="skip-package-card" wire:click="skipPackage" style="cursor:pointer;">
                            <i class="fa-solid fa-ban me-2 text-muted"></i>
                            Tanpa Paket – Bayar sesuai tarif dasar per jam
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    <button class="btn-booking-back" wire:click="prevStep">
                        <i class="fa-solid fa-arrow-left me-1"></i> Kembali
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══ STEP 3: Jadwal ═══ --}}
    @if ($step === 3)
        <div class="booking-card">
            <div class="booking-card-header">
                <h5 class="mb-0"><i class="fa-solid fa-calendar-days me-2 text-primary"></i>Tentukan Jadwal</h5>
                <small class="text-white">
                    Paket: <span class="text-white fw-bold">{{ $selectedPackage['name'] ?? 'Tanpa Paket' }}</span>
                </small>
            </div>
            <div class="booking-card-body">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label-custom">Tanggal Bermain</label>
                        <input type="date"
                            class="form-input-custom @error('scheduled_date') is-error @enderror"
                            wire:model="scheduled_date"
                            min="{{ now()->addDay()->format('Y-m-d') }}">
                        @error('scheduled_date')
                            <span class="error-msg">{{ $message }}</span>
                        @enderror
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label-custom">Jam Mulai</label>
                        <input type="time"
                            class="form-input-custom @error('scheduled_start') is-error @enderror"
                            wire:model.live="scheduled_start">
                        @error('scheduled_start')
                            <span class="error-msg">{{ $message }}</span>
                        @enderror
                    </div>
                    
                    <div class="col-md-6">
                        @if ($packageType !== 'loss')
                            <label class="form-label-custom">
                                Durasi Bermain (Jam) 
                                @if($packageType === 'normal') 
                                    <span class="text-warning small">(Fix dari Paket)</span> 
                                @endif
                            </label>
                            <input type="number" step="0.5" min="0.5"
                                class="form-input-custom @error('duration') is-error @enderror"
                                wire:model.live="duration" 
                                @if($packageType === 'normal') readonly @endif>
                            @error('duration')
                                <span class="error-msg">{{ $message }}</span>
                            @enderror
                            @if($scheduled_start && $duration)
                                <div class="text-white small mt-2">
                                    <i class="fa-solid fa-clock me-1"></i> Jam Selesai Terestimasi: <strong class="text-white ms-1">{{ $this->calculated_scheduled_end }}</strong>
                                </div>
                            @endif
                        @else
                            <label class="form-label-custom">Durasi Bermain</label>
                            <input type="text" class="form-input-custom text-muted" value="Loss (Bebas / Tanpa batas waktu)" readonly>
                            <div class="text-muted small mt-2">
                                <i class="fa-solid fa-infinity me-1"></i> Waktu dihitung saat bermain.
                            </div>
                        @endif
                    </div>
                    
                    <div class="col-12 mt-2">
                        <label class="form-label-custom">Catatan (opsional)</label>
                        <textarea class="form-input-custom" wire:model="notes"
                            rows="2" placeholder="Permintaan khusus, jumlah pemain, dll."></textarea>
                    </div>
                </div>
                <div class="d-flex justify-content-between mt-4">
                    <button class="btn-booking-back" wire:click="prevStep">
                        <i class="fa-solid fa-arrow-left me-1"></i> Kembali
                    </button>
                    <button class="btn-booking-next" wire:click="goToStep4">
                        Lanjut Konfirmasi <i class="fa-solid fa-arrow-right ms-1"></i>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══ STEP 4: Konfirmasi ═══ --}}
    @if ($step === 4)
        <div class="booking-card">
            <div class="booking-card-header">
                <h5 class="mb-0"><i class="fa-solid fa-clipboard-check me-2 text-success"></i>Konfirmasi Booking</h5>
            </div>
            <div class="booking-card-body">
                <div class="confirm-grid">
                    <div class="confirm-item">
                        <div class="confirm-label">Meja</div>
                        <div class="confirm-value">Meja {{ $selectedTable['table_number'] ?? '-' }}</div>
                    </div>
                    <div class="confirm-item">
                        <div class="confirm-label">Paket</div>
                        <div class="confirm-value">
                            {{ !empty($selectedPackage) ? ($selectedPackage['name'] . ' (' . $selectedPackage['formatted_price'] . ')') : 'Tanpa Paket (tarif per jam)' }}
                        </div>
                    </div>
                    <div class="confirm-item">
                        <div class="confirm-label">Tanggal</div>
                        <div class="confirm-value">
                            {{ \Carbon\Carbon::parse($scheduled_date)->locale('id')->isoFormat('dddd, D MMMM Y') }}
                        </div>
                    </div>
                    <div class="confirm-item">
                        <div class="confirm-label">Jam Bermain</div>
                        <div class="confirm-value">
                            {{ \Carbon\Carbon::parse($scheduled_start)->format('H:i') }}
                            @if($packageType !== 'loss' && $duration)
                                &ndash; {{ $this->calculated_scheduled_end }}
                                <span class="text-white small ms-1">({{ $duration }} Jam)</span>
                            @else
                                &ndash; Selesai
                                <span class="text-white small ms-1">(Loss)</span>
                            @endif
                        </div>
                    </div>
                    @if ($notes)
                        <div class="confirm-item" style="grid-column: 1 / -1;">
                            <div class="confirm-label">Catatan</div>
                            <div class="confirm-value">{{ $notes }}</div>
                        </div>
                    @endif
                </div>

                <div class="alert-info-booking mt-4">
                    <i class="fa-solid fa-circle-info me-2"></i>
                    Booking akan diproses dan dikonfirmasi oleh tim admin. Pembayaran dilakukan di tempat.
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button class="btn-booking-back" wire:click="prevStep">
                        <i class="fa-solid fa-arrow-left me-1"></i> Kembali
                    </button>
                    <button class="btn-booking-submit" wire:click="submitBooking"
                        wire:loading.attr="disabled" wire:target="submitBooking">
                        <span wire:loading.remove wire:target="submitBooking">
                            <i class="fa-solid fa-paper-plane me-1"></i> Kirim Booking
                        </span>
                        <span wire:loading wire:target="submitBooking">Mengirim...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>