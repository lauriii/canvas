<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit;

// cspell:ignore Anglais Allemand

use Drupal\canvas\EntityTranslationMetadata;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests shared metadata without changing consumer-specific URL semantics.
 */
#[CoversClass(EntityTranslationMetadata::class)]
#[Group('canvas')]
final class EntityTranslationMetadataTest extends UnitTestCase {

  /**
   * Tests requested current, localized/native names, access and fallback source.
   */
  public function testTranslationMetadata(): void {
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', new CacheContextsManager($container, ['languages', 'user.permissions']));
    \Drupal::setContainer($container);
    $languages = [];
    foreach (['en' => 'Anglais', 'fr' => 'Français', 'de' => 'Allemand', 'xx' => 'Custom'] as $id => $name) {
      $languages[$id] = new Language(['id' => $id, 'name' => $name]);
    }
    $manager = $this->createMock(LanguageManagerInterface::class);
    $manager->method('isMultilingual')->willReturn(TRUE);
    $manager->method('getLanguages')->willReturn($languages);
    $account = $this->createMock(AccountInterface::class);
    $rendered = $this->createMock(ContentEntityInterface::class);
    $denied_default = $this->createMock(ContentEntityInterface::class);
    foreach ([$rendered, $denied_default] as $entity) {
      $entity->method('getCacheTags')->willReturn(['entity:1']);
      $entity->method('getCacheContexts')->willReturn([]);
      $entity->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    }
    $rendered->method('hasTranslation')->willReturnMap([['en', TRUE], ['fr', TRUE], ['de', FALSE], ['xx', FALSE]]);
    $rendered->method('getTranslation')->willReturnMap([['en', $denied_default], ['fr', $rendered]]);
    $rendered->expects(self::once())->method('access')->with('view', $account, TRUE)
      ->willReturn(AccessResult::allowed()->addCacheTags(['allowed']));
    $denied_default->expects(self::once())->method('access')->with('view', $account, TRUE)
      ->willReturn(AccessResult::forbidden()->addCacheTags(['denied'])->addCacheContexts(['user.permissions'])->setCacheMaxAge(60));
    $cacheability = new CacheableMetadata();
    $entries = EntityTranslationMetadata::build(
      $rendered,
      $manager,
      'de',
      static function (EntityInterface $entity, LanguageInterface $language) use ($rendered): string {
        // Unavailable languages use the supplied rendered entity, not a newly
        // chosen default/safe destination. The consumer owns URL generation.
        self::assertSame($rendered, $entity);
        return '/' . $language->getId() . '/page/1';
      },
      $account,
      $cacheability,
    );
    self::assertSame([
      ['langcode' => 'en', 'name' => 'Anglais', 'nativeName' => 'English', 'url' => '/en/page/1', 'translationAvailable' => FALSE, 'current' => FALSE],
      ['langcode' => 'fr', 'name' => 'Français', 'nativeName' => 'Français', 'url' => '/fr/page/1', 'translationAvailable' => TRUE, 'current' => FALSE],
      ['langcode' => 'de', 'name' => 'Allemand', 'nativeName' => 'Deutsch', 'url' => '/de/page/1', 'translationAvailable' => FALSE, 'current' => TRUE],
      ['langcode' => 'xx', 'name' => 'Custom', 'nativeName' => 'Custom', 'url' => '/xx/page/1', 'translationAvailable' => FALSE, 'current' => FALSE],
    ], $entries);
    self::assertSame(['languages:language_interface', 'user.permissions'], $cacheability->getCacheContexts());
    foreach (['config:configurable_language_list', 'config:language.entity.en', 'config:language.entity.fr', 'config:language.entity.de', 'config:language.entity.xx', 'entity:1', 'allowed', 'denied'] as $tag) {
      self::assertContains($tag, $cacheability->getCacheTags());
    }
    self::assertSame(60, $cacheability->getCacheMaxAge());
  }

}
