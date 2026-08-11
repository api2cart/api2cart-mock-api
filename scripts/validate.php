<?php
/**
 * validate.php
 *
 * Standalone dataset validator for api2cart-mock-api. Unlike the generator scripts under
 * scripts/capture/ and build-index.php -- which stay in the app repo's generation workspace and
 * are never published -- this script ships in the public repo so external contributors and CI can
 * verify an integration dataset without access to the generator or the api2cart app.
 *
 * Checks, per integration directory (<dataset>/<Integration>):
 *   1) index.json <-> filesystem, both directions: index entries with no matching file pair on
 *      disk (stale index), and file pairs on disk with no index entry (index not regenerated).
 *   2) every {slug}.response.json carries the API2Cart response envelope: return_code,
 *      return_message, result.
 *   3) every {slug}.response.json is <= 512 KiB (524288 bytes), mirroring build-index.php /
 *      seed-from-phpunit.php -- except a case whose slug starts with "full-properties", the one
 *      deliberate full-shape exception per method documented in README.md, which gets a looser but
 *      still real ceiling of 1 MiB (a full-shape case is still meant to be readable by the primary
 *      consumer, not an excuse to skip trimming the request with count/response_fields).
 *   4) <Integration>/.covers.json matches what index.json actually publishes: no orphan covers key
 *      (no matching files on disk), no description drift from index.json.
 *   5) every "{entity}/count" method's successful response has no top-level pagination and its
 *      result contains only *_count / additional_fields / custom_fields keys -- catches a
 *      product.list-shaped response accidentally saved under product/count (APITOCART-46051 #1).
 *   6) no two cases in the same method have the exact same (endpoint, request payload) --
 *      duplicate-request cases teach nothing beyond the first and bloat the index the agent reads
 *      whole (APITOCART-46051 #2).
 *   7) every request query_cursor has a matching pagination.next somewhere else on the same
 *      integration -- an orphaned cursor sends an agent down a paginated loop it can never join
 *      (APITOCART-46051 #3).
 *   8) return_code != 0 <=> slug starts with "error-", in both directions -- an agent filtering
 *      cases by the error- prefix must not silently skip a real error case, nor expect an error
 *      from a slug that promises one but doesn't error (APITOCART-46051 #8).
 *   9) no slug contains a source label (real/live/rich) as a hyphen-delimited component -- these
 *      describe how WE captured the case, not what it demonstrates, and are never a stable part of
 *      the API contract (APITOCART-46051 #7).
 *  10) an empty `query`/`body` is serialized as `{}`, never `[]`. Checked on the RAW TEXT on
 *      purpose: PHP decodes both to the same empty array, so no structural check can tell them
 *      apart -- yet a consumer typing the field as a map (Go, Java, C#, TS Record<string,string>)
 *      fails on `[]` (APITOCART-46051 #9).
 *  11) a slug uses `_` only as the `__` parameter separator -- parameter NAMES are kebab-cased
 *      (`find-where-name`, not `find_where-name`), which is what seed-from-phpunit.php's slugFor()
 *      emits. Repo-wide since APITOCART-46051 #13 normalised EtsyAPIv3's 30 legacy slugs; before
 *      that this could only be enforced within a method. Note this constrains the SLUG only: the
 *      parameter names inside `query`/`body` keep their real API spelling (`find_where`, `sort_by`).
 *  12) a job_id published by a *.batch response is unique across the whole repo -- see
 *      validateJobIdsUnique() (repo-wide, so it runs outside the per-integration pass).
 *      (APITOCART-46054 #1)
 *  13) every published batch/job.result is reachable: some *.batch case returns that job_id, or
 *      some batch/job.list case lists it. (APITOCART-46054 #1)
 *  14) descriptions are unique within a method -- inside one method the description is the only
 *      thing distinguishing a case from its neighbour in the index. (APITOCART-46054 #3)
 *  15) a numeric parameter is a JSON number, not a quoted string. (APITOCART-46054 #7)
 *
 * Deliberately NOT included: the "declared params" check from build-index.php (needs the
 * api2cart app's openapi files, unavailable outside that repo) and anything that requires
 * re-running the generator. This script only reads the dataset tree.
 *
 * Usage: php validate.php <dataset>/<Integration> [<dataset>/<Integration> ...]
 * Exit code: 0 = all checks pass, 1 = at least one issue found.
 */

declare(strict_types=1);

const V_MAX_RESPONSE_BYTES = 524288; // 512 KiB
const V_MAX_FULL_PROPERTIES_BYTES = 1048576; // 1 MiB -- ceiling on the "full-properties" exception itself

/**
 * Known, documented exceptions to check 13 -- NOT a place to park new ones.
 *
 * These two AmazonSP results are genuine live captures of valuable failure modes (every item
 * not-found; all three item statuses in one job), but their job ids are small integers while that
 * integration's batch/job.list holds billion-range ids -- i.e. they were captured against a
 * different store than the rest of AmazonSP's batch data. Nothing in the published set leads an
 * agent to them. Fixing it needs a re-capture against the store that owns those jobs, which needs
 * Amazon credentials; inventing a job.list entry or asserting an enqueue case would be fabricating
 * data, which is worse than the gap. Tracked as APITOCART-46054 #1 (AmazonSP half).
 *
 * Delete an entry the moment its integration is re-captured -- the check then guards it for real.
 */
/**
 * Parameters whose value is a number in every integration's openapi. Deliberately a short, explicit
 * list rather than a heuristic: "is_numeric($value)" alone would also rewrite genuinely stringy
 * ids (a numeric-looking sku, an eBay item id) which are strings on purpose.
 */
const V_NUMERIC_PARAMS = [
  'count', 'start', 'position', 'price', 'old_price', 'cost_price', 'quantity',
];

const V_UNREACHABLE_JOB_DEBT = [
  'AmazonSP/id-149-update-not-found'       => true,
  'AmazonSP/id-165-update-mixed-statuses'  => true,
];

if (PHP_SAPI === 'cli' && realpath($argv[0]) === realpath(__FILE__)) {
  $platDirs = array_slice($argv, 1);
  if (!$platDirs) {
    fwrite(STDERR, "usage: php validate.php <dataset>/<Integration> [...]\n");
    exit(2);
  }

  $platDirs = array_map(static fn(string $d): string => rtrim($d, '/'), $platDirs);

  $errors = [];
  foreach ($platDirs as $platDir) {
    $errors = [...$errors, ...validateDataset($platDir)];
  }
  // Repo-wide, so it only means anything when several integrations are validated together --
  // which is exactly what CI does.
  $errors = [...$errors, ...validateJobIdsUnique($platDirs)];

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

      $rawRequest = (string)file_get_contents($reqFile);
      $request = json_decode($rawRequest, true);
      if (!is_array($request)) {
        $errors[] = "$platDir: invalid request JSON: $key";
        continue;
      }

      // check 10 -- raw text, because json_decode() maps [] and {} onto the same PHP value.
      if (preg_match('/"(query|body)"\s*:\s*\[/', $rawRequest)) {
        $errors[] = "$platDir: empty payload serialized as [] instead of {}: $key";
      }

      // check 9: source label in slug
      if (preg_match('/(^|-)(real|live|rich)(-|$)/', $slug)) {
        $errors[] = "$platDir: slug contains a source label (real/live/rich), not part of the API contract: $key";
      }

      // check 15: a numeric parameter is a JSON number, not a quoted string.
      // Mirror of check 10's argument: a consumer typing `count` as int chokes on "5", one typing
      // it as string chokes on 5, and the dataset was split roughly 3:1 between the two spellings
      // for the same parameter (APITOCART-46054 #7). Error cases are exempt -- sending the wrong
      // type is often the whole point ("price": "invalidValue", "count": "-1").
      if (!str_starts_with($slug, 'error-')) {
        $payloadForTypes = $request['query'] ?? $request['body'] ?? [];
        foreach (is_array($payloadForTypes) ? $payloadForTypes : [] as $pName => $pValue) {
          if (in_array($pName, V_NUMERIC_PARAMS, true) && is_string($pValue) && is_numeric($pValue)) {
            $errors[] = "$platDir: numeric parameter '$pName' is quoted (\"$pValue\") instead of a "
              . "JSON number: $key";
          }
        }
      }

      // check 11: `_` only as the `__` separator; parameter names are kebab-cased in slugs.
      if (str_contains(str_replace('__', '-', $slug), '_')) {
        $errors[] = "$platDir: slug spells a parameter name with `_`; kebab-case it "
          . "(`find-where-name`, not `find_where-name`) -- `__` is only the parameter separator: $key";
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
      $isFullProperties = str_starts_with($slug, 'full-properties');
      if (filesize($respFile) > V_MAX_RESPONSE_BYTES && !$isFullProperties) {
        $errors[] = "$platDir: response exceeds " . V_MAX_RESPONSE_BYTES . " bytes: $key";
      }
      if ($isFullProperties && filesize($respFile) > V_MAX_FULL_PROPERTIES_BYTES) {
        $errors[] = "$platDir: full-properties response exceeds " . V_MAX_FULL_PROPERTIES_BYTES
          . " bytes -- even the full-shape exception has a ceiling, recapture with a smaller count: $key";
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
      $errors[] = "$platDir: $senderKey sends a page_cursor with no matching pagination.next anywhere on this integration (orphaned cursor)";
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

  // ---- check 14: descriptions are unique within a method ----
  // The index is what the agent reads instead of walking the tree, and inside one method the
  // description is the ONLY thing distinguishing one case from its neighbour. Three cases all
  // labelled "filter: specific product id(s)" give no basis to choose between them. This is the
  // one finding of APITOCART-46051 that was closed by hand instead of becoming a check -- and the
  // only one that came back, on all three integrations at once (APITOCART-46054 #3).
  $byMethod = [];
  foreach ($indexDesc as $key => $desc) {
    $method = substr($key, 0, strrpos($key, '/') ?: 0);
    $byMethod[$method][$desc][] = substr($key, strrpos($key, '/') + 1);
  }
  foreach ($byMethod as $method => $descs) {
    foreach ($descs as $desc => $slugs) {
      if (count($slugs) > 1) {
        $errors[] = "$platDir: $method has " . count($slugs) . " cases sharing one description "
          . json_encode($desc) . " (" . implode(', ', $slugs) . ") -- inside a method the description is "
          . "the only thing that tells cases apart";
      }
    }
  }

  // ---- check 13: every published batch job result is reachable from some request in the set ----
  // Reachable = a *.batch case returns that job_id, OR a batch/job.list case lists it. Both are
  // legitimate entry points (README names the enqueue; job.list is the discovery path), so the
  // check is a union -- requiring an enqueue case for every captured result would force deleting
  // genuinely useful live-captured failure modes.
  // Note: a paginated job.list is NOT treated as an excuse to skip this. An earlier draft did that
  // and thereby disabled the check on the one integration it was written for (EBay's job.list has
  // a next cursor), which is worse than the false positives it avoided: if a captured page omits
  // the job a published result belongs to, the fix is to capture the page that includes it, or to
  // publish the enqueue case -- exactly what this check should push you to do.
  $issuedJobIds = [];
  foreach (glob("$platDir/*/*.batch/*.response.json") ?: [] as $respFile) {
    $resp = v_loadJson($respFile);
    $jid = $resp['result']['job_id'] ?? null;
    if ($jid !== null) {
      $issuedJobIds[(string)$jid] = true;
    }
  }
  foreach (glob("$platDir/batch/job.list/*.response.json") ?: [] as $listFile) {
    $list = v_loadJson($listFile);
    foreach (($list['result']['jobs'] ?? []) as $job) {
      if (isset($job['id'])) {
        $issuedJobIds[(string)$job['id']] = true;
      }
    }
  }
  if ($issuedJobIds !== []) {
    foreach (glob("$platDir/batch/job.result/*.request.json") ?: [] as $reqFile) {
      $slug = basename($reqFile, '.request.json');
      if (str_starts_with($slug, 'error-')) {
        continue; // not-found / no-params / not-yet-processed are about the lookup, not a real job
      }
      $req = v_loadJson($reqFile);
      $jid = (string)(($req['query'] ?? $req['body'] ?? [])['id'] ?? '');
      if ($jid === '' || isset($issuedJobIds[$jid])) {
        continue;
      }
      if (isset(V_UNREACHABLE_JOB_DEBT[basename($platDir) . '/' . $slug])) {
        continue; // known pre-existing debt, see the constant
      }
      $errors[] = "$platDir: batch/job.result/$slug reads job_id $jid, which no *.batch case "
        . "returns and no batch/job.list case lists -- nothing in this integration leads an agent to it";
    }
  }

  return $errors;
}

/**
 * Check 12: a job_id published by a *.batch response must be unique across the WHOLE repo.
 *
 * Runs across integrations, so it lives outside validateDataset(). Two integrations minting the
 * same job_id is not a cosmetic clash: if the other one also publishes a batch/job.result for it,
 * an agent following README's "look the job up next to whichever *.batch created it" gets a
 * confident, well-formed answer belonging to a different integration (APITOCART-46054 #1).
 *
 * @param array<int, string> $platDirs
 * @return array<int, string>
 */
function validateJobIdsUnique(array $platDirs): array
{
  $errors = [];
  $seen = [];
  foreach ($platDirs as $platDir) {
    foreach (glob("$platDir/*/*.batch/*.response.json") ?: [] as $respFile) {
      $resp = v_loadJson($respFile);
      $jid = $resp['result']['job_id'] ?? null;
      if ($jid === null) {
        continue;
      }
      $jid = (string)$jid;
      $here = $platDir . '/' . basename(dirname($respFile)) . '/' . basename($respFile, '.response.json');
      if (isset($seen[$jid]) && strtok($seen[$jid], '/') !== strtok($here, '/')) {
        $errors[] = "job_id $jid is published by both {$seen[$jid]} and $here -- job ids must be "
          . "unique across integrations, or an agent resolving a job lands in the wrong one";
      } else {
        $seen[$jid] = $here;
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
