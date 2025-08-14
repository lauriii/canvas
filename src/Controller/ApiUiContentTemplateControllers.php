<?php

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpressionInterface;
use Drupal\experience_builder\PropSource\DynamicPropSource;
use Drupal\experience_builder\ShapeMatcher\FieldForComponentSuggester;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controllers exposing HTTP API for powering XB's Content Template editor UI.
 *
 * @internal This HTTP API is intended only for the XB UI. These controllers
 *   and associated routes may change at any time.
 *
 * @see \Drupal\experience_builder\ShapeMatcher\FieldForComponentSuggester
 */
final class ApiUiContentTemplateControllers extends ApiControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly FieldForComponentSuggester $fieldForComponentSuggester,
  ) {}

  /**
   * Provides suggestions for a given Component based on entity type and bundle.
   *
   * @param string $content_entity_type_id
   *   A content entity type ID.
   * @param string $bundle
   *   A bundle of the given content entity type.
   * @param string $component_config_entity_id
   *   A Component config entity ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the suggestions for the component.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   */
  public function suggestions(string $content_entity_type_id, string $bundle, string $component_config_entity_id): JsonResponse {
    // @see \Drupal\Core\EventSubscriber\ExceptionJsonSubscriber
    $this->validateRequest($content_entity_type_id, $bundle, $component_config_entity_id);
    // @phpstan-ignore-next-line
    $source = Component::load($component_config_entity_id)->getComponentSource();
    assert($source instanceof GeneratedFieldExplicitInputUxComponentSourceBase);

    $suggestions = $this->fieldForComponentSuggester->suggest(
      $source->getSdcPlugin()->getPluginId(),
      EntityDataDefinition::createFromDataType("entity:$content_entity_type_id:$bundle"),
    );

    return new JsonResponse(status: Response::HTTP_OK, data: array_combine(
      // Top-level keys: the prop names of the targeted component.
      array_map(
        fn (string $key): string => ComponentPropExpression::fromString($key)->propName,
        array_keys($suggestions),
      ),
      array_map(
        fn (array $instances): array => array_combine(
          // Second level keys: opaque identifiers for the suggestions to
          // populate the component prop.
          array_map(
            fn (StructuredDataPropExpressionInterface $expr): string => \hash('xxh64', (string) $expr),
            array_values($instances),
          ),
          // Values: objects with "label" and "source" keys, with:
          // - "label": the human-readable label that the Content Template UI
          //   should present to the human
          // - "source": the array representation of the DynamicPropSource that,
          //   if selected by the human, the client should use verbatim as the
          //   source to populate this component instance's prop.
          array_map(
            function (string $label, StructuredDataPropExpressionInterface $expr) {
              return [
                'label' => $label,
                'source' => (new DynamicPropSource($expr))->toArray(),
              ];
            },
            array_keys($instances),
            array_values($instances),
          ),
        ),
        array_column($suggestions, 'instances'),
      ),
    ));
  }

  /**
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   */
  private function validateRequest(string $content_entity_type_id, string $bundle, string $component_config_entity_id): void {
    $component = Component::load($component_config_entity_id);
    if (NULL === $component) {
      throw new NotFoundHttpException("The component $component_config_entity_id does not exist.");
    }

    $source = $component->getComponentSource();
    if (!$source instanceof GeneratedFieldExplicitInputUxComponentSourceBase) {
      throw new BadRequestHttpException('Only components that define their inputs using JSON Schema and use fields to populate their inputs are currently supported.');
    }

    // @todo Add support for suggestions for code components in https://www.drupal.org/i/3503038
    if (!$source instanceof SingleDirectoryComponent) {
      throw new BadRequestHttpException('Code components are not supported yet.');
    }

    if ($this->entityTypeManager->getDefinition($content_entity_type_id, FALSE) === NULL) {
      throw new NotFoundHttpException(sprintf("The `%s` content entity type does not exist.", $content_entity_type_id));
    }

    if (!array_key_exists($bundle, $this->entityTypeBundleInfo->getBundleInfo($content_entity_type_id))) {
      throw new NotFoundHttpException(sprintf("The `%s` content entity type does not have a `%s` bundle.", $content_entity_type_id, $bundle));
    }
  }

}
