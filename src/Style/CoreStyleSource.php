<?php

declare(strict_types=1);

namespace Drupal\canvas\Style;

use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\Core\Theme\Style\StyleDefinitionInterface;
use Drupal\Core\Theme\Style\StylePluginManagerInterface;

/**
 * Style definitions from core's Style API.
 *
 * The only Canvas class that uses core's Style API types. Used whenever core
 * provides `plugin.manager.style`.
 *
 * @see https://www.drupal.org/project/drupal/issues/3517033
 * @see \Drupal\canvas\CanvasServiceProvider::alter()
 *
 * @internal
 */
final class CoreStyleSource implements StyleSourceInterface {

  public function __construct(
    private readonly StylePluginManagerInterface $styleManager,
  ) {}

  public function isCore(): bool {
    return TRUE;
  }

  public function definitions(): array {
    return \array_map(self::normalize(...), $this->styleManager->getDefinitions());
  }

  public function clearCachedDefinitions(): void {
    if ($this->styleManager instanceof CachedDiscoveryInterface) {
      $this->styleManager->clearCachedDefinitions();
    }
  }

  public function cacheTag(): string {
    return 'style_plugin';
  }

  /**
   * @return \Drupal\canvas\Style\StyleSourceInterface::SourceDefinition
   *
   * @phpstan-ignore-next-line return.type
   */
  private static function normalize(StyleDefinitionInterface $definition): array {
    $value = static fn (mixed $v): string => $v instanceof \BackedEnum ? (string) $v->value : (string) $v;
    $raw = $definition->getOptions();
    $options = [];
    foreach ($definition->getProcessedOptions() as $option_id => $option) {
      $options[(string) $option_id] = [
        // Core appends the status to processed labels; Canvas shows it
        // separately, so it reads the raw label.
        'label' => (string) ($raw[$option_id]['label'] ?? $option_id),
        'description' => (string) ($raw[$option_id]['description'] ?? ''),
        'value' => (string) $option['value'],
        'attribute' => (string) $option['attribute'],
        'target' => $value($option['target']),
        'status' => $value($option['status']),
        'icon' => isset($raw[$option_id]['icon']) ? (string) $raw[$option_id]['icon'] : NULL,
      ];
    }
    return [
      'id' => (string) $definition->id(),
      'provider' => $definition->getProvider(),
      'canvas_library' => isset($definition->getDefinition()['canvas_library']) ? (string) $definition->getDefinition()['canvas_library'] : NULL,
      'enabled' => $definition->isEnabled(),
      'label' => (string) $definition->getLabel(),
      'description' => (string) $definition->getDescription(),
      'category' => (string) $definition->getCategory(),
      'weight' => (int) $definition->getWeight(),
      'status' => $value($definition->getStatus()),
      'library' => $definition->getLibrary(),
      'options' => $options,
      // Canvas extensions (style libraries); themes use a sidecar file.
      'tokens' => $definition->getDefinition()['tokens'] ?? NULL,
      'custom' => $definition->getDefinition()['custom'] ?? NULL,
    ];
  }

}
