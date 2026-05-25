# `poli-page/symfony-bundle` example app

Minimal Symfony 7 app demonstrating every public method of the Poli Page PHP SDK through the Symfony bundle. Each route or command corresponds 1:1 to a step in the SDK's canonical demo (`../../sdk-php.md/examples/demo.php`).

## Setup

```bash
cd example-app
composer install
# POLI_PAGE_API_KEY is sourced from the bundle repo's root .env (one level up)
# via example-app/bootstrap.php — no per-app .env.local needed.

# Option A — PHP's built-in server (no extra install)
composer serve            # → http://127.0.0.1:8000

# Option B — Symfony CLI, if you have it installed
symfony serve
```

## Interactive demo (recommended)

Open **http://127.0.0.1:8000/** — a single-page dashboard with one button per SDK feature:

- **§01 Render** — three buttons that render the welcome PDF / streamed PDF / HTML preview inline (no download).
- **§02 Documents** — store a document, capture the ID, then the Get/Preview/Thumbnails/Delete buttons unlock. "Delete" clears state and re-locks them.
- **§03 Error handling** — fires `INVALID_VERSION_FORMAT` and shows the typed exception payload.
- **§04 CLI** — copy-buttons for the two console commands (a browser can't shell out).

PDFs render in an embedded viewer. JSON responses pretty-print. Errors flip the result panel red. Loading states are animated `· · ·`. Built with vanilla HTML/CSS/JS — no build step, no Twig.

If you'd rather drive the routes manually (curl, scripts, integration tests), the JSON / PDF endpoints below are unchanged and still respond.

## Routes (mirror SDK demo steps 1, 2, 4–10)

| SDK demo step | URL | What it does |
|---|---|---|
| 1. `render->pdf()` | `GET /render/pdf` | Returns the welcome PDF as `application/pdf`. |
| 2. `render->pdfStream()` | `GET /render/stream` | Same PDF but via PSR-7 stream + `StreamedResponse`. |
| 4. `render->preview()` | `GET /render/preview[?html=...]` | HTML preview. Pass `?html=<raw>` for inline mode. |
| 5. `render->document()` | `POST /documents` | Stores the document, returns descriptor JSON. |
| 6. `documents->get(id)` | `GET /documents/{id}` | 302 to the presigned PDF URL. |
| 7. `documents->thumbnails(id)` | `GET /documents/{id}/thumbnails` | Page thumbnails as base64 JSON. |
| 8. `documents->preview(id)` | `GET /documents/{id}/preview` | Stored document's HTML preview. |
| 9. `documents->delete(id)` | `DELETE /documents/{id}` | Soft-delete, `204 No Content`. |
| 10. Error handling | `GET /errors/bad-version` | Deliberately triggers `INVALID_VERSION_FORMAT`. |

## Commands

| SDK demo step | Command |
|---|---|
| 3. `renderToFile()` | `bin/console app:demo:render-to-file` |
| (bundle smoke test) | `bin/console poli-page:render --project=getting-started --template=welcome --template-version=1.0.0` |

## Quick smoke (no browser)

```bash
curl -o welcome.pdf http://127.0.0.1:8000/render/pdf
open welcome.pdf
```

If you see a styled welcome PDF, the bundle + SDK + your API key all work end-to-end.

## Architecture notes

- `bootstrap.php` (required by both `public/index.php` and `bin/console` **before** `vendor/autoload_runtime.php`) loads the repo-root `.env`. It deliberately does not include `vendor/autoload.php` — pre-loading the autoloader breaks Symfony Runtime's first-call detection and the kernel silently never boots. There's a `// Why:` comment in the file; preserve it.
- `src/Controller/DemoController.php` serves `templates/demo.html` verbatim via `file_get_contents`. No Twig dependency, no template compilation.
- The 10 demo routes live in `src/Controller/RenderController.php` and `src/Controller/DocumentController.php`. The interactive demo UI calls them via `fetch()` — it does not duplicate any SDK logic.
