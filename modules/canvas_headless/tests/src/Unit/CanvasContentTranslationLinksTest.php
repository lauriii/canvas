<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Unit;

use Drupal\canvas_headless\CanvasContentTranslationLinks;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\language\LanguageNegotiatorInterface;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationSession;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationUI;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationUrl;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests dependencies of both available and unavailable translation entries.
 */
#[CoversClass(CanvasContentTranslationLinks::class)]
#[Group('canvas_headless')]
final class CanvasContentTranslationLinksTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Cache::mergeContexts() validates tokens through Drupal's container when
    // PHP assertions are enabled. Declare the fixture's context IDs so this
    // test uses real token validation.
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', new CacheContextsManager($container, [
      'languages',
      'oauth2_scopes',
      'url',
      'url.query_args',
      'user.permissions',
    ]));
    \Drupal::setContainer($container);
  }

  /**
   * Tests switcher precedence and cacheability independently of URL processing.
   */
  #[DataProvider('negotiationOrder')]
  public function testCacheabilityAndPrecedence(?bool $session_first): void {
    $english = new Language(['id' => 'en', 'name' => 'English']);
    $french = new Language(['id' => 'fr', 'name' => 'French']);
    $german = new Language(['id' => 'de', 'name' => 'German']);
    $manager = $this->createMock(LanguageManagerInterface::class);
    $manager->method('getCurrentLanguage')->willReturn($english);
    $manager->method('isMultilingual')->willReturn(TRUE);
    $manager->method('getLanguages')->willReturn(['en' => $english, 'fr' => $french, 'de' => $german]);
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('getAccount')->willReturn($this->createMock(AccountInterface::class));
    $generated = (new GeneratedUrl())
      ->setGeneratedUrl('http://localhost/page/1')
      ->addCacheTags(['generated_url'])
      ->addCacheContexts(['url.query_args:generated'])
      ->setCacheMaxAge(120);
    $url = $this->createMock(Url::class);
    $url->method('setOption')->willReturnSelf();
    $url->method('setAbsolute')->willReturnSelf();
    $url->method('toString')->willReturn($generated);
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('id')->willReturn(1);
    $entity->method('hasLinkTemplate')->with('canonical')->willReturn(TRUE);
    $entity->method('toUrl')->with('canonical')->willReturn($url);
    $entity->method('hasTranslation')->willReturnMap([['en', TRUE], ['fr', TRUE], ['de', FALSE]]);
    $entity->method('language')->willReturn($english);
    $entity->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $entity->method('getCacheTags')->willReturn([]);
    $entity->method('getCacheContexts')->willReturn([]);
    $denied = $this->createMock(ContentEntityInterface::class);
    $denied->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $denied->method('getCacheTags')->willReturn([]);
    $denied->method('getCacheContexts')->willReturn([]);
    $denied->expects(self::once())->method('access')->with('view', $account->getAccount(), TRUE)
      ->willReturn(AccessResult::forbidden()->addCacheTags(['denied_translation'])->addCacheContexts(['user.permissions'])->setCacheMaxAge(60));
    $entity->method('getTranslation')->willReturnMap([['en', $entity], ['fr', $denied]]);
    $entity->expects(self::once())->method('access')->with('view', $account->getAccount(), TRUE)
      ->willReturn(AccessResult::allowed()->addCacheTags(['allowed_translation']));
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('getCacheTags')->willReturn([]);
    $config->method('getCacheContexts')->willReturn([]);
    $config->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($config);
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $negotiator = NULL;
    if ($session_first !== NULL) {
      $config->method('get')->willReturnMap([
        [
          'negotiation.language_content.enabled',
          $session_first
            ? ['language-session' => 0, 'language-interface' => 1]
            : ['language-interface' => 0, 'language-session' => 1],
        ],
        ['negotiation.language_interface.enabled', ['language-url' => 0]],
        ['session.parameter', 'locale'],
      ]);
      $negotiator = $this->createMock(LanguageNegotiatorInterface::class);
      // Discovery order deliberately differs from one configured ordering.
      $negotiator->method('getNegotiationMethods')->willReturnMap([
        [
          'language_content', [
            'language-session' => ['class' => LanguageNegotiationSession::class],
            'language-interface' => ['class' => LanguageNegotiationUI::class],
          ],
        ],
        ['language_interface', ['language-url' => ['class' => LanguageNegotiationUrl::class]]],
      ]);
      $session = $this->createMock(LanguageNegotiationSession::class);
      $url_method = $this->createMock(LanguageNegotiationUrl::class);
      $links = ['en' => ['url' => $url]];
      $session->expects($session_first ? self::once() : self::never())->method('getLanguageSwitchLinks')->willReturn($links);
      $url_method->expects($session_first ? self::never() : self::once())->method('getLanguageSwitchLinks')->willReturn($links);
      $negotiator->method('getNegotiationMethodInstance')->willReturnMap([
        ['language-session', $session],
        ['language-url', $url_method],
      ]);
      $module_handler->expects(self::once())->method('alter')
        ->with('language_switch_links', $links, $session_first ? 'language_content' : 'language_interface', $url);
    }
    $builder = new CanvasContentTranslationLinks(
      $manager,
      $config_factory,
      $module_handler,
      $account,
      $negotiator,
    );
    $cacheability = new CacheableMetadata();
    $result = $builder->build($entity, Request::create('http://localhost/page/1'), $cacheability);
    self::assertSame([
      'negotiatedLanguage' => 'en',
      'translations' => [
        ['langcode' => 'en', 'name' => 'English', 'nativeName' => 'English', 'url' => '/page/1', 'translationAvailable' => TRUE, 'current' => TRUE, 'external' => FALSE],
        ['langcode' => 'fr', 'name' => 'French', 'nativeName' => 'Français', 'url' => '/page/1', 'translationAvailable' => FALSE, 'current' => FALSE, 'external' => FALSE],
        ['langcode' => 'de', 'name' => 'German', 'nativeName' => 'Deutsch', 'url' => '/page/1', 'translationAvailable' => FALSE, 'current' => FALSE, 'external' => FALSE],
      ],
    ], $result);
    foreach (['allowed_translation', 'denied_translation', 'generated_url'] as $tag) {
      self::assertContains($tag, $cacheability->getCacheTags());
    }
    self::assertContains('url.query_args:generated', $cacheability->getCacheContexts());
    self::assertContains('user.permissions', $cacheability->getCacheContexts());
    self::assertSame(60, $cacheability->getCacheMaxAge());
  }

  /**
   * Provides no negotiator and both content negotiation priority orderings.
   */
  public static function negotiationOrder(): iterable {
    yield 'no negotiator' => [NULL];
    yield 'session first' => [TRUE];
    yield 'interface first' => [FALSE];
  }

}
