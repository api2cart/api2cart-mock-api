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

  // ---- checks 2 + 3, plus the file-pair side of check 1 ----
  $fsKeys = [];
  foreach (glob("$platDir/*/*", GLOB_ONLYDIR) ?: [] as $methodDir) {
    $methodFolder = basename($methodDir);
    $entity = basename(dirname($methodDir));
    $relPath = "$entity/$methodFolder";

    foreach (glob("$methodDir/*.request.json") ?: [] as $reqFile) {
      $slug = basename($reqFile, '.request.json');
      $key = "$relPath/$slug";
      $respFile = "$methodDir/$slug.response.json";

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
