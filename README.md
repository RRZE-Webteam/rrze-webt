# RRZE WEB-T Translator

Integrates eTranslation REST v2 into the WordPress block editor so authors can translate posts (title and content) without leaving the editor.

## Features

- Sidebar panel in the block editor to trigger WEB-T translations.
- Submits asynchronous document requests and receives translations via custom REST endpoints.
- Translation history limited to the three most recent jobs per post with detailed status (pending/completed/failed).
- Manual source/target selections.
- Stores translations in post meta to keep context across sessions.

## Requirements

- WordPress 6.8 or newer.
- PHP 8.2 or newer.
- Valid WEB-T (eTranslation) API credentials (application name & password).

## Installation

1. Copy the plugin folder into `wp-content/plugins/`.
2. Activate *RRZE WEB-T Translator* via **Plugins → Installed Plugins** in WordPress.
3. Go to **Settings → WEB-T Translator** and configure your API endpoint, application name, and password.
4. Open any post in the block editor, use the *WEB-T Translation* sidebar to request translations.

## Configuration

| Setting | Description |
| ------- | ----------- |
| API Endpoint URL | Full HTTPS URL: `https://language-tools.ec.europa.eu/etranslation/api/askTranslate`. |
| Application Name | Application identifier provided by WEB-T. |
| Password | Password/secret for the application. |

### eTranslation REST v2

New requests use HTTP Basic authentication over HTTPS. The configured endpoint
is used exactly as entered; the plugin does not append `/translate` or follow
redirects. Existing v1 URLs must be updated explicitly in Settings or in the
`rrze_webt_credentials` filter. Saved credentials and URLs are not rewritten by
the upgrade.

The plugin sends HTML in `documentToTranslate.document`, the local job reference
in `callerInformation.externalReference`, and separate URLs for `deliveries.http`,
`notifications.success.http` and `notifications.failure.http`. An immediate
response must contain a positive `requestId`; it is an acknowledgement, not the
translated document. A success notification only acknowledges completion at the
provider. The job completes when the delivery arrives with a Base64-encoded
`result` and `outputFormat: html`. Callback IDs, references and target languages
must match the stored job. New jobs require JSON; outstanding pre-upgrade jobs
can still receive raw Base64 deliveries.

Custom integrations using `rrze_webt_request_payload` must return v2 fields.
`rrze_webt_document_filename` now receives a default filename without extension,
as required by the v2 schema. Digest-handshake and cURL workaround hooks
(`rrze_webt_handshake_args`, `rrze_webt_force_tls12`, `rrze_webt_force_ipv4`) and
the synchronous `rrze_webt_translation_result` hook are no longer used.
`rrze_webt_request_args` still applies before authentication; redirects remain
disabled. Timeout, TLS verification and HTTP-version filters remain available.

The callback URLs must be reachable from the EU service. Local development uses
the explicit CLI simulator described below. A normal editor request still uses
the configured service. Job timing, duplicate handling and explicit application
of results remain separate repair steps; callbacks currently still update posts
automatically.

Protocol reference: [official REST v2 OpenAPI contract](https://language-tools.ec.europa.eu/assets/downloads/eTranslation-RestV2.yaml).

## Usage

1. Edit or create a post using the block editor.
2. Open the **WEB-T Translation** panel from the sidebar.
3. Choose the target language (defaults to the site language).
4. Choose a source language.
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
- `npm run test:integration -- <local-site-url>` – Runs the network-isolated WordPress integration tests. See [local simulation and tests](tests/README.md) for prerequisites, scenarios and known failures.

### Release archives

Tests remain versioned alongside the plugin, including on `main`. The root
`.gitattributes` marks `tests` as `export-ignore`, excluding the entire test suite
from `git archive` and GitHub's generated source ZIP/TAR downloads. To run the
tests, use a Git checkout rather than a release archive.

After committing the release contents, including the compiled `build/` assets and
`.gitattributes`, create an installable ZIP from that commit:

```sh
git archive --format=zip --prefix=rrze-webt/ --output=/tmp/rrze-webt.zip HEAD
```

For a tagged release, replace `HEAD` with its tag. The exclusion takes effect only
for commits/tags containing `.gitattributes`; existing releases are unchanged.
Manually uploaded release assets must be built with this command or an equivalent
exclusion: copying the checkout or using a generic ZIP command does not apply Git
export rules.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
