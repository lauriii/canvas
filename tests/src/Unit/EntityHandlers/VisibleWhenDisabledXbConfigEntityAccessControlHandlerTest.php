<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Unit\EntityHandlers;

use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Access\AccessResultNeutral;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigDependencyManager;
use Drupal\Core\Config\Entity\ConfigEntityDependency;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityType;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\experience_builder\Access\XbUiAccessCheck;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\EntityHandlers\VisibleWhenDisabledXbConfigEntityAccessControlHandler;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\experience_builder\EntityHandlers\VisibleWhenDisabledXbConfigEntityAccessControlHandler
 * @group experience_builder
 */
final class VisibleWhenDisabledXbConfigEntityAccessControlHandlerTest extends UnitTestCase {

  /**
   * @covers ::checkAccess
   * @dataProvider viewPermissionProvider
   */
  public function testCanViewWithoutCheckingPermissions(string $entityTypeId, string $entityTypeLabel, bool $status, bool $hasAccessToXb, string $permission, string $expectedAccessResult): void {
    $cacheContextsManager = $this->prophesize(CacheContextsManager::class);
    $cacheContextsManager->assertValidTokens(['user.roles:authenticated'])->willReturn(TRUE);
    $cacheContextsManager->assertValidTokens(['user.permissions'])->willReturn(TRUE);
    $cacheContextsManager->assertValidTokens(['user.roles:authenticated', 'context:one', 'context:two'])->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager->reveal());
    \Drupal::setContainer($container);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->expects($this->any())->method('invokeAll')->willReturn([]);
    $entityType = $this->createMock(EntityTypeInterface::class);
    $entityType->expects($this->never())->method('getAdminPermission')->willReturn($permission);
    $entity = $this->createMock(ConfigEntityInterface::class);
    $entity->expects($this->never())
      ->method('status');
    $configManager = $this->createMock(ConfigManagerInterface::class);
    $account = $this->createMock(AccountInterface::class);
    $language = $this->createMock(LanguageInterface::class);
    $language->expects($this->any())->method('getId')->willReturn('en');
    $entity->expects($this->any())->method('language')->willReturn($language);

    $xbUiAccessCheck = $this->createMock(XbUiAccessCheck::class);
    $xbUiAccessCheck->expects($this->once())
      ->method('access')
      ->willReturn($hasAccessToXb ? (new AccessResultAllowed())->addCacheContexts(['user.permissions']) : (new AccessResultNeutral())->addCacheContexts(['user.permissions']));

    $sut = new VisibleWhenDisabledXbConfigEntityAccessControlHandler(
      $entityType,
      $configManager,
      $this->createMock(EntityTypeManagerInterface::class),
      $xbUiAccessCheck
    );
    $sut->setModuleHandler($moduleHandler);
    $result = $sut->access($entity, 'view', $account, TRUE);
    $this->assertTrue($result::class == $expectedAccessResult);
    assert($result instanceof RefinableCacheableDependencyInterface);
    $this->assertSame(['user.permissions'], $result->getCacheContexts());
    $this->assertSame([], $result->getCacheTags());
    $this->assertSame(Cache::PERMANENT, $result->getCacheMaxAge());
  }

  public static function viewPermissionProvider(): array {
    return [
      'js_component, enabled, authenticated is allowed' => [JavaScriptComponent::ENTITY_TYPE_ID, 'js_component', TRUE, TRUE, JavaScriptComponent::ADMIN_PERMISSION, AccessResultAllowed::class],
      'js_component, disabled, authenticated is allowed' => [JavaScriptComponent::ENTITY_TYPE_ID, 'js_component', FALSE, TRUE, JavaScriptComponent::ADMIN_PERMISSION, AccessResultAllowed::class],
      'js_component, enabled, not authenticated is neutral' => [JavaScriptComponent::ENTITY_TYPE_ID, 'js_component', TRUE, FALSE, JavaScriptComponent::ADMIN_PERMISSION, AccessResultNeutral::class],
      'js_component, disabled, not authenticated is neutral' => [JavaScriptComponent::ENTITY_TYPE_ID, 'js_component', FALSE, FALSE, JavaScriptComponent::ADMIN_PERMISSION, AccessResultNeutral::class],
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
    $moduleHandler->expects($this->atLeastOnce())->method('invokeAll')->willReturn([]);
    $entityType = $this->createMock(ConfigEntityTypeInterface::class);
    $entityType->expects($this->once())->method('getAdminPermission')->willReturn(JavaScriptComponent::ADMIN_PERMISSION);
    if ($hasDependents) {
      $entityType->expects($this->once())
        ->method('getSingularLabel')
        ->willReturn($entityTypeLabel);
    }
    $entity = $this->createMock(ConfigEntityInterface::class);
    $entity->expects($this->once())->method('getConfigDependencyName')->willReturn("experience_builder.$entityTypeId.test");
    $configManager = $this->createMock(ConfigManagerInterface::class);
    $account = $this->createMock(AccountInterface::class);
    $account->expects($this->once())->method('hasPermission')->willReturn(TRUE);
    $language = $this->createMock(LanguageInterface::class);
    $language->expects($this->atLeastOnce())->method('getId')->willReturn('en');
    $entity->expects($this->atLeastOnce())->method('language')->willReturn($language);
    $configDependencyManager = $this->createMock(ConfigDependencyManager::class);
    $configManager->expects($this->once())->method('getConfigDependencyManager')
      ->willReturn($configDependencyManager);

    $configDependencyManager->expects($this->atLeastOnce())->method('getDependentEntities')
      ->with('config', "experience_builder.$entityTypeId.test")
      ->willReturn($hasDependents ? [new ConfigEntityDependency('one_dependent', [])] : []);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    if ($hasDependents) {
      $entityTypeManager->expects($this->once())
        ->method('getDefinition')
        ->with(Component::ENTITY_TYPE_ID)
        ->willReturn(new ConfigEntityType([
          'id' => Component::ENTITY_TYPE_ID,
          'provider' => 'experience_builder',
          'config_prefix' => 'component',
        ]));
    }
    else {
      $entityTypeManager->expects($this->never())
        ->method('getDefinition');
    }

    $sut = new VisibleWhenDisabledXbConfigEntityAccessControlHandler(
      $entityType,
      $configManager,
      $entityTypeManager,
      $this->createMock(XbUiAccessCheck::class)
    );
    $sut->setModuleHandler($moduleHandler);
    $result = $sut->access($entity, 'delete', $account, TRUE);
    $this->assertTrue($result::class == $expectedAccessResult);
    if ($result instanceof AccessResultReasonInterface) {
      $this->assertSame($expectedErrorReason, $result->getReason());
    }
  }

  public static function dependentsProvider(): array {
    return [
      ['js_component', 'code component', TRUE, AccessResultForbidden::class, 'There is other configuration depending on this code component.'],
      // Note that deletion is allowed if the sole dependent config is a Component config entity.
      // @see \Drupal\Tests\experience_builder\Kernel\Entity\JavascriptComponentAccessTest
      ['js_component', 'code component', FALSE, AccessResultAllowed::class, NULL],
    ];
  }

}
