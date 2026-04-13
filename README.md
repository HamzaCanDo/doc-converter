# Google Doc Converter

A Laravel 11 web tool that converts public Google Docs into PDF, DOCX, ODT, XLSX, HTML, Markdown, and JSON. All downloads are streamed (no export files written to disk), with signed links and per-IP rate limiting.

## Features
- Google Drive export for PDF, DOCX, and ODT
- HTML fetch + table parsing to build XLSX
- Markdown + JSON output
- Streaming downloads (no temp export files)
- 5-minute signed URLs + per-IP throttling
- File cache warm-up for faster repeated downloads

## Requirements
- PHP 8.2+
- Composer
- Extensions: curl, mbstring, openssl, json, pdo (sqlite or your DB driver), zip (required for XLSX)

## Local Setup
1. Install dependencies:
   ```bash
   composer install
   ```
2. Copy env and generate key:
   ```bash
   copy .env.example .env
   php artisan key:generate
   ```
3. Place your service account JSON at:
   ```
   storage/app/google-auth.json
   ```
4. Start the server:
   ```bash
   php artisan serve
   ```

## Service Account Notes
- The target Doc must be public: “Anyone with the link can view.”
- The tool uses the service account JSON key for Drive API authentication.

## Caching
- HTML export and parsed data are cached for 5 minutes using Laravel cache.
- Default cache store is file. You can change CACHE_STORE in .env if desired.

## Deployment (Shared Hosting)
- Set the document root to the public folder.
- Ensure storage and bootstrap/cache are writable.
- Add APP_URL and APP_KEY in .env.
- Enable the zip extension to allow XLSX downloads.

## Security
- Service account key is excluded from version control via .gitignore.
- Signed links expire in 5 minutes.
- Download endpoint is rate limited per IP.
