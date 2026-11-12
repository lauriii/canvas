<?php

declare(strict_types=1);

namespace Drupal\canvas\Routing;

use Drupal\canvas\ContentTranslation\ComponentTreeTranslationFork;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\ParamConverter\EntityConverter;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Upcasts a content entity to the translation named by `?canvas_langcode=`.
 *
 * Applies to `entity:*` route parameters that set `canvas_translation`:
 * - `optional`: without the query parameter, the negotiated translation is
 *   upcast, like for any entity parameter.
 * - `required`: without the query parameter, the route is not found.
 *
 * The named translation is exact: entity upcasting otherwise falls back to
 * another translation through the language fallback chain, and acting on a
 * translation the client did not name would write to the wrong language.
 * A language without a stored translation upcasts to a new translation
 * initialized from the default one: a translation forked before it was first
 * published exists only in its auto-save draft. Route access is then checked
 * against the named translation.
 *
 * Editor requests name a forked translation this way rather than through
 * language negotiation: the `canvas_preview_langcode` negotiation redirect is
 * GET-only by design, and redirecting request bodies through
 * negotiation-specific URLs is fragile.
 *
 * @see \Drupal\canvas\Access\ForkedTranslationEditAccessCheck
 * @see \Drupal\Core\ParamConverter\AdminPathConfigEntityConverter
 * @see \Drupal\canvas\EventSubscriber\CanvasRouteOptionsEventSubscriber::redirectCanvasApiToPreviewLanguage()
 */
final readonly class NamedTranslationConverter implements ParamConverterInterface {

  public const string QUERY_KEY = 'canvas_langcode';

  public function __construct(
    private EntityConverter $entityConverter,
    private ParamConverterInterface $adminPathConfigEntityConverter,
    private RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    // Upcast as the converter this one outranks would have: config entities
    // on admin routes (e.g. page variants in the editor) must be loaded
    // without config overrides, so the editor always edits the original.
    $converter = $this->adminPathConfigEntityConverter->applies($definition, $name, $defaults[RouteObjectInterface::ROUTE_OBJECT])
      ? $this->adminPathConfigEntityConverter
      : $this->entityConverter;
    $entity = $converter->convert($value, $definition, $name, $defaults);
    $langcode = $this->requestStack->getCurrentRequest()?->query->get(self::QUERY_KEY);
    if (!\is_string($langcode) || $langcode === '') {
      if ($definition['canvas_translation'] === 'required') {
        throw new ParamNotConvertedException(\sprintf('The "%s" parameter was not converted because the `%s` query parameter is missing.', $name, self::QUERY_KEY));
      }
      return $entity;
    }
    // Config entities (e.g. code components on the discard route) have no
    // content translations; the parameter does not apply to them.
    if (!$entity instanceof ContentEntityInterface || !$entity->isTranslatable()) {
      return $entity;
    }
    if ($entity->hasTranslation($langcode)) {
      return $entity->getTranslation($langcode);
    }
    try {
      return ComponentTreeTranslationFork::initializeTranslation($entity, $langcode);
    }
    catch (\InvalidArgumentException) {
      throw new ParamNotConvertedException(\sprintf('The "%s" parameter was not converted because %s is not a valid language.', $name, $langcode));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    return \in_array($definition['canvas_translation'] ?? NULL, ['optional', 'required'], TRUE)
      && $this->entityConverter->applies($definition, $name, $route);
  }

}
