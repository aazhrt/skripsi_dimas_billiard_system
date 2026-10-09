<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class MemberBookingThemeTest extends TestCase
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
     * Test that member booking layout renders theme toggle, anti-FOUC script,
     * and does NOT override .text-white with destructive !important rule.
     */
    public function test_member_booking_layout_renders_theme_toggle_safely(): void
    {
        $response = $this->actingAs($this->member)->get(route('member.booking.create'));

        $response->assertStatus(200);

        // Anti-FOUC script
        $response->assertSee("localStorage.getItem('theme')", false);
        $response->assertSee("document.documentElement.setAttribute('data-theme'", false);

        // Tokens
        $response->assertSee(':root', false);
        $response->assertSee('[data-theme="dark"]', false);

        // Theme toggle button
        $response->assertSee('id="themeToggleMb"', false);
        $response->assertSee('class="theme-toggle"', false);

        // Guard against CSS anti-pattern that breaks badge contrast on green background
        $response->assertDontSee('.booking-wrapper .text-white{color:var(--text)!important}', false);
        $response->assertDontSee('.booking-wrapper .text-white { color: var(--text) !important; }', false);
    }

    /**
     * Test that member booking create component uses semantic CSS variables instead of hardcoded hex.
     */
    public function test_member_booking_create_component_uses_semantic_css_variables(): void
    {
        $table1 = \App\Models\Table::find(1);
        $table2 = \App\Models\Table::find(2);

        $orig1 = $table1?->status;
        $orig2 = $table2?->status;

        try {
            if ($table1) $table1->update(['status' => 'occupied']);
            if ($table2) $table2->update(['status' => 'maintenance']);

            $response = $this->actingAs($this->member)->get(route('member.booking.create'));

            $response->assertStatus(200);

            // Verify that component view uses CSS variables
            $response->assertSee('color:var(--amber)', false);
            $response->assertSee('color:var(--red)', false);
            $response->assertDontSee('color:#f59e0b', false);
            $response->assertDontSee('color:#ef4444', false);
        } finally {
            if ($table1 && $orig1) $table1->update(['status' => $orig1]);
            if ($table2 && $orig2) $table2->update(['status' => $orig2]);
        }
    }
}
