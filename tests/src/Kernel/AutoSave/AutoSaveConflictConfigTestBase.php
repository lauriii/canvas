<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\AutoSave;

use Drupal\Core\Url;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\XbHttpApiEligibleConfigEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\Request;

abstract class AutoSaveConflictConfigTestBase extends KernelTestBase {

  use AutoSaveConflictTestTrait;
  use RequestTrait;
  use UserCreationTrait;

  protected string $updateKey;

  protected static function getPermissions(): array {
    return [
      'access administration pages',
      JavaScriptComponent::ADMIN_PERMISSION,
      'publish auto-saves',
    ];
  }

  protected function getUpdateAutoSaveRequest(array $json): Request {
    $json += ['clientInstanceId' => $this->randomString(100)];
    $request = Request::create($this->getAutoSaveUrl(), method: 'PATCH', content: json_encode($json, JSON_THROW_ON_ERROR));
    $request->headers->set('Content-Type', 'application/json');
    return $request;
  }

  protected function getAutoSaveUrl(): string {
    $entity_type_id = $this->entity->getEntityTypeId();
    $entity_id = $this->entity->id();
    return Url::fromUri("base:/xb/api/v0/config/auto-save/$entity_type_id/$entity_id")->toString();
  }

  protected function modifyJsonToSendAsAutoSave(array &$json, string $text): void {
    $json['data'][$this->updateKey] = $text;
  }

  protected function assertCurrentAutoSaveText(string $text): void {
    $entity = $this->getAutoSaveManager()->getAutoSaveEntity($this->entity)->entity;
    self::assertInstanceOf(XbHttpApiEligibleConfigEntityInterface::class, $entity);
    self::assertSame($text, $entity->get($this->updateKey));
  }

}
