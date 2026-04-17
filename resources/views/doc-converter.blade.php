<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>DocFlip</title>
    <link rel="icon" href="{{ asset('assets/global-logos/favicon/favicon.ico') }}" sizes="any" />
    <link rel="shortcut icon" href="{{ asset('assets/global-logos/favicon/favicon.ico') }}" />
    <link rel="icon" href="{{ asset('assets/global-logos/favicon/favicon-16x16.png') }}" sizes="16x16" type="image/png" />
    <link rel="icon" href="{{ asset('assets/global-logos/favicon/favicon-32x32.png') }}" sizes="32x32" type="image/png" />
    <link rel="icon" href="{{ asset('assets/global-logos/favicon/favicon-48x48.png') }}" sizes="48x48" type="image/png" />
    <link rel="apple-touch-icon" href="{{ asset('assets/global-logos/icon/icon-180x180.png') }}" sizes="180x180" />
    <link rel="icon" href="{{ asset('assets/global-logos/icon/icon-64x64.png') }}" sizes="64x64" type="image/png" />
    <link rel="icon" href="{{ asset('assets/global-logos/icon/icon-128x128.png') }}" sizes="128x128" type="image/png" />
    <link rel="icon" href="{{ asset('assets/global-logos/icon/icon-192x192.png') }}" sizes="192x192" type="image/png" />
    <link rel="manifest" href="{{ asset('assets/global-logos/site.webmanifest') }}" />
    <meta name="theme-color" content="#ffffff" />
    <link rel="preload" as="image" href="{{ asset('assets/global-logos/header/header-280x84.png') }}" fetchpriority="high" />

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=Space+Mono:wght@400&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="{{ asset('assets/docflip/doc-converter.css') }}" />
</head>
<body>
    <main class="shell">
        <section class="hero">
            <picture>
                <source
                    srcset="
                        {{ asset('assets/global-logos/header/header-120x36.png') }} 120w,
                        {{ asset('assets/global-logos/header/header-160x48.png') }} 160w,
                        {{ asset('assets/global-logos/header/header-180x54.png') }} 180w,
                        {{ asset('assets/global-logos/header/header-220x66.png') }} 220w,
                        {{ asset('assets/global-logos/header/header-240x72.png') }} 240w,
                        {{ asset('assets/global-logos/header/header-280x84.png') }} 280w,
                        {{ asset('assets/global-logos/hero/hero-600x180.png') }} 600w,
                        {{ asset('assets/global-logos/hero/hero-640x192.png') }} 640w
                    "
                    sizes="(max-width: 640px) 180px, 280px"
                />
                <img
                    class="hero-logo"
                    src="{{ asset('assets/global-logos/header/header-280x84.png') }}"
                    alt="DocFlip logo"
                    width="280"
                    height="84"
                    loading="eager"
                    decoding="async"
                    fetchpriority="high"
                />
            </picture>
            <div class="subtitle">
                Paste a public Doc link or upload DOCX, PDF, or XLSX to stream fast conversions.
            </div>
        </section>

        <section class="card">
            <form id="convert-form" method="POST" action="{{ route('doc.convert') }}" enctype="multipart/form-data">
                @csrf

                <div class="input-row">
                    <label for="url">Google Doc URL</label>
                    <input
                        id="url"
                        name="url"
                        type="url"
                        placeholder="https://docs.google.com/document/d/..."
                        value="{{ old('url') }}"
                    />
                </div>

                <div class="divider">OR</div>

                <div class="input-row">
                    <label for="file">Upload File (DOCX, PDF, XLSX)</label>
                    <input id="file" name="file" type="file" accept=".docx,.pdf,.xlsx" />
                    <div class="helper">Max 40 MB. After processing, a secure download button will appear.</div>
                </div>

                <div class="input-row">
                    <label for="upload_format">Output Format (Uploads)</label>
                    <select id="upload_format" name="upload_format">
                        <option value="" disabled @selected(old('upload_format') === null)>Choose output format</option>
                        <option value="pdf" @selected(old('upload_format') === 'pdf')>PDF</option>
                        <option value="xlsx" @selected(old('upload_format') === 'xlsx')>XLSX</option>
                        <option value="json" @selected(old('upload_format') === 'json')>JSON</option>
                        <option value="md" @selected(old('upload_format') === 'md')>Markdown</option>
                    </select>
                    <div class="helper">Availability depends on file type.</div>
                </div>

                @error('url')
                    <div class="error">{{ $message }}</div>
                @enderror

                @error('file')
                    <div class="error">{{ $message }}</div>
                @enderror

                @error('upload_format')
                    <div class="error">{{ $message }}</div>
                @enderror

                <div class="actions">
                    <button id="process-btn" class="btn" type="submit">
                        <span id="process-btn-text">Process</span>
                    </button>

                    <a id="upload-download-btn" class="btn secondary download-action-btn" href="#">
                        Download File
                    </a>
                </div>

                <div id="loading-wrap" class="loading-wrap" aria-live="polite" aria-busy="false">
                    <div class="loading-text">
                        <span id="loading-message">Processing conversion, please wait...</span>
                        <span id="loading-percent" class="loading-percent">0%</span>
                    </div>
                    <div class="loading-track">
                        <div id="loading-bar" class="loading-bar"></div>
                    </div>
                </div>
                <div id="ajax-error" class="error" style="display:none"></div>

                <div id="upload-download-wrap" class="upload-download-wrap" aria-live="polite">
                    <div class="upload-download-head">
                        <span id="upload-success-badge" class="success-badge">Ready</span>
                        <span id="upload-file-name" class="upload-file-name"></span>
                    </div>
                    <div id="upload-download-message" class="helper">Processing complete. Your file is ready.</div>
                    <div class="meta">Secure link expires in 5 minutes.</div>
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

    <script src="{{ asset('assets/docflip/doc-converter.js') }}" defer></script>
</body>
</html>