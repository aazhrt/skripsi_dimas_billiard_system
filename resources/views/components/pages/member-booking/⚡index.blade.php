<?php

use App\Models\Booking;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.member-booking', ['title' => 'Booking Saya'])] class extends Component {
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $filterStatus = '';

    public function updatingFilterStatus() { $this->resetPage(); }

    #[Computed]
    public function bookings()
    {
        $query = Booking::with(['table', 'package'])
            ->where('customer_id', auth()->id())
            ->orderBy('created_at', 'desc');

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        return $query->paginate(10);
    }

    public function cancelBooking($id)
    {
        $booking = Booking::where('id', $id)
            ->where('customer_id', auth()->id())
            ->firstOrFail();

        if (!$booking->isPending()) {
            $this->dispatch('notify', message: 'Hanya booking berstatus "Menunggu" yang dapat dibatalkan.', type: 'error');
            return;
        }

        $booking->update(['status' => 'cancelled']);
        $this->dispatch('notify', message: 'Booking berhasil dibatalkan.', type: 'success');
    }
};
?>

<div>
    {{-- Header + Tombol Buat Booking --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div style="margin-bottom:20px">
            <h4 class="mb-1" style="color: var(--neon); font-family: 'Bebas Neue', sans-serif; font-size: 2rem; letter-spacing: 0.05em;">
                BOOKING SAYA
            </h4>
            <p class="text-muted mb-0 small">Riwayat dan status semua booking Anda</p>
        </div>
        <a href="{{ route('member.booking.create') }}" style="margin-bottom:20px" wire:navigate class="btn-booking-next">
            <i class="fa-solid fa-plus me-1"></i> Buat Booking Baru
        </a>
    </div>

    {{-- Filter Status --}}
    <div class="d-flex flex-wrap gap-2" style="margin-bottom:20px">
        @php
            $filters = [
                '' => 'Semua',
                'pending'   => 'Menunggu',
                'confirmed' => 'Dikonfirmasi',
                'rejected'  => 'Ditolak',
                'cancelled' => 'Dibatalkan',
                'completed' => 'Selesai',
            ];
        @endphp
        @foreach ($filters as $val => $label)
            <button class="filter-chip {{ $filterStatus === $val ? 'active' : '' }}"
                wire:click="$set('filterStatus', '{{ $val }}')">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Daftar Booking --}}
    @forelse ($this->bookings as $bk)
        @php
            $statusMap = [
                'pending'   => ['label' => 'Menunggu',      'color' => 'var(--amber)'],
                'confirmed' => ['label' => 'Dikonfirmasi',  'color' => 'var(--neon)'],
                'rejected'  => ['label' => 'Ditolak',       'color' => 'var(--red)'],
                'cancelled' => ['label' => 'Dibatalkan',    'color' => '#6c757d'],
                'completed' => ['label' => 'Selesai',       'color' => '#6ea8fe'],
            ];
            $st = $statusMap[$bk->status] ?? ['label' => $bk->status, 'color' => '#6c757d'];
        @endphp
        <div class="booking-history-card" style="margin-bottom: 10px">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <div class="bk-code">{{ $bk->booking_code }}</div>
                    <div class="bk-date">
                        {{ $bk->scheduled_date?->format('d M Y') ?? '-' }} &bull;
                        {{ $bk->scheduled_start ? \Carbon\Carbon::parse($bk->scheduled_start)->format('H:i') : '-' }}
                        –
                        {{ $bk->scheduled_end ? \Carbon\Carbon::parse($bk->scheduled_end)->format('H:i') : '-' }}
                    </div>
                </div>
                <span class="bk-status-badge" style="color:{{ $st['color'] }}; border-color:{{ $st['color'] }};">
                    {{ $st['label'] }}
                </span>
            </div>

            <div class="d-flex flex-wrap gap-4 mt-3">
                <div>
                    <div class="bk-meta-label">Meja</div>
                    <div class="bk-meta-value">Meja {{ $bk->table?->table_number ?? '-' }}</div>
                </div>
                <div>
                    <div class="bk-meta-label">Paket</div>
                    <div class="bk-meta-value">{{ $bk->package?->name ?? 'Tanpa Paket' }}</div>
                </div>
                <div>
                    <div class="bk-meta-label">Dibuat</div>
                    <div class="bk-meta-value">{{ $bk->created_at?->format('d M Y, H:i') ?? '-' }}</div>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2 mt-3">
                <a href="{{ route('member.booking.show', $bk->id) }}" wire:navigate class="btn-booking-back btn-sm" style="font-size:0.78rem; padding:0.35rem 0.9rem; color: var(--neon); border-color: var(--neon);">
                    <i class="fa-solid fa-eye me-1"></i> Lihat Detail & Billing
                </a>

                {{-- Tombol batal hanya jika pending --}}
                @if ($bk->isPending())
                    <button class="btn-booking-back btn-sm text-danger border-danger"
                        style="font-size:0.78rem; padding:0.35rem 0.9rem;"
                        @click="
                            Swal.fire({
                                title: 'Batalkan Booking?',
                                text: 'Booking {{ $bk->booking_code }} akan dibatalkan.',
                                icon: 'warning',
                                background: '#0e1a12',
                                color: '#e8f5ed',
                                showCancelButton: true,
                                confirmButtonColor: '#dc3545',
                                cancelButtonColor: '#3a5c47',
                                confirmButtonText: 'Ya, Batalkan',
                                cancelButtonText: 'Tidak'
                            }).then((result) => {
                                if (result.isConfirmed) $wire.cancelBooking({{ $bk->id }})
                            })
                        ">
                        <i class="fa-solid fa-xmark me-1"></i> Batalkan
                    </button>
                @endif
            </div>

            @if ($bk->isRejected() && $bk->rejected_reason)
                <div class="bk-reject-reason mt-2">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    <strong>Alasan ditolak:</strong> {{ $bk->rejected_reason }}
                </div>
            @endif
        </div>
    @empty
        <div class="text-center py-5" style="color: var(--text-muted);">
            <i class="fa-solid fa-calendar-xmark fa-2x mb-3" style="color: var(--border-mid);"></i>
            <p>Belum ada booking. <a href="{{ route('member.booking.create') }}" wire:navigate style="color:var(--neon);">Buat booking sekarang</a>.</p>
        </div>
    @endforelse

    <div class="mt-3">
        {{ $this->bookings->links() }}
    </div>
</div>