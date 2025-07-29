<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional\Update;

use Drupal\Core\Database\Database;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\FunctionalTests\Update\UpdatePathTestBase;

/**
 * @covers experience_builder_post_update_javascript_component_data_dependencies()
 * @group experience_builder
 */
final class JavascriptComponentDataDependenciesUpdateTest extends UpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    if (Database::getConnection()->driver() !== 'sqlite') {
      $this->markTestSkipped('Due to the presence of `json:normal` in the test fixture DB, the update path can currently only be tested on SQLite. Fix in https://www.drupal.org/project/experience_builder/issues/3538537');
    }
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-xb-0.7.2-alpha1.filled.php.gz';
  }

  /**
   * Tests updating data dependencies.
   */
  public function testUpdateDataDependencies(): void {
    $before = JavaScriptComponent::load('xb_test_code_components_using_drupalsettings_get_site_data');
    \assert($before instanceof JavaScriptComponent);
    self::assertNull($before->get('dataDependencies'));

    $this->runUpdates();

    $after = JavaScriptComponent::load('xb_test_code_components_using_drupalsettings_get_site_data');
    \assert($after instanceof JavaScriptComponent);
    self::assertEquals(['drupalSettings' => ['v0.baseUrl', 'v0.branding']], $after->get('dataDependencies'));
  }

}
