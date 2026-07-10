<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\PageVariant;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Reads and writes site-wide Drupal Canvas settings.
 *
 * The default page variant lives in `canvas.settings` (simple config), which
 * the generic config entity API cannot write, so it has its own endpoint.
 *
 * @see \Drupal\canvas\Entity\PageVariant::DEFAULT_SETTING
 * @see openapi.yml
 */
final class ApiSettingsController implements ContainerInjectionInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('config.factory'));
  }

  /**
   * Returns the site default page variant id (or null).
   */
  public function getDefaultPageVariant(): CacheableJsonResponse {
    $settings = $this->configFactory->get('canvas.settings');
    $response = new CacheableJsonResponse([
      'default_page_variant' => $settings->get(PageVariant::DEFAULT_SETTING),
    ]);
    $response->addCacheableDependency(CacheableMetadata::createFromObject($settings));
    return $response;
  }

  /**
   * Sets the site default page variant.
   */
  public function setDefaultPageVariant(Request $request): JsonResponse {
    $body = \json_decode((string) $request->getContent(), TRUE);
    if (!\is_array($body) || !\array_key_exists('default_page_variant', $body)) {
      throw new BadRequestHttpException('Missing default_page_variant.');
    }
    $id = $body['default_page_variant'];
    // A null default is allowed (fall back to core block layout). A non-null
    // default must reference an existing variant.
    if ($id !== NULL && (!\is_string($id) || PageVariant::load($id) === NULL)) {
      throw new UnprocessableEntityHttpException(\sprintf('The page variant "%s" does not exist.', (string) $id));
    }
    $this->configFactory->getEditable('canvas.settings')
      ->set(PageVariant::DEFAULT_SETTING, $id)
      ->save();
    return new JsonResponse(['default_page_variant' => $id]);
  }

}
