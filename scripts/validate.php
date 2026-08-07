<?php
/**
 * validate.php
 *
 * Standalone dataset validator for api2cart-mock-api. Unlike the generator scripts under
 * scripts/capture/ and build-index.php -- which stay in the app repo's generation workspace and
 * are never published -- this script ships in the public repo so external contributors and CI can
 * verify a platform dataset without access to the generator or the api2cart app.
 *
 * Checks, per platform directory (<dataset>/<Platform>):
 *   1) index.json <-> filesystem, both directions: index entries with no matching file pair on
 *      disk (stale index), and file pairs on disk with no index entry (index not regenerated).
 *   2) every {slug}.response.json carries the API2Cart response envelope: return_code,
 *      return_message, result.
 *   3) every {slug}.response.json is <= 512 KiB (524288 bytes), mirroring build-index.php /
 *      seed-from-phpunit.php -- except a case whose slug starts with "full-properties", the one
 *      deliberate full-shape exception per method documented in README.md.
 *   4) <Platform>/.covers.json matches what index.json actually publishes: no orphan covers key
 *      (no matching files on disk), no description drift from index.json.
 *   5) every "{entity}/count" method's successful response has no top-level pagination and its
 *      result contains only *_count / additional_fields / custom_fields keys -- catches a
 *      product.list-shaped response accidentally saved under product/count (APITOCART-46051 #1).
 *   6) no two cases in the same method have the exact same (endpoint, request payload) --
 *      duplicate-request cases teach nothing beyond the first and bloat the index the agent reads
 *      whole (APITOCART-46051 #2).
 *   7) every request query_cursor has a matching pagination.next somewhere else on the same
 *      platform -- an orphaned cursor sends an agent down a paginated loop it can never join
 *      (APITOCART-46051 #3).
 *   8) return_code != 0 <=> slug starts with "error-", in both directions -- an agent filtering
 *      cases by the error- prefix must not silently skip a real error case, nor expect an error
 *      from a slug that promises one but doesn't error (APITOCART-46051 #8).
 *   9) no slug contains a source label (real/live/rich) as a hyphen-delimited component -- these
 *      describe how WE captured the case, not what it demonstrates, and are never a stable part of
 *      the API contract (APITOCART-46051 #7).
 *
 * Deliberately NOT included: the "declared params" check from build-index.php (needs the
 * api2cart app's openapi files, unavailable outside that repo) and anything that requires
 * re-running the generator. This script only reads the dataset tree.
 *
 * Usage: php validate.php <dataset>/<Platform> [<dataset>/<Platform> ...]
 * Exit code: 0 = all checks pass, 1 = at least one issue found.
 */

declare(strict_types=1);

const V_MAX_RESPONSE_BYTES = 524288; // 512 KiB

if (PHP_SAPI === 'cli' && realpath($argv[0]) === realpath(__FILE__)) {
  $platDirs = array_slice($argv, 1);
  if (!$platDirs) {
    fwrite(STDERR, "usage: php validate.php <dataset>/<Platform> [...]\n");
    exit(2);
  }

  $errors = [];
  foreach ($platDirs as $platDir) {
    $errors = [...$errors, ...validateDataset(rtrim($platDir, '/'))];
  }

  foreach ($errors as $e) {
    fwrite(STDERR, "$e\n");
  }
  fwrite(STDERR, $errors ? count($errors) . " issue(s)\n" : "validate ok\n");
  exit($errors ? 1 : 0);
}

/**
 * @return array<int, string> list of validation errors (empty = ok)
 */
function validateDataset(string $platDir): array
{
  $errors = [];

  // ---- checks 2 + 3 + 5 + 8 + 9, plus the file-pair side of check 1 ----
  $fsKeys = [];
  /** @var array<string, string> normalized page_cursor (url-decoded) => "method/slug" that sent it */
  $cursorSenders = [];
  /** @var array<string, string> normalized pagination.next (url-decoded) => "method/slug" that returned it */
  $nextProviders = [];
  // per-method (endpoint . '|' . normalized payload) => first slug seen -- for check 6
  $seenPayloads = [];

  foreach (glob("$platDir/*/*", GLOB_ONLYDIR) ?: [] as $methodDir) {
    $methodFolder = basename($methodDir);
    $entity = basename(dirname($methodDir));
    $relPath = "$entity/$methodFolder";
    $payloadsInMethod = [];

    foreach (glob("$methodDir/*.request.json") ?: [] as $reqFile) {
      $slug = basename($reqFile, '.request.json');
      $key = "$relPath/$slug";
      $respFile = "$methodDir/$slug.response.json";

      $request = json_decode((string)file_get_contents($reqFile), true);
      if (!is_array($request)) {
        $errors[] = "$platDir: invalid request JSON: $key";
        continue;
      }

      // check 9: source label in slug
      if (preg_match('/(^|-)(real|live|rich)(-|$)/', $slug)) {
        $errors[] = "$platDir: slug contains a source label (real/live/rich), not part of the API contract: $key";
      }

      // check 6: duplicate (endpoint, payload) within a method
      $payload = $request['query'] ?? $request['body'] ?? [];
      if (is_array($payload)) {
        ksort($payload);
        $payloadKey = ($request['endpoint'] ?? '') . '|' . json_encode($payload);
        if (isset($payloadsInMethod[$payloadKey])) {
          $errors[] = "$platDir: duplicate (endpoint, payload) in $relPath: $slug and {$payloadsInMethod[$payloadKey]} send the exact same request";
        } else {
          $payloadsInMethod[$payloadKey] = $slug;
        }
      }

      // check 7 (request side): record page_cursor this case sends
      $cursor = is_array($payload) ? ($payload['page_cursor'] ?? null) : null;
      if (is_string($cursor) && $cursor !== '') {
        $cursorSenders[rawurldecode($cursor)] = $key;
      }

      if (!is_file($respFile)) {
        $errors[] = "$platDir: missing response for $key";
        continue;
      }
      $fsKeys[$key] = true;

      $response = json_decode((string)file_get_contents($respFile), true);
      if (!is_array($response)) {
        $errors[] = "$platDir: invalid response JSON: $key";
        continue;
      }
      if (!array_key_exists('return_code', $response)
        || !array_key_exists('return_message', $response)
        || !array_key_exists('result', $response)) {
        $errors[] = "$platDir: missing API2Cart response envelope (return_code/return_message/result): $key";
      }
      if (filesize($respFile) > V_MAX_RESPONSE_BYTES && !str_starts_with($slug, 'full-properties')) {
        $errors[] = "$platDir: response exceeds " . V_MAX_RESPONSE_BYTES . " bytes: $key";
      }

      // check 8: return_code != 0 <=> slug starts with "error-"
      $rc = $response['return_code'] ?? 0;
      $isErrorSlug = str_starts_with($slug, 'error-');
      if ($rc !== 0 && !$isErrorSlug) {
        $errors[] = "$platDir: return_code=$rc but slug has no error- prefix: $key";
      }
      if ($rc === 0 && $isErrorSlug) {
        $errors[] = "$platDir: slug has error- prefix but return_code=0: $key";
      }

      // check 5: {entity}/count response shape
      if ($methodFolder === 'count' && $rc === 0) {
        if (array_key_exists('pagination', $response)) {
          $errors[] = "$platDir: $key is a *.count response but has top-level pagination (looks like a *.list response saved under count)";
        }
        $resultKeys = is_array($response['result'] ?? null) ? array_keys($response['result']) : [];
        foreach ($resultKeys as $rk) {
          if (!str_ends_with($rk, '_count') && !in_array($rk, ['additional_fields', 'custom_fields'], true)) {
            $errors[] = "$platDir: $key is a *.count response but result has an unexpected key '$rk' (looks like a *.list response saved under count)";
          }
        }
      }

      // check 7 (response side): record pagination.next this case provides
      $next = is_array($response['pagination'] ?? null) ? ($response['pagination']['next'] ?? null) : null;
      if (is_string($next) && $next !== '') {
        $nextProviders[rawurldecode($next)] = $key;
      }
    }
  }

  // check 7: every page_cursor sent must be traceable to a pagination.next produced somewhere
  foreach ($cursorSenders as $cursorValue => $senderKey) {
    if (!isset($nextProviders[$cursorValue])) {
      $errors[] = "$platDir: $senderKey sends a page_cursor with no matching pagination.next anywhere on this platform (orphaned cursor)";
    }
  }

  // ---- check 1: index.json <-> filesystem, both directions ----
  $index = v_loadJson("$platDir/index.json") ?? [];
  $indexDesc = [];
  foreach ($index['methods'] ?? [] as $method) {
    foreach ($method['cases'] ?? [] as $case) {
      if (isset($method['path'], $case['slug'])) {
        $indexDesc[$method['path'] . '/' . $case['slug']] = (string)($case['covers']['description'] ?? '');
      }
    }
  }

  foreach ($indexDesc as $key => $_) {
    if (!isset($fsKeys[$key])) {
      $errors[] = "$platDir: index.json entry has no files on disk (stale index): $key";
    }
  }
  foreach ($fsKeys as $key => $_) {
    if (!isset($indexDesc[$key])) {
      $errors[] = "$platDir: case on disk missing from index.json (index not regenerated): $key";
    }
  }

  // ---- check 4: .covers.json vs index.json ----
  $covers = v_loadJson("$platDir/.covers.json");
  if ($covers !== null) {
    foreach ($covers as $key => $entry) {
      if (!isset($fsKeys[$key])) {
        $errors[] = "$platDir: orphan in .covers.json (no files on disk): $key";
        continue;
      }
      if (!isset($indexDesc[$key])) {
        continue; // already reported above as missing from index.json
      }
      $coverDesc = is_array($entry) ? (string)($entry['description'] ?? '') : (string)$entry;
      if ($coverDesc !== $indexDesc[$key]) {
        $errors[] = "$platDir: description drift $key: .covers.json=" . json_encode($coverDesc)
          . " index.json=" . json_encode($indexDesc[$key]);
      }
    }
  }

  return $errors;
}

function v_loadJson(string $path): ?array
{
  if (!is_file($path)) {
    return null;
  }
  $data = json_decode((string)file_get_contents($path), true);
  return is_array($data) ? $data : null;
}
