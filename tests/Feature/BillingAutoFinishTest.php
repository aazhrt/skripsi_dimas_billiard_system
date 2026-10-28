<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Pricing;
use App\Models\Table;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class BillingAutoFinishTest extends TestCase
{
    protected ?User $kasir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kasir = User::role('kasir')->first();
        if (!$this->kasir) {
            $this->kasir = User::factory()->create();
            $this->kasir->assignRole('kasir');
        }
    }

    /**
     * Test config/view.php configuration is valid.
     */
    public function test_view_compiled_path_configuration_is_present(): void
    {
        $this->assertNotEmpty(config('view.compiled'));
        $this->assertStringContainsString('views', config('view.compiled'));
    }

    /**
     * Test billing show component auto-finishes session after 5-minute grace period.
     */
    public function test_billing_show_auto_finishes_when_grace_period_exceeded(): void
    {
        $table = Table::first() ?? Table::factory()->create(['status' => 'available']);
        $pricing = Pricing::first() ?? Pricing::factory()->create(['type' => 'regular', 'price_per_hour' => 30000]);

        $manager = app(\App\Services\BillingSessionManager::class);
        $billing = $manager->startWalkIn(
            table: $table,
            type: 'open',
            durationHours: 1,
            package: null,
            pricing: $pricing,
            guestName: 'Test Grace Period Guest'
        );

        $billing->update([
            'started_at'       => now()->subMinutes(66),
            'scheduled_end_at' => now()->subMinutes(6),
        ]);

        try {
            // Livewire show component for this billing
            Livewire::actingAs($this->kasir)
                ->test('pages.billing.show', ['id' => $billing->id])
                ->call('checkTime');

            $billing->refresh();
            $table->refresh();

            // Status should no longer be active
            $this->assertNotEquals('active', $billing->status);
            $this->assertFalse((bool) $table->device_status);
        } finally {
            $billing->payments()->delete();
            $billing->delete();
            $table->update(['status' => 'available', 'device_status' => false]);
        }
    }
}
