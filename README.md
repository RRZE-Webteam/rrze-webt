# RRZE WEB-T Translator

Integrates eTranslation REST v2 into the WordPress block editor so authors can translate posts (title and content) without leaving the editor.

## Features

- Sidebar panel in the block editor to trigger WEB-T translations.
- Submits asynchronous document requests and receives translations via custom REST endpoints.
- Translation history retains every pending job and the three most recently submitted finished jobs per post, with detailed status (pending/completed/failed).
- Local job reservation before dispatch, duplicate submission protection and traceable transport failures.
- Manual source/target selections.
- Stores translations in post meta to keep context across sessions.
- Compares source and translation in a text preview and applies results only on confirmation in the editor.

## Requirements

- WordPress 6.8 or newer.
- PHP 8.2 or newer.
- MySQL/MariaDB with connection-scoped `GET_LOCK` / `RELEASE_LOCK` support on the database server used for post metadata.
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
the configured service. Callbacks only store job results; they never update the post.

Protocol reference: [official REST v2 OpenAPI contract](https://language-tools.ec.europa.eu/assets/downloads/eTranslation-RestV2.yaml).

### Job lifecycle

The plugin saves a local job and token before contacting eTranslation. A valid
callback can therefore arrive before the submission response. The first valid
provider ID is bound to the job; a later conflicting ID is rejected. A success
notification binds the ID but does not complete the job without a document.

While a job is pending, submitting identical content, title and languages for the
same post returns that job without another provider request. Different input is
rejected with HTTP 409 until the pending job finishes or expires. Jobs distinguish
submission in progress (`submitting`), provider acceptance (`accepted`), an
unconfirmed outcome (`uncertain`) and explicit rejection (`rejected`). The job
itself remains `pending` until completion or failure.

A transport timeout, server error or malformed acknowledgement may occur after
the provider has accepted the document. Such a job remains pending and is not
automatically resubmitted. A later valid callback can still complete it. Without
a result it expires after five minutes by default, controlled by
`rrze_webt_job_max_age_seconds`. Expiration is checked when listing jobs, submitting
another request or receiving a callback. It does not cancel processing at the
provider; a manual submission after expiration may start new provider work.

The first terminal state (`completed` or `failed`) is retained. Duplicate or late
callbacks cannot overwrite the result or apply it to the post again. History is
ordered by submission, retaining all pending jobs and the newest three terminal
jobs. Acknowledgement does not reorder it.

Short per-post database locks serialize state changes. No lock is held during the
provider HTTP request. Lock contention or unavailable lock support returns HTTP
503 without an unlocked write. This relies on all relevant connections sharing
one MySQL/MariaDB lock namespace; custom database routing or multiple writable
database servers require additional validation.

The editor waits at least ten seconds longer than the configured provider HTTP
timeout (30 seconds versus 20 seconds by default). It refreshes the history after
success, errors and browser aborts. Aborting the browser request stops waiting;
it cannot cancel an already submitted translation.

### Reviewing and applying a translation

Every new job stores the exact submitted title and content, including unsaved
editor changes. Receiving a result or polling the history changes neither the
stored post nor the open editor. **Review translation** opens a plain-text
comparison; provider HTML is never executed in the preview.

**Apply in editor** rechecks the current post ID, title and content against that
snapshot. If they have changed, request a new translation. Confirmation replaces
the title and Gutenberg block model in one normal, undoable editor change. The
plugin does not call a save or publish endpoint. Normal WordPress saving,
autosaving and revision behavior continue to apply. Cancelling changes nothing.

New deliveries require exactly one ordered pair of title and content boundaries
and a nonempty content segment. Block delimiter order, nesting and JSON settings
must match the source. HTML element order and attributes such as links, image
URLs, classes and IDs must also remain intact. Human-readable HTML attributes
(`alt`, `title`, `aria-label`, `placeholder`) may translate. WordPress's KSES
checks reject disallowed markup, and Gutenberg validates registered blocks in
the browser before application. Damaged or unsupported content is rejected;
the plugin does not silently convert it to a Classic/HTML block or repair it.

This is deliberately conservative: custom/dynamic blocks whose translated text
requires changes to their JSON settings are not supported by this whole-document
workflow. Actual EU preservation of every custom block still requires live testing.
Old jobs without a source snapshot remain viewable but cannot be applied; create
a new job instead. The former `rrze_webt_auto_update_post` filter is no longer
used. Job acknowledgement requires edit permission and records review only;
it neither saves the post nor prevents a future explicit review.

## Usage

1. Edit or create a post using the block editor.
2. Open the **WEB-T Translation** panel from the sidebar.
3. Choose the target language (defaults to the site language).
4. Choose a source language.
5. Click **Translate content**. A job is created and monitored until WEB-T responds.
6. When complete, click **Review translation** and compare the source and translated text.
7. Click **Apply in editor** to replace the editor's title and content. Check the result and save/update the post through WordPress when ready; normal Undo remains available.

## Development

- PHP autoloading follows PSR-4 via `spl_autoload_register` (namespace `RRZE\WebT`).
- Source assets live in `src/` (ESNext + SCSS). Compiled and minified files are emitted to `build/` by the npm scripts.
- Translation jobs are stored in post meta (`_rrze_webt_job_*`), with an index key `_rrze_webt_jobs_index`.
- REST endpoints reside in `includes/RestController.php` (namespace `rrze-webt/v1`).

### Scripts

- `npm run build` – Bundles `src/editor.js` and `src/editor.scss` into `build/editor.js` and `build/editor.css`.
- `npm run start` – Watches the same sources and rebuilds into `build/` on changes.
- `npm run test:integration -- <local-site-url>` – Runs the network-isolated WordPress integration tests. See [local simulation and tests](tests/README.md) for prerequisites, scenarios and known failures.
- `npm run test:editor` – Checks editor request handling with Node's built-in test runner, without WordPress or network access.

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
