<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Command;

use Drupal\canvas\Audit\ComponentAudit;
use Drupal\canvas\Audit\RevisionAuditEnum;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Command\ComponentAuditCommand;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Tests\canvas\Kernel\Audit\ComponentAuditTestBase;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[RunTestsInSeparateProcesses]
#[CoversClass(ComponentAuditCommand::class)]
#[Group('canvas')]
final class ComponentAuditCommandTest extends ComponentAuditTestBase {

  /**
   * The `canvas:component:audit` command finds usages by source, ID and version.
   */
  public function testCommand(): void {
    // Auto-saving a page computes its path alias.
    $this->installEntitySchema('path_alias');
    $component_id = 'sdc.canvas_test_sdc.my-cta';
    $old_version = Component::load($component_id)?->getActiveVersion();
    \assert(\is_string($old_version));

    // A page whose first revision uses the old version, and a pattern using it.
    $page = Page::create([
      'title' => 'Audited page',
      'components' => $this->tree,
    ]);
    $page->save();
    $revision_id1 = (string) $page->getRevisionId();
    $page->setNewRevision();
    $page->set('components', [])->save();
    Pattern::create([
      'id' => 'test_pattern',
      'label' => 'Test Pattern',
      'component_tree' => $this->tree,
    ])->save();

    // Create a new version, and an auto-save of the page using it.
    \Drupal::service(ModuleInstallerInterface::class)
      ->install(['canvas_test_storable_prop_shape_alter']);
    $component = Component::load($component_id);
    \assert($component instanceof ComponentInterface);
    $new_version = $component->getActiveVersion();
    self::assertNotSame($old_version, $new_version);
    $page->setComponentTree([
      [
        'uuid' => '5f2c1b4e-6a7d-4e8f-9a0b-1c2d3e4f5a6b',
        'component_id' => $component_id,
        'component_version' => $new_version,
        'inputs' => [
          'text' => 'Hey there',
          // The new version stores this prop using the `uri` field type.
          'href' => 'https://drupal.org/',
        ],
      ],
    ]);
    $page->set('title', 'Audited page (draft)');
    $this->container->get(AutoSaveManager::class)->saveEntity($page);

    $content_usage = [
      'component' => $component_id,
      'version' => $old_version,
      'kind' => 'content',
      'entity_type' => Page::ENTITY_TYPE_ID,
      'id' => (string) $page->id(),
      'revision' => $revision_id1,
      'default' => FALSE,
      'latest' => FALSE,
      'label' => 'Audited page',
    ];
    $config_usage = [
      'component' => $component_id,
      'version' => $old_version,
      'kind' => 'config',
      'entity_type' => Pattern::ENTITY_TYPE_ID,
      'id' => 'test_pattern',
      'revision' => NULL,
      'default' => NULL,
      'latest' => NULL,
      'label' => 'Test Pattern',
    ];
    $auto_save_usage = [
      'component' => $component_id,
      'version' => $new_version,
      'kind' => 'auto-save',
      'entity_type' => Page::ENTITY_TYPE_ID,
      'id' => (string) $page->id(),
      'revision' => NULL,
      'default' => NULL,
      'latest' => NULL,
      // The auto-saved label, not the saved one.
      'label' => 'Audited page (draft)',
    ];

    // By component ID and version.
    self::assertSame([$content_usage, $config_usage], $this->runCommand("$component_id@$old_version"));
    self::assertSame([$auto_save_usage], $this->runCommand("$component_id@$new_version"));
    // By component ID: all versions.
    $all_usages = [$content_usage, $config_usage, $auto_save_usage];
    self::assertEqualsCanonicalizing($all_usages, $this->runCommand($component_id));
    // By component source: all components of that source.
    self::assertEqualsCanonicalizing($all_usages, $this->runCommand('sdc'));
    self::assertSame([], $this->runCommand('block'));
    // Only the default revision: the first revision is no longer searched.
    self::assertEqualsCanonicalizing([$config_usage, $auto_save_usage], $this->runCommand($component_id, ['--revisions' => 'default']));
    self::assertEqualsCanonicalizing([$config_usage, $auto_save_usage], $this->runCommand($component_id, ['--revisions' => 'latest']));

    // Invalid targets and options.
    $invalid_inputs = [
      ['target' => 'sdc.canvas_test_sdc.nope'],
      ['target' => "$component_id@nope"],
      ['target' => 'nope'],
      ['target' => 'sdc.canvas_test_sdc.nope@' . $old_version],
      ['target' => $component_id, '--format' => 'xml'],
      ['target' => $component_id, '--revisions' => 'foo'],
      ['target' => $component_id, '--revisions' => RevisionAuditEnum::AutoSave->value],
    ];
    foreach ($invalid_inputs as $invalid_input) {
      $tester = $this->createComponentAuditCommandTester();
      self::assertSame(Command::INVALID, $tester->execute($invalid_input), \json_encode($invalid_input, \JSON_THROW_ON_ERROR));
    }

    // Saving the auto-saved tree creates a revision that is both the default
    // and the latest one.
    $page->setNewRevision();
    $page->save();
    $revision_id3 = (string) $page->getRevisionId();
    self::assertContains([
      'component' => $component_id,
      'version' => $new_version,
      'kind' => 'content',
      'entity_type' => Page::ENTITY_TYPE_ID,
      'id' => (string) $page->id(),
      'revision' => $revision_id3,
      'default' => TRUE,
      'latest' => TRUE,
      'label' => 'Audited page (draft)',
    ], $this->runCommand("$component_id@$new_version", ['--revisions' => 'latest']));

    // The table format lists every usage, and the revision flags.
    $tester = $this->createComponentAuditCommandTester();
    self::assertSame(Command::SUCCESS, $tester->execute(['target' => $component_id]));
    self::assertStringContainsString("$revision_id3 (default, latest)", $tester->getDisplay());
    self::assertStringContainsString('4 usage(s) found.', $tester->getDisplay());
  }

  /**
   * Without a target, an interactive run asks for one; a non-interactive run errors.
   */
  public function testInteractiveTarget(): void {
    $component_id = 'sdc.canvas_test_sdc.my-cta';
    $page = Page::create(['title' => 'Audited page', 'components' => $this->tree]);
    $page->save();

    // Non-interactive: no target is an error, with usage guidance.
    $tester = $this->createComponentAuditCommandTester();
    self::assertSame(Command::INVALID, $tester->execute([], ['interactive' => FALSE]));
    self::assertStringContainsString('target', $tester->getDisplay());

    // Interactive: pick a source, then a component, then a version.
    $tester = $this->createComponentAuditCommandTester();
    $tester->setInputs(['sdc', $component_id, 'all']);
    self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => TRUE]));
    self::assertStringContainsString((string) $page->id(), $tester->getDisplay());
    self::assertStringContainsString('1 usage(s) found.', $tester->getDisplay());

    // Interactive: a whole source. The fallback source is not offered.
    $tester = $this->createComponentAuditCommandTester();
    $tester->setInputs(['block', 'all']);
    self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => TRUE]));
    self::assertStringContainsString('No usages found for block.', $tester->getDisplay());
    self::assertStringNotContainsString('fallback', $tester->getDisplay());
  }

  private function createComponentAuditCommandTester(): CommandTester {
    return new CommandTester(new ComponentAuditCommand(
      $this->container->get(ComponentAudit::class),
      $this->container->get(EntityTypeManagerInterface::class),
      $this->container->get(ComponentSourceManager::class),
      $this->container->get(AutoSaveManager::class),
    ));
  }

  /**
   * Runs the command with --format=json and returns the usages.
   *
   * The whole report is validated against docs/component-audit.schema.json.
   */
  private function runCommand(string $target, array $options = []): array {
    $tester = $this->createComponentAuditCommandTester();
    self::assertSame(Command::SUCCESS, $tester->execute(['target' => $target, '--format' => 'json'] + $options));
    $report = \json_decode($tester->getDisplay(), TRUE, flags: \JSON_THROW_ON_ERROR);
    $document = Validator::arrayToObjectRecursive($report);
    $validator = new Validator();
    $validator->validate($document, (object) ['$ref' => 'file://' . \realpath(__DIR__ . '/../../../../docs/component-audit.schema.json')]);
    self::assertTrue($validator->isValid(), \json_encode($validator->getErrors(), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    self::assertSame(ComponentAuditCommand::REPORT_VERSION, $report['report_version']);
    self::assertSame($target, $report['target']);
    self::assertSame($options['--revisions'] ?? RevisionAuditEnum::All->value, $report['revisions']);
    return $report['usages'];
  }

}
