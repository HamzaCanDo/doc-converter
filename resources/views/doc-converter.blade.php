<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Doc Converter</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any" />
    <link rel="icon" href="{{ asset('assets/favicons/favicon.png') }}" type="image/png" />

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;500;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet" />

    <style>
        :root {
            --bg-1: #ffffff;
            --bg-2: #ffffff;
            --accent: #ff7a59;
            --accent-2: #52b6df;
            --ink: #1b1b1b;
            --muted: #5b6570;
            --card: #ffffff;
            --card-border: #e7eaee;
            --card-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
            --surface: #f7f8fa;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Sora", sans-serif;
            color: var(--ink);
            background: linear-gradient(180deg, #ffffff 0%, #fbfbfd 100%);
            display: grid;
            place-items: center;
            padding: 32px 16px 56px;
        }

        .shell {
            width: min(980px, 100%);
            display: grid;
            gap: 28px;
            animation: fadeUp 700ms ease-out;
        }

        .hero {
            display: grid;
            gap: 12px;
            align-items: flex-start;
            max-width: 720px;
        }

        .hero-logo {
            width: min(250px, 65vw);
            height: auto;
        }

        .title {
            font-size: clamp(28px, 3vw, 40px);
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .subtitle {
            color: var(--muted);
            font-size: 16px;
            max-width: 60ch;
            line-height: 1.6;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            padding: 22px;
            box-shadow: var(--card-shadow);
        }

        .input-row {
            display: grid;
            gap: 12px;
        }

        label {
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            font-weight: 600;
            color: var(--muted);
        }

        input[type="url"] {
            width: 100%;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid #d7dce2;
            background: var(--surface);
            color: var(--ink);
            font-size: 16px;
            transition: border-color 160ms ease, box-shadow 160ms ease;
        }

        input[type="url"]:focus {
            outline: none;
            border-color: #ff9a7c;
            box-shadow: 0 0 0 3px rgba(255, 122, 89, 0.18);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .btn {
            appearance: none;
            border: none;
            padding: 12px 18px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--accent), #ffb347);
            color: #1b1b1b;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: transform 160ms ease, box-shadow 160ms ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 12px 24px rgba(255, 122, 89, 0.25);
        }

        .btn.secondary {
            background: linear-gradient(135deg, var(--accent-2), #67f4d2);
            box-shadow: 0 12px 24px rgba(82, 182, 223, 0.22);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.28);
        }

        .btn:active {
            transform: translateY(0);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        }

        .error {
            color: #c91a1a;
            font-size: 14px;
            margin-top: 8px;
        }

        .downloads {
            display: grid;
            gap: 12px;
        }

        .download-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
        }

        .meta {
            font-family: "Space Mono", monospace;
            color: var(--muted);
            font-size: 12px;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 640px) {
            .card { padding: 16px; }
            .actions { flex-direction: column; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="hero">
            <img class="hero-logo" src="{{ asset('assets/brand/headerlogo.png') }}" alt="Doc Converter logo" />
            <div class="subtitle">
                Paste a public, view-only Doc link and stream PDF, DOCX, ODT, XLSX, HTML, Markdown, and JSON.
            </div>
        </section>

        <section class="card">
            <form method="POST" action="{{ route('doc.convert') }}">
                @csrf

                <div class="input-row">
                    <label for="url">Google Doc URL</label>
                    <input
                        id="url"
                        name="url"
                        type="url"
                        required
                        placeholder="https://docs.google.com/document/d/..."
                        value="{{ old('url') }}"
                    />
                </div>

                @error('url')
                    <div class="error">{{ $message }}</div>
                @enderror

                <div class="actions" style="margin-top: 16px;">
                    <button class="btn" type="submit">Process</button>
                </div>
            </form>
        </section>

        @if (!empty($downloads))
            <section class="card downloads">
                <div class="title" style="font-size: 20px;">Downloads</div>
                <div class="download-grid">
                    @foreach ($downloads as $download)
                        <a class="btn secondary" href="{{ $download['url'] }}">
                            {{ $download['label'] }}
                        </a>
                    @endforeach
                </div>

                @if (!empty($fileId))
                    <div class="meta">File ID: {{ $fileId }}</div>
                @endif
            </section>
        @endif
    </main>
</body>
</html>
