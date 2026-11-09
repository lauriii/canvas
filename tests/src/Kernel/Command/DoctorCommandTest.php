<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Command;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Command\DoctorCommand;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Health\Doctor;
use Drupal\canvas\Health\Environment;
use Drupal\canvas\Health\HealthCheck;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[RunTestsInSeparateProcesses]
#[CoversClass(DoctorCommand::class)]
#[Group('canvas')]
final class DoctorCommandTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use PageTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::PAGE_TEST_MODULES,
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->generateComponentConfig();
    $this->installPageEntitySchema();
  }

  /**
   * The --details option lists each risk of a system check, with its change record where one exists.
   */
  public function testDetailsOption(): void {
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
    $report = \json_decode($tester->getDisplay(), flags: \JSON_THROW_ON_ERROR);
    $validator = new Validator();
    $validator->validate($report, (object) ['$ref' => 'file://' . \realpath(__DIR__ . '/../../../../docs/data-health.schema.json')]);
    self::assertTrue($validator->isValid(), \json_encode($validator->getErrors(), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    $report = \json_decode($tester->getDisplay(), TRUE);
    self::assertSame(DoctorCommand::REPORT_VERSION, $report['report_version']);
    $config = $report['checks'][HealthCheck::Config->value];
    self::assertSame('data', $config['type']);
    $schema_evolution = $report['checks'][HealthCheck::ComponentSourceSchemaEvolution->value];
    self::assertSame('system', $schema_evolution['type']);
    self::assertSame('risk', $schema_evolution['status']);
    self::assertSame(['failing', 'sources'], \array_keys($schema_evolution['details']));
  }

  private function buildCommand(): DoctorCommand {
    return new DoctorCommand($this->container->get(Doctor::class), $this->container->get(Environment::class));
  }

  private function runCommand(array $arguments, array $options = ['interactive' => FALSE]): CommandTester {
    $tester = new CommandTester($this->buildCommand());
    $tester->execute($arguments, $options);
    return $tester;
  }

  private static function createValidPage(string $title): Page {
    $page = Page::create(['title' => $title, 'components' => []]);
    self::assertSaveWithoutViolations($page);
    return $page;
  }

}
