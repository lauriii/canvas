<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_oauth\Functional\Update;

use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\Tests\canvas\Functional\Update\CanvasUpdatePathTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Canvas Oauth scopes for global regions and page variants are created.
 *
 * @legacy-covers \canvas_oauth_post_update_0006_page_region_scope
 * @legacy-covers \canvas_oauth_post_update_0007_page_variant_scope
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_oauth')]
final class PageRegionOauthScopeCreationUpgradeTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  private const string SCOPE_ID = 'canvas_page_region';

  private const string VARIANT_SCOPE_ID = 'canvas_page_variant';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.10-with-canvas_oauth-1.2.0.bare.php.gz';
  }

  /**
   * Tests the canvas:page_region and canvas:page_variant scopes are created.
   */
  public function testScopeIsCreated(): void {
    $original_scopes = Oauth2Scope::loadMultiple();
    $this->assertArrayNotHasKey(self::SCOPE_ID, $original_scopes);
    $this->assertArrayNotHasKey(self::VARIANT_SCOPE_ID, $original_scopes);

    $this->runUpdates();

    $updated_scopes = Oauth2Scope::loadMultiple();
    $this->assertArrayHasKey(self::SCOPE_ID, $updated_scopes);
    $this->assertEntityIsValid($updated_scopes[self::SCOPE_ID]);
    $scope = $updated_scopes[self::SCOPE_ID];
    $this->assertSame('canvas:page_region', $scope->getName());
    $this->assertSame(['canvas_oauth'], $scope->getDependencies()['module']);

    $this->assertArrayHasKey(self::VARIANT_SCOPE_ID, $updated_scopes);
    $this->assertEntityIsValid($updated_scopes[self::VARIANT_SCOPE_ID]);
    $variant_scope = $updated_scopes[self::VARIANT_SCOPE_ID];
    $this->assertSame('canvas:page_variant', $variant_scope->getName());
    $this->assertSame(['canvas_oauth'], $variant_scope->getDependencies()['module']);
  }

}
