<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests installing Canvas via a recipe, at site install time.
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class InstallerTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function installParameters(): array {
    $parameters = parent::installParameters();
    $parameters['parameters']['recipe'] = __DIR__ . '/../../fixtures/recipes/test_install';
    return $parameters;
  }

  public function testCanvasCanBeInstalledByRecipe(): void {
    // Everything we're testing here happens while installing Drupal, during
    // test set-up.
    $this->expectNotToPerformAssertions();
  }

}
