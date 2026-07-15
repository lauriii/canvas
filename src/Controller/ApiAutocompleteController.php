<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * HTTP API for entity autocomplete matches for a component's prop.
 *
 * Serves the native (client-side rendered) entity reference autocomplete
 * widget. The client only identifies the component prop being populated; all
 * scoping (target entity type, selection handler, target bundles) is derived
 * server-side from the Component config entity's stored prop field
 * definitions, so it cannot be manipulated by the client.
 *
 * @see docs/adr/0017-client-side-field-widgets.md
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 */
final class ApiAutocompleteController extends ApiControllerBase {

  /**
   * The maximum number of autocomplete matches to return.
   */
  private const int MATCH_LIMIT = 10;

  public function __construct(
    private readonly SelectionPluginManagerInterface $selectionPluginManager,
  ) {}

  public function __invoke(Request $request): JsonResponse {
    $prop_field_definition = self::resolvePropFieldDefinition($request);
    if (($prop_field_definition['field_type'] ?? NULL) !== 'entity_reference') {
      throw new BadRequestHttpException(\sprintf(
        "The `%s` prop is not stored as an `entity_reference` field, so autocompletion is not supported for it.",
        $request->query->getString('prop'),
      ));
    }

    $q = \trim($request->query->getString('q'));
    if ($q === '') {
      return new JsonResponse(['results' => []]);
    }

    $target_type = $prop_field_definition['field_storage_settings']['target_type'] ?? NULL;
    if (!\is_string($target_type) || $target_type === '') {
      throw new BadRequestHttpException(\sprintf(
        "The `%s` prop's field storage settings do not specify a target entity type.",
        $request->query->getString('prop'),
      ));
    }

    // Build a selection handler with the exact settings stored on the
    // Component config entity; its entity query applies entity access.
    // @see \Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection::buildEntityQuery()
    $options = ($prop_field_definition['field_instance_settings']['handler_settings'] ?? []) + [
      'target_type' => $target_type,
    ];
    if (isset($prop_field_definition['field_instance_settings']['handler'])) {
      $options['handler'] = $prop_field_definition['field_instance_settings']['handler'];
    }
    $selection_handler = $this->selectionPluginManager->getInstance($options);
    \assert($selection_handler instanceof SelectionInterface);

    $results = [];
    // The result is keyed by bundle; flatten it.
    foreach ($selection_handler->getReferenceableEntities($q, 'CONTAINS', self::MATCH_LIMIT) as $bundle_matches) {
      foreach ($bundle_matches as $entity_id => $label) {
        // Selection handlers HTML-escape labels for server-side rendering;
        // undo that because this is a plain-data JSON API.
        // @see \Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection::getReferenceableEntities()
        $results[] = [
          'id' => (string) $entity_id,
          'label' => Html::decodeEntities((string) $label),
        ];
      }
    }
    \usort($results, static fn (array $a, array $b): int => \strnatcasecmp($a['label'], $b['label']));

    // Deliberately not cacheable: matches are user-specific (entity access)
    // and change with every content change.
    return new JsonResponse(['results' => $results]);
  }

  /**
   * Resolves the requested prop's stored field definition.
   *
   * @return array<string, mixed>
   *   The prop's field definition as stored in the Component config entity's
   *   `settings.prop_field_definitions`: `field_type`, `field_widget`,
   *   `field_storage_settings`, `field_instance_settings`, etc.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the component, the requested version, or the prop does not
   *   exist.
   */
  private static function resolvePropFieldDefinition(Request $request): array {
    $component_id = $request->query->getString('component');
    $version = $request->query->getString('version');
    $prop = $request->query->getString('prop');

    $component = Component::load($component_id);
    if (!$component instanceof ComponentInterface) {
      throw new NotFoundHttpException(\sprintf("The component `%s` does not exist.", $component_id));
    }
    try {
      $component->loadVersion($version);
    }
    catch (\OutOfRangeException $e) {
      throw new NotFoundHttpException($e->getMessage(), $e);
    }

    $prop_field_definitions = $component->getSettings()['prop_field_definitions'] ?? [];
    if (!\array_key_exists($prop, $prop_field_definitions)) {
      throw new NotFoundHttpException(\sprintf("The `%s` component does not have a `%s` prop.", $component_id, $prop));
    }
    return $prop_field_definitions[$prop];
  }

}
