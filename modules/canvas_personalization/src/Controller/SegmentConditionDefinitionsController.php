<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\Controller;

use Drupal\canvas_personalization\SegmentCondition\EnumeratesAudiencesInterface;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionManager;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Error;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the available segment condition types for the authoring UI.
 *
 * Without this, the client has no way to learn that a condition exists: the
 * segments dashboard would have to hard-code the shipped plugin IDs, and a
 * condition provided by a third-party segmentation provider module would be
 * invisible in the product UI even though the server evaluates it correctly.
 *
 * Only non-secret plugin metadata is exposed, and only to users who may
 * administer segments.
 */
final class SegmentConditionDefinitionsController implements ContainerInjectionInterface {

  /**
   * Config schema types this can turn into a form control, and what to render.
   *
   * Anything else — a mapping, a sequence, a type with its own structure —
   * means the condition is not describable as a flat set of controls, and the
   * dashboard is told so rather than being shown a form that silently drops
   * half the settings.
   */
  private const array WIDGETS = [
    'string' => 'text',
    'label' => 'text',
    'text' => 'text',
    'integer' => 'number',
    'boolean' => 'checkbox',
  ];

  /**
   * Settings every condition carries, which the dashboard chrome already owns.
   */
  private const array CHROME_SETTINGS = ['id', 'negate'];

  public function __construct(
    private readonly SegmentConditionManager $segmentConditionManager,
    private readonly LoggerChannelInterface $logger,
    private readonly TypedConfigManagerInterface $typedConfigManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(SegmentConditionManager::class),
      $container->get('logger.channel.canvas_personalization'),
      $container->get(TypedConfigManagerInterface::class),
    );
  }

  /**
   * How long a listing that carries provider audiences stays fresh.
   *
   * Audiences live in someone else's product and can change at any time, so a
   * listing that includes them cannot be cached on module state alone.
   */
  private const int AUDIENCES_MAX_AGE = 300;

  public function __invoke(): CacheableJsonResponse {
    $definitions = [];
    $has_audiences = FALSE;
    foreach ($this->segmentConditionManager->getDefinitions() as $id => $definition) {
      if (!\is_array($definition)) {
        continue;
      }
      $definitions[$id] = [
        'id' => $id,
        'label' => (string) ($definition['label'] ?? $id),
        // Whether the dashboard ships an editor for this condition. Anything
        // else is authored through the per-condition plugin form instead.
        'provider' => (string) ($definition['provider'] ?? ''),
      ];
      $audiences = $this->audiences($id);
      if ($audiences !== NULL) {
        $has_audiences = TRUE;
      }
      // What the dashboard needs to render an editor without shipping code for
      // this condition: the settings it has, and what each one accepts.
      $settings = $this->settingsSchema($id, $audiences);
      if ($settings !== NULL) {
        $definitions[$id]['settings'] = $settings;
      }
    }
    \ksort($definitions);

    $response = new CacheableJsonResponse($definitions);
    $cacheability = (new CacheableMetadata())->setCacheTags(['segment_condition_plugins']);
    if ($has_audiences) {
      $cacheability->setCacheMaxAge(self::AUDIENCES_MAX_AGE);
    }
    // Otherwise this varies only by which modules are installed, which the
    // plugin definition cache tracks; no per-request variation.
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * A condition's settings, described well enough for a client to render them.
   *
   * Read from the config schema every condition already has to ship — §6 makes
   * `canvas_personalization.segment_condition.<plugin_id>` mandatory — so a
   * provider contributes an authoring UI by declaring its settings, not by
   * shipping client code. Returns NULL when the settings cannot be described
   * as a flat set of controls, which tells the dashboard to keep sending the
   * author to the condition's own form rather than render a partial one.
   *
   * @param array<string, string>|null $audiences
   *   Provider audiences, when the condition enumerates them. They become the
   *   options of its first string setting — the identifier naming the audience
   *   is the one thing config schema cannot enumerate, because the values live
   *   in the provider.
   */
  private function settingsSchema(string $plugin_id, ?array $audiences): ?array {
    try {
      $definition = $this->typedConfigManager->getDefinition('canvas_personalization.segment_condition.' . $plugin_id, FALSE);
    }
    catch (\Throwable) {
      return NULL;
    }
    $mapping = $definition['mapping'] ?? NULL;
    if (!\is_array($mapping) || ($definition['type'] ?? '') === 'undefined') {
      return NULL;
    }

    $settings = [];
    foreach ($mapping as $name => $property) {
      if (\in_array($name, self::CHROME_SETTINGS, TRUE)) {
        continue;
      }
      $type = (string) ($property['type'] ?? '');
      if (!isset(self::WIDGETS[$type])) {
        // A setting this cannot describe means the whole condition cannot be
        // rendered honestly; do not offer a form that would drop it.
        return NULL;
      }
      $constraints = $property['constraints'] ?? [];
      $setting = [
        'name' => $name,
        'widget' => self::WIDGETS[$type],
        'label' => (string) ($property['label'] ?? $name),
        'required' => \array_key_exists('NotBlank', \is_array($constraints) ? $constraints : []),
      ];
      if ($audiences !== NULL && $setting['widget'] === 'text') {
        $setting['widget'] = 'select';
        $setting['options'] = $audiences;
        // Enumerated options are the whole point: an identifier the provider
        // does not know is indistinguishable at runtime from an audience
        // nobody is in, so it must not also be typeable.
        $audiences = NULL;
      }
      $settings[] = $setting;
    }
    return $settings === [] ? NULL : $settings;
  }

  /**
   * The audiences a condition offers, or NULL if it does not offer a list.
   *
   * A provider that cannot be reached returns nothing to choose from rather
   * than failing the whole listing: the authoring UI must still open, with the
   * audience typed by hand, when the provider is down.
   */
  private function audiences(string $plugin_id): ?array {
    try {
      $condition = $this->segmentConditionManager->createInstance($plugin_id);
    }
    catch (\Throwable) {
      return NULL;
    }
    if (!$condition instanceof EnumeratesAudiencesInterface) {
      return NULL;
    }
    try {
      $audiences = $condition->listAudiences();
    }
    catch (\Throwable $exception) {
      // ::listAudiences() is documented as not throwing, but a provider SDK
      // reaching the network is not something to take on faith in a controller
      // that has to keep answering.
      Error::logException($this->logger, $exception, 'Listing audiences for the %plugin_id segment condition failed.', ['%plugin_id' => $plugin_id]);
      return [];
    }
    return \array_map(strval(...), $audiences);
  }

}
