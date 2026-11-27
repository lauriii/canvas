<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\DesignToken\BrandKitTokens;
use Drupal\canvas\Entity\StagedConfigUpdate;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\Plugin\ConfigAction\DesignTokenValue;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ApiStagedConfigUpdateAutoSaveController extends ApiControllerBase {

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly BrandKitTokens $brandKitTokens,
  ) {}

  public function post(Request $request): JsonResponse {
    $decoded = self::decode($request);
    if (!\array_key_exists('data', $decoded)) {
      throw new BadRequestHttpException('Missing data');
    }

    $data = $decoded['data'];
    $token_input = self::designTokenInput($data);
    if ($token_input !== NULL) {
      $data = $this->prepareDesignTokenDraft($data, $token_input);
    }
    $canvas_config_entity = StagedConfigUpdate::createFromClientSide($data);

    // Make sure the user can update the entity before saving it to verify the
    // user would also be able to edit it later. When creating a new entity,
    // there is no context to the data being provided.
    $update_access = $this->entityTypeManager
      ->getAccessControlHandler(StagedConfigUpdate::ENTITY_TYPE_ID)
      ->access($canvas_config_entity, 'update', return_as_object: TRUE);
    if (!$update_access->isAllowed()) {
      throw new AccessDeniedHttpException('Access denied to create entity of type ' . StagedConfigUpdate::ENTITY_TYPE_ID);
    }

    // Validate the entity before saving it.
    $violations = $canvas_config_entity->getTypedData()->validate();
    if ($violations->count()) {
      throw new ConstraintViolationException($violations);
    }

    if ($token_input === NULL) {
      $this->autoSaveManager->saveEntity($canvas_config_entity);
      return new JsonResponse(status: Response::HTTP_CREATED);
    }

    // A style guide draft equal to the published value is no change: delete
    // it instead of saving a no-op. That includes resetting a token that has
    // no override.
    $input = \array_values($canvas_config_entity->getActions())[0]['input'];
    if (BrandKitTokens::sameValue($input['type'], $input['value'], $input['base'])) {
      $this->autoSaveManager->delete($canvas_config_entity);
      return new JsonResponse(['draft' => NULL], Response::HTTP_OK);
    }
    $this->autoSaveManager->saveEntity($canvas_config_entity);
    return new JsonResponse(['draft' => ['value' => $input['value']]], Response::HTTP_OK);
  }

  /**
   * The `canvasDesignTokenValue` input of a style guide draft, if it is one.
   *
   * @param array<string, mixed> $data
   *
   * @return array<string, mixed>|null
   */
  private static function designTokenInput(array $data): ?array {
    $actions = $data['actions'] ?? NULL;
    if (!\is_array($actions) || \count($actions) !== 1) {
      return NULL;
    }
    $action = \reset($actions);
    if (($action['name'] ?? NULL) !== BrandKitTokens::CONFIG_ACTION || !\is_array($action['input'] ?? NULL) || !\is_string($action['input']['path'] ?? NULL)) {
      return NULL;
    }
    return $action['input'];
  }

  /**
   * The server owns a draft's ID, target, label and `base`.
   *
   * `base` is the token's published `:root` value when the draft was created.
   * It is kept on later saves, so a debounced save after a CLI push never
   * adopts the pushed value. Only discarding the draft resets it.
   *
   * @param array<string, mixed> $data
   * @param array<string, mixed> $input
   *
   * @return array<string, mixed>
   */
  private function prepareDesignTokenDraft(array $data, array $input): array {
    $path = $input['path'];
    $id = BrandKitTokens::stagedUpdateId($path);
    unset($input['base']);

    $existing = $this->autoSaveManager->getAutoSaveEntity(StagedConfigUpdate::create(['id' => $id]))->entity;
    if ($existing instanceof StagedConfigUpdate) {
      $input['base'] = $existing->getActions()[0]['input']['base'] ?? NULL;
    }
    else {
      $input['base'] = $this->brandKitTokens->publishedValue($path);
    }
    // Store the normalized value, so `1` and `1.0` compare as equal.
    if ($input['value'] !== NULL && \is_string($input['type'] ?? NULL)) {
      [$normalized, $errors] = BrandKitTokens::validateValue($input['type'], $input['value']);
      if ($errors === []) {
        $input['value'] = $normalized;
      }
    }

    return [
      'id' => $id,
      'label' => $this->brandKitTokens->draftLabel($path),
      'target' => DesignTokenValue::TARGET,
      'actions' => [
        ['name' => BrandKitTokens::CONFIG_ACTION, 'input' => $input],
      ],
    ] + $data;
  }

}
