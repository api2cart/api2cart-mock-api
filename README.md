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

Generic, store-independent methods (`account.*`, `batch.*`, `webhook.*`,
`cart.create/delete/disconnect/list/methods/validate`) are intentionally omitted.

## File formats

`{case}.request.json` — capture timestamp, HTTP method and endpoint. GET/DELETE parameters are in
`query`; POST/PUT parameters are in `body`.

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

## Contract (stability)

Case slugs and index paths are a public contract. Add new cases freely; do **not** rename or
delete published cases — mark them `"deprecated": true` instead. Before the first public release,
duplicate or opaque slugs may be consolidated. The index is generated, so a silent published
rename/delete would break consumers without any error.
