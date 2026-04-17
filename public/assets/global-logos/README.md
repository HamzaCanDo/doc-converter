# Global Logos Scaffold

This folder is the single source of truth for branding assets.

## Folder Layout

- `favicon/`
  - `favicon.ico`
  - `favicon-16x16.png`
  - `favicon-32x32.png`
  - `favicon-48x48.png`
- `header/`
  - `header-120x36.png`
  - `header-160x48.png`
  - `header-180x54.png`
  - `header-220x66.png`
  - `header-240x72.png`
  - `header-280x84.png`
- `hero/`
  - `hero-600x180.png`
  - `hero-640x192.png`
- `icon/`
  - `icon-64x64.png`
  - `icon-128x128.png`
  - `icon-180x180.png`
  - `icon-192x192.png`
- `site.webmanifest`

## Where These Are Used

- Browser tab/favicon and app icons: `resources/views/doc-converter.blade.php` head tags.
- Hero/logo image on page: `resources/views/doc-converter.blade.php` hero picture block.

## Swap Workflow

1. Keep the same filenames.
2. Replace only the image files in each folder.
3. No Blade changes required if names stay the same.
