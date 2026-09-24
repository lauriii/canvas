<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas_headless\CanvasContentTranslationLinks;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests headless language context without the optional language module.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
final class TranslationLinksWithoutLanguageTest extends CanvasKernelTestBase {

  use RequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'serialization',
    'consumers',
    'simple_oauth',
    'custom_elements',
    'canvas_headless',
  ];

  /**
   * Tests optional service injection and the monolingual route contract.
   */
  public function testWithoutLanguageModule(): void {
    self::assertFalse($this->container->get(ModuleHandlerInterface::class)->moduleExists('language'));
    self::assertFalse($this->container->has('language_negotiator'));
    $builder = $this->container->get(CanvasContentTranslationLinks::class);
    self::assertInstanceOf(CanvasContentTranslationLinks::class, $builder);
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->expects(self::never())->method('getTranslationLanguages');
    $cacheability = new CacheableMetadata();
    self::assertSame([
      'negotiatedLanguage' => 'en',
      'translations' => [],
    ], $builder->build($entity, Request::create('/page/1'), $cacheability));
    self::assertContains('languages:language_content', $cacheability->getCacheContexts());

    foreach (['path_alias', 'user', 'consumer', 'oauth2_token'] as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $response = $this->request(Request::create(CanvasContentApiRequest::API_PATH . '?requestUri=/user/login'));
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertSame(200, $response->getStatusCode());
    $data = self::decodeResponse($response);
    self::assertSame('en', $data['route']['negotiatedLanguage']);
    self::assertSame([], $data['route']['translations']);
    self::assertNull($data['route']['entity']);
    // Kernel tests register all module namespaces. These checks ensure the
    // optional plugins were not autoloaded despite being available to tests.
    self::assertFalse(class_exists('Drupal\\language\\Plugin\\LanguageNegotiation\\LanguageNegotiationSession', FALSE));
    self::assertFalse(class_exists('Drupal\\language\\Plugin\\LanguageNegotiation\\LanguageNegotiationUI', FALSE));
  }

}
