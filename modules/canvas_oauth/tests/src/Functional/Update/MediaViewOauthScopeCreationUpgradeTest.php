<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_oauth\Functional\Update;

use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\Tests\canvas\Functional\Update\CanvasUpdatePathTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Canvas Oauth scope for viewing media is created.
 *
 * @legacy-covers \canvas_oauth_post_update_0004_media_view_scope
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_oauth')]
final class MediaViewOauthScopeCreationUpgradeTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  private const string SCOPE_ID = 'canvas_media_view';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.10-with-canvas_oauth-1.2.0.bare.php.gz';
  }

  /**
   * Tests scope for viewing media is created.
   */
  public function testScopesAreCreated(): void {
    $original_scopes = Oauth2Scope::loadMultiple();
    $this->assertArrayNotHasKey(self::SCOPE_ID, $original_scopes);

    $this->runUpdates();

    $updated_scopes = Oauth2Scope::loadMultiple();
    $this->assertArrayHasKey(self::SCOPE_ID, $updated_scopes);
    $this->assertEntityIsValid($updated_scopes[self::SCOPE_ID]);
    $this->assertSame(['canvas_oauth'], $updated_scopes[self::SCOPE_ID]->getDependencies()['module']);
  }

}
