<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Plugin\Oauth2Grant;

use Drupal\canvas_headless\Grant\PreviewAssertionGrant;
use Drupal\canvas_headless\PreviewAssertionFactory;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\simple_oauth\Plugin\Oauth2GrantBase;
use League\OAuth2\Server\Grant\GrantTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The JWT bearer (preview assertion) grant plugin.
 *
 * Registers the RFC 7523 assertion grant with Simple OAuth's plugin system;
 * the wire-level grant_type is the registered URI
 * urn:ietf:params:oauth:grant-type:jwt-bearer (the league grant's
 * identifier), while this plugin id is what consumer entities reference in
 * their grant_types field.
 *
 * @Oauth2Grant(
 *   id = "canvas_headless_preview_assertion",
 *   label = @Translation("JWT bearer (preview assertion)")
 * )
 */
final class PreviewAssertion extends Oauth2GrantBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected KeyValueExpirableFactoryInterface $keyValueExpirable,
    protected LockBackendInterface $lock,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected FileSystemInterface $fileSystem,
    protected LanguageManagerInterface $languageManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(KeyValueExpirableFactoryInterface::class),
      $container->get('lock'),
      $container->get(EntityTypeManagerInterface::class),
      $container->get(ConfigFactoryInterface::class),
      $container->get(FileSystemInterface::class),
      $container->get(LanguageManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getGrantType(ConsumerInterface $client): GrantTypeInterface {
    $public_key_path = PreviewAssertionFactory::resolveKeyPath(
      $this->fileSystem,
      (string) $this->configFactory->get('simple_oauth.settings')->get('public_key'),
    );
    $audience = PreviewAssertionFactory::tokenEndpointAudience($this->languageManager);
    $issuer = (string) $this->configFactory->get('system.site')->get('uuid');
    \assert($public_key_path !== '' && $audience !== '' && $issuer !== '');

    /** @var \Drupal\user\UserStorageInterface $user_storage */
    $user_storage = $this->entityTypeManager->getStorage('user');

    return new PreviewAssertionGrant(
      $this->keyValueExpirable->get('canvas_headless_used_assertions'),
      $this->keyValueExpirable->get('canvas_headless_session_challenges'),
      $this->lock,
      $user_storage,
      $public_key_path,
      $audience,
      $issuer,
    );
  }

}
