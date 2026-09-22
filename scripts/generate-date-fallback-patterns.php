<?php

/**
 * @file
 * Generates src/Utility/DateFallbackPatterns.php from ICU data.
 *
 * \Drupal\canvas\Utility\DateResolver uses the generated patterns when the
 * intl PHP extension is unavailable. This script needs ext-intl: for every
 * langcode in Drupal core's standard language list, it reads the ICU short
 * date and time patterns, converts them to PHP date() patterns, and keeps a
 * langcode only when date() output is identical to \IntlDateFormatter output
 * for all sample values and all three formats (date, date_time, time).
 *
 * Usage, from the Canvas module root:
 *   composer run generate:date-fallback-patterns
 *
 * A summary (ICU version, generated and skipped langcodes) goes to STDERR.
 * Commit the regenerated file.
 */

declare(strict_types=1);

use Drupal\Core\Language\LanguageManager;

if (!extension_loaded('intl')) {
  fwrite(STDERR, "ext-intl is required.\n");
  exit(1);
}

// Find the host Drupal project's autoloader by walking up from this file.
$autoloader = NULL;
for ($dir = __DIR__; $dir !== dirname($dir); $dir = dirname($dir)) {
  if (file_exists("$dir/vendor/autoload.php")) {
    $autoloader = "$dir/vendor/autoload.php";
    break;
  }
}
if ($autoloader === NULL) {
  fwrite(STDERR, "vendor/autoload.php not found. Canvas must be inside a Drupal project.\n");
  exit(1);
}
require $autoloader;

// ICU pattern tokens that date() can express. 'a' is resolved per locale.
const TOKENS = [
  'yyyy' => 'Y',
  'yy' => 'y',
  'y' => 'Y',
  'MM' => 'm',
  'M' => 'n',
  'dd' => 'd',
  'd' => 'j',
  'HH' => 'H',
  'H' => 'G',
  'hh' => 'h',
  'h' => 'g',
  'mm' => 'i',
  'a' => 'A',
];

// Morning and afternoon, 1-digit and 2-digit day, month and hour, midnight
// and noon.
const SAMPLES = [
  '2026-01-05T09:05:00Z',
  '2026-11-25T14:30:00Z',
  '2026-07-04T00:00:00Z',
  '2026-03-09T12:00:00Z',
];

const TYPES = [
  'date' => [\IntlDateFormatter::SHORT, \IntlDateFormatter::NONE],
  'date_time' => [\IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT],
  'time' => [\IntlDateFormatter::NONE, \IntlDateFormatter::SHORT],
];

const OUTPUT_FILE = __DIR__ . '/../src/Utility/DateFallbackPatterns.php';

/**
 * Replaces the space variants that ICU uses with a normal space.
 */
function normalize_spaces(string $value): string {
  return str_replace(["\u{202f}", "\u{a0}"], ' ', $value);
}

/**
 * Converts an ICU pattern to a date() pattern.
 *
 * @param string $icu
 *   ICU pattern, for example "M/d/yy, h:mm a".
 * @param string $meridiem
 *   The date() token to use for ICU's 'a': 'A' (AM/PM) or 'a' (am/pm).
 *
 * @return string|null
 *   The date() pattern, or NULL when the ICU pattern has a token that date()
 *   cannot express.
 */
function convert(string $icu, string $meridiem): ?string {
  $php = '';
  $parts = preg_split("/('[^']*'|[A-Za-z]+)/u", $icu, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
  if ($parts === FALSE) {
    return NULL;
  }
  foreach ($parts as $part) {
    if ($part[0] === "'") {
      // Quoted literal text. Escape ASCII letters so date() prints them as-is.
      $php .= addcslashes(substr($part, 1, -1), 'A..Za..z');
    }
    elseif (preg_match('/^[A-Za-z]+$/', $part)) {
      if (!isset(TOKENS[$part])) {
        return NULL;
      }
      $php .= $part === 'a' ? $meridiem : TOKENS[$part];
    }
    else {
      // Punctuation, spaces and non-ASCII literal text.
      $php .= normalize_spaces($part);
    }
  }
  return $php;
}

/**
 * Checks that date() output equals ICU output for all sample values.
 */
function matches(string $php, \IntlDateFormatter $formatter): bool {
  foreach (SAMPLES as $sample) {
    $date = new \DateTimeImmutable($sample);
    if ($date->format($php) !== normalize_spaces((string) $formatter->format($date))) {
      return FALSE;
    }
  }
  return TRUE;
}

$patterns = [];
// Langcodes where date() cannot reproduce ICU output, with the ICU pattern.
$skipped = [];
// Langcodes that ICU does not know.
$unknown = [];

foreach (array_keys(LanguageManager::getStandardLanguageList()) as $langcode) {
  $row = [];
  foreach (TYPES as $key => [$date_type, $time_type]) {
    $formatter = \IntlDateFormatter::create(\Locale::canonicalize($langcode), $date_type, $time_type, 'UTC');
    try {
      // For a locale that ICU does not know, create() returns an object and
      // the first method call throws "Found unconstructed IntlDateFormatter".
      $icu_pattern = $formatter?->getPattern();
    }
    catch (\Throwable) {
      $icu_pattern = FALSE;
    }
    if ($formatter === NULL || !is_string($icu_pattern)) {
      $unknown[] = $langcode;
      continue 2;
    }
    // date() has 'A' (AM/PM) and 'a' (am/pm). Use the one that matches.
    foreach (['A', 'a'] as $meridiem) {
      $php = convert($icu_pattern, $meridiem);
      if ($php !== NULL && matches($php, $formatter)) {
        $row[$key] = $php;
        continue 2;
      }
    }
    $skipped[$langcode] = "$key: $icu_pattern";
    continue 2;
  }
  // DateResolver compares Drupal langcodes in lowercase.
  /**
   * @var array{date: string, date_time: string, time: string} $row
   */
  $patterns[strtolower($langcode)] = $row;
}
ksort($patterns);

fwrite(STDERR, sprintf(
  "ICU %s\n  generated: %d\n  skipped (date() cannot match ICU): %d: %s\n  unknown to ICU: %d: %s\n",
  INTL_ICU_VERSION,
  count($patterns),
  count($skipped),
  implode(', ', array_keys($skipped)),
  count($unknown),
  implode(', ', $unknown),
));

$rows = '';
foreach ($patterns as $langcode => $row) {
  $rows .= sprintf(
    "    %s => ['date' => %s, 'date_time' => %s, 'time' => %s],\n",
    var_export($langcode, TRUE),
    var_export($row['date'], TRUE),
    var_export($row['date_time'], TRUE),
    var_export($row['time'], TRUE),
  );
}

$output = <<<'PHP'
<?php

// phpcs:ignoreFile
// GENERATED by scripts/generate-date-fallback-patterns.php. Do not edit.

declare(strict_types=1);

namespace Drupal\canvas\Utility;

/**
 * Short date/time patterns for when the intl PHP extension is unavailable.
 *
 * Generated from ICU data by scripts/generate-date-fallback-patterns.php. Each
 * row holds PHP date() patterns whose output is identical to
 * \IntlDateFormatter's SHORT output for that langcode. Langcodes whose ICU
 * output date() cannot reproduce (native digits, localized AM/PM text) are
 * not listed.
 *
 * @internal
 */
final class DateFallbackPatterns {

  /**
   * The ICU version the patterns were generated from.
   */
  public const string ICU_VERSION = '%ICU_VERSION%';

  /**
   * Patterns keyed by lowercase Drupal langcode.
   *
   * @var array<string, array{date: string, date_time: string, time: string}>
   */
  public const array PATTERNS = [
%ROWS%  ];

}

PHP;

$output = str_replace(
  ['%ICU_VERSION%', '%ROWS%'],
  [INTL_ICU_VERSION, $rows],
  $output,
);
file_put_contents(OUTPUT_FILE, $output);
fwrite(STDERR, 'Wrote ' . realpath(OUTPUT_FILE) . "\n");
