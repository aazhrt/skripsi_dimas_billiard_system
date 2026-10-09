<?php

use App\Models\Booking;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Detail Booking', 'breadcrumbs' => [['title' => 'Monitoring'], ['title' => 'Booking', 'url' => '/owner/booking'], ['title' => 'Detail', 'url' => '#']]])] class extends Component {

    public Booking $booking;

    public $showRejectModal   = false;
    public $rejectReason      = '';

    public function mount($id)
    {
        $this->booking = Booking::with([
            'customer', 'table', 'package', 'pricing', 'confirmedBy', 'billing'
        ])->findOrFail($id);

        $this->checkBillingTime();
    }

    public function checkBillingTime()
    {
        // Polling waktu sekarang hanya dilakukan di view billing.
    }

    public function confirmBooking()
    {
        if (!$this->booking->isPending()) return;

        $this->booking->update([
            'status'       => 'confirmed',
            'confirmed_by' => auth()->id(),
            'confirmed_at' => now(),
        ]);

        // Jika booking untuk hari ini → langsung tandai meja 'occupied'
        // agar meja tidak bisa dipakai walk-in lain sebelum billing dimulai
        if ($this->booking->scheduled_date?->isToday()) {
            $this->booking->table?->update(['status' => 'occupied']);
        }

        $this->booking->refresh();
        $this->dispatch('notify', message: 'Booking berhasil dikonfirmasi!', type: 'success');
    }

    public function rejectBooking()
    {
        if (!$this->booking->isPending()) return;

        $this->booking->update([
            'status'          => 'rejected',
            'rejected_reason' => $this->rejectReason ?: null,
        ]);

        // Kembalikan meja ke 'available' jika tidak ada booking confirmed lain
        // atau billing aktif yang masih berjalan di meja ini
        $table = $this->booking->table;
        if ($table) {
            $hasActiveBilling = \App\Models\Billing::where('table_id', $table->id)
                ->where('status', 'active')
                ->exists();

            $hasOtherConfirmedToday = \App\Models\Booking::where('table_id', $table->id)
                ->where('id', '!=', $this->booking->id)
                ->where('status', 'confirmed')
                ->whereDate('scheduled_date', today())
                ->exists();

            if (!$hasActiveBilling && !$hasOtherConfirmedToday) {
                $table->update(['status' => 'available']);
            }
        }

        $this->booking->refresh();
        $this->showRejectModal = false;
        $this->rejectReason = '';
        $this->dispatch('notify', message: 'Booking berhasil ditolak.', type: 'success');
    }

    public function createBilling(?\App\Services\BillingSessionManager $sessionManager = null)
    {
        $sessionManager = $sessionManager ?? app(\App\Services\BillingSessionManager::class);
        try {
            $sessionManager->start([
                'booking_id' => $this->booking->id,
            ]);

            $this->booking->refresh();
            $this->dispatch('notify', message: 'Billing berhasil dibuat & permainan dimulai!', type: 'success');
        } catch (\App\Exceptions\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');
        }
    }

    // Addon logic dipindahkan sepenuhnya ke menu detail billing
};
?>

<div wire:poll.10s="checkBillingTime">
    {{-- Notifikasi Waktu Habis dipindahkan ke menu Detail Billing --}}

    <div class="row">
        {{-- LEFT: Info Booking --}}
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="header-title">
                        <h4 class="card-title mb-0">Detail Booking</h4>
                    </div>
                    @php
                        $badgeMap = [
                            'pending'   => ['class' => 'bg-warning text-dark', 'label' => 'Menunggu Konfirmasi'],
                            'confirmed' => ['class' => 'bg-success',            'label' => 'Dikonfirmasi'],
                            'rejected'  => ['class' => 'bg-danger',             'label' => 'Ditolak'],
                            'cancelled' => ['class' => 'bg-secondary',          'label' => 'Dibatalkan'],
                            'completed' => ['class' => 'bg-primary',            'label' => 'Selesai'],
                        ];
                        $badge = $badgeMap[$booking->status] ?? ['class' => 'bg-secondary', 'label' => $booking->status];
                    @endphp
                    <span class="badge {{ $badge['class'] }} fs-6 px-3 py-2">{{ $badge['label'] }}</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 bg-light">
                                <div class="text-muted small mb-1"><i class="fa-solid fa-hashtag me-1"></i>Kode Booking</div>
                                <div class="fw-bold font-monospace fs-5">{{ $booking->booking_code }}</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 bg-light">
                                <div class="text-muted small mb-1"><i class="fa-solid fa-calendar me-1"></i>Dibuat Pada</div>
                                <div class="fw-medium">{{ $booking->created_at->format('d M Y, H:i') }}</div>
                            </div>
                        </div>

                        {{-- Jadwal --}}
                        <div class="col-12">
                            <h6 class="text-muted fw-semibold border-bottom pb-2 mt-2">
                                <i class="fa-solid fa-calendar-days me-1"></i> Jadwal Bermain
                            </h6>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Tanggal</div>
                            <div class="fw-medium">{{ $booking->scheduled_date?->format('d M Y') ?? '-' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Jam Mulai</div>
                            <div class="fw-medium">{{ \Carbon\Carbon::parse($booking->scheduled_start)->format('H:i') }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Jam Selesai</div>
                            <div class="fw-medium">{{ \Carbon\Carbon::parse($booking->scheduled_end)->format('H:i') }}</div>
                        </div>

                        {{-- Fasilitas --}}
                        <div class="col-12">
                            <h6 class="text-muted fw-semibold border-bottom pb-2 mt-2">
                                <i class="fa-solid fa-circle-info me-1"></i> Detail Fasilitas
                            </h6>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Meja</div>
                            <div class="fw-medium">Meja {{ $booking->table?->table_number ?? '-' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Paket</div>
                            <div class="fw-medium">{{ $booking->package?->name ?? 'Tanpa Paket' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Tarif Dasar</div>
                            <div class="fw-medium">{{ $booking->pricing?->name ?? '-' }}</div>
                        </div>

                        {{-- Catatan --}}
                        @if ($booking->notes)
                            <div class="col-12">
                                <div class="text-muted small">Catatan</div>
                                <div class="p-2 rounded bg-light mt-1">{{ $booking->notes }}</div>
                            </div>
                        @endif

                        {{-- Info Konfirmasi / Penolakan --}}
                        @if ($booking->isConfirmed())
                            <div class="col-12">
                                <div class="alert alert-success d-flex align-items-start gap-2 mb-0">
                                    <i class="fa-solid fa-circle-check mt-1"></i>
                                    <div>
                                        <strong>Dikonfirmasi oleh:</strong> {{ $booking->confirmedBy?->name ?? '-' }}<br>
                                        <small class="text-muted">{{ $booking->confirmed_at?->format('d M Y, H:i') }}</small>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if ($booking->isRejected() && $booking->rejected_reason)
                            <div class="col-12">
                                <div class="alert alert-danger d-flex align-items-start gap-2 mb-0">
                                    <i class="fa-solid fa-ban mt-1"></i>
                                    <div>
                                        <strong>Alasan Penolakan:</strong><br>
                                        {{ $booking->rejected_reason }}
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Footer Aksi --}}
                @if ($booking->isPending())
                    <div class="card-footer d-flex justify-content-end gap-2">
                        <button class="btn btn-danger"
                            @click="$wire.set('showRejectModal', true)">
                            <i class="fa-solid fa-ban me-1"></i> Tolak Booking
                        </button>
                        <button class="btn btn-success"
                            @click="
                                Swal.fire({
                                    title: 'Konfirmasi Booking?',
                                    text: 'Booking {{ $booking->booking_code }} akan dikonfirmasi.',
                                    icon: 'question',
                                    showCancelButton: true,
                                    confirmButtonColor: '#198754',
                                    cancelButtonColor: '#6c757d',
                                    confirmButtonText: 'Ya, Konfirmasi!',
                                    cancelButtonText: 'Batal'
                                }).then((result) => {
                                    if (result.isConfirmed) $wire.confirmBooking()
                                })
                            ">
                            <i class="fa-solid fa-check me-1"></i> Konfirmasi Booking
                        </button>
                    </div>
                @elseif($booking->isConfirmed() && !$booking->billing()->exists())
                    <div class="card-footer d-flex justify-content-end gap-2">
                         <button class="btn btn-primary"
                            @click="
                                Swal.fire({
                                    title: 'Mulai Permainan?',
                                    text: 'Billing akan dibuat dan meja akan ditandai Sedang Digunakan.',
                                    icon: 'info',
                                    showCancelButton: true,
                                    confirmButtonColor: '#0d6efd',
                                    cancelButtonColor: '#6c757d',
                                    confirmButtonText: 'Ya, Mulai!',
                                    cancelButtonText: 'Batal'
                                }).then((result) => {
                                    if (result.isConfirmed) $wire.createBilling()
                                })
                            ">
                            <i class="fa-solid fa-play me-1"></i> Mulai Permainan (Buat Billing)
                        </button>
                    </div>
                @elseif($booking->billing && $booking->billing->isActive())
                    <div class="card-footer d-flex justify-content-end gap-2 bg-light">
                        <a href="{{ auth()->user()->hasRole('owner') ? route('owner.billing.show', $booking->billing->id) : route('kasir.billing.show', $booking->billing->id) }}" wire:navigate class="btn btn-info">
                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Kelola di Menu Billing
                        </a>
                    </div>
                @endif
            </div>

            {{-- TABEL DAFTAR ADDON (Di bawah Card Booking) --}}
            @if ($booking->billing && $booking->billing->addons->isNotEmpty())
            <div class="card mt-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="fa-solid fa-burger me-2"></i>Daftar Pesanan F&B</h5>
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover table-striped mb-0 text-center align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th class="text-start">Item</th>
                                <th>Qty</th>
                                <th class="text-end">Harga Satuan</th>
                                <th class="text-end pe-3">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($booking->billing->addons as $index => $ba)
                            <tr>
                                <td class="text-muted">{{ $index + 1 }}</td>
                                <td class="text-start fw-medium">{{ $ba->addon->name ?? 'Terhapus' }}</td>
                                <td>{{ $ba->quantity }}</td>
                                <td class="text-end text-muted">{{ 'Rp ' . number_format($ba->unit_price, 0, ',', '.') }}</td>
                                <td class="text-end pe-3 text-success fw-semibold">{{ $ba->formatted_subtotal }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="4" class="text-end fw-bold">Total F&B:</td>
                                <td class="text-end pe-3 fw-bold text-primary">{{ 'Rp ' . number_format($booking->billing->addon_total, 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            @endif
        </div>

        {{-- RIGHT: Info Pelanggan + Billing --}}
        <div class="col-lg-4">
            {{-- Pelanggan --}}
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fa-solid fa-user me-2"></i>Informasi Member</h5>
                </div>
                <div class="card-body">
                    @if ($booking->customer)
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="flex-shrink-0">
                                @if ($booking->customer->avatar)
                                    <img src="{{ Storage::url($booking->customer->avatar) }}"
                                        class="rounded-circle" width="52" height="52" style="object-fit:cover;" alt="">
                                @else
                                    <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center"
                                        style="width:52px;height:52px;">
                                        <span class="text-white fw-bold fs-5">
                                            {{ strtoupper(substr($booking->customer->name, 0, 1)) }}
                                        </span>
                                    </div>
                                @endif
                            </div>
                            <div>
                                <div class="fw-semibold">{{ $booking->customer->name }}</div>
                                <small class="text-muted">{{ $booking->customer->email }}</small>
                            </div>
                        </div>
                        <div class="small text-muted mb-1">No. HP</div>
                        <div class="mb-2">{{ $booking->customer->phone ?? '-' }}</div>
                    @else
                        <p class="text-muted mb-0">Data member tidak ditemukan.</p>
                    @endif
                </div>
            </div>

            {{-- Billing --}}
            <div class="card mt-0">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fa-solid fa-receipt me-2"></i>Informasi Billing</h5>
                </div>
                <div class="card-body">
                    @if ($booking->billing)
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted small">Kode Billing</span>
                            <span class="fw-medium font-monospace">{{ $booking->billing->billing_code }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted small">Status</span>
                            <span class="badge {{ $booking->billing->isActive() ? 'bg-success' : 'bg-primary' }}">
                                {{ $booking->billing->isActive() ? 'Aktif' : 'Selesai' }}
                            </span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Total</span>
                            <span class="fw-bold text-success">{{ $booking->billing->formatted_current_total }}</span>
                        </div>

                        @if ($booking->billing->isActive())
                            <div class="mt-3">
                                <a href="{{ auth()->user()->hasRole('owner') ? route('owner.billing.show', $booking->billing->id) : route('kasir.billing.show', $booking->billing->id) }}" wire:navigate class="btn btn-outline-primary w-100">
                                    <i class="fa-solid fa-arrow-right me-1"></i> Ke Halaman Kelola Billing
                                </a>
                            </div>
                        @endif

                    @else
                        <p class="text-muted small mb-0">Billing belum dibuat untuk booking ini.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Back Button --}}
    <div class="mt-2">
        <a href="{{ auth()->user()->hasRole('owner') ? route('owner.booking.index') : route('kasir.booking.index') }}" wire:navigate class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Daftar Booking
        </a>
    </div>

    {{-- Modal Tolak --}}
    @if ($showRejectModal)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title text-danger"><i class="fa-solid fa-ban me-2"></i>Tolak Booking</h5>
                        <button type="button" class="btn-close" @click="$wire.set('showRejectModal', false)"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted">Kode: <strong>{{ $booking->booking_code }}</strong></p>
                        <div class="mb-3">
                            <label class="form-label">Alasan Penolakan <span class="text-muted">(opsional)</span></label>
                            <textarea class="form-control" wire:model="rejectReason" rows="3"
                                placeholder="Masukkan alasan penolakan..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" @click="$wire.set('showRejectModal', false)">Batal</button>
                        <button class="btn btn-danger" wire:click="rejectBooking" wire:loading.attr="disabled">
                            <span wire:loading.remove>Tolak Booking</span>
                            <span wire:loading>Memproses...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>