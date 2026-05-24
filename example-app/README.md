# `poli-page/symfony-bundle` example app

Minimal Symfony 7 app demonstrating every public method of the Poli Page PHP SDK through the Symfony bundle. Each route or command corresponds 1:1 to a step in the SDK's canonical demo (`../../sdk-php.md/examples/demo.php`).

## Setup

```bash
cd example-app
composer install
cp .env .env.local
# Edit .env.local and set POLI_PAGE_API_KEY=pp_test_... (get one at https://app-develop.poli.page)
symfony serve
```

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
| (bundle smoke test) | `bin/console poli-page:render --project=getting-started --template=welcome --version=1.0.0` |

## Quick smoke

```bash
curl -o welcome.pdf http://localhost:8000/render/pdf
open welcome.pdf
```

If you see a styled welcome PDF, the bundle + SDK + your API key all work end-to-end.
