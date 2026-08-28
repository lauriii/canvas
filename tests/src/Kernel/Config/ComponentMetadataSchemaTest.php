<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Verifies the shared authored metadata fixtures against the published schema.
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
class ComponentMetadataSchemaTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = self::CANVAS_KERNEL_TEST_MINIMAL_MODULES;

  /**
   * Tests the shared metadata fixtures.
   */
  public function testFixtures(): void {
    $module_path = $this->container->get(ModuleExtensionList::class)->getPath('canvas');
    $schema = json_decode((string) file_get_contents("$module_path/component-metadata.schema.json"), flags: JSON_THROW_ON_ERROR);

    foreach ([TRUE => 'valid', FALSE => 'invalid'] as $expected_valid => $directory) {
      $fixture_paths = glob("$module_path/tests/fixtures/component-metadata/$directory/*.yml");
      foreach ($fixture_paths !== FALSE ? $fixture_paths : [] as $fixture_path) {
        $document = Validator::arrayToObjectRecursive(Yaml::parseFile($fixture_path));
        $validator = new Validator();
        $validator->validate($document, $schema, Constraint::CHECK_MODE_NORMAL);

        $message = \sprintf(
          "%s:\n%s",
          basename($fixture_path),
          json_encode($validator->getErrors(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
        $this->assertSame((bool) $expected_valid, $validator->isValid(), $message);
      }
    }
  }

}
