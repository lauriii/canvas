<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_oauth\Functional\Update;

use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\Tests\canvas\Functional\Update\CanvasUpdatePathTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Canvas Oauth scopes for Canvas Pages are created.
 *
 * @legacy-covers \canvas_oauth_post_update_0001_canvas_page_scopes
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_oauth')]
final class PageOauthScopesCreationUpgradeTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  private const array SCOPE_IDS = [
    'canvas_page_create',
    'canvas_page_read',
    'canvas_page_edit',
    'canvas_page_delete',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.10-with-canvas_oauth-1.2.0.bare.php.gz';
  }

  /**
   * Tests scopes for Canvas Pages are created.
   */
  public function testScopesAreCreated(): void {
    $original_scopes = Oauth2Scope::loadMultiple();
    $this->assertCount(2, $original_scopes);
    foreach (self::SCOPE_IDS as $scope_id) {
      $this->assertArrayNotHasKey($scope_id, $original_scopes);
    }

    $this->runUpdates();

    $updated_scopes = Oauth2Scope::loadMultiple();
    foreach (self::SCOPE_IDS as $scope_id) {
      $this->assertArrayHasKey($scope_id, $updated_scopes);
      $this->assertEntityIsValid($updated_scopes[$scope_id]);
      $this->assertSame(['canvas_oauth'], $updated_scopes[$scope_id]->getDependencies()['module']);
    }
  }

}
