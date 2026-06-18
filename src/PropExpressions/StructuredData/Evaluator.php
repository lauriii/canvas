<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\TypedData\DataReferenceInterface;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeFieldItemList;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;

/**
 * Evaluates an expression, starting with the given context (entity or field).
 *
 * It is crucial to know whether the result is required or optional. (In Canvas,
 * this typically means the SDC prop that is being populated by this expression
 * is required or not.)
 *
 * Impacts:
 * - absence of context would result in NULL if optional, exception otherwise
 * - when a field property value targeted by an expression evaluates to NULL
 *   (returned by its `::getCastedValue()`), this is fine if optional.
 *   However, if it is required, NULL is unacceptable. This can only happen due
 *   to inaccessible values, so a CacheableAccessDeniedHttpException is thrown.
 */
final class Evaluator {

  private static function permanentCacheabilityUnlessSpecified(mixed $value): CacheableDependencyInterface {
    if ($value instanceof CacheableDependencyInterface) {
      return $value;
    }
    // Unlike \Drupal\Core\Cache\CacheableMetadata::createFromObject(), when
    // evaluating expressions against structured data (an entity field or a
    // conjured field), permanent cacheability must be assumed: expressions are
    // guaranteed to traverse all relevant objects and will accumulate the right
    // cacheability that way.
    return new CacheableMetadata();
  }

  /**
   * Resolves an entity to its translation if available.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to resolve.
   * @param \Drupal\canvas\PropExpressions\StructuredData\NegotiatedLanguage $language
   *   The language code to resolve to.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The translated entity if available, otherwise the original entity.
   *
   * @throws \LogicException
   *   When requesting a translation for a locked language, like `und` etc. This
   *   should be impossible, but err on the side of caution.
   *
   * @see \Drupal\Core\Language\LanguageInterface::LANGCODE_NOT_SPECIFIED
   * @see \Drupal\Core\Language\LanguageInterface::LANGCODE_NOT_APPLICABLE
   * @see \Drupal\Core\Language\LanguageInterface::LANGCODE_DEFAULT
   * @see \Drupal\Core\Language\LanguageInterface::LANGCODE_SITE_DEFAULT
   * @see \Drupal\Core\Language\LanguageInterface::LANGCODE_SYSTEM
   */
  private static function resolveTranslation(FieldableEntityInterface $entity, NegotiatedLanguage $language): FieldableEntityInterface {
    if ($language->language->isLocked()) {
      // TRICKY: the ::isTranslatable() check is only necessary to allow e.g.
      // prop expressions pointing to a non-translatable entity type (e.g. File)
      // to not require a language to be specified.
      // @todo Remove this early return in https://git.drupalcode.org/project/canvas/-/work_items/3571785
      if ($entity instanceof TranslatableInterface && !$entity->isTranslatable()) {
        // @phpcs:ignore Drupal.Semantics.FunctionTriggerError.TriggerErrorTextLayoutRelaxed
        @trigger_error(\sprintf('Requested the %s translation for a %s entity object in the %s language.', $language->language->getId(), $entity->getEntityTypeId(), $entity->language()->getId()), E_USER_DEPRECATED);
        return $entity;
      }
      throw new \LogicException('Entities cannot have a translation for a locked language.');
    }
    // TRICKY: EntityRepositoryInterface::getTranslationFromContext() claims to
    // return either an entity object or NULL if the translation is "missing,
    // forbidden or unavailable", but the implementation actually guarantees an
    // entity object is returned *or* throws an \InvalidArgumentException.
    return \Drupal::service(EntityRepositoryInterface::class)
      // TRICKY: ::getTranslationFromContext() would add the necessary cache
      // context only if the entity actually has a translation. Canvas always
      // adds the necessary cache context, as soon as there's >=2 languages on
      // this site.
      ->getTranslationFromContext($entity, $language->language->getId())
      ->addCacheableDependency($language);
  }

  public static function evaluate(null|EntityInterface|FieldItemInterface|FieldItemListInterface $entity_or_field, StructuredDataPropExpressionInterface $expr, bool $is_required, NegotiatedLanguage $language): EvaluationResult {
    $result = self::doEvaluate($entity_or_field, $expr, $is_required, $language);
    // Compensate for DateTimeItemInterface::DATETIME_STORAGE_FORMAT not
    // including the trailing `Z`. In theory, this should always use an adapter.
    // But is the storage and complexity overhead of doing that worth that
    // architectural purity?
    // @see \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem::DATETIME_TYPE_DATETIME
    // @see \Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface::DATETIME_STORAGE_FORMAT
    // @see https://ijmacd.github.io/rfc3339-iso8601/
    // @todo Remove this in https://www.drupal.org/project/canvas/issues/3573934.
    if ($expr instanceof FieldTypePropExpression &&
      $expr->fieldType === 'datetime' &&
      $entity_or_field instanceof FieldItemInterface &&
      $entity_or_field->getFieldDefinition()->getFieldStorageDefinition()->getSetting('datetime_type') === DateTimeItem::DATETIME_TYPE_DATETIME &&
      \is_string($result->value) &&
      // Don't intervene if the result is already in iso8601 format: this
      // includes a trailing offset, or using the Z flag.
      !\preg_match('/(Z|[+-](?:2[0-3]|[01][0-9])(?::?[0-5][0-9])?)$/', $result->value)) {

      return new EvaluationResult($result->value . 'Z', $result);
    }
    return new EvaluationResult(
      // Use the cacheability carried by:
      // - the host entity: EntityInterface always implements
      //   CacheableDependencyInterface
      // - the field item list (both when it is an entity field and a conjured
      //   field): some computed field types implement
      //   CacheableDependencyInterface
      $result,
      self::permanentCacheabilityUnlessSpecified($entity_or_field)
    );
  }

  private static function doEvaluate(null|EntityInterface|FieldItemInterface|FieldItemListInterface $entity_or_field, StructuredDataPropExpressionInterface $expr, bool $is_required, NegotiatedLanguage $language): EvaluationResult {
    $permanent_cacheability = new CacheableMetadata();
    // Evaluating an expression when the evaluation context is NULL is
    // impossible.
    // @see \Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpressionInterface::validateSupport()
    if ($entity_or_field === NULL) {
      return match ($is_required) {
        // Optional value: the expression evaluates to NULL.
        FALSE => new EvaluationResult(NULL, $permanent_cacheability),
        // Required value: the expression MUST not evaluate to NULL, but without
        // data that is impossible. Throw exception that the caller MAY act on.
        TRUE => throw new \OutOfRangeException('No data provided to evaluate expression ' . (string) $expr),
      };
    }

    // Assert that the received entity or field meets the needs of the
    // expression.
    try {
      $expr->validateSupport($entity_or_field);
    }
    catch (\DomainException $e) {
      throw $e;
    }

    // When a list of field items is given:
    // - keep the deltas as keys
    // - evaluate each FieldItemInterface inside the list individually
    // 💡 This branch handles multiple-cardinality StaticPropSources.
    // @see \Drupal\canvas\PropSource\StaticPropSource::evaluate()
    if ($entity_or_field instanceof FieldItemListInterface) {
      return new EvaluationResult(
        \array_map(
          fn (FieldItemInterface $item) => self::evaluate($item, $expr, $is_required, $language),
          iterator_to_array($entity_or_field),
        ),
        $permanent_cacheability
      );
    }
    // 💡 This branch handles single-cardinality StaticPropSources.
    // @see \Drupal\canvas\PropSource\StaticPropSource::evaluate()
    elseif ($entity_or_field instanceof FieldItemInterface) {
      $field = $entity_or_field;
      $result = match ($expr::class) {
        FieldTypePropExpression::class => (function () use ($field, $expr) {
          $prop = $field->get($expr->propName);
          $prop_value = $prop instanceof PrimitiveInterface
            ? $prop->getCastedValue()
            : $prop->getValue();
          return new EvaluationResult(
            $prop_value,
            // Use the cacheability carried by the field property (common for
            // computed field properties), otherwise assume permanent
            // cacheability.
            self::permanentCacheabilityUnlessSpecified($prop->getValue())
          );
        })(),
        FieldTypeObjectPropsExpression::class => \array_map(
          fn ((ScalarPropExpressionInterface&FieldTypeBasedPropExpressionInterface)|(ReferencePropExpressionInterface&FieldTypeBasedPropExpressionInterface) $sub_expr) => self::evaluate($field, $sub_expr, $is_required, $language),
          $expr->getObjectExpressions(),
        ),
        ReferenceFieldTypePropExpression::class => (function () use ($field, $expr, $is_required, $language) {
          $reference_property = $field->get($expr->referencer->propName);
          \assert($reference_property instanceof DataReferenceInterface);
          \assert($reference_property instanceof EntityReference);

          $referenced_entity = $reference_property->getValue();
          \assert($referenced_entity === $reference_property->getTarget()?->getValue(), 'EntityReference::getTarget() returns an EntityAdapter that does not match.');
          \assert($referenced_entity instanceof FieldableEntityInterface || $referenced_entity === NULL);

          // If the field item is empty (it does not reference an entity), then
          // the expression in $expr->referenced does not need evaluating: there
          // is no entity to evaluate that expression against.
          if ($referenced_entity === NULL) {
            return NULL;
          }

          // Optional expression with no branch for this bundle: omit it.
          if (self::shouldOmitUnmatchedBundle($expr, $referenced_entity, $is_required)) {
            return NULL;
          }

          // Resolve the referenced entity to its translation if available.
          $referenced_entity = self::resolveTranslation($referenced_entity, $language);

          $referenced_expression = $expr->getTargetExpression(
            $expr->targetsMultipleBundles() ? $referenced_entity : NULL
          );
          return self::evaluate(
            $referenced_entity,
            $referenced_expression,
            $is_required,
            $language,
          );
        })(),
        default => throw new \LogicException('Unhandled expression type. ' . (string) $expr),
      };
      return new EvaluationResult(
        // Permanent cacheability because this is a conjured field; cacheability
        // of a computed field property is handled in the `match` above;
        // cacheability of a referenced entity is handled by traversing into
        // that entity.
        // @see \Drupal\canvas\PropSource\StaticPropSource
        $result,
        self::permanentCacheabilityUnlessSpecified($result)
      );
    }
    // 💡 This branch handles expressions used by EntityFieldPropSources.
    // @see \Drupal\canvas\PropSource\EntityFieldPropSource::evaluate()
    else {
      \assert($expr instanceof EntityFieldBasedPropExpressionInterface);
      $entity = $entity_or_field;
      // @todo support non-fieldable entities?
      \assert($entity instanceof FieldableEntityInterface);
      $entity_access = self::validateAccess($entity, $expr);
      $field_item_list = $entity->get($expr->getFieldName());
      \assert($field_item_list instanceof FieldItemListInterface);

      $field_definition = $field_item_list->getFieldDefinition();
      $cardinality = $field_definition->getFieldStorageDefinition()->getCardinality();
      // If a specific delta is requested, validate it.
      if ($expr->getDelta() !== NULL) {
        if ($expr->getDelta() < 0) {
          throw new \LogicException(\sprintf("Requested delta %d, but deltas must be positive integers.", $expr->getDelta()));
        }
        elseif ($cardinality === 1 && $expr->getDelta() !== 0) {
          throw new \LogicException(\sprintf("Requested delta %d for single-cardinality field, must be either zero or omitted.", $expr->getDelta()));
        }
        elseif ($cardinality !== FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED && $expr->getDelta() >= $cardinality) {
          throw new \LogicException(\sprintf("Requested delta %d for %d cardinality field, but must be in range [0, %d].", $expr->getDelta(), $cardinality, $cardinality - 1));
        }
        elseif (!$field_item_list->offsetExists($expr->getDelta())) {
          throw new \LogicException(\sprintf("Requested delta %d for unlimited cardinality field, but only deltas [0, %d] exist.", $expr->getDelta(), $field_item_list->count() - 1));
        }
      }

      $field_access = self::validateAccess($field_item_list, $expr);

      $result = match ($expr::class) {
        FieldPropExpression::class => (function () use ($expr, $field_item_list, $is_required, $cardinality) {
          $result = [];
          $raw_result = [];
          $result_cacheability = new CacheableMetadata();
          foreach ($field_item_list as $delta => $field_item) {
            if ($expr->delta === NULL || $expr->delta === $delta) {
              $prop = $field_item->get($expr->getFieldPropertyName());
              if ($prop instanceof CacheableDependencyInterface) {
                $result_cacheability->addCacheableDependency($prop);
              }
              $raw_result[$delta] = $prop->getValue();
              // An entity reference field item whose target was deleted still
              // stores the target ID, but getValue() returns NULL because the
              // entity no longer loads. The cached NULL result must invalidate
              // if that ID is ever written again (e.g. entity recreated with
              // the same ID), so add the cache tag now while the ID is still
              // reachable via getTargetIdentifier().
              if ($prop instanceof EntityReference
                && $raw_result[$delta] === NULL
                && ($target_id = $prop->getTargetIdentifier()) !== NULL
                && ($target_entity_type_id = $prop->getTargetDefinition()->getEntityTypeId()) !== NULL
              ) {
                // TRICKY: imperfect, but the default for most entity types.
                // @see \Drupal\Core\Entity\EntityBase::getCacheTagsToInvalidate
                $result_cacheability->addCacheTags([$target_entity_type_id . ':' . $target_id]);
              }
              $result[$delta] = $prop instanceof PrimitiveInterface
                ? $prop->getCastedValue()
                : $raw_result[$delta];
            }
          }

          // @see ::evaluate()
          // @todo Remove this in https://www.drupal.org/project/canvas/issues/3573934.
          if ($field_item_list instanceof DateTimeFieldItemList && $field_item_list->getSetting('datetime_type') === DateTimeItem::DATETIME_TYPE_DATETIME) {
            foreach ($result as $delta => $value) {
              // Nothing to do if NULL.
              if ($value === NULL) {
                continue;
              }
              // Don't intervene if the result is already in iso8601 format:
              // this includes a trailing offset, or using the Z flag.
              if (\preg_match('/(Z|[+-](?:2[0-3]|[01][0-9])(?::?[0-5][0-9])?)$/', $value)) {
                continue;
              }
              $result[$delta] = $value . 'Z';
            }
          }

          // - Single-cardinality or delta requested ⇒ single value.
          // - Multiple-cardinality and no delta requested ⇒ multiple values.
          if ($cardinality === 1 || \is_int($expr->delta)) {
            $result = $result[$expr->delta ?? 0] ?? NULL;
            $raw_result = $raw_result[$expr->delta ?? 0] ?? NULL;
          }
          if (!$is_required) {
            return new EvaluationResult($result, $result_cacheability);
          }

          // If the evaluation is for a required component prop, then the shape
          // matching infrastructure would have guaranteed that Typed Data flags
          // indicated the entire path in the given expression was required. In
          // other words: a value MUST exist.
          // But here we are: there is no value, there is NULL.
          // The only possible explanation for this is that some field
          // properties are computed and access checks prevent them from
          // returning the actual underlying value, to prevent information
          // disclosure vulnerabilities.
          $required_yet_empty = match(\is_array($result)) {
            // Multiple-cardinality and no delta requested.
            TRUE => array_all($result, fn ($prop_value) => $prop_value === NULL),
            // Single-cardinality or delta requested
            default => $result === NULL,
          };

          // Required and populated: evaluation successful.
          if (!$required_yet_empty) {
            return new EvaluationResult($result, $result_cacheability);
          }

          // Required and empty: evaluation failed; infer access was forbidden.
          $access_error_cache = new CacheableMetadata();
          if (!\is_array($result)) {
            $access_error_cache->addCacheableDependency($raw_result);
          }
          else {
            array_walk($raw_result, $access_error_cache->addCacheableDependency(...));
          }
          throw new CacheableAccessDeniedHttpException(
            $access_error_cache, \sprintf(
              'Required field property empty due to entity or field access while evaluating expression %s, reason: %s',
              $expr,
              $raw_result instanceof AccessResultReasonInterface ? $raw_result->getReason() : ''
            )
          );
        })(),
        ReferenceFieldPropExpression::class => (function () use ($entity, $field_item_list, $expr, $is_required, $cardinality, $language) {
          \assert($field_item_list->getName() === $expr->referencer->getFieldName());
          // Step 1: evaluate the referencer expression to get the referenced
          // entities. This always is a FieldPropExpression, which also handles
          // respecting the delta in the expression.
          // Note: this EvaluationResult object carries cacheability (for e.g.
          // entity access).
          $referencer_result = self::evaluate($entity, $expr->referencer, $is_required, $language);

          // Step 2A: single-cardinality or single delta: result is a single
          // value, not an array.
          if ($cardinality === 1 || $expr->getDelta() !== NULL) {
            $referenced_entity = $referencer_result->value;
            \assert($referenced_entity instanceof FieldableEntityInterface || $referenced_entity === NULL);
            if ($referenced_entity === NULL) {
              // If required and empty, the FieldPropExpression evaluation would
              // already have thrown a CacheableAccessDeniedHttpException.
              \assert(!$is_required);
              return new EvaluationResult(NULL, $referencer_result);
            }
            // Optional expression with no branch for this bundle: omit it.
            if (self::shouldOmitUnmatchedBundle($expr, $referenced_entity, $is_required)) {
              return new EvaluationResult(NULL, $referencer_result);
            }
            // Resolve the referenced entity to its translation if available.
            $referenced_entity = self::resolveTranslation($referenced_entity, $language);
            $referenced_expression = $expr->getTargetExpression(
              $expr->targetsMultipleBundles() ? $referenced_entity : NULL
            );
            return self::evaluate($referenced_entity, $referenced_expression, $is_required, $language);
          }

          // Step 2B: multiple-cardinality, no delta: result is an array of
          // values.
          // @phpstan-ignore identical.alwaysTrue
          \assert(($cardinality > 1 || $cardinality === FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) && $expr->getDelta() === NULL);
          $evaluated_references = [];
          $referenced_entities = $referencer_result->value;
          \assert(\is_array($referenced_entities));
          foreach ($referenced_entities as $delta => $referenced_entity) {
            \assert($referenced_entity instanceof FieldableEntityInterface);
            // Optional expression with no branch for this bundle: skip it.
            if (self::shouldOmitUnmatchedBundle($expr, $referenced_entity, $is_required)) {
              continue;
            }
            // Resolve the referenced entity to its translation if available.
            $referenced_entity = self::resolveTranslation($referenced_entity, $language);
            $referenced_expression = $expr->getTargetExpression(
              $expr->targetsMultipleBundles() ? $referenced_entity : NULL
            );
            $evaluated_references[$delta] = self::evaluate($referenced_entity, $referenced_expression, $is_required, $language);
          }
          return new EvaluationResult($evaluated_references, $referencer_result);
        })(),
        FieldObjectPropsExpression::class => \array_map(
          fn((ScalarPropExpressionInterface&EntityFieldBasedPropExpressionInterface)|(ReferencePropExpressionInterface&EntityFieldBasedPropExpressionInterface) $sub_expr): EvaluationResult => self::evaluate($entity_or_field, $sub_expr, $is_required, $language),
          $expr->getObjectExpressions(),
        ),
        default => throw new \LogicException('Unhandled expression type.'),
      };
      return new EvaluationResult(
        $result,
        CacheableMetadata::createFromObject($entity_access)
          ->addCacheableDependency($field_access)
      );
    }
  }

  /**
   * Determines whether an unmatched multi-bundle reference must be omitted.
   *
   * A multi-bundle reference expression only carries branches for specific
   * bundles. When the referenced entity's bundle has no branch, an optional
   * result omits it (the caller returns NULL or skips the delta); a required
   * result instead lets evaluation proceed so getBranch() surfaces an
   * \OutOfRangeException.
   *
   * @todo Figure out a fuller solution for the required case in
   *   https://www.drupal.org/project/canvas/issues/3563309
   * @see \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches::getBranch()
   */
  private static function shouldOmitUnmatchedBundle(ReferenceFieldTypePropExpression|ReferenceFieldPropExpression $expr, FieldableEntityInterface $entity, bool $is_required): bool {
    if (!$expr->targetsMultipleBundles()) {
      return FALSE;
    }
    \assert($expr->referenced instanceof ReferencedBundleSpecificBranches);
    return !$is_required
      && !$expr->referenced->hasBranch("entity:{$entity->getEntityTypeId()}:{$entity->bundle()}");
  }

  protected static function validateAccess(EntityInterface|FieldItemListInterface $entity_or_field, StructuredDataPropExpressionInterface $expr): AccessResultInterface {
    $access = $entity_or_field->access('view', NULL, TRUE);
    if (!$access->isAllowed()) {
      $access_error_cache = CacheableMetadata::createFromObject($access);
      throw new CacheableAccessDeniedHttpException(
        $access_error_cache, \sprintf(
          'Access denied to %s while evaluating expression, %s, reason: %s',
          $entity_or_field instanceof EntityInterface ? 'entity' : 'field',
          $expr,
          $access instanceof AccessResultReasonInterface ? $access->getReason() : NULL
        )
      );
    }
    return $access;
  }

}
