<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_oauth\Functional\Update;

use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\Tests\canvas\Functional\Update\CanvasUpdatePathTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Canvas Oauth scopes for media image types are created.
 *
 * @legacy-covers \canvas_oauth_post_update_0003_media_image_scopes
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_oauth')]
final class MediaImageOauthScopesCreationUpgradeTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  private const string SCOPE_ID = 'canvas_media_image_create';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.10-with-canvas_oauth-1.2.0.bare.php.gz';
  }

  /**
   * Tests scopes for media image types are created.
   */
  public function testScopesAreCreated(): void {
    $original_scopes = Oauth2Scope::loadMultiple();
    $this->assertArrayNotHasKey(self::SCOPE_ID, $original_scopes);

    $this->runUpdates();

    $updated_scopes = Oauth2Scope::loadMultiple();
    $this->assertArrayHasKey(self::SCOPE_ID, $updated_scopes);
    $this->assertEntityIsValid($updated_scopes[self::SCOPE_ID]);
    $dependencies = $updated_scopes[self::SCOPE_ID]->getDependencies();
    $this->assertSame(['canvas_oauth'], $dependencies['module']);
    $this->assertSame(['media.type.image'], $dependencies['config']);
  }

}
