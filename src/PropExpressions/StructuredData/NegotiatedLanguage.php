<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Language\LanguageInterface;

/**
 * Defines a value object for a negotiated language and its cacheability.
 *
 * @internal
 */
final class NegotiatedLanguage implements CacheableDependencyInterface {

  use CacheableDependencyTrait;

  public function __construct(
    public readonly LanguageInterface $language,
    CacheableDependencyInterface $cacheability,
  ) {
    $this->setCacheability($cacheability);
  }

  /**
   * Creates a NegotiatedLanguage from an entity.
   *
   * Every entity specifies a language and is a cacheable dependency.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity object whose language to reuse, and whose cacheability
   *   determines the cacheability of the negotiated language.
   *   For example: pass in a Node object, then its langcode will be used and
   *   its cacheability, meaning that if the Node object itself is changed, the
   *   resulting NegotiatedLanguage would be invalidated just like the Node
   *   itself.
   *
   * @return static
   */
  public static function matchEntity(EntityInterface $entity): static {
    return new static($entity->language(), $entity);
  }

  /**
   * Creates a NegotiatedLanguage from site configuration and request context.
   *
   * @return static
   *   The currently negotiated content language. On monolingual sites, this is
   *   always the same language, and hence no cacheability is needed. On multi-
   *   lingual sites, it can be one of multiple, and hence a cache context is
   *   associated.
   */
  public static function negotiateFromConfigAndContext(): static {
    $language_manager = \Drupal::languageManager();
    return new NegotiatedLanguage(
      $language_manager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT),
      $language_manager->isMultilingual()
        ? (new CacheableMetadata())->setCacheContexts(['languages:' . LanguageInterface::TYPE_CONTENT])
        // No cache context needed on monolingual sites.
        : (new CacheableMetadata())
    );
  }

  /**
   * Creates a NegotiatedLanguage for a component tree's referenced entities.
   *
   * The referenced entities must be read in the same language as the rest of
   * the component instance's props: the host entity's language, matching how
   * EntityFieldPropSource and StaticPropSource resolve references. Pass the
   * same already-resolved fieldable host the prop sources were evaluated
   * against (see JsonSchemaPropsComponentSourceBase::getFieldableHostEntity());
   * without one (e.g. a config-entity tree) fall back to the negotiated
   * content language.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $reference_host
   *   The component instance's resolved fieldable host entity, if any.
   *
   * @return static
   *
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::getFieldableHostEntity()
   * @see \Drupal\canvas\PropSource\StaticPropSource::evaluate()
   */
  public static function forReferenceHost(?FieldableEntityInterface $reference_host): static {
    // @todo Same host-language conditional as StaticPropSource/EntityFieldPropSource::evaluate(); all three collapse to matchEntity() on the tree root in https://git.drupalcode.org/project/canvas/-/work_items/3571785.
    return $reference_host instanceof TranslatableInterface && $reference_host->isTranslatable()
      ? static::matchEntity($reference_host)
      : static::negotiateFromConfigAndContext();
  }

}
