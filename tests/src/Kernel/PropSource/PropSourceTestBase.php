<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\PropSource;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\file\Entity\File;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\media\Entity\Media;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PredictableImageStyleItokTestTrait;
use Drupal\Tests\canvas\Kernel\Traits\VfsPublicStreamUrlTrait;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Base class for PropSource tests.
 *
 * @see \Drupal\canvas\PropSource\PropSource
 */
abstract class PropSourceTestBase extends CanvasKernelTestBase {

  protected const FILE_UUID1 = 'a461c159-039a-4de2-96e5-07d1112105df';
  protected const FILE_UUID2 = '792ea357-71d6-45fa-a12b-78d029edbe4c';
  protected const IMAGE_MEDIA_UUID1 = '83b145bb-d8c3-4410-bbd6-fdcd06e27c29';
  protected const IMAGE_MEDIA_UUID2 = '93b145bb-d8c3-4410-bbd6-fdcd06e27c29';
  protected const TEST_MEDIA = '43b145bb-d8c3-4410-bbd6-fdcd06e27c29';

  use ContentTypeCreationTrait;
  use EntityReferenceFieldCreationTrait;
  use ImageFieldCreationTrait;
  use MediaTypeCreationTrait;
  use NodeCreationTrait;
  use PredictableImageStyleItokTestTrait;
  use UserCreationTrait;
  use TestFileCreationTrait;
  use VfsPublicStreamUrlTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'node',
    'datetime_range',
    'media_test_source',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('field_storage_config');
    $this->installEntitySchema('field_config');
    $this->installEntitySchema('media');
    $this->installEntitySchema('path_alias');

    $this->createMediaType('image', ['id' => 'image']);
    $this->createMediaType('image', ['id' => 'anything_is_possible']);
    // @see \Drupal\media_test_source\Plugin\media\Source\Test
    $this->createMediaType('test', ['id' => 'image_but_not_image_media_source']);

    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $file_uri = 'public://image-2.jpg';
    if (!\file_exists($file_uri)) {
      $file_system->copy(\Drupal::root() . '/core/tests/fixtures/files/image-2.jpg', PublicStream::basePath(), FileExists::Replace);
    }
    $file1 = File::create([
      'uuid' => self::FILE_UUID1,
      'uri' => $file_uri,
      'status' => 1,
    ]);
    $file1->save();
    $file_uri = 'public://image-3.jpg';
    if (!\file_exists($file_uri)) {
      $file_system->copy(\Drupal::root() . '/core/tests/fixtures/files/image-3.jpg', PublicStream::basePath(), FileExists::Replace);
    }
    $file2 = File::create([
      'uuid' => self::FILE_UUID2,
      'uri' => $file_uri,
      'status' => 1,
    ]);
    $file2->save();
    $this->installEntitySchema('media');
    $image1 = Media::create([
      'uuid' => self::IMAGE_MEDIA_UUID1,
      'bundle' => 'image',
      'name' => 'Amazing image',
      'field_media_image' => [
        [
          'target_id' => $file1->id(),
          'alt' => 'An image so amazing that to gaze upon it would melt your face',
          'title' => 'This is an amazing image, just look at it and you will be amazed',
        ],
      ],
    ]);
    $image1->save();
    $image2 = Media::create([
      'uuid' => self::IMAGE_MEDIA_UUID2,
      'bundle' => 'anything_is_possible',
      'name' => 'amazing',
      'field_media_image_1' => [
        [
          'target_id' => $file2->id(),
          'alt' => 'amazing',
          'title' => 'amazing',
        ],
      ],
    ]);
    $image2->save();
    $test_media = Media::create([
      'uuid' => self::TEST_MEDIA,
      'bundle' => 'image_but_not_image_media_source',
      'name' => 'contrived example',
      'field_media_test' => [
        'value' => 'Jack is awesome!',
      ],
    ]);
    $test_media->save();
    $this->setupPredictableItok();
  }

  protected function allowSimplifiedExpectations(EvaluationResult $actual_result): EvaluationResult {
    return new EvaluationResult(
      // Simplified result to allow simplified test expectations.
      value: $this->recursivelyReplaceStrings($actual_result->value, [
        \base_path() . $this->siteDirectory => '::SITE_DIR_BASE_URL::',
      ]),
      // Unchanged cacheability.
      cacheability: $actual_result,
    );
  }

  protected function recursivelyReplaceStrings(mixed $value, array $string_replacements): mixed {
    // Recurse.
    if (\is_array($value)) {
      return \array_map(
        fn (mixed $v) => $this->recursivelyReplaceStrings($v, $string_replacements),
        $value,
      );
    }
    // Nothing to do.
    if (!\is_string($value)) {
      return $value;
    }
    return str_replace(
      \array_keys($string_replacements),
      array_values($string_replacements),
      $value
    );
  }

  /**
   * Switches the active content language if it differs from the site default.
   */
  protected function switchContentLanguage(string $langcode): void {
    $default_langcode = \Drupal::languageManager()->getDefaultLanguage()->getId();
    if ($langcode !== $default_langcode) {
      $language = \Drupal::languageManager()->getLanguage($langcode);
      \assert($language !== NULL);
      \Drupal::service('language.default')->set($language);
      \Drupal::languageManager()->reset();
    }
  }

  /**
   * Enables language and content_translation modules and creates two languages.
   *
   * ConfigurableLanguage::postSave() calls language_negotiation_url_prefixes_update(),
   * so after this call language.negotiation config has prefixes `es` and `fr`
   * set. Callers that need URL generation to reflect those prefixes must call
   * \Drupal::service('kernel')->rebuildContainer() themselves — it is not done
   * here to avoid penalizing tests that do not generate URLs.
   */
  protected function setupContentTranslation(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['language', 'content_translation']);
    $this->installConfig(['language']);

    self::assertNull($this->config('language.negotiation')->get('url.prefixes.es'));
    ConfigurableLanguage::createFromLangcode('es')->save();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    // ConfigurableLanguage::postSave() auto-configured the URL prefix.
    self::assertSame('es', $this->config('language.negotiation')->get('url.prefixes.es'));
  }

  /**
   * Creates a translated image Media fixture for use in translation tests.
   *
   * Requires setupContentTranslation() to have been called first.
   *
   * @return \Drupal\media\Entity\Media
   *   The saved EN media entity (with ES translation attached).
   */
  protected function createTranslatedMediaFixture(): Media {
    \Drupal::service('content_translation.manager')
      ->setEnabled('media', 'image', TRUE);

    $media = Media::create([
      'bundle' => 'image',
      'name' => 'English Image',
      'langcode' => 'en',
      'field_media_image' => [
        'target_id' => 1,
        'alt' => 'Media Alt EN',
      ],
    ]);
    self::assertEntityIsValid($media);
    $media->save();
    $media->addTranslation('es', [
      'name' => 'Spanish Image',
      'field_media_image' => [
        'target_id' => 1,
        'alt' => 'Media Alt ES',
      ],
    ]);
    self::assertEntityIsValid($media->getTranslation('es'));
    $media->save();
    return $media;
  }

  /**
   * Data provider for translated-entity-reference scenarios.
   *
   * @see ::createTranslatedMediaFixture()
   */
  public static function providerTranslatedReferencedMedia(): \Generator {
    yield 'English (default language)' => [
      'langcode' => 'en',
      'expected_alt' => 'Media Alt EN',
    ];
    yield 'Spanish (translated)' => [
      'langcode' => 'es',
      'expected_alt' => 'Media Alt ES',
    ];
    // Language fallback: no FR translation exists, should return EN default.
    yield 'French (fallback to default)' => [
      'langcode' => 'fr',
      'expected_alt' => 'Media Alt EN',
    ];
  }

  /**
   * Langcodes covered by multilingual scenarios.
   *
   * - `en`: site default, no translation lookup.
   * - `es`: non-default content language.
   * - `fr`: non-default content language with no translation (fallback path).
   *
   * @return string[]
   */
  protected static function langcodes(): array {
    return ['en', 'es', 'fr'];
  }

  /**
   * Creates a mock fieldable, translatable entity with the given language code.
   *
   * @param string $langcode
   *   The language code.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface&\Drupal\Core\Entity\TranslatableInterface
   *   A mocked entity whose language() returns a LanguageInterface with the
   *   given langcode, and whose isTranslatable() returns TRUE.
   */
  protected function createEntityWithLangcode(string $langcode): FieldableEntityInterface&TranslatableInterface {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')
      ->willReturn($langcode);

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('isTranslatable')
      ->willReturn(TRUE);
    $entity->method('language')
      ->willReturn($language);
    $entity->method('getCacheTags')
      ->willReturn([]);
    $entity->method('getCacheContexts')
      ->willReturn([]);
    $entity->method('getCacheMaxAge')
      ->willReturn(Cache::PERMANENT);

    return $entity;
  }

}
