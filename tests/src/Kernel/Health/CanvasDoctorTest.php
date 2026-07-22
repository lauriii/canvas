<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Health;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasConfigUpdater;
use Drupal\canvas\Command\DoctorCommand;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Health\Doctor;
use Drupal\canvas\Health\Environment;
use Drupal\canvas\Health\EnvironmentInterface;
use Drupal\canvas\Health\HealthCheck;
use Drupal\canvas\Health\HealthRecords;
use Drupal\canvas\Health\Inventory;
use Drupal\canvas\Hook\HealthHooks;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Site\Settings;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[RunTestsInSeparateProcesses]
#[CoversClass(Doctor::class)]
#[CoversClass(Environment::class)]
#[CoversClass(HealthRecords::class)]
#[CoversClass(DoctorCommand::class)]
#[Group('canvas')]
final class CanvasDoctorTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use PageTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::PAGE_TEST_MODULES,
  ];

  private Doctor $validator;
  private HealthRecords $healthRecords;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->generateComponentConfig();
    $this->installPageEntitySchema();
    $this->validator = $this->container->get(Doctor::class);
    $this->healthRecords = $this->container->get(HealthRecords::class);
  }

  /**
   * A clean site has no violations (Config and Content both ran); an invalid content revision is reported.
   */
  public function testContentValidation(): void {
    // A clean site has no violations, and both Config and Content ran.
    self::createValidPage('Clean page');
    $items = \iterator_to_array($this->validator->runDataChecks(), FALSE);
    self::assertNotEmpty($items);
    self::assertSame([], self::failingFindings($items));
    $checks = \array_unique(\array_map(static fn (array $item): string => $item['check'], $items));
    self::assertContains(HealthCheck::Config->value, $checks);
    self::assertContains(HealthCheck::Content->value, $checks);

    // An invalid content revision is reported.
    self::createInvalidPage('Broken page');
    $items = \iterator_to_array($this->validator->runDataChecks([HealthCheck::Content]), FALSE);
    self::assertNotEmpty(self::failingFindings($items));
  }

  /**
   * Every system check returns the uniform result envelope; risk-status checks are surfaced as a risk (not a failure) by both the engine API and the CLI.
   */
  public function testSystemChecks(): void {
    $envelope_keys = ['status', 'total', 'healthy', 'details', 'problems'];
    $updates_escaped_config = $this->validator->runSystemCheck(HealthCheck::UpdatesEscapedConfig);
    $updates_executed = $this->validator->runSystemCheck(HealthCheck::UpdatesExecuted);
    $code_tagged_releases = $this->validator->runSystemCheck(HealthCheck::CodeTaggedReleases);
    $schema_evolution = $this->validator->runSystemCheck(HealthCheck::ComponentSourceSchemaEvolution);

    // Every system check returns the same envelope shape.
    foreach ([$updates_escaped_config, $updates_executed, $code_tagged_releases, $schema_evolution] as $result) {
      self::assertSame($envelope_keys, \array_keys($result));
    }

    // UpdatesEscapedConfig: a clean site has no config entities pending an update path.
    self::assertSame('healthy', $updates_escaped_config['status']);
    self::assertSame([], $updates_escaped_config['details']['pending']);
    self::assertSame($updates_escaped_config['total'], $updates_escaped_config['healthy']);

    // UpdatesExecuted: status reflects whether any post-updates are pending.
    self::assertSame(['schema_version', 'applied_post_updates', 'pending_post_updates'], \array_keys($updates_executed['details']));
    self::assertSame(
      \count($updates_executed['details']['applied_post_updates']) + \count($updates_executed['details']['pending_post_updates']),
      $updates_executed['total'],
    );
    self::assertSame(\count($updates_executed['details']['applied_post_updates']), $updates_executed['healthy']);
    $expected_status = $updates_executed['details']['pending_post_updates'] === [] ? 'healthy' : 'problem';
    self::assertSame($expected_status, $updates_executed['status']);

    // CodeTaggedReleases: Canvas is a dev-checkout extension in tests, a risk rather than a failure.
    self::assertSame('risk', $code_tagged_releases['status']);
    self::assertContains('canvas', $code_tagged_releases['details']['dev_extensions']);
    self::assertSame($code_tagged_releases['total'], $code_tagged_releases['healthy']);

    // ComponentSourceSchemaEvolution: `block` has no updater (risk); `fallback` has no schema, so it is excluded entirely; sdc and js declare updaters.
    self::assertSame('risk', $schema_evolution['status']);
    self::assertContains('block', $schema_evolution['details']['failing']);
    self::assertSame($schema_evolution['total'], $schema_evolution['healthy']);
    self::assertArrayNotHasKey('fallback', $schema_evolution['details']['sources']);
    self::assertNotContains('fallback', $schema_evolution['details']['failing']);
    self::assertTrue($schema_evolution['details']['sources']['sdc']['has_updater']);
    self::assertTrue($schema_evolution['details']['sources']['js']['has_updater']);

    // The CLI surfaces the same schema-evolution risk: a numbered detail list and block's change record.
    $tester = $this->runCommand(['--checks' => HealthCheck::ComponentSourceSchemaEvolution->value, '--details' => TRUE]);
    self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    $output = $tester->getDisplay();
    self::assertStringContainsString('risk(s)', $output);
    self::assertStringContainsString('block', $output);
    self::assertStringContainsString('https://www.drupal.org/node/3521221', $output);
    self::assertStringContainsString('1.', $output);

    // The CLI surfaces the same tagged-releases risk: the dev extension itself.
    $tester = $this->runCommand(['--checks' => HealthCheck::CodeTaggedReleases->value, '--details' => TRUE]);
    self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    $output = $tester->getDisplay();
    self::assertStringContainsString('code_tagged_releases', $output);
    self::assertStringContainsString('risk(s)', $output);
    self::assertStringContainsString('canvas_test_sdc', $output);
  }

  /**
   * Result reuse is disabled while dev-checkout extensions are present, and activates once they are gone (simulated via StubEnvironment, since Canvas is always a dev checkout in tests).
   */
  public function testIncrementalReuse(): void {
    // Dev extensions present: every item is revalidated on every run.
    $environment = $this->container->get(Environment::class);
    self::assertNotEmpty($environment->getDevExtensions());
    self::createValidPage('Reusable page');
    self::assertAllCached($this->validator->runDataChecks(), FALSE);
    self::assertAllCached($this->validator->runDataChecks(), FALSE);

    // Switch to an environment without dev extensions, so reuse can activate.
    [$validator] = $this->buildEngineWithEnvironment(new StubEnvironment('test-fixed-fingerprint'));
    $page = self::createValidPage('Reusable page (no dev extensions)');
    $content_key = \sprintf(Doctor::KEY_FORMAT, Page::ENTITY_TYPE_ID, $page->id());
    // First run: nothing stored yet, so every item is freshly validated.
    self::assertAllCached($validator->runDataChecks([HealthCheck::Content]), FALSE);
    // Second, identical run: every item is reused from the store.
    self::assertAllCached($validator->runDataChecks([HealthCheck::Content]), TRUE);
    // Cache off bypasses reads but still records: everything is revalidated, and the next plain run reuses again.
    self::assertAllCached($validator->runDataChecks([HealthCheck::Content], FALSE), FALSE);
    self::assertAllCached($validator->runDataChecks([HealthCheck::Content]), TRUE);

    // Saving bumps the page's cache tag: only its own item is revalidated; any other item present stays reused.
    $page->save();
    $third = \iterator_to_array($validator->runDataChecks([HealthCheck::Content]), FALSE);
    self::assertNotEmpty($third);
    foreach ($third as $item) {
      $expected_cached = $item['key'] !== $content_key;
      self::assertSame($expected_cached, $item['cached']);
    }
  }

  /**
   * GetSummary() aggregates stored results by fingerprint and check without validating; deleting an entity or revision immediately prunes its rows via HealthHooks.
   */
  public function testSummary(): void {
    // A fresh store, before any run, summarizes to nothing.
    $environment = new StubEnvironment('fp-summary');
    [$engine, $store] = $this->buildEngineWithEnvironment($environment);
    $summary = $store->getSummary();
    self::assertSame(0, $summary['total_items']);
    self::assertSame(0, $summary['problem_items']);
    self::assertNull($summary['last_checked_at']);

    // After a run, the summary reflects what was stored, overall and per check.
    self::createValidPage('Summary page');
    self::createInvalidPage('Broken summary page');
    \iterator_to_array($engine->runDataChecks([HealthCheck::Content]), FALSE);
    $summary = $store->getSummary();
    self::assertGreaterThan(0, $summary['total_items']);
    self::assertGreaterThan(0, $summary['problem_items']);
    self::assertNotNull($summary['last_checked_at']);
    // Allowlist: only Content ran, so a Config-scoped summary is empty.
    self::assertGreaterThanOrEqual(1, $store->getSummary([HealthCheck::Content])['problem_items']);
    self::assertSame(0, $store->getSummary([HealthCheck::Config])['total_items']);

    // Flipping the fingerprint (as a Canvas upgrade would) drops rows stored under the old one back to "nothing computed yet".
    $environment->fingerprint = 'fp-summary-B';
    $summary = $store->getSummary();
    self::assertSame(0, $summary['total_items']);
    self::assertSame(0, $summary['problem_items']);
    self::assertNull($summary['last_checked_at']);

    // Deleting a single revision prunes exactly its row (hook_entity_revision_delete), and deleting an entity prunes all of its remaining rows by key prefix (hook_entity_delete) — no run needed afterwards.
    [$engine, $store] = $this->buildEngineWithEnvironment(new StubEnvironment('fp-prune'));
    $page = self::createValidPage('Prunable page');
    $first_rid = $page->getRevisionId();
    self::assertNotNull($first_rid);
    $page->setNewRevision(TRUE);
    $page->save();
    \iterator_to_array($engine->runDataChecks([HealthCheck::Content, HealthCheck::ContentPastRevisions]), FALSE);
    $content_before = $store->getSummary([HealthCheck::Content])['total_items'];
    $past_before = $store->getSummary([HealthCheck::ContentPastRevisions])['total_items'];
    self::assertGreaterThanOrEqual(1, $content_before);
    self::assertGreaterThanOrEqual(1, $past_before);
    $page_storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);
    \assert($page_storage instanceof RevisionableStorageInterface);
    $page_storage->deleteRevision($first_rid);
    self::assertSame($past_before - 1, $store->getSummary([HealthCheck::ContentPastRevisions])['total_items']);
    $page->delete();
    self::assertSame($content_before - 1, $store->getSummary([HealthCheck::Content])['total_items']);
  }

  /**
   * Hook_cron covers every data check (including past revisions) and advances/wraps a persistent State cursor, bounded by items inspected (not items freshly validated, since the real dev-checkout engine here disables result reuse).
   */
  public function testCronCursor(): void {
    $page = self::createValidPage('Cron cursor page 1');
    // A second revision turns the first one into a past revision.
    $page->setNewRevision(TRUE);
    $page->save();
    self::createValidPage('Cron cursor page 2');
    self::createValidPage('Cron cursor page 3');

    $state = $this->container->get('state');
    $hook = new HealthHooks($this->validator, $state, $this->healthRecords);
    $total = 0;
    foreach (HealthCheck::dataChecks() as $check) {
      $total += $this->validator->countCheckItems($check);
    }
    self::assertGreaterThan(0, $total);

    // Loop until the cursor wraps to 0; bound so a never-wrapping regression fails instead of hanging.
    $seen_nonzero = FALSE;
    $previous = 0;
    $iterations = 0;
    do {
      $hook->cron();
      $cursor = (int) $state->get(HealthHooks::CURSOR_STATE_KEY, 0);
      if ($cursor !== 0) {
        $seen_nonzero = TRUE;
        self::assertGreaterThan($previous, $cursor);
        self::assertLessThanOrEqual($total, $cursor);
      }
      $previous = $cursor;
      $iterations++;
      self::assertLessThan(1000, $iterations, 'The cron cursor never wrapped.');
    } while ($cursor !== 0);
    self::assertSame(0, (int) $state->get(HealthHooks::CURSOR_STATE_KEY, 0));

    // With more items than one batch, the cursor must advance before wrapping.
    if ($total > Settings::get('entity_update_batch_size', 50)) {
      self::assertTrue($seen_nonzero);
    }
    // Every data check got covered, including the page's past revision.
    self::assertGreaterThanOrEqual(1, $this->healthRecords->getSummary([HealthCheck::ContentPastRevisions])['total_items']);
    self::assertGreaterThanOrEqual(3, $this->healthRecords->getSummary()['total_items']);
  }

  /**
   * RunCronWindow() bounds work to $limit items, advances across calls, and wraps at the end of the stream (the three pages form the whole content stream).
   */
  public function testRunCronWindowIsBoundedAdvancesAndWraps(): void {
    [$engine, $store] = $this->buildEngineWithEnvironment(new StubEnvironment('fp-cron-window'));
    self::createValidPage('Window page 1');
    self::createValidPage('Window page 2');
    self::createValidPage('Window page 3');
    self::assertSame(3, $engine->countCheckItems(HealthCheck::Content));

    // First window: 2 of 3 items, not yet at the end.
    $first = $engine->runCronWindow(0, 2, [HealthCheck::Content]);
    self::assertSame(2, $first['processed']);
    self::assertFalse($first['wrapped']);
    self::assertSame(2, $store->getSummary([HealthCheck::Content])['total_items']);

    // Second window resumes at offset 2: the remaining item, and wraps.
    $second = $engine->runCronWindow(2, 2, [HealthCheck::Content]);
    self::assertSame(1, $second['processed']);
    self::assertTrue($second['wrapped']);
    self::assertSame(3, $store->getSummary([HealthCheck::Content])['total_items']);

    // A cursor at or beyond the end wraps without inspecting anything.
    $beyond = $engine->runCronWindow(3, 2, [HealthCheck::Content]);
    self::assertSame(0, $beyond['processed']);
    self::assertTrue($beyond['wrapped']);
  }

  /**
   * The --checks option is required, is validated against known checks, accepts a comma-separated list, and a clean site succeeds; --help documents every check name.
   */
  public function testChecksOptionHandling(): void {
    // Omitting --checks is an error.
    $missing = $this->runCommand([]);
    self::assertSame(Command::INVALID, $missing->getStatusCode());
    self::assertStringContainsString('--checks', $missing->getDisplay());

    // A bare `--checks` (no value) must not crash: it is treated the same as
    // omitting the option (its optional value surfaces as NULL).
    $bare = $this->runCommand(['--checks' => NULL]);
    self::assertSame(Command::INVALID, $bare->getStatusCode());
    self::assertStringContainsString('--checks', $bare->getDisplay());

    // `--checks=` (empty value) is an error, not a silent zero-check run.
    $empty = $this->runCommand(['--checks' => '']);
    self::assertSame(Command::INVALID, $empty->getStatusCode());
    self::assertStringContainsString('at least one check name', $empty->getDisplay());

    // An unknown check is rejected.
    $unknown = $this->runCommand(['--checks' => 'bogus']);
    self::assertSame(Command::INVALID, $unknown->getStatusCode());
    self::assertStringContainsString("Unknown check 'bogus'", $unknown->getDisplay());

    // Multiple comma-separated checks are accepted; a clean site succeeds.
    self::createValidPage('Multi-check page');
    $multi = $this->runCommand(['--checks' => HealthCheck::Config->value . ',' . HealthCheck::Content->value]);
    self::assertSame(Command::SUCCESS, $multi->getStatusCode());
    $output = $multi->getDisplay();
    self::assertStringContainsString(HealthCheck::Config->value, $output);
    self::assertStringContainsString(HealthCheck::Content->value, $output);
    self::assertStringContainsString('Canvas Doctor', $output);
    self::assertStringContainsString('Clean bill of health!', $output);
    self::assertStringContainsString('Your Canvas installation is healthy.', $output);

    // The Prescription column is always present, but a healthy check shows no remedy. (update_path_escaped_config is healthy here; update_path_executed is not, since kernel tests never mark post-updates executed.)
    $escaped = $this->runCommand(['--checks' => HealthCheck::UpdatesEscapedConfig->value]);
    self::assertSame(Command::SUCCESS, $escaped->getStatusCode());
    self::assertStringContainsString('Prescription', $escaped->getDisplay());
    self::assertStringNotContainsString('drush updatedb', $escaped->getDisplay());

    // --no-cache is accepted and the run still succeeds on a clean site.
    $no_cache = $this->runCommand(['--checks' => HealthCheck::Config->value, '--no-cache' => TRUE]);
    self::assertSame(Command::SUCCESS, $no_cache->getStatusCode());

    // An invalid auto-save is an advisory risk, not a failure: drafts are allowed to be incomplete, so the run still succeeds.
    $draft = self::createValidPage('Draft page');
    $draft->set('components', [['uuid' => 'b4d5e2f3-0000-4000-8000-000000000000', 'component_id' => 'sdc.canvas.this_component_does_not_exist', 'inputs' => []]]);
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);
    $auto_save = $this->runCommand(['--checks' => HealthCheck::AutoSave->value]);
    self::assertSame(Command::SUCCESS, $auto_save->getStatusCode());
    self::assertStringContainsString('risk(s)', $auto_save->getDisplay());
    $auto_save_json = $this->runCommand(['--checks' => HealthCheck::AutoSave->value, '--format' => 'json']);
    self::assertSame(Command::SUCCESS, $auto_save_json->getStatusCode());
    $report = \json_decode($auto_save_json->getDisplay(), TRUE);
    self::assertSame('risk', $report['checks'][HealthCheck::AutoSave->value]['status']);
    self::assertTrue($report['overall']['healthy']);
    self::assertSame(1, $report['overall']['risks']);

    // `dr help canvas:doctor` documents every check name and its explanation,
    // so --checks needs no separate option to discover valid values.
    $help = $this->buildCommand()->getProcessedHelp();
    foreach (HealthCheck::cases() as $check) {
      self::assertStringContainsString($check->value, $help);
      self::assertStringContainsString($check->explanation(), $help);
    }
  }

  /**
   * With no flags the command errors; --all-checks warns, always exits non-zero, and produces grouped, prescriptive output.
   */
  public function testAllChecksWarning(): void {
    self::createValidPage('Prompt page');
    // No flags: always an error with usage guidance, even on a TTY.
    $tester = $this->runCommand([], ['interactive' => TRUE]);
    self::assertSame(Command::INVALID, $tester->getStatusCode());
    self::assertStringContainsString('--checks', $tester->getDisplay());

    // --all-checks: BC warning, non-zero exit (exploratory, not a stable
    // gate), grouped checks, reuse status, and a copy-pasteable prescription.
    // Assert wrap-safe tokens: SymfonyStyle may wrap the warning at spaces.
    $tester = $this->runCommand(['--all-checks' => TRUE]);
    self::assertSame(Command::FAILURE, $tester->getStatusCode());
    $output = $tester->getDisplay();
    self::assertStringContainsString('automation', $output);
    self::assertStringContainsString('non-zero', $output);
    self::assertStringContainsString(HealthCheck::Config->value, $output);
    self::assertStringContainsString('code_tagged_releases', $output);
    self::assertStringContainsString('System', $output);
    self::assertStringContainsString('Data', $output);
    self::assertStringContainsString('Result reuse is disabled', $output);
    self::assertStringContainsString('Diagnosis:', $output);
    self::assertStringContainsString('Prescription:', $output);
    self::assertStringContainsString('vendor/bin/dr canvas:doctor --checks=', $output);
    self::assertStringContainsString('--details', $output);
  }

  /**
   * The --format=json envelope matches its stable, versioned contract (@see \Drupal\canvas\Command\DoctorCommand::jsonReport(), docs/data-health.schema.json).
   */
  public function testJsonReportContract(): void {
    self::createValidPage('JSON report page');
    $checks = [HealthCheck::Config->value, HealthCheck::Content->value, HealthCheck::ComponentSourceSchemaEvolution->value];
    $tester = $this->runCommand(['--checks' => \implode(',', $checks), '--format' => 'json']);
    // No progress-bar/banner leaked: the whole of stdout parses as JSON.
    $report = \json_decode($tester->getDisplay(), TRUE);
    self::assertIsArray($report);
    self::assertSame(1, $report['report_version']);
    self::assertSame(['report_version', 'environment_fingerprint', 'overall', 'checks'], \array_keys($report));
    self::assertSame(['healthy', 'problems', 'risks'], \array_keys($report['overall']));
    $entry_keys = ['type', 'status', 'total', 'healthy', 'cached', 'duration_s', 'details'];
    foreach ($report['checks'] as $entry) {
      self::assertSame($entry_keys, \array_keys($entry));
    }
    $config = $report['checks'][HealthCheck::Config->value];
    self::assertSame('data', $config['type']);
    $schema_evolution = $report['checks'][HealthCheck::ComponentSourceSchemaEvolution->value];
    self::assertSame('system', $schema_evolution['type']);
    self::assertSame('risk', $schema_evolution['status']);
    self::assertSame(['failing', 'sources'], \array_keys($schema_evolution['details']));
  }

  /**
   * Flattens the run's data items to the findings that have violations.
   *
   * @param array<int, array{findings: array<int, array{label: string, violations: array<mixed>}>}> $items
   *
   * @return array<int, array{label: string, violations: array<mixed>}>
   */
  private static function failingFindings(array $items): array {
    $failing = [];
    foreach ($items as $item) {
      foreach ($item['findings'] as $finding) {
        if ($finding['violations'] !== []) {
          $failing[] = $finding;
        }
      }
    }
    return $failing;
  }

  /**
   * Builds an engine and store bound to a stub environment.
   *
   * @return array{\Drupal\canvas\Health\Doctor, \Drupal\canvas\Health\HealthRecords}
   */
  private function buildEngineWithEnvironment(EnvironmentInterface $environment): array {
    $healthRecords = new HealthRecords($this->container->get('database'), $environment, $this->container->get('datetime.time'));
    $engine = new Doctor(
      $this->container->get('entity_type.manager'),
      $this->container->get(Inventory::class),
      $this->container->get(CanvasConfigUpdater::class),
      $healthRecords,
      $environment,
      $this->container->get(CacheTagsChecksumInterface::class),
      $this->container->get(ComponentSourceManager::class),
    );
    return [$engine, $healthRecords];
  }

  private function buildCommand(): DoctorCommand {
    return new DoctorCommand($this->validator, $this->container->get(Environment::class));
  }

  private function runCommand(array $arguments, array $options = ['interactive' => FALSE]): CommandTester {
    $tester = new CommandTester($this->buildCommand());
    $tester->execute($arguments, $options);
    return $tester;
  }

  /**
   * Asserts every item of a run has the given 'cached' state.
   */
  private static function assertAllCached(iterable $run, bool $expected): void {
    $items = \iterator_to_array($run, FALSE);
    self::assertNotEmpty($items);
    foreach ($items as $item) {
      self::assertSame($expected, $item['cached']);
    }
  }

  private static function createValidPage(string $title): Page {
    $page = Page::create(['title' => $title, 'components' => []]);
    self::assertSaveWithoutViolations($page);
    return $page;
  }

  /**
   * Creates a Page whose component tree references a non-existent component (saving does not validate, so this persists broken data on purpose).
   */
  private static function createInvalidPage(string $title): Page {
    $page = Page::create([
      'title' => $title,
      'components' => [['uuid' => 'a3c4f1e2-0000-4000-8000-000000000000', 'component_id' => 'sdc.canvas.this_component_does_not_exist', 'inputs' => []]],
    ]);
    $page->save();
    return $page;
  }

}

/**
 * A stub EnvironmentInterface simulating a production environment (Environment is final and cannot be mocked, and Canvas is always a dev checkout in tests).
 */
final class StubEnvironment implements EnvironmentInterface {

  public function __construct(public string $fingerprint = 'stub-fingerprint') {}

  public function getFingerprint(): string {
    return $this->fingerprint;
  }

  public function getCanvasSchemaVersion(): int {
    return 0;
  }

  public function getAppliedCanvasPostUpdates(): array {
    return [];
  }

  public function getPendingCanvasPostUpdates(): array {
    return [];
  }

  public function getInstalledExtensionCount(): int {
    return 0;
  }

  public function getDevExtensions(): array {
    return [];
  }

}
