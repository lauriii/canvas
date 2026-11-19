<?php

declare(strict_types=1);

namespace Drupal\canvas_oauth\Plugin\ConfigAction;

use Drupal\canvas_oauth\MediaScopesHelper;
use Drupal\Core\Config\Action\Attribute\ConfigAction;
use Drupal\Core\Config\Action\ConfigActionPluginInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Config action to create OAuth scopes for certain media types.
 */
#[ConfigAction(
  id: 'createOAuthScope',
  admin_label: new TranslatableMarkup('Create OAuth2 scope for media type'),
  entity_types: ['media_type'],
)]
final readonly class CreateOAuthScope implements ConfigActionPluginInterface, ContainerFactoryPluginInterface {

  public function __construct(
    private MediaScopesHelper $scopesHelper,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function apply(string $configName, mixed $value): void {
    \assert(\str_starts_with($configName, 'media.type.'));
    [,, $media_type] = \explode('.', $configName, 3);
    $this->scopesHelper->ensureMediaScopes([$media_type]);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      MediaScopesHelper::create($container),
    );
  }

}
