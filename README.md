# API2Cart Mock Dataset

Reference dataset of **request/response examples** for building integrations with the
API2Cart REST API **without a live store**. Nothing runs live — this is a static catalogue
of captured example pairs. Primary consumer: AI agents.

These mocks reproduce **API2Cart REST responses** (the shape API2Cart returns), not the
native platform API. Response shape differs per platform, so mocks are captured per platform.

## Start here (for agents)

1. Read `index.json` (root) — list of platforms, each pointing to `{Platform}/index.json`.
2. Read `{Platform}/index.json` — every method with its cases; each case has `covers`
   (what it demonstrates) and `captured_at`. Do not crawl the tree; the index is the source
   of truth.

## Layout

```
index.json                                  # platforms → {Platform}/index.json
{Platform}/index.json                        # entity / method / case index
{Platform}/{entity}/{method}/{case}.request.json
{Platform}/{entity}/{method}/{case}.response.json
```

Method folder naming keeps the API2Cart method string 1:1 — dots are **not** split into
nested folders (`product.child_item.info` → `product/child_item.info/`).

Generic, store-independent methods (`account.*`, `webhook.*`,
`cart.create/delete/disconnect/list/methods/validate`) are intentionally omitted.

`batch.job.list` / `batch.job.result` **are included**, despite being generic-looking method
names: their content is not store-independent — a job result is tied to a specific store and to
the specific `*.add.batch`/`*.update.batch`/`*.delete.batch` call that created it. Without these
two, an agent has no example of how to learn the outcome of an async batch operation. Look them
up next to whichever `*.batch` method created the job.

`marketplace.*` methods (e.g. `marketplace.product.find`) are a different kind of entity: they
query Amazon's public product catalog, not the connected store's own inventory. Treat their
mocks as catalog lookups, not store-data examples.

## File formats

`{case}.request.json` — capture timestamp, HTTP method and endpoint. Parameters live in exactly
one of two keys: `query` (sent as a query string) or `body` (sent as a JSON body). GET and DELETE
always use `query`, and POST/PUT normally use `body` — but **do not infer the container from the
verb**: a few endpoints are genuine PUT-with-query methods (e.g. EBay's `order.update` and
`product.image.update`, whose every parameter is declared `in: query`). Always read whichever key
the case actually carries.

```json
{ "captured_at": "2026-01-01T00:00:00Z", "method": "GET", "endpoint": "product.info.json",
  "query": { "id": "10", "response_fields": "{result{id,name,price,images}}" } }
```

`{case}.response.json` — raw API2Cart body only. Every REST response has top-level
`return_code`, `return_message` and `result`; paginated list responses may also have top-level
`pagination`.

## Case slugs

Use kebab-case. Errors begin with `error-`; multiple meaningful parameters are separated by `__`.
Platform prefixes such as `etsy-` are reserved for platform-specific behavior. Never put opaque
cursor values, source labels (`real`, `live`, `rich`) or secrets in a slug.

## Validating a platform

`scripts/validate.php` ships in this repo and needs nothing but the dataset tree itself —
no access to the api2cart app or its OpenAPI files:

```bash
php scripts/validate.php AmazonSP EtsyAPIv3
```

It checks, per platform: `index.json` matches the filesystem in both directions, every response
carries the `return_code`/`return_message`/`result` envelope, no response exceeds 512 KiB except
a `full-properties`-slugged case, `.covers.json` has no orphaned or drifted entries, `*.count`
responses are not list-shaped, no two cases in a method send the identical request, every
`page_cursor` has a source, `return_code != 0` matches the `error-` slug prefix, slugs carry no
source label and kebab-case their parameter names, and empty payloads serialize as `{}` not `[]`.
Exit code 0 = clean. `.github/workflows/validate.yml` runs it on every platform on push and PR.

Platforms are added one at a time, but **the conventions above are repo-wide** — a new platform
does not get to bring its own. If something genuinely does not fit, change the rule and the
check for everyone rather than carving out an exception for one platform.

## Contract (stability)

Case slugs and index paths are a public contract. Add new cases freely; do **not** rename or
delete published cases — mark them `"deprecated": true` instead. Before the first public release,
duplicate or opaque slugs may be consolidated. The index is generated, so a silent published
rename/delete would break consumers without any error.
