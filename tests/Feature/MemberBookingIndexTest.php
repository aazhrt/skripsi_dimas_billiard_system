<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Package;
use App\Models\Table;
use App\Models\User;
use Tests\TestCase;

class MemberBookingIndexTest extends TestCase
{
    protected ?User $member = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = User::role('member')->first();
        if (!$this->member) {
            $this->member = User::factory()->create();
            $this->member->assignRole('member');
        }
    }

    /**
     * Test member can access /member/booking (member.booking.index)
     */
    public function test_member_can_view_booking_index_page(): void
    {
        $response = $this->actingAs($this->member)->get(route('member.booking.index'));

        $response->assertStatus(200);
        $response->assertSee('BOOKING SAYA');
    }

    /**
     * Test member can see their bookings list with status badge on /member/booking
     */
    public function test_member_sees_booking_list_with_details(): void
    {
        $table = Table::first();
        $package = Package::first();

        $booking = Booking::create([
            'customer_id'     => $this->member->id,
            'table_id'        => $table->id,
            'package_id'      => $package?->id,
            'scheduled_date'  => now()->toDateString(),
            'scheduled_start' => '14:00',
            'scheduled_end'   => '16:00',
            'status'          => 'pending',
        ]);

        try {
            $response = $this->actingAs($this->member)->get(route('member.booking.index'));

            $response->assertStatus(200);
            $response->assertSee($booking->booking_code);
            $response->assertSee('Menunggu');
            $response->assertSee('Meja ' . $table->table_number);
        } finally {
            $booking->delete();
        }
    }

    /**
     * Test member can cancel their pending booking.
     */
    public function test_member_can_cancel_pending_booking(): void
    {
        $table = Table::first();
        $booking = Booking::create([
            'customer_id'     => $this->member->id,
            'table_id'        => $table->id,
            'scheduled_date'  => now()->toDateString(),
            'scheduled_start' => '17:00',
            'scheduled_end'   => '18:00',
            'status'          => 'pending',
        ]);

        try {
            \Livewire\Livewire::actingAs($this->member)
                ->test('pages.member-booking.index')
                ->call('cancelBooking', $booking->id);

            $this->assertEquals('cancelled', $booking->fresh()->status);
        } finally {
            $booking->delete();
        }
    }
}
