<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\Core\Breadcrumb\ChainBreadcrumbBuilderInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Service to expose site metadata to drupalSettings for JS components.
 *
 * This includes site branding, breadcrumbs, page title, main entity
 * identifiers, and base URL. Intended for use with dynamic
 * JavaScript components such as those in Drupal Canvas.
 */
readonly final class CodeComponentDataProvider {

  public const string V0 = 'v0';
  public const string CANVAS_DATA_KEY = 'canvasData';

  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private RequestStack $requestStack,
    private RouteMatchInterface $routeMatch,
    private TitleResolverInterface $titleResolver,
    private ChainBreadcrumbBuilderInterface $breadcrumbManager,
    private LanguageManagerInterface $languageManager,
    private ContainerInterface $container,
    private ThemeSettingsProvider $themeSettingsProvider,
  ) {}

  /**
   * Returns the BaseUrl for V0 of drupalSettings.canvasData.
   *
   * @return array[]
   */
  public function getCanvasDataBaseUrlV0(): array {
    $request = $this->requestStack->getCurrentRequest();
    \assert($request instanceof Request);

    return [
      self::V0 => [
        // ⚠️ Not the same as `drupalSettings.path.baseUrl` nor Symfony's
        // definition of a base URL.
        // JavaScript tools like @drupal-api-client/json-api-client usually need
        // a full absolute URL.
        // @see \Symfony\Component\HttpFoundation\Request::getBaseUrl()
        // @see \Drupal\system\Hook\SystemHooks::jsSettingsAlter()
        'baseUrl' => $request->getSchemeAndHttpHost() . $request->getBaseUrl(),
      ],
    ];
  }

  /**
   * Returns the Branding array for V0 of drupalSettings.canvasData.
   *
   * @return array[]
   */
  public function getCanvasDataBrandingV0(): array {
    $site_config = $this->configFactory->get('system.site');
    return [
      self::V0 => [
        'branding' => [
          'homeUrl' => $site_config->get('page')['front'] ?? '',
          'siteName' => $site_config->get('name') ?? '',
          'siteSlogan' => $site_config->get('slogan') ?? '',
        ],
      ],
    ];
  }

  /**
   * Returns the Breadcrumbs for V0 of drupalSettings.canvasData.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface|null $routeMatch
   *   (optional) The route match to build breadcrumbs for. Defaults to the
   *   current route match. Callers targeting an arbitrary entity (rather than
   *   the currently rendered page) construct a route match for that entity's
   *   canonical route and pass it here.
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface|null $cacheability
   *   (optional) When given, the breadcrumb's own cacheable metadata is added
   *   to it. The page render pipeline bubbles this automatically for the
   *   current-route case; a standalone JSON endpoint has no such pipeline and
   *   must bubble it explicitly.
   *
   * @return array[]
   */
  public function getCanvasDataBreadcrumbsV0(?RouteMatchInterface $routeMatch = NULL, ?RefinableCacheableDependencyInterface $cacheability = NULL): array {
    $breadcrumb = $this->breadcrumbManager->build($routeMatch ?? $this->routeMatch);
    $cacheability?->addCacheableDependency($breadcrumb);
    return [
      self::V0 => [
        'breadcrumbs' => \array_map(static function (Link $link) {
          $url = $link->getUrl();
          return [
            'key' => $url->getRouteName() ?? '',
            'text' => $link->getText(),
            'url' => $url->toString() ?? '',
          ];
        }, $breadcrumb->getLinks()),
      ],
    ];
  }

  /**
   * Returns the PageTitle for V0 of drupalSettings.canvasData.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   (optional) When given, the entity's label is used as the page title
   *   instead of resolving the title from the current route/request. Core's
   *   default entity canonical title resolves to the entity label, so this
   *   matches a real page render for entity types without a custom title
   *   callback.
   *
   * @return array[]
   */
  public function getCanvasDataPageTitleV0(?EntityInterface $entity = NULL): array {
    if ($entity !== NULL) {
      return [
        self::V0 => [
          'pageTitle' => (string) $entity->label(),
        ],
      ];
    }
    $request = $this->requestStack->getCurrentRequest();
    \assert($request instanceof Request);
    $route = $this->routeMatch->getRouteObject();
    \assert($route instanceof Route);
    return [
      self::V0 => [
        // @todo improve title in https://www.drupal.org/i/3502371
        'pageTitle' => $this->titleResolver->getTitle($request, $route) ?? '',
      ],
    ];
  }

  /**
   * Returns settings for using JSON:API for V0 of drupalSettings.canvasData.
   *
   * @return array
   */
  public function getCanvasDataJsonApiSettingsV0(): array {
    if (!$this->container->hasParameter('jsonapi.base_path')) {
      // If the `jsonapi.base_path` service parameter is not available, it means
      // the JSON:API module is not installed.
      // In contrast to the other settings, this may hence not change the
      // placeholder values in `canvas/canvasData.v0.jsonapiSettings` at
      // all.
      return [
        self::V0 => [
          'jsonapiSettings' => NULL,
        ],
      ];
    }
    $jsonapi_base_path = $this->container->getParameter('jsonapi.base_path');
    \assert(\is_string($jsonapi_base_path));
    return [
      self::V0 => [
        'jsonapiSettings' => [
          'apiPrefix' => ltrim($jsonapi_base_path, '/'),
        ],
      ],
    ];
  }

  /**
   * Returns theme assets for V0 of drupalSettings.canvasData.
   *
   * @return array[]
   */
  public function getCanvasDataThemeAssetsV0(): array {
    return [
      self::V0 => [
        'themeAssets' => [
          'logo' => [
            'url' => $this->themeSettingsProvider->getSetting('logo.url') ?? '',
          ],
          'favicon' => [
            'url' => $this->themeSettingsProvider->getSetting('favicon.url') ?? '',
            'mimeType' => $this->themeSettingsProvider->getSetting('favicon.mimetype') ?? '',
          ],
        ],
      ],
    ];
  }

  /**
   * Returns main entity data for V0 of drupalSettings.canvasData.
   *
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface|null $cacheability
   *   (optional) When given, the cacheability of the per-translation `view`
   *   access results embedded in the returned data is added to it.
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   (optional) When given, this entity is used as the main entity directly,
   *   instead of scanning the current route's parameters for a likely entity.
   *   Callers that already know the target entity (e.g. the page-data
   *   endpoint, whose route upcasts it) need no route-guessing.
   *
   * @return array
   */
  public function getCanvasDataMainEntityV0(?RefinableCacheableDependencyInterface $cacheability = NULL, ?EntityInterface $entity = NULL): array {
    if ($entity === NULL) {
      // List of likely route parameters to check for the entity.
      $likelyEntityIdentifiers = ['preview_entity', 'node', 'entity', 'canvas_page'];
      $currentRouteParams = $this->routeMatch->getParameters()->keys();

      // Remove any identifiers from $currentRouteParams that are already in
      // $likelyEntityIdentifiers.
      $remainingParams = array_diff($currentRouteParams, $likelyEntityIdentifiers);
      $mergedIdentifiers = array_merge($likelyEntityIdentifiers, $remainingParams);

      foreach ($mergedIdentifiers as $identifier) {
        $candidate = $this->routeMatch->getParameter($identifier);
        if ($candidate instanceof EntityInterface) {
          $entity = $candidate;
          break;
        }
      }
    }

    if ($entity instanceof EntityInterface) {
      // The requested language is negotiated from the request (e.g. the URL
      // prefix). The rendered language is the translation the entity actually
      // loaded as; it falls back to the default when the requested language
      // has no translation. They differ on `/de/page/1` when the page has no
      // German translation: requested `de`, rendered `en`.
      $requested_langcode = $this->languageManager
        ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
        ->getId();
      $rendered_langcode = $entity->language()->getId();
      $translations = [];
      // The translations list powers language switchers, so it is provided
      // for every content entity on a multilingual site, regardless of
      // whether the entity (its type or bundle) is translatable: every
      // enabled language must be listed for the switcher to be complete.
      // Whether a translation actually exists in a language is conveyed
      // per language by `translationAvailable`; for an untranslatable
      // entity every other language simply reports
      // `translationAvailable: false` with a fallback URL. On a monolingual
      // site `translations` stays empty: there is nothing to switch to.
      if ($entity instanceof TranslatableInterface
        && $this->languageManager->isMultilingual()) {
        // Native names (e.g. "Deutsch") come from the predefined language
        // list; `ConfigurableLanguage` only stores the localized name.
        $native_names = LanguageManager::getStandardLanguageList();
        foreach ($this->languageManager->getLanguages() as $language) {
          $langcode = $language->getId();
          // A translation is reported as available only when the entity has
          // a translation the current user may view. Gating on view access
          // folds in the translation's published state: node and Canvas Page
          // access deny viewing an unpublished translation without the
          // relevant permission. So an unpublished or otherwise inaccessible
          // translation is reported like an untranslated language
          // (`translationAvailable: false` with a fallback URL), and is not
          // disclosed.
          // TRICKY: `hook_js_settings_alter()`, where this data is attached,
          // runs during asset rendering and cannot bubble cacheability. The
          // access result cacheability (e.g. the `user.permissions` cache
          // context) is therefore bubbled into the page via the
          // $cacheability parameter by JsComponent::renderComponent(), for
          // every code component depending on this data. The other
          // dependencies need no bubbling here: the per-entity cache tags
          // are already on the response because the main entity is rendered
          // on this page (so creating, updating or deleting a translation
          // invalidates it), and the language config cache tags are added in
          // JsComponent::renderComponent() too.
          // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::renderComponent()
          $translation_available = FALSE;
          if ($entity->hasTranslation($langcode)) {
            $access_result = $entity->getTranslation($langcode)->access('view', NULL, TRUE);
            $cacheability?->addCacheableDependency($access_result);
            $translation_available = $access_result->isAllowed();
          }
          $translations[] = [
            'langcode' => $langcode,
            // Localized name (e.g. "German") and the language's own native
            // name (e.g. "Deutsch"), so a switcher can show either.
            'name' => $language->getName(),
            'nativeName' => $native_names[$langcode][1] ?? $language->getName(),
            // Unavailable translations fall back to the default translation,
            // in that language's URL form (path prefix, domain, etc.) per
            // the site's language negotiation.
            'url' => ($translation_available ? $entity->getTranslation($langcode) : $entity)
              ->toUrl('canonical', ['language' => $language])
              ->toString(),
            'translationAvailable' => $translation_available,
            'current' => $langcode === $requested_langcode,
          ];
        }
      }
      return [
        self::V0 => [
          'mainEntity' => [
            'bundle' => $entity->bundle(),
            'entityTypeId' => $entity->getEntityTypeId(),
            'uuid' => $entity->uuid(),
            'requestedLanguage' => $requested_langcode,
            'renderedLanguage' => $rendered_langcode,
            'translations' => $translations,
          ],
        ],
      ];
    }
    return [];
  }

}
