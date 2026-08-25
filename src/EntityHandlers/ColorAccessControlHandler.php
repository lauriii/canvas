<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\Access\CanvasUiAccessCheck;
use Drupal\canvas\Audit\ColorAudit;
use Drupal\canvas\Audit\RevisionAuditEnum;
use Drupal\canvas\Entity\Color;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines an access control handler for Brand Kit Color entities.
 *
 * Deleting a Color that is still in use is blocked, matching how code
 * components behave.
 *
 * TRICKY: this extends CanvasConfigEntityAccessControlHandler rather than
 * ContentCreatorVisibleCanvasConfigEntityAccessControlHandler — whose `view`
 * and `view label` behavior it reproduces below — because that class has a
 * `final` constructor, so a subclass cannot inject ColorAudit.
 *
 * @see \Drupal\canvas\EntityHandlers\VisibleWhenDisabledCanvasConfigEntityAccessControlHandler::checkAccess()
 * @see \Drupal\canvas\EntityHandlers\ContentCreatorVisibleCanvasConfigEntityAccessControlHandler::checkAccess()
 */
final class ColorAccessControlHandler extends CanvasConfigEntityAccessControlHandler {

  protected $viewLabelOperation = TRUE;

  public function __construct(
    EntityTypeInterface $entity_type,
    ConfigManagerInterface $configManager,
    EntityTypeManagerInterface $entityTypeManager,
    private readonly CanvasUiAccessCheck $canvasUiAccessCheck,
    private readonly ColorAudit $colorAudit,
  ) {
    parent::__construct($entity_type, $configManager, $entityTypeManager);
  }

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get(ConfigManagerInterface::class),
      $container->get(EntityTypeManagerInterface::class),
      $container->get(CanvasUiAccessCheck::class),
      $container->get(ColorAudit::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    \assert($entity instanceof ConfigEntityInterface);

    // Content creators may see a color's label so it can be published, and the
    // color itself while it is enabled.
    if ($operation === 'view label') {
      return $this->canvasUiAccessCheck->access($account)->addCacheableDependency($entity);
    }
    if ($operation === 'view') {
      return $this->canvasUiAccessCheck->access($account)
        ->andIf(AccessResult::allowedIf($entity->status())->addCacheableDependency($entity));
    }

    $parent_result = parent::checkAccess($entity, $operation, $account);
    if ($operation !== 'delete' || !$entity instanceof Color) {
      return $parent_result;
    }

    // TRICKY: inspect usage last for 2 reasons:
    // 1. This avoids overwriting the "config dependencies" reason to not allow
    //    access set by the parent implementation.
    // 2. This avoids calling the more expensive ColorAudit service when there
    //    is no need.
    //
    // The parent's answer still needs the audit's cacheability. It is derived
    // from the config dependency graph, which carries none of its own: without
    // these tags a forbid outlives the last Pattern that caused it.
    if (!$parent_result->isAllowed()) {
      return $this->withUsageCacheability($parent_result);
    }

    // TRICKY: run one audit at a time and return on the first hit. Chaining
    // ::orIf(AccessResult::forbiddenIf(…)) would read better, but PHP
    // evaluates every argument, so all three audits would run on every access
    // check even once the answer is known.
    //
    // Auto-save comes *first*, because it tends to require far less I/O.
    // A forward (pending, non-default) revision counts as a usage too:
    // publishing it must not end up rendering a color that no longer exists.
    // Usage in only a prior revision does not block, matching code components.
    $blocking_usages = [
      [RevisionAuditEnum::AutoSave, 'This color is in use in a Canvas auto-save and cannot be deleted.'],
      [RevisionAuditEnum::Default, 'This color is in use in a default revision and cannot be deleted.'],
      [RevisionAuditEnum::Latest, 'This color is in use in the latest revision and cannot be deleted.'],
    ];
    foreach ($blocking_usages as [$which_revisions, $reason]) {
      // Config usage is already covered by the parent implementation, so only
      // content and auto-save usages remain to be inspected.
      $in_use = $which_revisions === RevisionAuditEnum::AutoSave
        ? $this->colorAudit->getAutoSavesUsingAuditTarget($entity) !== []
        : $this->colorAudit->hasContentUsages($entity, $which_revisions);
      if ($in_use) {
        return $this->withUsageCacheability($parent_result->orIf(AccessResult::forbidden($reason)));
      }
    }

    return $this->withUsageCacheability($parent_result);
  }

  /**
   * Declares what a usage-derived access result depends on.
   *
   * Every outcome needs this: a forbid must lapse once the last usage is gone,
   * and an allow must lapse once a first usage appears. That holds whether the
   * usage was found in content, in an auto-save, or in the config dependency
   * graph the parent implementation consults.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $result
   *   An access result derived from a usage answer.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The same result, now carrying the audit's cache tags.
   */
  private function withUsageCacheability(AccessResultInterface $result): AccessResultInterface {
    \assert($result instanceof RefinableCacheableDependencyInterface);
    return $result->addCacheTags($this->colorAudit->getUsageCacheTags());
  }

}
