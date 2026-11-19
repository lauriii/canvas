<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_oauth\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the recipe that sets up OAuth for Canvas CLI.
 */
#[Group('canvas_oauth')]
#[RunTestsInSeparateProcesses]
#[CoversFunction('canvas_oauth_install')]
class CliSetupRecipeTest extends CanvasKernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['field', 'media_test_source'];

  /**
   * Tests that media scopes are created if we install via a recipe.
   */
  public function testMediaScopesCreatedWhenInstallingViaRecipe(): void {
    $this->installEntitySchema('path_alias');
    // We need an administrative user to import content.
    $this->installEntitySchema('user');
    $this->createUser(admin: TRUE);

    $this->createMediaType('image', ['id' => 'image']);
    $this->createMediaType('file', ['id' => 'document']);
    $this->createMediaType('test', ['id' => 'test']);

    $recipe = Recipe::createFromDirectory(
      dirname(__DIR__, 3) . '/recipes/cli_setup',
    );
    RecipeRunner::processRecipe($recipe);

    foreach (['image', 'document'] as $media_type) {
      $scope = Oauth2Scope::load("canvas_media_{$media_type}_create");
      $this->assertInstanceOf(Oauth2Scope::class, $scope);

      $this->assertSame("canvas:media:$media_type:create", $scope->getName());
      $dependencies = $scope->get('dependencies') ?? [];
      $this->assertContains('canvas_oauth', $dependencies['enforced']['module']);
      $this->assertContains("media.type.$media_type", $dependencies['enforced']['config']);
    }

    $all_canvas_scopes = array_filter(
      \array_keys(Oauth2Scope::loadMultiple()),
      fn (string $id): bool => str_starts_with($id, 'canvas_'),
    );
    sort($all_canvas_scopes);
    // A media type with a non-file source should not have a scope created for it.
    $this->assertNotContains('canvas_media_test_create', $all_canvas_scopes);

    // The consumer should have all Canvas scopes.
    $consumer = \Drupal::service(EntityRepositoryInterface::class)
      ->loadEntityByUuid('consumer', '9a1724a1-6a79-4819-87e3-871542db531b');
    $this->assertInstanceOf(Consumer::class, $consumer);
    $consumer_scopes = $consumer->get('scopes')->getValue();
    $this->assertIsArray($consumer_scopes);
    $consumer_scopes = array_column($consumer_scopes, 'scope_id');
    sort($consumer_scopes);
    $this->assertSame($all_canvas_scopes, $consumer_scopes);
  }

}
