<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\DesignToken\BrandKitTokens;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns the theme style guide (experimental design tokens) on and off.
 *
 * Turning it on or off applies at once, deletes nothing and changes no
 * stored value. Both need `{"confirm": true}`, and both are logged.
 */
final class ApiDesignTokensController extends ApiControllerBase {

  public function __construct(
    private readonly BrandKitTokens $brandKitTokens,
    private readonly AccountInterface $currentUser,
    #[Autowire(service: 'logger.channel.canvas')]
    private readonly LoggerInterface $logger,
  ) {}

  public function enable(Request $request): JsonResponse {
    return $this->set($request, TRUE);
  }

  public function disable(Request $request): JsonResponse {
    return $this->set($request, FALSE);
  }

  private function set(Request $request, bool $enabled): JsonResponse {
    if ((self::decode($request)['confirm'] ?? NULL) !== TRUE) {
      return self::createJsonApiStyleErrorResponse('Confirm the change with {"confirm": true}.', 'confirm', Response::HTTP_UNPROCESSABLE_ENTITY);
    }
    if ($enabled && !$this->brandKitTokens->isAvailable()) {
      return self::createJsonApiStyleErrorResponse('This version of Drupal core has no compatible design tokens API.', 'confirm', Response::HTTP_CONFLICT);
    }
    $this->brandKitTokens->setEnabled($enabled);
    $this->logger->notice('@user turned the theme style guide @state.', [
      '@user' => $this->currentUser->getAccountName(),
      '@state' => $enabled ? 'on' : 'off',
    ]);
    return new JsonResponse([
      'available' => $this->brandKitTokens->isAvailable(),
      'enabled' => $this->brandKitTokens->isEnabled(),
    ]);
  }

}
