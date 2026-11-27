<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\ConfigAction;

use Drupal\canvas\DesignToken\BrandKitTokens;
use Drupal\canvas\Entity\BrandKit;
use Drupal\Core\Config\Action\Attribute\ConfigAction;
use Drupal\Core\Config\Action\ConfigActionException;
use Drupal\Core\Config\Action\ConfigActionPluginInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sets or resets the `:root` value of a theme design token.
 *
 * Input: `{path, type, value, base?}`. A `null` value resets the token.
 *
 * Style guide drafts are staged config updates with this action, targeting
 * the global brand kit: publishing them runs it. Their constraint runs first,
 * in publish's validation pass.
 *
 * Recipes may call it without `base`: then the `base` check, the feature
 * check and the listed-path check are skipped, because recipes often run
 * before their theme is the default.
 *
 * @see \Drupal\canvas\Plugin\Validation\Constraint\DesignTokenStagedValueConstraintValidator
 */
#[ConfigAction(
  id: BrandKitTokens::CONFIG_ACTION,
  admin_label: new TranslatableMarkup('Set a theme design value'),
)]
final class DesignTokenValue implements ConfigActionPluginInterface, ContainerFactoryPluginInterface {

  public const string TARGET = 'canvas.' . BrandKit::ENTITY_TYPE_ID . '.' . BrandKit::GLOBAL_ID;

  public function __construct(
    private readonly BrandKitTokens $tokens,
  ) {}

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($container->get(BrandKitTokens::class));
  }

  public function apply(string $configName, mixed $value): void {
    // Pinned to the brand kit, so only users who may update the brand kit
    // can stage these.
    if ($configName !== self::TARGET) {
      throw new ConfigActionException(\sprintf('The %s action can only target %s.', BrandKitTokens::CONFIG_ACTION, self::TARGET));
    }
    if (!\is_array($value) || !\is_string($value['path'] ?? NULL) || !\is_string($value['type'] ?? NULL) || !\array_key_exists('value', $value)) {
      throw new ConfigActionException('Expected {path, type, value}.');
    }
    ['path' => $path, 'type' => $type] = $value;
    if (!$this->tokens->isAvailable() || !\in_array($type, BrandKitTokens::TYPES, TRUE) || !BrandKitTokens::isStorablePath($path)) {
      throw new ConfigActionException(\sprintf('The design token "%s" cannot be set.', $path));
    }
    // Defensive backstop: the staged update constraint makes this
    // unreachable for drafts.
    if (\array_key_exists('base', $value) && !BrandKitTokens::sameValue($type, $this->tokens->publishedValue($path), $value['base'])) {
      throw new ConfigActionException(\sprintf('The design token "%s" changed since this draft was created.', $path));
    }
    if ($value['value'] === NULL) {
      $this->tokens->resetRootValue($path);
      return;
    }
    try {
      $this->tokens->setRootValue($path, $value['value'], $type);
    }
    catch (\InvalidArgumentException $e) {
      throw new ConfigActionException($e->getMessage(), previous: $e);
    }
  }

}
