<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Unit\EntityHandlers;

use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigDependencyManager;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Entity\Pattern;
use Drupal\experience_builder\EntityHandlers\ContentCreatorVisibleXbConfigEntityAccessControlHandler;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\experience_builder\EntityHandlers\ContentCreatorVisibleXbConfigEntityAccessControlHandler
 * @group experience_builder
 */
final class ContentCreatorVisibleXbConfigEntityAccessControlHandlerTest extends UnitTestCase {

  /**
   * @covers ::checkAccess
   * @dataProvider viewPermissionProvider
   */
  public function testCanViewWithoutCheckingPermissions(string $entityTypeId, string $entityTypeLabel, string $permission, string $expectedAccessResult): void {
    $cacheContextsManager = $this->prophesize(CacheContextsManager::class);
    $cacheContextsManager->assertValidTokens(['user.permissions'])->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager->reveal());
    \Drupal::setContainer($container);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->expects($this->any())->method('invokeAll')->willReturn([]);
    $entityType = $this->createMock(EntityTypeInterface::class);
    $entityType->expects($this->never())->method('getAdminPermission')->willReturn($permission);
    $entity = $this->createMock(ConfigEntityInterface::class);
    $configManager = $this->createMock(ConfigManagerInterface::class);
    $account = $this->createMock(AccountInterface::class);
    $account->expects($this->never())->method('hasPermission')->willReturn(TRUE);
    $language = $this->createMock(LanguageInterface::class);
    $language->expects($this->any())->method('getId')->willReturn('en');
    $entity->expects($this->any())->method('language')->willReturn($language);

    $sut = new ContentCreatorVisibleXbConfigEntityAccessControlHandler($entityType, $configManager);
    $sut->setModuleHandler($moduleHandler);
    $result = $sut->access($entity, 'view', $account, TRUE);
    $this->assertTrue($result::class == $expectedAccessResult);
  }

  public static function viewPermissionProvider(): array {
    return [
      [Component::ENTITY_TYPE_ID, 'component', Component::ADMIN_PERMISSION, AccessResultAllowed::class],
      [Pattern::ENTITY_TYPE_ID, 'pattern', Pattern::ADMIN_PERMISSION, AccessResultAllowed::class],
      [PageRegion::ENTITY_TYPE_ID, 'page region', PageRegion::ADMIN_PERMISSION, AccessResultAllowed::class],
    ];
  }

  /**
   * @covers ::checkAccess
   * @dataProvider dependentsProvider
   */
  public function testCannotDeleteWhenThereAreDependents(string $entityTypeId, string $entityTypeLabel, bool $hasDependents, string $expectedAccessResult, ?string $expectedErrorReason): void {
    $cacheContextsManager = $this->prophesize(CacheContextsManager::class);
    $cacheContextsManager->assertValidTokens(['user.permissions'])->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager->reveal());
    \Drupal::setContainer($container);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->expects($this->any())->method('invokeAll')->willReturn([]);
    $entityType = $this->createMock(EntityTypeInterface::class);
    $entityType->expects($this->once())->method('getAdminPermission')->willReturn(JavaScriptComponent::ADMIN_PERMISSION);
    $entityType->expects($this->once())->method('getSingularLabel')->willReturn($entityTypeLabel);
    $entity = $this->createMock(ConfigEntityInterface::class);
    $entity->expects($this->once())->method('getConfigDependencyName')->willReturn("experience_builder.$entityTypeId.test");
    $configManager = $this->createMock(ConfigManagerInterface::class);
    $account = $this->createMock(AccountInterface::class);
    $account->expects($this->once())->method('hasPermission')->willReturn(TRUE);
    $language = $this->createMock(LanguageInterface::class);
    $language->expects($this->any())->method('getId')->willReturn('en');
    $entity->expects($this->any())->method('language')->willReturn($language);
    $configDependencyManager = $this->createMock(ConfigDependencyManager::class);
    $configManager->expects($this->once())->method('getConfigDependencyManager')
      ->willReturn($configDependencyManager);

    $configDependencyManager->expects($this->any())->method('getDependentEntities')
      ->with('config', "experience_builder.$entityTypeId.test")
      ->willReturn($hasDependents ? ['one_dependent'] : []);

    $sut = new ContentCreatorVisibleXbConfigEntityAccessControlHandler($entityType, $configManager);
    $sut->setModuleHandler($moduleHandler);
    $result = $sut->access($entity, 'delete', $account, TRUE);
    $this->assertTrue($result::class == $expectedAccessResult);
    if ($result instanceof AccessResultReasonInterface) {
      $this->assertSame($expectedErrorReason, $result->getReason());
    }
  }

  public static function dependentsProvider(): array {
    return [
      ['component', 'component', TRUE, AccessResultForbidden::class, 'There is other configuration depending on this component.'],
      ['component', 'component', FALSE, AccessResultAllowed::class, NULL],
      ['pattern', 'pattern', TRUE, AccessResultForbidden::class, 'There is other configuration depending on this pattern.'],
      ['pattern', 'pattern', FALSE, AccessResultAllowed::class, NULL],
      ['page region', 'page region', TRUE, AccessResultForbidden::class, 'There is other configuration depending on this page region.'],
      ['page region', 'page region', FALSE, AccessResultAllowed::class, NULL],
    ];
  }

}
