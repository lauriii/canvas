<?php

declare(strict_types=1);

namespace Drupal\canvas\Access;

use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Plugin\DataType\ConfigEntityAdapter;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Checks access for editing an entity's component tree.
 *
 * @internal
 */
final class ComponentTreeEditAccessCheck implements AccessInterface {

  public function __construct(private readonly ComponentTreeLoader $componentTreeLoader) {}

  /**
   * Checks access for editing an entity's component tree.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity containing a component tree.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account being checked.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(EntityInterface $entity, AccountInterface $account): AccessResultInterface {
    if ($entity instanceof FieldableEntityInterface || $entity instanceof ComponentTreeEntityInterface) {
      // A field-hosted component tree that is not a Canvas page: per-entity
      // editing is only offered for templated bundles with exposed slots
      // (decision 6). This check runs both when gating the Layout API and, via
      // checkNamedRoute(), when building the "Layout" task on the entity's
      // canonical route.
      $is_field_hosted = $entity instanceof FieldableEntityInterface && !$entity instanceof ComponentTreeEntityInterface;

      // Per-content editing (templated bundle): slot content lives in internal
      // per-slot `component_tree` fields merged at render time, so there is no
      // single tree to field-access-check. Gate on entity update access.
      if ($is_field_hosted && $this->componentTreeLoader->hasContentTemplateWithExposedSlots($entity)) {
        \assert($entity instanceof FieldableEntityInterface);
        $entity_access = $entity->access('update', $account, TRUE);
        \assert($entity_access instanceof AccessResult);
        return $entity_access->addCacheableDependency(self::perContentCacheability($entity));
      }

      // The loader throws when a field-hosted entity has no Canvas field and is
      // not templated; translate that into a clean access denial, not a 500.
      try {
        $tree = $this->componentTreeLoader->load($entity);
      }
      catch (\LogicException) {
        \assert($is_field_hosted && $entity instanceof FieldableEntityInterface);
        return AccessResult::forbidden('This entity has no editable component tree.')
          ->addCacheableDependency(self::perContentCacheability($entity));
      }
      // TRICKY: field access hooks must return AccessResult::forbidden() to
      // override the default field access. Then the forbidden field access's
      // reason would overwrite that of non-allowed entity access. Avoid that by
      // explicitly checking entity access and returning early.
      // @see \Drupal\Core\Field\FieldItemList::defaultAccess()
      $entity_access = $entity->access('update', $account, TRUE);
      if (!$entity_access->isAllowed()) {
        if ($is_field_hosted) {
          \assert($entity instanceof FieldableEntityInterface && $entity_access instanceof AccessResult);
          $entity_access->addCacheableDependency(self::perContentCacheability($entity));
        }
        return $entity_access;
      }

      // If the component tree is a field on the entity, also check field
      // access.
      if ($entity instanceof FieldableEntityInterface) {
        \assert(
          // Every fieldable entity's component tree field has the edited entity
          // as its parent.
          // @phpstan-ignore-next-line method.notFound
          $tree->getParent()->getEntity() === $entity
          // TRICKY: when the component tree field itself is not translatable
          // but the containing entity is, the $entity object will not match the
          // field's parent entity object due to how Drupal loads untranslatable
          // fields: it always does so using the default entity translation. So
          // verify using config dependency names that both objects truly do
          // refer to the same content entity.
          // @see \Drupal\Core\Language\LanguageInterface::LANGCODE_DEFAULT
          // @see \Drupal\Core\Entity\ContentEntityBase::getTranslatedField()
          // @see \Drupal\Tests\canvas\Functional\TranslationTest
          || (
            // @phpstan-ignore-next-line method.notFound
            $entity->isDefaultTranslation() === FALSE
            && $tree->getFieldDefinition()->isTranslatable() === FALSE
            && $tree->getLangcode() !== $entity->language()->getId()
            // @phpstan-ignore-next-line method.nonObject
            && $tree->getParent()->getEntity()->getConfigDependencyName() === $entity->getConfigDependencyName()
          )
        );
        $access = $entity_access->andIf($tree->access('edit', $account, TRUE));
        if ($is_field_hosted) {
          \assert($access instanceof AccessResult);
          $access->addCacheableDependency(self::perContentCacheability($entity));
        }
        return $access;
      }

      // Every non-fieldable entity containing a component tree has a component
      // tree with a config entity as the host entity.
      \assert($tree->getParent() instanceof ConfigEntityAdapter && $tree->getParent()->getEntity() === $entity);
      \assert($entity instanceof ConfigEntityInterface);

      return $entity_access;
    }
    // No opinion.
    return AccessResult::neutral();
  }

  /**
   * Cacheability for per-content (templated bundle) edit access decisions.
   *
   * The decision hinges on whether the entity's bundle has an enabled content
   * template with exposed slots. Depend on that so the "Layout" local task and
   * Layout API access re-evaluate when a template is created, deleted, or
   * (un)exposes slots.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The templated content entity.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The cacheability metadata to attach to the access result.
   */
  private static function perContentCacheability(FieldableEntityInterface $entity): CacheableMetadata {
    $cacheability = (new CacheableMetadata())
      ->addCacheTags([
        ContentTemplate::ENTITY_TYPE_ID . '_list',
        'entity_field_info',
        'entity_bundles',
      ]);
    // Only the full view mode can expose slots.
    $template = ContentTemplate::loadForEntity($entity, 'full');
    if ($template !== NULL) {
      $cacheability->addCacheableDependency($template);
    }
    return $cacheability;
  }

}
