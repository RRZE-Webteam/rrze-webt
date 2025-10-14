# RRZE WEB-T Translator

Integrates the WEB-T translation API into the WordPress block editor so authors can translate posts (title and content) without leaving the editor.

## Features

- Sidebar panel in the block editor to trigger WEB-T translations.
- Supports both synchronous and asynchronous responses from WEB-T via custom REST endpoints.
- Translation history limited to the three most recent jobs per post with detailed status (pending/completed/failed).
- Respects site language, offers manual source/target selections, and auto-detects when desired.
- Stores translations in post meta to keep context across sessions.

## Requirements

- WordPress 6.8 or newer.
- PHP 8.2 or newer.
- Valid WEB-T (eTranslation) API credentials (application name & password).

## Installation

1. Copy the plugin folder into `wp-content/plugins/`.
2. Run `npm install` followed by `npm run build` to compile the JavaScript and CSS assets into the `build/` directory.
3. Activate *RRZE WEB-T Translator* via **Plugins → Installed Plugins** in WordPress.
4. Go to **Settings → WEB-T Translator** and configure your API endpoint, application name, and password.
5. Open any post in the block editor, use the *WEB-T Translation* sidebar to request translations.

## Configuration

| Setting | Description |
| ------- | ----------- |
| API Endpoint URL | Full URL to the WEB-T translate endpoint, e.g. `https://webgate.ec.europa.eu/etranslation/si/translate`. |
| Application Name | Application identifier provided by WEB-T. |
| Password | Password/secret for the application. |

## Usage

1. Edit or create a post using the block editor.
2. Open the **WEB-T Translation** panel from the sidebar.
3. Choose the target language (defaults to the site language).
4. (Optional) Choose a source language or leave on *Auto detect*.
5. Click **Translate content**. A job is created and monitored until WEB-T responds.
6. When complete, the post title and content update automatically. The translation history shows job details.

## Development

- PHP autoloading follows PSR-4 via `spl_autoload_register` (namespace `RRZE\WebT`).
- Source assets live in `src/` (ESNext + SCSS). Compiled and minified files are emitted to `build/` by the npm scripts.
- Translation jobs are stored in post meta (`_rrze_webt_job_*`), with an index key `_rrze_webt_jobs_index`.
- REST endpoints reside in `includes/RestController.php` (namespace `rrze-webt/v1`).

### Scripts

- `npm run build` – Bundles `src/editor.js` and `src/editor.scss` into `build/editor.js` and `build/editor.css`.
- `npm run start` – Watches the same sources and rebuilds into `build/` on changes.

## Changelog

### 1.0.0
- Initial release with block editor integration and WEB-T API support.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
