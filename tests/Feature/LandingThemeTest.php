<?php

namespace Tests\Feature;

use Tests\TestCase;

class LandingThemeTest extends TestCase
{
    /**
     * Test that landing page includes anti-FOUC script and theme toggle elements.
     */
    public function test_landing_page_renders_theme_toggle_and_anti_fouc_script(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);

        // Anti-FOUC script in head
        $response->assertSee("localStorage.getItem('theme')", false);
        $response->assertSee("document.documentElement.setAttribute('data-theme'", false);

        // Theme tokens
        $response->assertSee('[data-theme="dark"]', false);
        $response->assertSee('--card-shadow', false);

        // Theme toggle button and nav-actions container
        $response->assertSee('id="themeToggle"', false);
        $response->assertSee('class="theme-toggle"', false);
        $response->assertSee('class="nav-actions"', false);
    }
}
