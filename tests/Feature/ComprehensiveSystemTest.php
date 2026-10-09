<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Table;
use App\Models\User;
use App\Services\BillingSessionManager;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ComprehensiveSystemTest extends TestCase
{
    protected ?User $owner = null;
    protected ?User $kasir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::role('owner')->first();
        $this->kasir = User::role('kasir')->first();
    }

    protected function tearDown(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        \App\Models\Payment::truncate();
        \App\Models\BillingAddon::truncate();
        \App\Models\Billing::truncate();
        \App\Models\Booking::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        \App\Models\Table::query()->update(['status' => 'available', 'device_status' => false]);

        parent::tearDown();
    }

    public function test_public_pages_and_microcontroller_api(): void
    {
        // 1. Landing & Login
        $this->get('/')->assertStatus(200);
        $this->get('/login')->assertStatus(200);

        // 2. Microcontroller Batch API
        $response = $this->get('/api/microcontroller/tables/light');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['table_id', 'table_name', 'light_on']
                ]
            ]);

        // 3. Microcontroller Single Table API
        $response = $this->get('/api/microcontroller/table/1/light');
        $response->assertStatus(200)
            ->assertJsonStructure(['table_id', 'table_name', 'light_on', 'message']);
    }

    public function test_owner_can_access_all_management_pages(): void
    {
        if (!$this->owner) {
            $this->markTestSkipped('Owner not found');
        }

        $pages = [
            '/owner/dashboard',
            '/owner/billing',
            '/owner/billing/create',
            '/owner/meja',
            '/owner/package',
            '/owner/pricing',
            '/owner/addon',
            '/owner/kasir',
            '/owner/member',
            '/owner/booking',
        ];

        foreach ($pages as $page) {
            $this->actingAs($this->owner)
                ->get($page)
                ->assertStatus(200);
        }
    }

    public function test_kasir_can_access_all_cashier_pages(): void
    {
        if (!$this->kasir) {
            $this->markTestSkipped('Kasir not found');
        }

        $pages = [
            '/kasir/dashboard',
            '/kasir/billing',
            '/kasir/billing/create',
            '/kasir/booking',
        ];

        foreach ($pages as $page) {
            $this->actingAs($this->kasir)
                ->get($page)
                ->assertStatus(200);
        }
    }

    public function test_full_billing_lifecycle_syncs_with_hardware_api(): void
    {
        $table = Table::where('id', 1)->first();
        $this->assertNotNull($table);

        // Pastikan kondisi awal lampu meja mati
        $table->update(['status' => 'available', 'device_status' => false]);

        $apiCheck = $this->get('/api/microcontroller/table/1/light');
        $apiCheck->assertStatus(200)->assertJson(['light_on' => false]);

        $this->actingAs($this->kasir);

        /** @var BillingSessionManager $manager */
        $manager = app(BillingSessionManager::class);

        // 1. Start billing (Simulasi kasir memulai meja)
        $billing = $manager->start([
            'guest_name' => 'Test Lifecycle',
            'table_id'   => 1,
            'package_id' => 1,
        ]);

        $this->assertEquals('active', $billing->status);
        $this->assertTrue((bool)$billing->table->fresh()->device_status);

        // API hardware harus mengembalikan light_on = true
        $apiCheck = $this->get('/api/microcontroller/table/1/light');
        $apiCheck->assertStatus(200)->assertJson(['light_on' => true]);

        // 2. Finish billing (Simulasi kasir menyelesaikan billing)
        $manager->finish($billing, 'cash', 50000, false);

        $this->assertEquals('completed', $billing->fresh()->status);
        $this->assertFalse((bool)$billing->table->fresh()->device_status);

        // API hardware harus kembali light_on = false
        $apiCheck = $this->get('/api/microcontroller/table/1/light');
        $apiCheck->assertStatus(200)->assertJson(['light_on' => false]);

        // 3. Bersihkan data test ini agar database tetap steril untuk demo
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        \App\Models\Payment::where('billing_id', $billing->id)->delete();
        $billing->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $table->update(['status' => 'available', 'device_status' => false]);
    }
}
