<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Booking' }} — Billiard Hall</title>
    <script>(function(){try{var t=localStorage.getItem('theme')||'light';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">

    {{-- Bootstrap 5 CSS --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    {{-- Font Awesome --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    {{-- SweetAlert2 --}}
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    @livewireStyles

    <style>
        :root {
            --felt: #0d4a2b;
            --neon: #0e8a3e;
            --neon-dim: #0a6b30;
            --neon-glow: rgba(14, 138, 62, 0.14);
            --amber: #b77900;
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
        }

        [data-theme="dark"] {
            --felt: #0d4a2b;
            --neon: #39ff8f;
            --neon-dim: #22cc68;
            --neon-glow: rgba(57, 255, 143, 0.35);
            --amber: #ffb830;
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
            --nav-bg: rgba(8, 14, 10, 0.9);
            --card-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
            min-height: 100vh;
        }

        /* Noise */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.04'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 1000;
            opacity: 0.4;
        }

        /* Nav */
        nav {
            position: sticky;
            top: 0;
            z-index: 500;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 2.5rem;
            height: 60px;
            background: var(--nav-bg);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
        }

        .nav-logo {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.4rem;
            letter-spacing: .1em;
            color: var(--neon);
            text-decoration: none;
        }

        .nav-logo span { color: var(--text-dim); }

        .nav-user {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: .875rem;
        }

        .nav-user a {
            color: var(--text-dim);
            text-decoration: none;
            transition: color .2s;
        }
        .nav-user a:hover { color: var(--neon); }

        .nav-badge {
            background: rgba(57,255,143,.1);
            border: 1px solid var(--border-mid);
            color: var(--neon);
            padding: .25rem .75rem;
            border-radius: 100px;
            font-size: .75rem;
            font-family: 'JetBrains Mono', monospace;
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
            font-size: .95rem;
            flex-shrink: 0;
            transition: all .2s;
        }
        .theme-toggle:hover {
            border-color: var(--neon);
            color: var(--neon);
        }

        .booking-card, .table-option-card, .package-option-card, .booking-history-card {
            box-shadow: var(--card-shadow);
        }
        .booking-wrapper .text-white:not(.badge):not(.btn) {
            color: var(--text) !important;
        }
        [data-theme="dark"] .booking-wrapper .text-white:not(.badge):not(.btn) {
            color: #e8f5ed !important;
        }
        .booking-card-header h5 {
            color: var(--text);
        }
        .form-input-custom::placeholder {
            color: var(--text-muted);
        }
        html {
            transition: background .2s, color .2s;
        }
        .table-card-disabled {
            opacity: .55 !important;
        }

        /* Wrapper */
        .booking-wrapper {
            max-width: 860px;
            margin: 0 auto;
            padding: 2.5rem 1.25rem 4rem;
        }

        /* Progress */
        .booking-progress { padding-bottom: .5rem; }

        .progress-line {
            position: absolute;
            top: 20px;
            left: 12%;
            right: 12%;
            height: 1px;
            background: var(--border);
            z-index: 0;
        }

        .step-indicator {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: .4rem;
            position: relative;
            z-index: 1;
        }

        .step-bubble {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'JetBrains Mono', monospace;
            font-size: .85rem;
            font-weight: 700;
            background: var(--bg-card);
            border: 1.5px solid var(--border);
            color: var(--text-muted);
            transition: all .3s;
        }

        .step-indicator.active .step-bubble,
        .step-indicator.done .step-bubble {
            background: rgba(57,255,143,.12);
            border-color: var(--neon);
            color: var(--neon);
        }

        .step-label {
            font-size: .7rem;
            letter-spacing: .05em;
            color: var(--text-muted);
            text-transform: uppercase;
            font-family: 'JetBrains Mono', monospace;
        }

        .step-indicator.active .step-label { color: var(--neon); }

        /* Cards */
        .booking-card {
            background: var(--bg-card2);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
        }

        .booking-card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: .5rem;
        }

        .booking-card-body { padding: 1.5rem; }

        /* Table option */
        .table-option-card {
            background: var(--bg-card);
            border: 1.5px solid var(--border);
            border-radius: 10px;
            padding: 1.25rem;
            transition: border-color .2s, box-shadow .2s, transform .15s;
        }

        .table-option-card:hover {
            border-color: var(--border-mid);
            transform: translateY(-2px);
        }

        .table-option-card.selected {
            border-color: var(--neon);
            box-shadow: 0 0 20px var(--neon-glow);
        }

        .table-number-badge {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.6rem;
            color: var(--text);
            letter-spacing: .05em;
            line-height: 1;
        }

        .status-available {
            font-family: 'JetBrains Mono', monospace;
            font-size: .65rem;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--neon);
            background: rgba(57,255,143,.1);
            border: 1px solid rgba(57,255,143,.2);
            padding: .2rem .6rem;
            border-radius: 100px;
        }

        /* Form inputs */
        .form-label-custom {
            display: block;
            margin-bottom: .4rem;
            font-size: .85rem;
            color: var(--text-dim);
            font-weight: 500;
        }

        .form-input-custom {
            width: 100%;
            background: var(--bg-card);
            border: 1.5px solid var(--border);
            border-radius: 8px;
            padding: .7rem 1rem;
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
            font-size: .9rem;
            transition: border-color .2s;
            outline: none;
        }

        .form-input-custom:focus { border-color: var(--neon); }
        .form-input-custom.is-error { border-color: var(--red); }
        .error-msg { display:block; color: var(--red); font-size:.8rem; margin-top:.3rem; }

        /* Package option */
        .package-option-card {
            background: var(--bg-card);
            border: 1.5px solid var(--border);
            border-radius: 10px;
            padding: 1.25rem;
            transition: border-color .2s, transform .15s;
        }

        .package-option-card:hover {
            border-color: var(--neon);
            transform: translateY(-2px);
        }

        .pkg-type-badge {
            font-family: 'JetBrains Mono', monospace;
            font-size: .65rem;
            letter-spacing: .1em;
            text-transform: uppercase;
            padding: .2rem .6rem;
            border-radius: 100px;
        }

        .pkg-type-badge.normal {
            background: rgba(57,255,143,.1);
            color: var(--neon);
            border: 1px solid rgba(57,255,143,.2);
        }

        .pkg-type-badge.loss {
            background: rgba(255,184,48,.1);
            color: var(--amber);
            border: 1px solid rgba(255,184,48,.2);
        }

        .pkg-name  { font-family: 'Bebas Neue', sans-serif; font-size: 1.3rem; margin-top: .5rem; }
        .pkg-price { font-family: 'JetBrains Mono', monospace; font-size: 1.1rem; color: var(--neon); margin-top: .25rem; }
        .pkg-duration { font-size: .8rem; color: var(--text-muted); margin-top: .2rem; }
        .pkg-desc  { font-size: .8rem; color: var(--text-dim); margin-top: .5rem; line-height: 1.5; }

        .skip-package-card {
            background: var(--bg-card);
            border: 1.5px dashed var(--border-mid);
            border-radius: 10px;
            padding: 1rem 1.25rem;
            color: var(--text-muted);
            font-size: .875rem;
            text-align: center;
            transition: border-color .2s, color .2s;
        }

        .skip-package-card:hover {
            border-color: var(--border);
            color: var(--text-dim);
        }

        /* Confirm */
        .confirm-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 580px) { .confirm-grid { grid-template-columns: 1fr; } }

        .confirm-item {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: .875rem 1rem;
        }

        .confirm-label { font-size: .7rem; letter-spacing: .1em; text-transform: uppercase; color: var(--text-muted); margin-bottom: .25rem; font-family: 'JetBrains Mono', monospace; }
        .confirm-value { font-size: .95rem; font-weight: 500; color: var(--text); }

        .alert-info-booking {
            background: rgba(57,255,143,.06);
            border: 1px solid var(--border-mid);
            border-radius: 8px;
            padding: .875rem 1rem;
            font-size: .85rem;
            color: var(--text-dim);
        }

        /* History */
        .booking-history-card {
            background: var(--bg-card2);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            transition: border-color .2s;
        }

        .booking-history-card:hover { border-color: var(--border-mid); }

        .bk-code { font-family: 'JetBrains Mono', monospace; font-size: .95rem; color: var(--neon); }
        .bk-date { font-size: .82rem; color: var(--text-dim); margin-top: .2rem; }

        .bk-status-badge {
            font-family: 'JetBrains Mono', monospace;
            font-size: .7rem;
            letter-spacing: .1em;
            text-transform: uppercase;
            padding: .2rem .75rem;
            border-radius: 100px;
            border: 1px solid currentColor;
        }

        .bk-meta-label { font-size: .7rem; color: var(--text-muted); letter-spacing: .05em; text-transform: uppercase; }
        .bk-meta-value { font-size: .88rem; color: var(--text-dim); margin-top: .1rem; }

        .bk-reject-reason {
            background: rgba(255,68,68,.06);
            border: 1px solid rgba(255,68,68,.15);
            border-radius: 6px;
            padding: .6rem .875rem;
            font-size: .82rem;
            color: #ffaaaa;
        }

        /* Filter chips */
        .filter-chip {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-muted);
            border-radius: 100px;
            padding: .3rem .9rem;
            font-size: .8rem;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            transition: all .2s;
        }

        .filter-chip:hover { border-color: var(--border-mid); color: var(--text-dim); }
        .filter-chip.active { border-color: var(--neon); color: var(--neon); background: rgba(57,255,143,.08); }

        /* Buttons */
        .btn-booking-next, .btn-booking-submit {
            background: var(--neon);
            color: var(--bg);
            border: none;
            padding: .7rem 1.5rem;
            border-radius: 6px;
            font-weight: 700;
            font-size: .875rem;
            letter-spacing: .05em;
            text-transform: uppercase;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: box-shadow .2s, transform .15s;
        }

        .btn-booking-next:hover, .btn-booking-submit:hover {
            box-shadow: 0 0 20px var(--neon-glow);
            transform: translateY(-1px);
            color: var(--bg);
        }

        .btn-booking-back {
            background: transparent;
            border: 1px solid var(--border-mid);
            color: var(--text-dim);
            padding: .7rem 1.25rem;
            border-radius: 6px;
            font-size: .875rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            transition: border-color .2s, color .2s;
            font-family: 'DM Sans', sans-serif;
        }

        .btn-booking-back:hover { border-color: var(--neon); color: var(--neon); }

        /* Flash */
        .flash-success {
            background: rgba(57,255,143,.1);
            border: 1px solid rgba(57,255,143,.25);
            border-radius: 8px;
            padding: .875rem 1rem;
            color: var(--neon);
            font-size: .875rem;
            margin-bottom: 1.5rem;
        }
    </style>
</head>

<body>
    <nav>
        <a href="/" class="nav-logo">BILLIARD<span>HALL</span></a>
        <div class="nav-user">
            <a href="{{ route('landing') }}">← Beranda</a>
            <span class="nav-badge">{{ auth()->user()?->name }}</span>
            <button type="button" class="theme-toggle" id="themeToggleMb" aria-label="Ganti tema" title="Ganti tema">🌙</button>
            <form action="{{ route('logout') }}" method="POST" style="display:inline;">
                @csrf
                <button type="submit" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:.875rem;" onmouseover="this.style.color='var(--red)'" onmouseout="this.style.color='var(--text-muted)'">Logout</button>
            </form>
        </div>
    </nav>

    <div class="booking-wrapper">
        {{-- Flash messages --}}
        @if (session('success'))
            <div class="flash-success">
                <i class="fa-solid fa-circle-check me-2"></i>{{ session('success') }}
            </div>
        @endif

        {{ $slot }}
    </div>

    @livewireScripts

    <script>
        let defaultSwal;
        function updateSwalTheme(theme) {
            defaultSwal = Swal.mixin({
                background: theme === 'dark' ? '#0e1a12' : '#ffffff',
                color: theme === 'dark' ? '#e8f5ed' : '#0f1f14',
            });
        }

        (function() {
            var btn = document.getElementById('themeToggleMb');
            function curTheme() {
                return document.documentElement.getAttribute('data-theme') || 'light';
            }
            function apply(t) {
                document.documentElement.setAttribute('data-theme', t);
                try { localStorage.setItem('theme', t); } catch (e) {}
                if (btn) {
                    btn.textContent = t === 'dark' ? '☀️' : '🌙';
                    btn.title = t === 'dark' ? 'Mode terang' : 'Mode gelap';
                }
                updateSwalTheme(t);
            }
            var initial = 'light';
            try { initial = localStorage.getItem('theme') || 'light'; } catch (e) {}
            apply(initial);
            if (btn) {
                btn.addEventListener('click', function() {
                    apply(curTheme() === 'dark' ? 'light' : 'dark');
                });
            }
        })();
    </script>
    {{-- Bootstrap 5 JS --}}
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
