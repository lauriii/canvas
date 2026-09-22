<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\multi_frontend\Form\FormDescriber;
use Drupal\multi_frontend\Form\FormRegistry;
use Drupal\multi_frontend\Form\FormSubmitter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Describes and submits forms.
 */
final class FormApiController extends ControllerBase {

  public function __construct(
    private readonly FormRegistry $registry,
    private readonly FormDescriber $describer,
    private readonly FormSubmitter $submitter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(FormRegistry::class),
      $container->get(FormDescriber::class),
      $container->get(FormSubmitter::class),
    );
  }

  /**
   * Lists the forms this site exposes.
   */
  public function catalog(): CacheableJsonResponse {
    $forms = [];
    foreach ($this->registry->all() as $id => $definition) {
      if (!$this->permitted($definition)) {
        continue;
      }
      $forms[] = ['form' => $id, 'label' => $definition['label']];
    }
    $response = new CacheableJsonResponse(['forms' => $forms]);
    $response->addCacheableDependency(
      (new CacheableMetadata())->addCacheContexts([
        'user.permissions',
        'url.site',
        // Labels in the catalog are translated too.
        'languages:language_interface',
      ]),
    );
    return $response;
  }

  /**
   * Describes a form so a client can render it natively.
   */
  public function describe(string $form): CacheableJsonResponse {
    $definition = $this->registry->get($form);
    if ($definition === NULL || !$this->permitted($definition)) {
      throw new NotFoundHttpException();
    }

    $cacheability = (new CacheableMetadata())
      // A form can build different elements for different permissions, the
      // catalog is per-site, and every label, description and option label in
      // the response has been translated into the interface language.
      ->addCacheContexts(['user.permissions', 'url.site', 'languages:language_interface']);
    try {
      $description = $this->describer->describe($definition['class'], $cacheability);
    }
    catch (\InvalidArgumentException $e) {
      // Narrow deliberately. This is the one failure that is the definition's
      // fault rather than the server's: FormBuilder throws it for a form it
      // cannot resolve, most often an entity form, which needs an entity to
      // build against and is not reachable through FormBuilder at all.
      // Catching every Throwable here would turn database and service faults
      // into 422s carrying raw exception text.
      // @see \Drupal\Core\Form\FormBuilder::getFormId()
      throw new UnprocessableEntityHttpException(\sprintf(
        'The form "%s" cannot be described: %s',
        $form,
        $e->getMessage(),
      ), $e);
    }

    $response = new CacheableJsonResponse($description);
    // The form's own cacheability rides along, so a description whose options
    // come from configuration is invalidated when that configuration changes
    // rather than served stale until a cache rebuild.
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * Submits a form.
   */
  public function submit(string $form, Request $request): JsonResponse {
    $definition = $this->registry->get($form);
    if ($definition === NULL || !$this->permitted($definition)) {
      throw new NotFoundHttpException();
    }

    $payload = \json_decode((string) $request->getContent(), TRUE);
    if (!\is_array($payload) || !\is_array($payload['values'] ?? NULL)) {
      throw new BadRequestHttpException('Expected a JSON object with a "values" object.');
    }

    $result = $this->submitter->submit($definition['class'], $payload['values']);
    return new JsonResponse($result, $result['status'] === 'ok' ? 200 : 422);
  }

  /**
   * Whether the current account may use this form.
   *
   * A form that declares no permission is public, which is what a contact or
   * signup form wants. Anything else says so, and an account without it is
   * told the form does not exist rather than that it exists and is barred.
   */
  private function permitted(array $definition): bool {
    return $definition['permission'] === NULL
      || $this->currentUser()->hasPermission($definition['permission']);
  }

}
