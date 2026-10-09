{{--
    resources/views/pages/landing.blade.php

    Landing Page Publik — Billiard Booking System
    Layout: Standalone (tidak pakai layouts/app.blade.php)
    Realtime: Livewire wire:poll setiap 10 detik untuk status meja
--}}
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billiard Hall — Booking & Status Meja</title>
    <meta name="description" content="Cek ketersediaan meja billiard secara realtime. Booking mudah, main lebih seru.">
    <script>(function(){try{var t=localStorage.getItem('theme')||'light';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;700&display=swap"
        rel="stylesheet">

    @livewireStyles

    <style>
        /* ═══════════════════════════════════════════════════
           TOKENS & VARIABLES
        ═══════════════════════════════════════════════════ */
        :root {
            /* Light — default */
            --felt: #0d4a2b;
            --felt-mid: #0f5c33;
            --felt-light: #1a7a45;
            --neon: #0e8a3e;
            --neon-dim: #0a6b30;
            --neon-glow: rgba(14, 138, 62, 0.14);
            --amber: #b77900;
            --amber-glow: rgba(183, 121, 0, 0.18);
            --red: #d93636;
            --red-glow: rgba(217, 54, 54, 0.14);
            --bg: #f4f7f5;
            --bg-card: #ffffff;
            --bg-card2: #ffffff;
            --border: rgba(15, 31, 20, 0.09);
            --border-mid: rgba(15, 31, 20, 0.15);
            --text: #0f1f14;
            --text-dim: #4a6658;
            --text-muted: #8aa79a;
            --nav-bg: rgba(255, 255, 255, 0.82);
            --card-shadow: 0 4px 24px rgba(15, 31, 20, 0.06);
            --hero-glow: rgba(13, 74, 43, 0.07);
        }

        [data-theme="dark"] {
            --felt: #0d4a2b;
            --felt-mid: #0f5c33;
            --felt-light: #1a7a45;
            --neon: #39ff8f;
            --neon-dim: #22cc68;
            --neon-glow: rgba(57, 255, 143, 0.35);
            --amber: #ffb830;
            --amber-glow: rgba(255, 184, 48, 0.35);
            --red: #ff4444;
            --red-glow: rgba(255, 68, 68, 0.35);
            --bg: #080e0a;
            --bg-card: #0e1a12;
            --bg-card2: #111f16;
            --border: rgba(57, 255, 143, 0.12);
            --border-mid: rgba(57, 255, 143, 0.22);
            --text: #e8f5ed;
            --text-dim: #8aab95;
            --text-muted: #4d7060;
            --nav-bg: rgba(8, 14, 10, 0.85);
            --card-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
            --hero-glow: rgba(13, 74, 43, 0.7);
        }

        /* ═══════════════════════════════════════════════════
           RESET & BASE
        ═══════════════════════════════════════════════════ */
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
            font-size: 16px;
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* Noise grain overlay */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.04'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 1000;
            opacity: 0.4;
        }

        /* ═══════════════════════════════════════════════════
           NAVIGATION
        ═══════════════════════════════════════════════════ */
        nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 2.5rem;
            height: 64px;
            background: var(--nav-bg);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
        }

        .nav-logo {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.6rem;
            letter-spacing: 0.1em;
            color: var(--neon);
            text-shadow: 0 0 20px var(--neon-glow);
            text-decoration: none;
        }

        .nav-logo span {
            color: var(--text-dim);
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .theme-toggle {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 1px solid var(--border-mid);
            background: var(--bg-card);
            color: var(--text-dim);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            font-size: 0.95rem;
            flex-shrink: 0;
        }

        .theme-toggle:hover {
            border-color: var(--neon);
            color: var(--neon);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 2rem;
            list-style: none;
        }

        .nav-links a {
            color: var(--text-dim);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            transition: color 0.2s;
        }

        .nav-links a:hover {
            color: var(--neon);
        }

        .nav-cta {
            background: transparent;
            border: 1.5px solid var(--neon);
            color: var(--neon) !important;
            padding: 0.45rem 1.25rem;
            border-radius: 4px;
            font-weight: 600 !important;
            transition: background 0.2s, box-shadow 0.2s !important;
        }

        .nav-cta:hover {
            background: rgba(57, 255, 143, 0.1) !important;
            box-shadow: 0 0 20px var(--neon-glow) !important;
        }

        /* ═══════════════════════════════════════════════════
           HERO
        ═══════════════════════════════════════════════════ */
        .hero {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 80px 2rem 4rem;
            position: relative;
            overflow: hidden;
        }

        /* Radial bg glow */
        .hero::after {
            content: '';
            position: absolute;
            top: 40%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 700px;
            height: 400px;
            background: radial-gradient(ellipse, var(--hero-glow) 0%, transparent 70%);
            pointer-events: none;
        }

        /* Billiard table felt decoration */
        .hero-felt {
            position: absolute;
            bottom: -80px;
            left: 50%;
            transform: translateX(-50%);
            width: 900px;
            height: 300px;
            border: 2px solid rgba(57, 255, 143, 0.08);
            border-radius: 48px;
            background: linear-gradient(180deg, rgba(13, 74, 43, 0.15) 0%, transparent 100%);
            pointer-events: none;
        }

        /* Pocket circles */
        .hero-felt::before,
        .hero-felt::after {
            content: '';
            position: absolute;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--bg);
            border: 1.5px solid rgba(57, 255, 143, 0.15);
        }

        .hero-felt::before {
            top: -14px;
            left: 40px;
        }

        .hero-felt::after {
            top: -14px;
            right: 40px;
        }

        .hero-eyebrow {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.75rem;
            letter-spacing: 0.25em;
            text-transform: uppercase;
            color: var(--neon);
            margin-bottom: 1.25rem;
            opacity: 0;
            animation: fadeUp 0.6s ease 0.1s forwards;
        }

        .hero-title {
            font-family: 'Bebas Neue', sans-serif;
            font-size: clamp(3.5rem, 10vw, 7.5rem);
            line-height: 0.92;
            letter-spacing: 0.03em;
            color: var(--text);
            margin-bottom: 0.5rem;
            opacity: 0;
            animation: fadeUp 0.7s ease 0.2s forwards;
        }

        .hero-title .accent {
            color: var(--neon);
            text-shadow:
                0 0 30px var(--neon-glow),
                0 0 60px rgba(57, 255, 143, 0.2);
        }

        .hero-subtitle {
            font-size: 1.1rem;
            color: var(--text-dim);
            max-width: 480px;
            margin: 1.5rem auto 2.5rem;
            font-weight: 300;
            opacity: 0;
            animation: fadeUp 0.7s ease 0.35s forwards;
        }

        .hero-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            opacity: 0;
            animation: fadeUp 0.7s ease 0.5s forwards;
        }

        .btn-primary {
            background: var(--neon);
            color: var(--bg);
            border: none;
            padding: 0.85rem 2rem;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            transition: box-shadow 0.2s, transform 0.15s;
        }

        .btn-primary:hover {
            box-shadow: 0 0 30px var(--neon-glow), 0 4px 20px rgba(0, 0, 0, 0.4);
            transform: translateY(-2px);
        }

        .btn-ghost {
            background: transparent;
            color: var(--text-dim);
            border: 1px solid var(--border-mid);
            padding: 0.85rem 2rem;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.95rem;
            font-weight: 500;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            border-radius: 6px;
            text-decoration: none;
            transition: border-color 0.2s, color 0.2s;
        }

        .btn-ghost:hover {
            border-color: var(--neon);
            color: var(--neon);
        }

        /* Scroll indicator */
        .scroll-hint {
            position: absolute;
            bottom: 2.5rem;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
            font-size: 0.7rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            opacity: 0;
            animation: fadeUp 1s ease 1s forwards;
        }

        .scroll-arrow {
            width: 1px;
            height: 40px;
            background: linear-gradient(to bottom, var(--neon), transparent);
            animation: scrollPulse 2s ease-in-out infinite;
        }

        /* ═══════════════════════════════════════════════════
           STATS BAR
        ═══════════════════════════════════════════════════ */
        .stats-bar {
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            background: var(--bg-card);
            padding: 1.5rem 2.5rem;
            display: flex;
            justify-content: center;
            gap: 4rem;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 2rem;
            color: var(--neon);
            line-height: 1;
            text-shadow: 0 0 15px var(--neon-glow);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            letter-spacing: 0.1em;
            text-transform: uppercase;
            margin-top: 0.25rem;
        }

        /* ═══════════════════════════════════════════════════
           SECTION LAYOUT
        ═══════════════════════════════════════════════════ */
        section {
            padding: 6rem 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .section-label {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.7rem;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: var(--neon);
            margin-bottom: 0.75rem;
        }

        .section-title {
            font-family: 'Bebas Neue', sans-serif;
            font-size: clamp(2rem, 5vw, 3.5rem);
            letter-spacing: 0.04em;
            line-height: 1;
            color: var(--text);
            margin-bottom: 1rem;
        }

        .section-desc {
            color: var(--text-dim);
            font-size: 1rem;
            font-weight: 300;
            max-width: 560px;
        }

        /* ═══════════════════════════════════════════════════
           MEJA STATUS SECTION
        ═══════════════════════════════════════════════════ */
        #status-meja {
            background: linear-gradient(180deg, var(--bg) 0%, var(--bg-card) 100%);
        }

        .status-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            margin-bottom: 3rem;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .live-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.75rem;
            color: var(--neon);
            letter-spacing: 0.1em;
        }

        .live-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--neon);
            box-shadow: 0 0 8px var(--neon-glow);
            animation: livePulse 2s ease-in-out infinite;
        }

        /* ═══════════════════════════════════════════════════
           MEJA CARDS GRID
        ═══════════════════════════════════════════════════ */
        .meja-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.25rem;
        }

        .meja-card {
            box-shadow: var(--card-shadow);
            background: var(--bg-card2);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s, border-color 0.2s, box-shadow 0.2s;
        }

        .meja-card:hover {
            transform: translateY(-3px);
        }

        /* Status-based card styles */
        .meja-card.available {
            border-color: rgba(57, 255, 143, 0.2);
        }

        .meja-card.available:hover {
            border-color: var(--neon);
            box-shadow: 0 8px 32px rgba(57, 255, 143, 0.12), 0 0 0 1px rgba(57, 255, 143, 0.1);
        }

        .meja-card.available::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--neon), transparent);
        }

        .meja-card.occupied {
            border-color: rgba(255, 68, 68, 0.2);
        }

        .meja-card.occupied:hover {
            border-color: var(--red);
            box-shadow: 0 8px 32px rgba(255, 68, 68, 0.1);
        }

        .meja-card.occupied::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--red), transparent);
        }

        .meja-card.maintenance {
            border-color: rgba(255, 184, 48, 0.2);
            opacity: 0.7;
        }

        .meja-card.maintenance::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--amber), transparent);
        }

        /* Card felt texture */
        .meja-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at top left, rgba(57, 255, 143, 0.03) 0%, transparent 60%);
            pointer-events: none;
        }

        .meja-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
        }

        .meja-number {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 2.5rem;
            line-height: 1;
            color: var(--text);
            letter-spacing: 0.05em;
        }

        .status-pill {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.3rem 0.75rem;
            border-radius: 100px;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            font-family: 'JetBrains Mono', monospace;
        }

        .status-pill.available {
            background: rgba(57, 255, 143, 0.12);
            color: var(--neon);
            border: 1px solid rgba(57, 255, 143, 0.25);
        }

        .status-pill.occupied {
            background: rgba(255, 68, 68, 0.12);
            color: var(--red);
            border: 1px solid rgba(255, 68, 68, 0.25);
        }

        .status-pill.maintenance {
            background: rgba(255, 184, 48, 0.12);
            color: var(--amber);
            border: 1px solid rgba(255, 184, 48, 0.25);
        }

        .pill-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        .status-pill.available .pill-dot,
        .status-pill.occupied .pill-dot {
            animation: livePulse 2s ease-in-out infinite;
        }

        .meja-name {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 0.25rem;
        }

        .meja-desc {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 1.25rem;
        }

        /* Divider */
        .card-divider {
            height: 1px;
            background: var(--border);
            margin: 1.25rem 0;
        }

        /* Billing info block (hanya muncul jika occupied) */
        .billing-info {
            background: rgba(255, 68, 68, 0.06);
            border: 1px solid rgba(255, 68, 68, 0.15);
            border-radius: 8px;
            padding: 1rem;
        }

        .billing-info-label {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.65rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }

        .billing-time-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .billing-end-time {
            font-family: 'JetBrains Mono', monospace;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--red);
            text-shadow: 0 0 15px rgba(255, 68, 68, 0.4);
            letter-spacing: 0.05em;
        }

        .billing-end-label {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .billing-countdown {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.8rem;
            color: var(--amber);
        }

        .billing-countdown-icon {
            font-size: 0.85rem;
        }

        /* Progress bar waktu berjalan */
        .time-progress {
            margin-top: 0.75rem;
        }

        .time-progress-bar {
            height: 3px;
            background: rgba(255, 255, 255, 0.06);
            border-radius: 100px;
            overflow: hidden;
        }

        .time-progress-fill {
            height: 100%;
            border-radius: 100px;
            background: linear-gradient(90deg, var(--amber), var(--red));
            transition: width 1s linear;
            min-width: 4px;
        }

        .time-progress-labels {
            display: flex;
            justify-content: space-between;
            margin-top: 0.4rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.65rem;
            color: var(--text-muted);
        }

        /* Available state info */
        .available-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            background: rgba(57, 255, 143, 0.05);
            border: 1px solid rgba(57, 255, 143, 0.12);
            border-radius: 8px;
        }

        .available-icon {
            font-size: 1.25rem;
            opacity: 0.8;
        }

        .available-text {
            font-size: 0.82rem;
            color: var(--text-dim);
            line-height: 1.4;
        }

        .available-text strong {
            color: var(--neon);
            font-weight: 600;
        }

        /* Maintenance state info */
        .maintenance-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            background: rgba(255, 184, 48, 0.05);
            border: 1px solid rgba(255, 184, 48, 0.12);
            border-radius: 8px;
        }

        .maintenance-text {
            font-size: 0.82rem;
            color: var(--text-dim);
        }

        /* CTA di bawah card available */
        .card-book-btn {
            display: block;
            width: 100%;
            margin-top: 1rem;
            padding: 0.65rem;
            background: transparent;
            border: 1px solid rgba(57, 255, 143, 0.2);
            border-radius: 6px;
            color: var(--neon);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.82rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.2s, border-color 0.2s, box-shadow 0.2s;
        }

        .card-book-btn:hover {
            background: rgba(57, 255, 143, 0.1);
            border-color: var(--neon);
            box-shadow: 0 0 16px var(--neon-glow);
        }

        /* ═══════════════════════════════════════════════════
           LEGEND
        ═══════════════════════════════════════════════════ */
        .legend {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .legend-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .legend-dot.available {
            background: var(--neon);
            box-shadow: 0 0 6px var(--neon-glow);
        }

        .legend-dot.occupied {
            background: var(--red);
            box-shadow: 0 0 6px var(--red-glow);
        }

        .legend-dot.maintenance {
            background: var(--amber);
            box-shadow: 0 0 6px var(--amber-glow);
        }

        /* ═══════════════════════════════════════════════════
           PACKAGES SECTION
        ═══════════════════════════════════════════════════ */
        #paket {
            background: var(--bg-card);
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .packages-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 1rem;
            margin-top: 3rem;
        }

        .package-card {
            box-shadow: var(--card-shadow);
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1.5rem;
            transition: transform 0.2s, border-color 0.2s;
        }

        .package-card:hover {
            transform: translateY(-3px);
            border-color: var(--border-mid);
        }

        .package-card.featured {
            border-color: rgba(57, 255, 143, 0.35);
            background: linear-gradient(160deg, rgba(57, 255, 143, 0.05) 0%, var(--bg) 100%);
            position: relative;
        }

        .package-badge {
            position: absolute;
            top: -10px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--neon);
            color: var(--bg);
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            padding: 0.2rem 0.75rem;
            border-radius: 100px;
            font-family: 'JetBrains Mono', monospace;
        }

        .package-type {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.65rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }

        .package-name {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.6rem;
            letter-spacing: 0.05em;
            color: var(--text);
            margin-bottom: 0.75rem;
        }

        .package-price {
            font-family: 'JetBrains Mono', monospace;
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--neon);
            margin-bottom: 0.25rem;
        }

        .package-price-normal {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-decoration: line-through;
        }

        .package-desc {
            font-size: 0.8rem;
            color: var(--text-dim);
            margin-top: 0.75rem;
            line-height: 1.5;
        }

        /* ═══════════════════════════════════════════════════
           HOW IT WORKS
        ═══════════════════════════════════════════════════ */
        #cara-booking {
            background: var(--bg);
        }

        .steps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-top: 3rem;
            position: relative;
        }

        .steps-grid::before {
            content: '';
            position: absolute;
            top: 2rem;
            left: 10%;
            right: 10%;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-mid), transparent);
        }

        .step-card {
            box-shadow: var(--card-shadow);
            text-align: center;
            padding: 2rem 1.25rem;
            background: var(--bg-card2);
            border: 1px solid var(--border);
            border-radius: 10px;
        }

        .step-number {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 3rem;
            color: rgba(57, 255, 143, 0.15);
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .step-icon {
            font-size: 1.75rem;
            margin-bottom: 0.75rem;
            display: block;
        }

        .step-title {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--text);
            margin-bottom: 0.5rem;
        }

        .step-desc {
            font-size: 0.8rem;
            color: var(--text-muted);
            line-height: 1.5;
        }

        /* ═══════════════════════════════════════════════════
           CTA SECTION
        ═══════════════════════════════════════════════════ */
        #cta {
            text-align: center;
            background: linear-gradient(180deg, var(--bg-card) 0%, var(--bg) 100%);
            border-top: 1px solid var(--border);
        }

        .cta-title {
            font-family: 'Bebas Neue', sans-serif;
            font-size: clamp(2.5rem, 6vw, 5rem);
            letter-spacing: 0.04em;
            line-height: 1;
            margin-bottom: 1rem;
        }

        .cta-title .accent {
            color: var(--neon);
            text-shadow: 0 0 30px var(--neon-glow);
        }

        /* ═══════════════════════════════════════════════════
           FOOTER
        ═══════════════════════════════════════════════════ */
        footer {
            padding: 2rem 2.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .footer-logo {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.2rem;
            color: var(--neon);
            letter-spacing: 0.1em;
        }

        .footer-copy {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        /* ═══════════════════════════════════════════════════
           ANIMATIONS
        ═══════════════════════════════════════════════════ */
        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes livePulse {

            0%,
            100% {
                opacity: 1;
                transform: scale(1);
            }

            50% {
                opacity: 0.5;
                transform: scale(0.8);
            }
        }

        @keyframes scrollPulse {

            0%,
            100% {
                transform: scaleY(1);
                opacity: 1;
            }

            50% {
                transform: scaleY(0.6);
                opacity: 0.4;
            }
        }

        /* Refresh flash (dipicu saat data di-refresh) */
        .refresh-flash {
            animation: flashBorder 0.5s ease;
        }

        @keyframes flashBorder {
            0% {
                box-shadow: 0 0 0 0 rgba(57, 255, 143, 0.0);
            }

            50% {
                box-shadow: 0 0 0 3px rgba(57, 255, 143, 0.3);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(57, 255, 143, 0.0);
            }
        }

        /* ═══════════════════════════════════════════════════
           RESPONSIVE
        ═══════════════════════════════════════════════════ */
        @media (max-width: 768px) {
            nav {
                padding: 0 1.25rem;
            }

            .nav-actions {
                gap: 0.5rem;
            }

            .nav-links li:not(:last-child) {
                display: none;
            }

            .nav-links {
                display: flex;
            }

            .stats-bar {
                gap: 2rem;
                flex-wrap: wrap;
                padding: 1.5rem;
            }

            .status-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .hero-felt {
                display: none;
            }

            .steps-grid::before {
                display: none;
            }
        }
    </style>
</head>

<body>

    {{-- ══════════════════════════════════════════════════
     NAVIGATION
══════════════════════════════════════════════════ --}}
    <nav>
        <a href="/" class="nav-logo">BILLIARD<span>HALL</span></a>
        <div class="nav-actions">
            <ul class="nav-links">
                <li><a href="#status-meja">Status Meja</a></li>
                <li><a href="#paket">Paket</a></li>
                <li><a href="#cara-booking">Cara Booking</a></li>
                @auth
                    @role('member')
                        <li><a href="{{ route('member.booking.index') }}" class="nav-cta">Booking Saya</a></li>
                    @endrole
                    @role('owner')
                        <li><a href="{{ route('owner.dashboard') }}" class="nav-cta">Dashboard</a></li>
                    @endrole
                    @role('kasir')
                        <li><a href="{{ route('kasir.dashboard') }}" class="nav-cta">Dashboard Kasir</a></li>
                    @endrole
                @else
                    <li><a href="{{ route('login') }}" class="nav-cta">Login / Booking</a></li>
                @endauth
            </ul>
            <button type="button" class="theme-toggle" id="themeToggle" aria-label="Ganti tema" title="Ganti tema">🌙</button>
        </div>
    </nav>

    {{-- ══════════════════════════════════════════════════
     HERO
══════════════════════════════════════════════════ --}}
    <section class="hero">
        <div class="hero-eyebrow">⬤ Open Every Day — 10:00 — 24:00</div>
        <h1 class="hero-title">
            POTONG<br>
            <span class="accent">BOLA.</span><br>
            BOOK MEJA.
        </h1>
        <p class="hero-subtitle">
            Cek ketersediaan meja secara realtime, pilih paket terbaik,
            dan nikmati pengalaman billiard tanpa antrian panjang.
        </p>
        <div class="hero-actions">
            @auth
                @role('member')
                    <a href="{{ route('member.booking.create') }}" class="btn-primary">Booking Sekarang</a>
                @endrole
            @else
                <a href="{{ route('login') }}" class="btn-primary">Booking Sekarang</a>
            @endauth
            <a href="#status-meja" class="btn-ghost">Cek Status Meja</a>
        </div>
        <div class="hero-felt"></div>
        <div class="scroll-hint">
            <div class="scroll-arrow"></div>
            Scroll
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════
     STATS BAR
══════════════════════════════════════════════════ --}}
    <div class="stats-bar">
        <div class="stat-item">
            <div class="stat-number">{{ $totalMeja }}</div>
            <div class="stat-label">Total Meja</div>
        </div>
        <div class="stat-item">
            <div class="stat-number" style="color: var(--neon)">{{ $mejaAvailable }}</div>
            <div class="stat-label">Tersedia</div>
        </div>
        <div class="stat-item">
            <div class="stat-number" style="color: var(--red)">{{ $mejaOccupied }}</div>
            <div class="stat-label">Sedang Dipakai</div>
        </div>
        <div class="stat-item">
            <div class="stat-number" style="color: var(--amber)">{{ $activeMember }}</div>
            <div class="stat-label">Member Aktif</div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════
     STATUS MEJA — Livewire Component
══════════════════════════════════════════════════ --}}
    <section id="status-meja">
        <div class="container">
            <div class="status-header">
                <div>
                    <div class="section-label">// realtime status</div>
                    <h2 class="section-title">STATUS MEJA SAAT INI</h2>
                    <p class="section-desc">
                        Diperbarui otomatis setiap 10 detik.
                        Pilih meja tersedia dan segera booking.
                    </p>
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.75rem; align-items: flex-end;">
                    <div class="live-indicator">
                        <span class="live-dot"></span>
                        LIVE UPDATE
                    </div>
                    <div class="legend">
                        <div class="legend-item"><span class="legend-dot available"></span> Tersedia</div>
                        <div class="legend-item"><span class="legend-dot occupied"></span> Terpakai</div>
                        <div class="legend-item"><span class="legend-dot maintenance"></span> Maintenance</div>
                    </div>
                </div>
            </div>

            {{-- Livewire component untuk status meja --}}
            <livewire:table-status-public />
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════
     PAKET
══════════════════════════════════════════════════ --}}
    <section id="paket">
        <div class="container">
            <div class="section-label">// pilih sesuai gaya main</div>
            <h2 class="section-title">PAKET BERMAIN</h2>
            <p class="section-desc">Hemat lebih banyak dengan paket bundled kami, atau bebas main tanpa batas dengan
                paket loss.</p>

            <div class="packages-grid">
                @foreach ($packages as $pkg)
                    <div class="package-card {{ $loop->iteration === 2 ? 'featured' : '' }}">
                        @if ($loop->iteration === 2)
                            <span class="package-badge">Favorit</span>
                        @endif
                        <div class="package-type">
                            {{ $pkg->type === 'normal' ? 'Paket Durasi Fix' : 'Paket Loss' }}
                        </div>
                        <div class="package-name">{{ $pkg->name }}</div>
                        @if ($pkg->isNormal())
                            <div class="package-price">Rp {{ number_format($pkg->price, 0, ',', '.') }}</div>
                            @php $normalPrice = $pkg->pricing ? $pkg->duration_hours * $pkg->pricing->price_per_hour : null; @endphp
                            @if ($normalPrice && $normalPrice > $pkg->price)
                                <div class="package-price-normal">Normal: Rp
                                    {{ number_format($normalPrice, 0, ',', '.') }}</div>
                            @endif
                        @else
                            <div class="package-price">
                                Rp {{ number_format($pkg->pricing?->price_per_hour ?? 0, 0, ',', '.') }}/jam
                            </div>
                            <div class="package-price-normal">Dihitung di akhir sesi</div>
                        @endif
                        <p class="package-desc">{{ $pkg->description }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════
     CARA BOOKING
══════════════════════════════════════════════════ --}}
    <section id="cara-booking">
        <div class="container">
            <div class="section-label">// 4 langkah mudah</div>
            <h2 class="section-title">CARA BOOKING</h2>
            <p class="section-desc">Dari daftar akun sampai meja siap dimainkan, cukup dalam hitungan menit.</p>

            <div class="steps-grid">
                <div class="step-card">
                    <div class="step-number">01</div>
                    <span class="step-icon">👤</span>
                    <div class="step-title">Daftar / Login</div>
                    <div class="step-desc">Buat akun gratis atau login jika sudah punya. Tamu bisa melihat status meja
                        tanpa login.</div>
                </div>
                <div class="step-card">
                    <div class="step-number">02</div>
                    <span class="step-icon">🎱</span>
                    <div class="step-title">Pilih Meja & Waktu</div>
                    <div class="step-desc">Pilih meja favorit, tentukan tanggal dan jam mulai bermain sesuai jadwal.
                    </div>
                </div>
                <div class="step-card">
                    <div class="step-number">03</div>
                    <span class="step-icon">📦</span>
                    <div class="step-title">Pilih Paket</div>
                    <div class="step-desc">Hemat dengan paket bundled atau pilih paket loss untuk main bebas tanpa
                        batas waktu tetap.</div>
                </div>
                <div class="step-card">
                    <div class="step-number">04</div>
                    <span class="step-icon">🎯</span>
                    <div class="step-title">Konfirmasi & Main</div>
                    <div class="step-desc">Tunggu konfirmasi kasir, datang tepat waktu, dan meja sudah siap untuk Anda
                        mainkan.</div>
                </div>
            </div>
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════
     CTA
══════════════════════════════════════════════════ --}}
    <section id="cta">
        <div class="container">
            <p class="section-label" style="text-align:center">// jangan tunggu lama</p>
            <h2 class="cta-title">
                MEJA <span class="accent">TERSEDIA</span><br>
                SEKARANG JUGA
            </h2>
            <p style="color: var(--text-dim); margin: 1rem auto 2.5rem; max-width: 400px; font-size: 1rem;">
                Segera booking sebelum diambil orang lain. Bayar di tempat, mudah dan cepat.
            </p>
            @auth
                @role('member')
                    <a href="{{ route('member.booking.create') }}" class="btn-primary">Booking Meja →</a>
                @endrole
            @else
                <a href="{{ route('login') }}" class="btn-primary">Mulai Booking →</a>
            @endauth
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════
     FOOTER
══════════════════════════════════════════════════ --}}
    <footer>
        <div class="footer-logo">BILLIARD HALL</div>
        <div class="footer-copy">
            &copy; {{ date('Y') }} Billiard Hall. Open 10:00 – 24:00 every day.
        </div>
    </footer>

    @livewireScripts

    <script>
        (function() {
            var btn = document.getElementById('themeToggle');
            function apply(t) {
                document.documentElement.setAttribute('data-theme', t);
                try { localStorage.setItem('theme', t); } catch (e) {}
                if (btn) {
                    btn.textContent = t === 'dark' ? '☀️' : '🌙';
                    btn.title = t === 'dark' ? 'Mode terang' : 'Mode gelap';
                }
            }
            try { apply(localStorage.getItem('theme') || 'light'); } catch (e) { apply('light'); }
            if (btn) {
                btn.addEventListener('click', function() {
                    var cur = document.documentElement.getAttribute('data-theme') || 'light';
                    apply(cur === 'dark' ? 'light' : 'dark');
                });
            }
        })();

        // Smooth scroll untuk nav links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            });
        });
    </script>

</body>

</html>
