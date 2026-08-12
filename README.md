# API2Cart Mock Dataset

Reference dataset of **request/response examples** for building integrations with the
API2Cart REST API **without a live store**. Nothing runs live — this is a static catalogue
of captured example pairs. Primary consumer: AI agents.

These mocks reproduce **API2Cart REST responses** (the shape API2Cart returns), not the
native integration's own API. Response shape differs per integration, so mocks are captured
per integration.

## Start here (for agents)

1. Read `index.json` (root) — list of integrations, each pointing to `{Integration}/index.json`.
2. Read `{Integration}/index.json` — every method with its cases; each case has `covers`
   (what it demonstrates) and `captured_at`. Do not crawl the tree; the index is the source
   of truth.

## Layout

```
index.json                                    # integrations → {Integration}/index.json
{Integration}/index.json                      # entity / method / case index
{Integration}/{entity}/{method}/{case}.request.json
{Integration}/{entity}/{method}/{case}.response.json
```

Method folder naming keeps the API2Cart method string 1:1 — dots are **not** split into
nested folders (`product.child_item.info` → `product/child_item.info/`).

Two kinds of method are intentionally omitted, and their absence is not an oversight:

- **Generic, store-independent methods** — `account.*`, `webhook.*`,
  `cart.create/delete/disconnect/list/methods/validate`.
- **Methods the integration marks deprecated.** The dataset is a teaching set, so it must not
  show an agent a method it should not be adopting. This is why `EBay/product/fields` is absent
  while the rest of EBay is complete: eBay still answers it, and it is still in that integration's
  OpenAPI, but the integration flags it deprecated. Check the integration's own method table
  before concluding a missing method is a gap.

`batch.job.list` / `batch.job.result` **are included**, despite being generic-looking method
names: their content is not store-independent — a job result is tied to a specific store and to
the specific `*.add.batch`/`*.update.batch`/`*.delete.batch` call that created it. Without these
two, an agent has no example of how to learn the outcome of an async batch operation.

To get from a batch call to its outcome, take the `job_id` the `*.batch` response returned and read
`batch/job.result` for that id; `batch/job.list` is the same mapping in the other direction, and is
the entry point when you have no `job_id` in hand. Both ends name each other in their `covers`
text. A `job_id` is unique across the whole repo, so a job never resolves into another
integration's results — and an enqueue case whose result is still `pending` says so explicitly
rather than pointing at a result that does not exist yet.

`marketplace.*` methods (e.g. `marketplace.product.find`) are a different kind of entity: they
query the connected integration's own public product catalog (Amazon's ASIN/UPC/EAN catalog for
AmazonSP, eBay's catalog for EBay, ...), not the connected store's own inventory. Treat their
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
Integration prefixes such as `etsy-` are reserved for integration-specific behavior. Never put
opaque cursor values, source labels (`real`, `live`, `rich`) or secrets in a slug.

## Validating an integration

`scripts/validate.php` ships in this repo and needs nothing but the dataset tree itself —
no access to the api2cart app or its OpenAPI files. Pass it any subset of the integration
directories at the repo root — e.g., all of them:

```bash
php scripts/validate.php AmazonSP Bricklink EBay EtsyAPIv3 Facebook
```

It checks, per integration: `index.json` matches the filesystem in both directions, every response
carries the `return_code`/`return_message`/`result` envelope, no response exceeds 512 KiB except
a `full-properties`-slugged case (which gets a looser but still real 1 MiB ceiling of its own),
`.covers.json` has no orphaned or drifted entries, `*.count`
responses are not list-shaped, no two cases in a method send the identical request, every
`page_cursor` has a source, `return_code != 0` matches the `error-` slug prefix, slugs carry no
source label and kebab-case their parameter names, and empty payloads serialize as `{}` not `[]`.
Exit code 0 = clean.

Integrations are added one at a time, but **the conventions above are repo-wide** — a new
integration does not get to bring its own. If something genuinely does not fit, change the rule
and the check for everyone rather than carving out an exception for one integration.

## Contract (stability)

Case slugs and index paths are a public contract. Add new cases freely; do **not** rename or
delete published cases — mark them `"deprecated": true` instead. The index is generated, so a
silent published rename/delete would break consumers without any error.

**Until the first public release this repo is explicitly pre-contract**, and that licence is being
used: duplicate or opaque slugs are being consolidated, decorative integration prefixes removed,
and the index schema itself has changed shape (`platforms`/`platform` → `integrations`/
`integration`) **without** a `schema_version` bump, because nothing outside this repo can have read
it yet. `schema_version` stays at `1` for that reason. From the first release onward the opposite
rule applies: any change to a key an agent reads bumps `schema_version`, and slugs stop moving.
