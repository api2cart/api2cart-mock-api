# API2Cart Mock Dataset

Reference dataset of **request/response examples** for building integrations with the
API2Cart REST API **without a live store**. Nothing runs live — this is a static catalogue
of captured example pairs. Primary consumer: AI agents.

These mocks reproduce **API2Cart REST responses** (the shape API2Cart returns), not the
native platform API. Response shape differs per platform, so mocks are captured per platform.

## Start here (for agents)

1. Read `index.json` (root) — list of platforms, each pointing to `{Platform}/index.json`.
2. Read `{Platform}/index.json` — every method with its cases and `captured_at`.

## Layout

```
index.json                                  # platforms → {Platform}/index.json
{Platform}/index.json                        # entity / method / case index
{Platform}/{entity}/{method}/{case}.request.json
{Platform}/{entity}/{method}/{case}.response.json
assets/img/                                  # placeholder images
```

Method folder naming keeps the API2Cart method string 1:1 — dots are **not** split into
nested folders (`product.child_item.info` → `product/child_item.info/`).

Generic, store-independent methods (`account.*`, `batch.*`, `webhook.*`,
`cart.create/delete/disconnect/list/methods/validate`) are intentionally omitted.

## File formats

`{case}.request.json` — capture timestamp plus HTTP method, endpoint and query params as sent to API2Cart:

```json
{ "captured_at": "2026-01-01T00:00:00Z", "method": "GET", "endpoint": "product.info.json",
  "query": { "id": "10", "response_fields": "{result{id,name,price,images}}" } }
```

`{case}.response.json` — raw API2Cart body only (`return_code`, `return_message`, `result`).

## Contract (stability)

Case slugs and index paths are a public contract. Add new cases freely; do **not** rename or
delete existing ones — mark them `"deprecated": true` instead. The index is generated, so a
silent rename/delete would break consumers without any error.
