<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride;
use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\canvas\Utility\TypedDataHelper;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItemInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\text\Plugin\Field\FieldType\TextItemBase;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controllers exposing HTTP API for the content-entity-reference selection UI.
 *
 * This controller allows building a Drupal core Typed Data browser UI, with:
 * - roots: content entity types
 * - leafs: entity fields' field properties
 * - for every node: the Typed Data label
 * - for every leaf node: not just the Typed Data label, but also an expression
 *   (the string representation of a `EntityFieldBasedPropExpressionInterface`)
 *   that allows pointing to that field property (and evaluating/retrieving the
 *   value it contains)
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 */
final class ApiUiContentEntityReferenceControllers extends ApiControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Lists fieldable content entity types + bundles the current user can view.
   */
  public function listContentEntityTypes(): CacheableJsonResponse {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags([
      // @see \Drupal\Core\Entity\EntityTypeManager::__construct()
      'entity_types',
      // @see \Drupal\Core\Entity\EntityTypeBundleInfo::getAllBundleInfo()
      'entity_bundles',
    ]);

    $data = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if (!\is_subclass_of($entity_type->getClass(), ContentEntityInterface::class)) {
        continue;
      }

      $bundles = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);
      $accessible_bundles = [];
      foreach ($bundles as $bundle_id => $bundle_info) {
        $stub = $this->createBundleStub($entity_type_id, $bundle_id);
        if ($stub === NULL) {
          continue;
        }
        $access = self::getBundleViewAccess($stub);
        $cacheability->addCacheableDependency($access);
        if ($access->isAllowed()) {
          $fields_url = Url::fromRoute('canvas.api.ui.content_entity_reference.fields', [
            'entity_type' => $entity_type_id,
            'bundle' => $bundle_id,
          ])->toString(TRUE);
          $cacheability->addCacheableDependency($fields_url);
          $accessible_bundles[$bundle_id] = [
            'label' => (string) $bundle_info['label'],
            'links' => [
              CanvasUriDefinitions::LINK_REL_TYPED_DATA_BROWSER => ['href' => $fields_url->getGeneratedUrl()],
            ],
          ];
        }
      }

      if ($accessible_bundles === []) {
        continue;
      }

      $data[$entity_type_id] = [
        'label' => (string) $entity_type->getLabel(),
        'bundles' => $accessible_bundles,
      ];
    }

    $response = new CacheableJsonResponse(data: ['data' => $data]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * Lists fields on an entity type + bundle, each with its pickable properties.
   */
  public function listFields(string $entity_type, string $bundle, Request $request): CacheableJsonResponse {
    $entity_type_def = $this->entityTypeManager->getDefinition($entity_type, FALSE);
    if ($entity_type_def === NULL) {
      throw new NotFoundHttpException(\sprintf("The entity type '%s' does not exist.", $entity_type));
    }
    $bundle_info = $this->entityTypeBundleInfo->getBundleInfo($entity_type);
    if (!\array_key_exists($bundle, $bundle_info)) {
      throw new NotFoundHttpException(\sprintf("The entity type '%s' does not have a '%s' bundle.", $entity_type, $bundle));
    }

    $cacheability = new CacheableMetadata();

    $stub = $this->createBundleStub($entity_type, $bundle);
    if ($stub === NULL) {
      throw new CacheableAccessDeniedHttpException($cacheability);
    }
    $access = self::getBundleViewAccess($stub);
    $cacheability->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      throw new CacheableAccessDeniedHttpException($cacheability);
    }

    $host_entity_definition = BetterEntityDataDefinition::create($entity_type, $bundle);

    $parent_expression_string = $request->query->get('parent');
    $parent_expression = NULL;
    if (\is_string($parent_expression_string) && $parent_expression_string !== '') {
      try {
        $parent_expression = StructuredDataPropExpression::fromString($parent_expression_string);
      }
      catch (\Throwable) {
        throw new NotFoundHttpException('Invalid parent expression.');
      }
      // The parent represents the reference chain followed so far, so it must
      // be a reference expression: any other expression has no reference chain
      // to compose the picked fields onto.
      // @see ::composeWithParent()
      if (!$parent_expression instanceof ReferenceFieldPropExpression) {
        throw new NotFoundHttpException('Parent expression is not a reference expression.');
      }
      // A multi-target-bundle reference anywhere in the chain makes the leaf
      // to compose onto ambiguous. The picker never composes such parents.
      // @see ::resolveReferenceTarget()
      // @see \Drupal\canvas\Plugin\Validation\Constraint\MultiTargetBundleReferenceNotSupportedConstraint
      if ($parent_expression->findMultiTargetBundleReference() !== NULL) {
        throw new NotFoundHttpException('Multi-target-bundle parent expressions are not supported.');
      }
      // The composed expressions' leaves live on the requested entity type +
      // bundle, so the parent's reference chain must actually terminate there.
      $terminus = $parent_expression->getFinalTargetExpression()->getHostEntityDataDefinition();
      if ($terminus->getDataType() !== $host_entity_definition->getDataType()) {
        throw new NotFoundHttpException(\sprintf("The parent expression's reference chain terminates at '%s', not at the requested '%s'.", $terminus->getDataType(), $host_entity_definition->getDataType()));
      }
      // Verify access for every entity in the parent expression's reference
      // chain.
      $this->checkParentExpressionReferenceChainAccess($parent_expression, $cacheability);
    }

    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

    $data = [];
    foreach ($field_definitions as $field_definition) {
      // Skip internal (non-computed) fields, mirroring the per-property rule in
      // buildFieldEntry(): the picker must not offer what storage rejects.
      // @see ::buildFieldEntry()
      // @see \Drupal\canvas\Utility\TypedDataHelper::isEffectivelyInternal()
      // @see \Drupal\canvas\Plugin\Validation\Constraint\EntityFieldExpressionMustNotTargetInternalPropertyConstraint
      if (TypedDataHelper::isEffectivelyInternal($field_definition)) {
        continue;
      }
      if (self::isConsideredIrrelevant($entity_type_def, $field_definition->getName())) {
        continue;
      }
      // Skip multi-valued fields, for the same reason: the picker composes
      // delta-less expressions, which on a multi-valued field resolve to a
      // delta-keyed array of values (or entities) — not yet supported at
      // render time, and rejected by storage.
      // @see \Drupal\canvas\Plugin\Validation\Constraint\MultiValuedFieldNotSupportedConstraint
      // @todo https://git.drupalcode.org/project/canvas/-/work_items/3589536
      if ($field_definition->getFieldStorageDefinition()->getCardinality() !== 1) {
        continue;
      }
      // Skip fields the current user cannot view (e.g., the user entity's
      // `pass` field denies view access to non-admins). Same defensive catch
      // as the bundle-level access check above for handlers that dereference
      // unpopulated stub fields.
      try {
        $field_access = $stub->get($field_definition->getName())->access('view', return_as_object: TRUE);
      }
      catch (\Throwable) {
        $field_access = AccessResult::forbidden('Field access check failed against an unsaved stub entity.');
      }
      $cacheability->addCacheableDependency($field_access);
      if (!$field_access->isAllowed()) {
        continue;
      }
      $entry = $this->buildFieldEntry($field_definition, $host_entity_definition, $parent_expression);
      if ($entry !== NULL) {
        $data[] = $entry;
      }
    }

    $cacheability->addCacheTags($entity_type_def->getListCacheTags());
    // The field list — and the per-field decisions to skip multi-valued or
    // multi-target-bundle references — derive from field definitions. Both the
    // cardinality (field storage config) and the target bundles (field config)
    // can change, so depend on the tag invalidated whenever field definitions
    // change.
    // @see \Drupal\Core\Field\FieldStorageDefinitionListener::onFieldStorageDefinitionUpdate()
    // @see \Drupal\Core\Field\FieldConfigBase::postSave()
    $cacheability->addCacheTags(['entity_field_info']);
    // A reference that can be browsed into lists its target bundles' labels,
    // read from the target entity type's bundle info; depend on the tag
    // invalidated whenever bundle info changes so a renamed bundle's label is
    // not served stale.
    // @see ::buildFieldEntry()
    // @see \Drupal\Core\Entity\EntityTypeBundleInfo::getAllBundleInfo()
    $cacheability->addCacheTags(['entity_bundles']);
    $cacheability->addCacheContexts(['url.query_args:parent']);
    $response = new CacheableJsonResponse(data: ['data' => $data]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * Verifies access at every entity in a parent expression's reference chain.
   *
   * This is similar to \Drupal\canvas\PropExpressions\StructuredData\Evaluator,
   * which also checks access at every entity in a reference chain — but we
   * cannot use that here, because we act on a stub, before any entity has been
   * picked.
   */
  private function checkParentExpressionReferenceChainAccess(EntityFieldBasedPropExpressionInterface $expression, CacheableMetadata $cacheability): void {
    $entity_type_bundles = $this->collectEntityTypeBundlesInChain($expression);
    foreach ($entity_type_bundles as [$entity_type, $bundle]) {
      $stub = $this->createBundleStub($entity_type, $bundle);
      if ($stub === NULL) {
        throw new CacheableAccessDeniedHttpException($cacheability, "Access denied: cannot create stub for $entity_type:$bundle.");
      }
      $access = self::getBundleViewAccess($stub);
      $cacheability->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        throw new CacheableAccessDeniedHttpException($cacheability, "Access denied: view access to $entity_type:$bundle is not allowed.");
      }
    }
  }

  /**
   * Extracts (entity_type, bundle) pairs for every entity in a reference chain.
   *
   * @return array<array{0: string, 1: string}>
   *   A list of "entity type ID, bundle" pairs.
   */
  private function collectEntityTypeBundlesInChain(EntityFieldBasedPropExpressionInterface $expression): array {
    $entity_type_bundles = [];
    $host = $expression->getHostEntityDataDefinition();
    $entity_type_id = $host->getEntityTypeId();
    \assert(\is_string($entity_type_id));
    $bundles = $host->getBundles();
    $bundle = \is_array($bundles) && \count($bundles) === 1 ? reset($bundles) : NULL;
    \assert($bundle === NULL || \is_string($bundle));
    $entity_type_bundles[] = [$entity_type_id, $bundle ?? $entity_type_id];

    if ($expression instanceof ReferenceFieldPropExpression) {
      $referenced = $expression->referenced;
      if ($referenced instanceof EntityFieldBasedPropExpressionInterface) {
        $entity_type_bundles = [...$entity_type_bundles, ...$this->collectEntityTypeBundlesInChain($referenced)];
      }
    }
    return $entity_type_bundles;
  }

  /**
   * Whether a base field is entity-system bookkeeping the picker should hide.
   *
   * Covers the translation and revision metadata base fields a Code Component
   * Developer never consumes as content: "Default translation", "Revision
   * translation affected", "Translation source", "Translation outdated" and
   * "Revision log message". None are marked internal, so the internal-field
   * rule in listFields() cannot reach them.
   *
   * Mirrors the intent of PropSourceSuggester::isConsideredIrrelevant() but is
   * kept separate: the two surfaces have different needs (e.g. this picker
   * offers a reference field's `target_id`, which the suggester hides).
   *
   * @see \Drupal\canvas\ShapeMatcher\PropSourceSuggester::isConsideredIrrelevant()
   */
  private static function isConsideredIrrelevant(EntityTypeInterface $entity_type, string $field_name): bool {
    // "Default translation" and "Revision translation affected" are both
    // default entity keys, defined on the base entity type regardless of
    // whether it is a content or config entity.
    // @see \Drupal\Core\Entity\EntityType::__construct()
    $irrelevant = [
      $entity_type->getKey('default_langcode'),
      $entity_type->getKey('revision_translation_affected'),
    ];
    if ($entity_type instanceof ContentEntityTypeInterface) {
      // The "Revision log message" field name is entity-type specific (node
      // names it `revision_log`), so resolve it through the metadata key,
      // which is only declared on ContentEntityTypeInterface.
      $irrelevant[] = $entity_type->getRevisionMetadataKey('revision_log_message');
    }
    // content_translation adds these fixed-name base fields when a bundle is
    // translatable.
    // @see \Drupal\content_translation\ContentTranslationHandler::fieldStorageDefinitions()
    $irrelevant[] = 'content_translation_source';
    $irrelevant[] = 'content_translation_outdated';
    return \in_array($field_name, \array_filter($irrelevant), TRUE);
  }

  /**
   * Builds the JSON entry for a single field.
   *
   * Every non-internal typed-data property of the field becomes a row in
   * `properties[]`. Reference fields exclude their `entity` typed-data
   * property (it represents the descend path, not a leaf) and additionally
   * carry `hasChildren: true` plus `targetEntityType`/`targetBundles`.
   *
   * Returns NULL when the field has no surviving properties AND no walkable
   * target (so the UI has nothing to render for it).
   *
   * @return array{name: string, label: string, hasChildren: bool, properties: list<array{name: string, label: string, expression: string}>, targetEntityType?: string, targetBundles?: array<string, array{label: string, labelExpression: string}>}|null
   */
  private function buildFieldEntry(FieldDefinitionInterface $field_definition, BetterEntityDataDefinition $host_entity_definition, ?EntityFieldBasedPropExpressionInterface $parent_expression): ?array {
    $field_name = $field_definition->getName();
    // Field-level check is intentional and mirrors
    // EntityFieldPropSourceMatcher::matchContentEntityReferenceShape(): this
    // controller is scoped to content-entity-reference functionality. Other
    // reference-like field types (detectable via
    // `DataReferenceDefinitionInterface` at the property level) are out of
    // scope here.
    // @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher::matchContentEntityReferenceShape
    $is_reference = \is_subclass_of(
      $field_definition->getClass(),
      EntityReferenceFieldItemListInterface::class,
      TRUE,
    );

    $target_entity_type = NULL;
    $target_bundles = [];
    $target_label_key = NULL;
    if ($is_reference) {
      [$target_entity_type, $target_bundles, $target_label_key] = $this->resolveReferenceTarget($field_definition);
    }
    $has_walkable_target = $target_entity_type !== NULL && $target_bundles !== [] && $target_label_key !== NULL;

    $item_definition = $field_definition->getItemDefinition();
    \assert($item_definition instanceof ComplexDataDefinitionInterface);
    $properties = [];
    foreach ($item_definition->getPropertyDefinitions() as $property_name => $property_definition) {
      // Skip properties that are internal but not computed — i.e. internal
      // because the developer marked them as such, not because of the
      // computed-defaults-to-internal behavior in core. Computed properties
      // (e.g. Canvas's `src_with_alternate_widths`) stay pickable, unless the
      // definition explicitly opts back in to being internal (e.g. `date`,
      // via `DateTimeItemOverride`).
      // @see \Drupal\canvas\Utility\TypedDataHelper::isEffectivelyInternal()
      if (TypedDataHelper::isEffectivelyInternal($property_definition)) {
        continue;
      }
      // The `image` field type exposes two computed properties that survive the
      // rule above (computed and non-internal) but are implementation details a
      // Code Component Developer should not pick: `src_with_alternate_widths`
      // (which `src` clones, so listing it would show the same URL twice) and
      // `srcset_candidate_uri_template` (a raw RFC 6570 URI template that feeds
      // `src`). The developer-facing `src` is offered instead. They are
      // excluded by name so any other (existing or future) image property stays
      // offered by default.
      // @todo Remove the `src_with_alternate_widths` exclusion once it is removed entirely in https://git.drupalcode.org/project/canvas/-/work_items/3591648
      //
      // This is done here, in the picker only: marking those properties
      // internal at the field-type level would also hide them from
      // PropSourceSuggester, which still offers them. The `is source for`
      // setting cannot drive it either — it is set on `src`, and
      // EntityFieldPropSourceMatcher reads it the other way around (it hides
      // `src` and keeps `src_with_alternate_widths`), the opposite of what the
      // picker wants.
      // @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher::recurseDataDefinitionInterface()
      // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride::propertyDefinitions()
      if (\in_array($property_name, ['src_with_alternate_widths', 'srcset_candidate_uri_template'], TRUE)
        && \is_a($item_definition->getClass(), ImageItemOverride::class, TRUE)) {
        continue;
      }
      // Formatted text field types (`text`, `text_long`, `text_with_summary`
      // — all extend `TextItemBase`) expose the raw, unprocessed user input
      // `value`/`summary`; exposing those would let a code component render
      // markup that never passed through the text format's filters (an XSS
      // vector), so keep only the computed `processed`/`summary_processed`.
      // `format` is retained: it is not the raw input, and a code component
      // may need it to conditionally load assets the way a Drupal `filter`
      // plugin does, which `processed` cannot bubble when consumed over the
      // API.
      // @see \Drupal\text\Plugin\Field\FieldType\TextItemBase
      // @see \Drupal\filter\FilterProcessResult
      if (\in_array($property_name, ['value', 'summary'], TRUE)
        && \is_a($item_definition->getClass(), TextItemBase::class, TRUE)) {
        continue;
      }
      // A `uri`-typed property not constrained to the http/https schemes is
      // not guaranteed to resolve to a browser-accessible URL: e.g. the
      // `link` field type's raw `uri` can be `entity:node/1` or
      // `internal:/node`, and the `file_uri` field type's raw `value` is a
      // stream-wrapper URI like `public://image.jpg`. Properties that resolve
      // such values to a browser-accessible URL add their own
      // UriSchemeConstraint restricted to http/https instead (e.g. link's
      // `url`, file's `url`).
      // @see \Drupal\canvas\Utility\TypedDataHelper::isRestrictedToHttpSchemes()
      if ($property_definition->getDataType() === 'uri' && !TypedDataHelper::isRestrictedToHttpSchemes($property_definition)) {
        continue;
      }
      // The typed-data reference property on reference fields is the descend
      // path — surfaced via `targetEntityType`/`targetBundle`/`hasChildren`,
      // not as a pickable leaf.
      if ($property_definition instanceof DataReferenceDefinitionInterface) {
        continue;
      }
      $leaf = new FieldPropExpression($host_entity_definition, $field_name, NULL, $property_name);
      $composed = self::composeWithParent($leaf, $parent_expression);
      $label = (string) $property_definition->getLabel();
      if ($label === '') {
        $label = $property_name;
      }
      $properties[] = [
        'name' => $property_name,
        'label' => $label,
        'expression' => (string) $composed,
      ];
    }

    // Omit fields that have nothing to render: no surviving properties AND no
    // walkable target. (Reference fields with a walkable target stay even if
    // every leaf property was filtered out.)
    if ($properties === [] && !$has_walkable_target) {
      return NULL;
    }

    $output = [
      'name' => $field_name,
      'label' => (string) $field_definition->getLabel(),
      'hasChildren' => $has_walkable_target,
      'properties' => $properties,
    ];
    if ($has_walkable_target) {
      $output['targetEntityType'] = $target_entity_type;
      $all_bundle_info = $this->entityTypeBundleInfo->getBundleInfo($target_entity_type);
      $target_bundles_output = [];
      foreach ($target_bundles as $target_bundle) {
        $target_host = BetterEntityDataDefinition::create($target_entity_type, $target_bundle);
        $reference_expression = new ReferenceFieldPropExpression(
          new FieldPropExpression($host_entity_definition, $field_name, NULL, 'entity'),
          new FieldPropExpression($target_host, $target_label_key, NULL, 'value'),
        );
        $composed = (string) self::composeWithParent($reference_expression, $parent_expression);
        $target_bundles_output[$target_bundle] = [
          'label' => (string) ($all_bundle_info[$target_bundle]['label'] ?? $target_bundle),
          'labelExpression' => $composed,
          'links' => [
            CanvasUriDefinitions::LINK_REL_TYPED_DATA_BROWSER => [
              'href' => Url::fromRoute('canvas.api.ui.content_entity_reference.fields', [
                'entity_type' => $target_entity_type,
                'bundle' => $target_bundle,
              ])->setOption('query', [
                'parent' => $composed,
              ])->toString(),
            ],
          ],
        ];
      }
      $output['targetBundles'] = $target_bundles_output;
    }
    return $output;
  }

  /**
   * Resolves a reference field's target entity type, bundle(s), and label key.
   *
   * Returns [NULL, [], NULL] when the reference cannot be browsed:
   * - the target type doesn't resolve to a fieldable entity type (e.g.
   *   `langcode` references the config `configurable_language` type);
   * - the target type is bundle-keyed but `handler_settings.target_bundles`
   *   is empty.
   *
   * For bundle-less target types (user, file, …) the entity type ID itself
   * is used as the bundle ID. References whose `target_bundles` resolve to
   * more than one bundle also return [NULL, [], NULL]: browsing into them is
   * deferred, because the resulting multi-target-bundle expression is not
   * yet supported at render time.
   *
   * @todo https://git.drupalcode.org/project/canvas/-/work_items/3591656
   *
   * @return array{0: ?string, 1: list<string>, 2: ?string}
   */
  private function resolveReferenceTarget(FieldDefinitionInterface $field_definition): array {
    // Mirror how Canvas already navigates entity-reference fields: read the
    // target entity type off the `entity` property's typed-data definition.
    // @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher::getConstrainedTargetDefinition()
    $item_definition = $field_definition->getItemDefinition();
    if (!\is_subclass_of($item_definition->getClass(), EntityReferenceItemInterface::class, TRUE)) {
      throw new \LogicException(\sprintf("resolveReferenceTarget() must only be called for entity-reference fields, but '%s' is not a subclass of EntityReferenceItemInterface.", $item_definition->getClass()));
    }
    \assert($item_definition instanceof ComplexDataDefinitionInterface);
    $entity_property = $item_definition->getPropertyDefinition('entity');
    \assert($entity_property instanceof DataReferenceDefinitionInterface);
    $target = $entity_property->getTargetDefinition();
    \assert($target instanceof EntityDataDefinition);
    $target_type = $target->getEntityTypeId();
    \assert(\is_string($target_type) && $target_type !== '');
    $target_entity_type = $this->entityTypeManager->getDefinition($target_type, FALSE);
    if ($target_entity_type === NULL || !\is_subclass_of($target_entity_type->getClass(), FieldableEntityInterface::class)) {
      return [NULL, [], NULL];
    }

    // TRICKY: core's EntityReferenceItem never constrains the `entity`
    // property's target definition to a bundle, so the bundle has to come
    // from the field item's `handler_settings.target_bundles` — same
    // workaround as EntityFieldPropSourceMatcher.
    // @see \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem::propertyDefinitions()
    // @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher::matchEntityProps()
    // @see https://www.drupal.org/project/drupal/issues/2169813
    $target_bundles = $item_definition->getSettings()['handler_settings']['target_bundles'] ?? [];
    if (!$target_bundles && $target_entity_type->hasKey('bundle')) {
      // The target entity type uses bundles but none are configured: we
      // don't know which bundle's fields to show. The UI won't be able to
      // browse into the referenced entity's fields, but the field's own
      // leaf properties still surface.
      return [NULL, [], NULL];
    }
    $resolved_bundles = $target_bundles ? \array_values(\array_map('strval', $target_bundles)) : [$target_type];
    \sort($resolved_bundles);
    // References targeting more than one bundle would let the picker compose a
    // multi-target-bundle expression (coalescing into
    // ReferencedBundleSpecificBranches), which is not yet supported at render
    // time. Don't offer browsing into them until that lands; the field's own
    // leaf properties still surface.
    // @see \Drupal\canvas\Plugin\Validation\Constraint\MultiTargetBundleReferenceNotSupportedConstraint
    // @todo https://git.drupalcode.org/project/canvas/-/work_items/3591656
    if (\count($resolved_bundles) > 1) {
      return [NULL, [], NULL];
    }
    $label_key = $target_entity_type->getKey('label');
    if (!$label_key) {
      $base_fields = $this->entityFieldManager->getBaseFieldDefinitions($target_type);
      // @see \Drupal\user\Entity\User::getAccountName()
      $label_key = isset($base_fields['name']) ? 'name' : NULL;
    }
    if (!$label_key) {
      return [NULL, [], NULL];
    }
    return [$target_type, $resolved_bundles, $label_key];
  }

  /**
   * If a parent expression is provided, composes it with the field expression.
   *
   * The parent expression represents a reference chain up to (and including)
   * the reference field. The field expression is what we picked at the current
   * level. Result is a ReferenceFieldPropExpression rooted at the original
   * host, following the parent expression's reference chain, with the current
   * field as the leaf.
   */
  private static function composeWithParent(EntityFieldBasedPropExpressionInterface $field_expression, ?EntityFieldBasedPropExpressionInterface $parent_expression): EntityFieldBasedPropExpressionInterface {
    if (!$parent_expression instanceof ReferenceFieldPropExpression) {
      return $field_expression;
    }
    return $parent_expression->withFinalTargetReplaced($field_expression);
  }

  /**
   * Creates an unsaved stub entity for the given bundle, for access checks.
   *
   * The stub is normalized so that access handlers keying on entity data grant
   * view access to users who hold the relevant permission, rather than denying
   * the content-less stub:
   * - users are activated and content entities published, for handlers that
   *   require the entity to be "active"/"published";
   * - files are given a public-scheme URI: FileAccessControlHandler grants
   *   view access to a public file via the 'access content' permission, but a
   *   URI-less stub would be denied. The real per-file access is enforced at
   *   render time by Evaluator.
   *
   * Returns NULL when the entity type cannot be stubbed from just the bundle
   * key.
   *
   * @see \Drupal\file\FileAccessControlHandler::checkAccess()
   */
  private function createBundleStub(string $entity_type_id, string $bundle): ?ContentEntityInterface {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $values = [];
    if ($bundle_key = $entity_type->getKey('bundle')) {
      $values[$bundle_key] = $bundle;
    }
    try {
      $stub = $storage->create($values);
    }
    catch (\Throwable) {
      return NULL;
    }
    \assert($stub instanceof ContentEntityInterface);
    match (TRUE) {
      $stub instanceof UserInterface => $stub->activate(),
      $stub instanceof FileInterface => $stub->setFileUri('public://'),
      $stub instanceof EntityPublishedInterface => $stub->setPublished(),
      default => NULL,
    };
    return $stub;
  }

  /**
   * Checks 'view' access for a stub entity, swallowing handler errors.
   *
   * Some core access handlers dereference fields the stub can't realistically
   * populate (CommentAccessControlHandler calls
   * `$entity->getCommentedEntity()->access(...)`, which errors on a stub with
   * no commented entity). Treat those as forbidden so one such entity type
   * doesn't sink the entire response.
   */
  private static function getBundleViewAccess(ContentEntityInterface $stub): AccessResultInterface {
    try {
      return $stub->access('view', return_as_object: TRUE);
    }
    catch (\Throwable) {
      return AccessResult::forbidden('Access check failed against an unsaved stub entity.');
    }
  }

}
