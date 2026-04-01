<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Component\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Drupal\canvas\Component\Schema\PropChoiceOptionsResolver;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests enum option resolution for component props.
 */
#[CoversClass(PropChoiceOptionsResolver::class)]
#[Group('canvas')]
final class PropChoiceOptionsResolverTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', new class {

      public function assertValidTokens(array $tokens): bool {
        return TRUE;
      }

    });
    \Drupal::setContainer($container);
  }

  public function testResolvesMetaEnumMap(): void {
    $translation = $this->createMock(TranslationInterface::class);
    $translation->expects($this->never())->method('translate');

    $resolver = new PropChoiceOptionsResolver($translation);
    $schema = [
      'enum' => ['left', 'right'],
      'meta:enum' => [
        'left' => 'Image on the left',
        'right' => 'Image on the right',
      ],
    ];

    $this->assertSame([
      ['label' => 'Image on the left', 'value' => 'left'],
      ['label' => 'Image on the right', 'value' => 'right'],
    ], $resolver->resolveEnumOptions($schema));
  }

  public function testResolvesMetaEnumList(): void {
    $translation = $this->createMock(TranslationInterface::class);
    $translation->expects($this->never())->method('translate');

    $resolver = new PropChoiceOptionsResolver($translation);
    $schema = [
      'enum' => ['left', 'right'],
      'meta:enum' => ['Image on the left', 'Image on the right'],
    ];

    $this->assertSame([
      ['label' => 'Image on the left', 'value' => 'left'],
      ['label' => 'Image on the right', 'value' => 'right'],
    ], $resolver->resolveEnumOptions($schema));
  }

  public function testFallsBackWhenMetaEnumMissingEntry(): void {
    $translation = $this->createMock(TranslationInterface::class);
    $translation->expects($this->never())->method('translate');

    $resolver = new PropChoiceOptionsResolver($translation);
    $schema = [
      'enum' => ['left', 'right'],
      'meta:enum' => ['left' => 'Image on the left'],
    ];

    $this->assertSame([
      ['label' => 'Image on the left', 'value' => 'left'],
      ['label' => 'right', 'value' => 'right'],
    ], $resolver->resolveEnumOptions($schema));
  }

  public function testFallsBackWhenEnumValueCannotBeEncoded(): void {
    $translation = $this->createMock(TranslationInterface::class);
    $translation->expects($this->never())->method('translate');

    $resolver = new PropChoiceOptionsResolver($translation);
    $recursive_value = new \stdClass();
    $recursive_value->self = $recursive_value;

    $options = $resolver->resolveEnumOptions([
      'enum' => [$recursive_value],
    ]);

    $this->assertCount(1, $options);
    $this->assertSame('[unable to encode]', $options[0]['label']);
    $this->assertSame($recursive_value, $options[0]['value']);
  }

  public function testAddsLanguageCacheContextWhenTranslating(): void {
    $translation = $this->createMock(TranslationInterface::class);
    $translation->expects($this->once())
      ->method('translate')
      ->with('Image on the left', [], ['context' => 'Hero'])
      ->willReturn(new TranslatableMarkup('Image on the left', [], ['context' => 'Hero']));

    $resolver = new PropChoiceOptionsResolver($translation);
    $schema = [
      'enum' => ['left'],
      'meta:enum' => ['left' => 'Image on the left'],
      'x-translation-context' => 'Hero',
    ];
    $cacheability = new CacheableMetadata();

    $this->assertSame([
      ['label' => 'Image on the left', 'value' => 'left'],
    ], $resolver->resolveEnumOptions($schema, $cacheability));
    $this->assertContains('languages:language_interface', $cacheability->getCacheContexts());
  }

}
