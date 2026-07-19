<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Access;

use Drupal\canvas\Access\CanvasUiAccessCheck;
use Drupal\canvas\EditableContentDiscovery;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Drupal\canvas\Access\CanvasUiAccessCheck.
 */
#[CoversClass(CanvasUiAccessCheck::class)]
#[Group('canvas')]
class CanvasUiAccessCheckTest extends UnitTestCase {

  /**
   * Tests access based on some permissions.
   *
   * @param ?string $permission
   * @param bool $accessGranted
   *
   * @legacy-covers ::access
   */
  #[DataProvider('provider')]
  public function testAccess(?string $permission, bool $accessGranted): void {
    $cacheContextsManager = $this->prophesize(CacheContextsManager::class);
    $cacheContextsManager->assertValidTokens(['user.permissions'])->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager->reveal());
    \Drupal::setContainer($container);

    $entityFieldManager = $this->prophesize(EntityFieldManagerInterface::class);
    // We purposely return no fields, because that requires mocking a lot of the stack.
    $entityFieldManager
      ->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID)
      ->willReturn([]);
    $entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    // No content templates exist: the discovery contributes only canvas_page,
    // which the templated-bundle loop skips.
    $templateQuery = $this->createMock(QueryInterface::class);
    $templateQuery->method('condition')->willReturnSelf();
    $templateQuery->method('execute')->willReturn([]);
    $templateStorage = $this->createMock(EntityStorageInterface::class);
    $templateStorage->method('getQuery')->willReturn($templateQuery);
    $entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID)->willReturn($templateStorage);
    $account = $this->createMock(AccountInterface::class);
    $account->expects($this->atLeastOnce())
      ->method('hasPermission')
      ->willReturnCallback(fn (string $argPermission): bool => $argPermission === $permission);
    // EditableContentDiscovery and ComponentTreeLoader are final, so real
    // instances are wired over the mocked services.
    $editableContentDiscovery = new EditableContentDiscovery(
      $entityTypeManager->reveal(),
      new ComponentTreeLoader(
        $entityFieldManager->reveal(),
        $this->prophesize(ModuleHandlerInterface::class)->reveal(),
        $entityTypeManager->reveal(),
      ),
    );
    $accessChecker = new CanvasUiAccessCheck($entityFieldManager->reveal(), $entityTypeManager->reveal(), $editableContentDiscovery);
    $result = $accessChecker->access($account);
    $this->assertEquals($accessGranted, $result->isAllowed());
  }

  /**
   * Data provider for testing access based on permissions.
   */
  public static function provider(): array {
    return [
      [NULL, FALSE],
      [Pattern::ADMIN_PERMISSION, FALSE],
      [JavaScriptComponent::ADMIN_PERMISSION, TRUE],
      [ContentTemplate::ADMIN_PERMISSION, TRUE],
    ];
  }

}
