<?php

declare(strict_types=1);

namespace Drupal\canvas\Command;

use Drupal\canvas\Health\Doctor;
use Drupal\canvas\Health\EnvironmentInterface;
use Drupal\canvas\Health\HealthCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Terminal;

/**
 * Checks the health of Canvas' data by running every check against it.
 *
 * Requires Drupal >= 11.4 (`dr` CLI). Thin wrapper over Doctor, so the same
 * logic can power a web UI (https://www.drupal.org/i/3588907).
 *
 * @phpstan-import-type Finding from \Drupal\canvas\Health\Doctor
 * @phpstan-import-type SystemCheckResult from \Drupal\canvas\Health\Doctor
 * @internal
 * @see https://www.drupal.org/node/3584928
 * @see docs/adr/0017-data-health-validation-check-based-coverage-with-environment-fingerprinted-incremental-results.md
 */
#[AsCommand(
  name: 'canvas:doctor',
  description: "Validates all Canvas data and reports any integrity problems.",
)]
final class DoctorCommand extends Command {

  /**
   * Version of the --format=json envelope. Bumped only on breaking changes.
   *
   * A check whose entry in docs/data-health.schema.json precisely constrains
   * its `details` freezes that shape; one that falls back to the generic entry
   * makes no such promise and may change without a version bump.
   *
   * @see docs/data-health.md
   */
  public const int REPORT_VERSION = 1;

  private const string ALL_CHECKS_WARNING = "Running 'all' checks includes future ones, so the set may change across Canvas releases without warning. For stable automation, enumerate the checks you want explicitly with --checks.";

  public function __construct(
    private readonly Doctor $validator,
    private readonly EnvironmentInterface $environment,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->addOption('cache', NULL, InputOption::VALUE_NEGATABLE, 'Reuse previously stored results while still fresh (default). Pass --no-cache to ignore them and revalidate everything requested; fresh outcomes are still recorded.', TRUE)
      ->addOption('checks', NULL, InputOption::VALUE_OPTIONAL, 'Limit validation to one or more comma-separated checks: ' . \implode(', ', self::checkValues()) . '.')
      ->addOption('all-checks', NULL, InputOption::VALUE_NONE, 'Run every check without interaction. Exploratory only: the set of checks can change without warning, so use --checks instead for reliable automation.')
      ->addOption('details', NULL, InputOption::VALUE_NONE, 'Show per-violation details for unhealthy checks.')
      ->addOption('format', NULL, InputOption::VALUE_REQUIRED, 'Output format: table (default) or json.', 'table')
      ->setHelp(self::checksHelp());
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);

    $json = $input->getOption('format') === 'json';

    if (!$json) {
      self::printBanner($io);
    }

    $all_checks = (bool) $input->getOption('all-checks');
    // A bare `--checks` (no value) yields NULL because the option's value is
    // optional; treat it the same as omitting the option entirely.
    $checks_option = $input->getOption('checks');
    if ($all_checks) {
      $checks = HealthCheck::cases();
      if (!$json) {
        $io->warning(self::ALL_CHECKS_WARNING . ' Because of that, --all-checks always exits non-zero; gate automation on --checks instead.');
      }
    }
    elseif ($checks_option !== NULL) {
      $checks = self::resolveChecks($checks_option, $io);
      if ($checks === NULL) {
        return self::INVALID;
      }
    }
    else {
      $io->error('Use --checks to specify which data to validate, or --all-checks to run everything.');
      return self::INVALID;
    }

    $cache = (bool) $input->getOption('cache');

    $system_results = [];
    foreach ($checks as $check) {
      if (!$check->isSystemCheck()) {
        continue;
      }
      $start_ns = \hrtime(TRUE);
      $result = $this->validator->runSystemCheck($check);
      $system_results[$check->value] = [
        'result' => $result,
        'duration_ms' => (\hrtime(TRUE) - $start_ns) / 1_000_000,
      ];
    }

    // Live progress only when a human is plausibly watching: no explicit
    // --format (even --format=table implies scripted/captured output) and not
    // running with --no-interaction.
    $show_progress = !$input->hasParameterOption('--format', TRUE) && $input->isInteractive();

    $check_stats = [];
    $bar = NULL;
    foreach ($checks as $check) {
      if ($check->isSystemCheck()) {
        continue;
      }
      $total = 0;
      $valid = 0;
      $validated = 0;
      $failures = [];
      if ($show_progress) {
        if ($bar === NULL) {
          $bar = self::createDoctorProgressBar($io);
          $bar->start();
        }
        $bar->setMessage($check->value);
      }
      $start_ns = \hrtime(TRUE);
      foreach ($this->validator->runDataChecks([$check], $cache) as $item) {
        $total++;
        if (!$item['cached']) {
          $validated++;
        }
        $item_has_failure = FALSE;
        foreach ($item['findings'] as $finding) {
          if ($finding['violations'] !== []) {
            $failures[] = $finding;
            $item_has_failure = TRUE;
          }
        }
        if (!$item_has_failure) {
          $valid++;
        }
        $bar?->advance();
      }
      $duration_ms = (\hrtime(TRUE) - $start_ns) / 1_000_000;
      $check_stats[$check->value] = [
        'total' => $total,
        'valid' => $valid,
        'validated' => $validated,
        'failures' => $failures,
        'duration_ms' => $duration_ms,
      ];
    }
    if ($bar !== NULL) {
      $bar->finish();
      $bar->clear();
    }

    // Risk-kind data checks (auto-save) never make the run unhealthy.
    $all_failures = [];
    foreach ($check_stats as $check_value => $stats) {
      if (!HealthCheck::from($check_value)->findingsAreRisks()) {
        $all_failures = \array_merge($all_failures, $stats['failures']);
      }
    }

    $has_system_problem = FALSE;
    foreach ($system_results as $system_result) {
      if ($system_result['result']['status'] === 'problem') {
        $has_system_problem = TRUE;
        break;
      }
    }
    $is_overall_healthy = $all_failures === [] && !$has_system_problem;

    if ($json) {
      $report = self::jsonReport($check_stats, $system_results, $this->environment->getFingerprint());
      // OUTPUT_RAW: angle brackets in a violation message must not be read
      // as style tags.
      $output->writeln((string) \json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
      return ($is_overall_healthy && !$all_checks) ? self::SUCCESS : self::FAILURE;
    }

    $details = (bool) $input->getOption('details');
    $reuse_disabled_extensions = $this->environment->getDevExtensions();
    self::renderReport($io, $system_results, $check_stats, $reuse_disabled_extensions, $details);
    return ($is_overall_healthy && !$all_checks) ? self::SUCCESS : self::FAILURE;
  }

  /**
   * @return \Drupal\canvas\Health\HealthCheck[]|null
   */
  private static function resolveChecks(string $checks_option, SymfonyStyle $io): ?array {
    $values = \array_filter(\array_map('trim', \explode(',', $checks_option)));
    if ($values === []) {
      $io->error(\sprintf('The --checks option requires at least one check name. Valid checks: %s.', \implode(', ', self::checkValues())));
      return NULL;
    }
    $resolved = [];
    foreach ($values as $value) {
      $c = HealthCheck::tryFrom($value);
      if ($c === NULL) {
        $io->error(\sprintf("Unknown check '%s'. Valid checks: %s.", $value, \implode(', ', self::checkValues())));
        return NULL;
      }
      $resolved[] = $c;
    }
    return $resolved;
  }

  /**
   * @return string[]
   */
  private static function checkValues(): array {
    return \array_column(HealthCheck::cases(), 'value');
  }

  /**
   * Builds the command's --help text: every check name plus what it does.
   *
   * Shown by `dr help canvas:doctor` and `dr canvas:doctor --help`, so
   * --checks needs no separate --list-checks option to discover its values.
   */
  private static function checksHelp(): string {
    $lines = ['Available checks (for --checks):'];
    foreach (HealthCheck::cases() as $check) {
      $lines[] = \sprintf('  <info>%s</info>  %s', $check->value, $check->explanation());
    }
    return \implode("\n", $lines);
  }

  private static function printBanner(SymfonyStyle $io): void {
    $io->newLine();
    $io->writeln('  <fg=cyan;options=bold>🩺  Canvas Doctor</>  <fg=gray>·  a check-up for your Canvas data</>');
    $io->writeln('  <fg=magenta>__/\\__/\\____/\\______/\\__/\\___♥___</>');
    $io->newLine();
  }

  private static function createDoctorProgressBar(SymfonyStyle $io): ProgressBar {
    $bar = $io->createProgressBar();
    $bar->setBarWidth(18);
    $bar->setBarCharacter('<fg=green>━</>');
    $bar->setProgressCharacter('<fg=green>╺</>');
    $bar->setEmptyBarCharacter('<fg=gray>░</>');
    $bar->setFormat(" 🩺 <fg=cyan;options=bold>%message%</> %bar% <fg=gray>%current% items · %elapsed%</>");
    return $bar;
  }

  /**
   * @param array<string, array{total: int, valid: int, validated: int, failures: list<Finding>, duration_ms: float}> $check_stats
   * @param array<string, array{result: SystemCheckResult, duration_ms: float}> $system_results
   * @return array<string, mixed>
   */
  private static function jsonReport(array $check_stats, array $system_results, string $fingerprint): array {
    $entry = static fn (HealthCheck $check, string $status, int $total, int $healthy, int $cached, float $duration_s, array $details): array => [
      'type' => $check->isSystemCheck() ? 'system' : 'data',
      'status' => $status,
      'total' => $total,
      'healthy' => $healthy,
      'cached' => $cached,
      'duration_s' => $duration_s,
      'details' => $details,
    ];

    $checks = [];
    foreach ($system_results as $check_value => $system_result) {
      $result = $system_result['result'];
      $checks[$check_value] = $entry(
        HealthCheck::from($check_value),
        $result['status'],
        $result['total'], $result['healthy'], 0, \round($system_result['duration_ms'] / 1000, 3),
        $result['details'],
      );
    }
    foreach ($check_stats as $check_value => $stats) {
      $check = HealthCheck::from($check_value);
      $checks[$check_value] = $entry(
        $check,
        $stats['failures'] === [] ? 'healthy' : ($check->findingsAreRisks() ? 'risk' : 'problem'),
        $stats['total'], $stats['valid'], $stats['total'] - $stats['validated'], \round($stats['duration_ms'] / 1000, 3),
        ['failures' => $stats['failures']],
      );
    }

    $statuses = \array_column(\array_values($checks), 'status');
    $problems = \count(\array_filter($statuses, static fn (string $s): bool => $s === 'problem'));
    $risks = \count(\array_filter($statuses, static fn (string $s): bool => $s === 'risk'));

    return [
      'report_version' => self::REPORT_VERSION,
      'environment_fingerprint' => $fingerprint,
      'overall' => [
        'healthy' => $problems === 0,
        'problems' => $problems,
        'risks' => $risks,
      ],
      'checks' => $checks,
    ];
  }

  /**
   * Prints the whole report: summary table, then details, then verdict.
   *
   * Three steps: (1) walk $system_results and $check_stats once, building one
   * summary-table row per check plus a "section" (a label and description
   * lines) for each check that found a problem or risk; (2) print the summary
   * table, grouped into a System block and a Data block; (3) either print
   * every section in full (--details) or just a short prescription list, then
   * the final healthy/unhealthy message.
   *
   * @param array<string, array{result: SystemCheckResult, duration_ms: float}> $system_results
   * @param array<string, array{total: int, valid: int, validated: int, failures: list<Finding>, duration_ms: float}> $check_stats
   * @param string[] $reuse_disabled_extensions
   */
  private static function renderReport(SymfonyStyle $io, array $system_results, array $check_stats, array $reuse_disabled_extensions, bool $details = FALSE): void {
    $columns = ['Check', 'Status', 'Total', 'Healthy', 'Cached', '% Healthy', 'Duration', 'Prescription'];
    // Sections are keyed by check name; each holds the list of problems (or
    // risks) that --details would print for that check. Kept separate from
    // $risk_sections because risks are shown as ⚠️ and never fail the run.
    $problem_sections = [];
    $risk_sections = [];
    // Table rows, kept apart so the summary table can print a "System"
    // header above one group and a "Data" header above the other.
    $system_rows = [];
    $data_rows = [];

    // Builds one row of the summary table for a single check. `cached` is
    // always 0 for system checks, which have no cacheable items. `kind`
    // picks the status icon: 'health' shows ✅/❌, 'risk' shows ✅/⚠️.
    $format_row = static function (string $check, int $total, int $healthy, int $cached, int $count, float $duration_ms, string $kind = 'health'): array {
      if ($count === 0) {
        $status = '<info>✅ healthy</info>';
      }
      elseif ($kind === 'risk') {
        // VS15 (not VS16/emoji) keeps this one column wide for the table's
        // width math, which is reported incorrectly as 2 for some terminals.
        // @see https://en.wikipedia.org/wiki/Emoticons_(Unicode_block)#Variant_forms
        // @see https://en.wikipedia.org/wiki/Variation_Selectors_(Unicode_block)
        $status = \sprintf('<comment>⚠︎  %d risk(s)</comment>', $count);
      }
      else {
        $status = \sprintf('<error>❌ %d problem(s)</error>', $count);
      }
      $pct = $total > 0 ? \number_format($healthy / $total * 100, 1) . '%' : 'N/A';
      return [
        $check,
        $status,
        (string) $total,
        (string) $healthy,
        (string) $cached,
        $pct,
        \number_format($duration_ms / 1000, 3) . 's',
        $count > 0 ? (HealthCheck::from($check)->prescription() ?? '') : '',
      ];
    };

    // Turns one problem/violation into the shape a detail section prints: a
    // bold label followed by zero or more indented description lines.
    $problem = static fn (string $id, string $description = ''): array => [
      'label' => $id,
      'lines' => $description === '' ? [] : ['    ' . $description],
    ];

    // System checks: one row per check, plus a section per problem it found
    // (a system check reports its own list of discrete problems directly).
    foreach ($system_results as $check_value => $system_result) {
      $result = $system_result['result'];
      $kind = $result['status'] === 'risk' ? 'risk' : 'health';
      $system_rows[] = $format_row($check_value, $result['total'], $result['healthy'], 0, \count($result['problems']), $system_result['duration_ms'], $kind);
      if ($result['problems'] === []) {
        continue;
      }
      $sections = \array_map(
        static fn (array $p): array => $problem($p['label'], $p['description']),
        $result['problems'],
      );
      if ($kind === 'risk') {
        $risk_sections[$check_value] = $sections;
      }
      else {
        $problem_sections[$check_value] = $sections;
      }
    }

    // Data checks: one row per check, plus a section per failing item, each
    // listing that item's individual constraint violations.
    foreach ($check_stats as $check_value => $stats) {
      $failure_count = \count($stats['failures']);
      $cached = $stats['total'] - $stats['validated'];
      $kind = HealthCheck::from($check_value)->findingsAreRisks() ? 'risk' : 'health';
      $data_rows[] = $format_row($check_value, $stats['total'], $stats['valid'], $cached, $failure_count, $stats['duration_ms'], $kind);
      if ($failure_count > 0) {
        $problems = [];
        foreach ($stats['failures'] as $failure) {
          $lines = [];
          foreach ($failure['violations'] as $violation) {
            $lines[] = $violation['property_path'] !== ''
              ? \sprintf('    <fg=magenta>[%s]</> %s', $violation['property_path'], $violation['message'])
              : \sprintf('    %s', $violation['message']);
          }
          $problems[] = ['label' => $failure['label'], 'lines' => $lines];
        }
        if ($kind === 'risk') {
          $risk_sections[$check_value] = $problems;
        }
        else {
          $problem_sections[$check_value] = $problems;
        }
      }
    }

    // Assemble the summary table: a "System" header row above the system
    // rows, a "Data" header row above the data rows, with either group
    // omitted entirely if it's empty.
    $colspan = \count($columns);
    $rows = [];
    if ($system_rows !== []) {
      $rows[] = [new TableCell('<fg=cyan;options=bold>System</>', ['colspan' => $colspan])];
      $rows = \array_merge($rows, $system_rows);
    }
    if ($data_rows !== []) {
      if ($system_rows !== []) {
        $rows[] = new TableSeparator();
      }
      $rows[] = [new TableCell('<fg=cyan;options=bold>Data</>', ['colspan' => $colspan])];
      $rows = \array_merge($rows, $data_rows);
    }
    self::renderTable($io, $columns, $rows);

    // Explain a Cached column full of zeros, so it doesn't look like a bug:
    // it means at least one dev-checkout extension made cached results
    // untrustworthy, not that caching is broken.
    if ($reuse_disabled_extensions !== [] && $check_stats !== []) {
      self::infoNote($io, \sprintf(
        "Result reuse is disabled, so every data item is revalidated on each run (Cached = 0). "
        . "%d installed extension(s) are development checkouts with no release version (%s); "
        . "their code can change without changing the environment fingerprint, so cached results cannot be trusted. "
        . "Reuse activates automatically once every installed extension is at a tagged release.",
        \count($reuse_disabled_extensions),
        \implode(', ', $reuse_disabled_extensions),
      ));
    }

    // Prints one check's full detail section: a heading, a numbered list of
    // its problems (each with its description/violation lines), and that
    // check's prescription. Only called when --details is passed.
    $render_section = static function (string $name, array $problems, string $id_style) use ($io): void {
      $io->newLine();
      $io->writeln('<fg=cyan;options=bold>' . $name . '</>');
      $io->writeln('<fg=cyan>' . \str_repeat('─', \mb_strlen($name)) . '</>');
      $n = 0;
      foreach ($problems as $problem) {
        $n++;
        $io->text(\sprintf(' %d. <%s>%s</%s>', $n, $id_style, $problem['label'], $id_style));
        foreach ($problem['lines'] as $line) {
          $io->text($line);
        }
      }
      $steps = self::prescriptionsFor([$name], TRUE);
      if ($steps !== []) {
        $io->newLine();
        $io->text('<fg=cyan;options=bold>💊 Prescription:</>');
        foreach ($steps as $step) {
          self::prescriptionStep($io, $step);
        }
      }
    };

    // --details: print every problem section in full (errors first, then
    // risks), instead of just the one-line summary row each got in the table.
    if (($problem_sections !== [] || $risk_sections !== []) && $details) {
      foreach ($problem_sections as $check_name => $problems) {
        $render_section($check_name, $problems, 'error');
      }
      foreach ($risk_sections as $check_name => $problems) {
        $render_section($check_name, $problems, 'comment');
      }
      $io->newLine();
    }

    $risk_count = \array_sum(\array_map('count', $risk_sections));

    // Risks alone never make the run unhealthy: only real problems do.
    if ($problem_sections === []) {
      $io->success('Clean bill of health! Your Canvas installation is healthy.');
      if ($risk_count > 0 && !$details) {
        self::infoNote($io, \sprintf('%d advisory risk(s) noted — re-run with --details to review them.', $risk_count));
      }
      return;
    }

    // Without --details, skip the full sections and print just a short,
    // deduplicated prescription list plus a copy-pasteable follow-up command
    // (added by prescriptionsFor()'s $details=FALSE branch).
    if (!$details) {
      $prescriptions = self::prescriptionsFor(\array_keys($problem_sections), FALSE);
      if ($prescriptions !== []) {
        $io->text('<fg=cyan;options=bold>💊 Prescription:</>');
        foreach ($prescriptions as $step) {
          self::prescriptionStep($io, $step);
        }
        $io->newLine();
      }
    }
    $io->error(\sprintf('Diagnosis: %d check(s) need attention.', \count($problem_sections)));
  }

  private static function infoNote(SymfonyStyle $io, string $message): void {
    $io->block($message, 'NOTE', 'fg=cyan', ' ! ', TRUE);
  }

  /**
   * Prints one prescription step, wrapped to the terminal width.
   */
  private static function prescriptionStep(SymfonyStyle $io, string $step): void {
    if (!$io->isDecorated()) {
      $io->text('    <fg=cyan>→</> ' . $step);
      return;
    }
    $width = \max(40, (new Terminal())->getWidth() - 8);
    foreach (\explode("\n", \wordwrap($step, $width, "\n", TRUE)) as $i => $line) {
      $io->text(($i === 0 ? '    <fg=cyan>→</> ' : '      ') . $line);
    }
  }

  /**
   * Renders the summary table, fitted to the terminal width.
   *
   * A Symfony table never wraps on its own: one wider than the terminal
   * hard-wraps at the terminal edge and mangles every row. All columns except
   * Prescription hold short values, so Prescription gets whatever width the
   * other columns leave over, and wraps within it.
   *
   * @param string[] $columns
   * @param array<int, mixed> $rows
   */
  private static function renderTable(SymfonyStyle $io, array $columns, array $rows): void {
    $formatter = new OutputFormatter();
    $last = \count($columns) - 1;
    $fixed_width = 0;
    foreach (\array_keys($columns) as $i) {
      if ($i === $last) {
        continue;
      }
      $column_width = Helper::width($columns[$i]);
      foreach ($rows as $row) {
        if (\is_array($row) && \count($row) === \count($columns) && \is_string($row[$i] ?? NULL)) {
          $column_width = \max($column_width, Helper::width(Helper::removeDecoration($formatter, $row[$i])));
        }
      }
      // Each column costs its content width plus 3 characters of padding.
      $fixed_width += $column_width + 3;
      if ($i === 0) {
        $check_max = $column_width;
      }
    }
    \assert(isset($check_max));
    $terminal_width = (new Terminal())->getWidth();
    $prescription_max = \max(24, $terminal_width - $fixed_width - 3);

    $build = static function ($output, ?int $check_max, ?int $prescription_max) use ($columns, $rows, $last): Table {
      $style = clone Table::getStyleDefinition('symfony-style-guide');
      $style->setCellHeaderFormat('<info>%s</info>');
      $table = new Table($output);
      $table->setStyle($style);
      $table->setHeaders($columns);
      $table->setRows($rows);
      if ($check_max !== NULL && $prescription_max !== NULL) {
        $table->setColumnMaxWidth(0, $check_max);
        $table->setColumnMaxWidth($last, $prescription_max);
      }
      return $table;
    };

    // Only a real terminal hard-wraps overlong lines into a mangled table;
    // for piped or captured output, print at natural width instead.
    if (!$io->isDecorated()) {
      $build($io, NULL, NULL)->render();
      $io->newLine();
      return;
    }

    // The style's exact rendering overhead is easier to measure than to
    // predict: dry-run into a buffer, then shrink the wrappable columns —
    // Prescription first, the Check names as a last resort — by however much
    // the widest rendered line still overflows the terminal.
    for ($attempt = 0; $attempt < 3; $attempt++) {
      $buffer = new BufferedOutput();
      $build($buffer, $check_max, $prescription_max)->render();
      $widest = 0;
      foreach (\explode("\n", $buffer->fetch()) as $line) {
        $widest = \max($widest, Helper::width($line));
      }
      $overflow = $widest - $terminal_width;
      if ($overflow <= 0) {
        break;
      }
      $take = \min($overflow, $prescription_max - 20);
      $prescription_max -= $take;
      $overflow -= $take;
      if ($overflow > 0) {
        $check_max = \max(20, $check_max - $overflow);
      }
    }

    $build($io, $check_max, $prescription_max)->render();
    $io->newLine();
  }

  /**
   * Maps the unhealthy checks to the concrete commands that address them.
   *
   * $details: whether --details was already passed (suppresses the hint).
   *
   * @param string[] $unhealthy_checks
   *
   * @return string[]
   */
  private static function prescriptionsFor(array $unhealthy_checks, bool $details): array {
    $steps = [];
    foreach ($unhealthy_checks as $check_value) {
      $prescription = HealthCheck::from($check_value)->prescription();
      if ($prescription !== NULL && !\in_array($prescription, $steps, TRUE)) {
        $steps[] = $prescription;
      }
    }
    if ($unhealthy_checks !== [] && !$details) {
      $steps[] = \sprintf('Inspect each problem: <info>vendor/bin/dr canvas:doctor --checks=%s --details</info>', \implode(',', $unhealthy_checks));
    }
    return $steps;
  }

}
