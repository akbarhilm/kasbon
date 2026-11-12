@php
    $loggedIn = auth()->check();
    $href = $loggedIn ? $appUrl : $loginUrl;
    $label = $loggedIn ? 'Buka aplikasi' : 'Masuk';
    $name = config('app.name');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $name }}</title>
    <meta name="description" content="Catat pinjaman, pantau jatuh tempo, dan kelola saldo seluruh cabang dalam satu tempat.">
    <style>
        :root {
            --bg: #F4F6F8; --surface: #FFFFFF; --ink: #1B2430; --muted: #4A5565;
            --brand: #0F6E5C; --on-brand: #FFFFFF; --border: #D9DEE5;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0F1720; --surface: #17212C; --ink: #E8EDF2; --muted: #A9B4C0;
                --brand: #3FB6A0; --on-brand: #06201B; --border: #2A3644;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.6; }
        .wrap { max-width: 1040px; margin: 0 auto; padding: 0 24px; }
        header .wrap { display: flex; align-items: center; justify-content: space-between; height: 68px; }
        .brand { font-weight: 700; font-size: 18px; color: var(--brand); }
        .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 0 28px; border-radius: 10px; background: var(--brand); color: var(--on-brand); font-weight: 600; font-size: 16px; text-decoration: none; }
        .btn.small { min-height: 44px; padding: 0 20px; font-size: 15px; }
        .btn:focus-visible { outline: 3px solid var(--ink); outline-offset: 3px; }
        .hero { padding: 72px 0 56px; }
        h1 { margin: 0 0 16px; font-size: clamp(30px, 5vw, 46px); line-height: 1.15; max-width: 16em; }
        .lead { margin: 0 0 32px; max-width: 36em; font-size: 18px; color: var(--muted); }
        .note { margin: 16px 0 0; font-size: 14px; color: var(--muted); }
        h2 { margin: 0 0 20px; font-size: 22px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 20px; }
        .card h3 { margin: 0 0 8px; font-size: 17px; }
        .card p { margin: 0; font-size: 15px; color: var(--muted); }
        .features { padding-bottom: 64px; }
        footer { border-top: 1px solid var(--border); padding: 24px 0; font-size: 14px; color: var(--muted); }
    </style>
</head>
<body>
    <header>
        <div class="wrap">
            <span class="brand">{{ $name }}</span>
            <a class="btn small" href="{{ $href }}">{{ $label }}</a>
        </div>
    </header>

    <main class="wrap">
        <section class="hero">
            <h1>Pinjaman seluruh cabang, dalam satu tempat.</h1>
            <p class="lead">Catat pinjaman, pantau jatuh tempo dan keterlambatan, serta kelola saldo kas dan administrasi setiap cabang.</p>
            <a class="btn" href="{{ $href }}">{{ $label }}</a>
            <p class="note">Akses hanya untuk pengguna terdaftar. Lupa password? Hubungi super admin.</p>
        </section>

        <section class="features" aria-labelledby="fitur">
            <h2 id="fitur">Yang bisa Anda lakukan</h2>
            <div class="grid">
                <article class="card">
                    <h3>Pinjaman dan jatuh tempo</h3>
                    <p>Tenor hingga 3 bulan, jatuh tempo dihitung otomatis, dan daftar peminjam yang belum bayar selalu terlihat.</p>
                </article>
                <article class="card">
                    <h3>Saldo kas dan administrasi</h3>
                    <p>Buku kas per cabang dengan penambahan modal dan penarikan dari pos saldo kas atau administrasi.</p>
                </article>
                <article class="card">
                    <h3>Dashboard dan konsolidasi</h3>
                    <p>Ringkasan setiap cabang, dan tampilan konsolidasi seluruh cabang untuk super admin.</p>
                </article>
                <article class="card">
                    <h3>Laporan dan audit</h3>
                    <p>Rekening koran dengan export Excel dan cetak, serta jejak audit atas perubahan penting.</p>
                </article>
            </div>
        </section>
    </main>

    <footer>
        <div class="wrap">&copy; {{ date('Y') }} {{ $name }}</div>
    </footer>
</body>
</html>
