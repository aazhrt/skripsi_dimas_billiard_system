<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_owner_can_view_dashboard(): void
    {
        $owner = User::role('owner')->first();
        if (!$owner) {
            $this->markTestSkipped('Owner user not found.');
        }

        $response = $this->actingAs($owner)->get('/owner/dashboard');

        $response->assertStatus(200);
    }

    public function test_owner_can_view_billing_show_without_error(): void
    {
        $owner = User::role('owner')->first();
        if (!$owner) {
            $this->markTestSkipped('Owner user not found.');
        }

        $billing = \App\Models\Billing::first();
        if (!$billing) {
            $this->markTestSkipped('No billing available.');
        }

        $response = $this->actingAs($owner)->get("/owner/billing/{$billing->id}");

        $response->assertStatus(200);
    }
}
