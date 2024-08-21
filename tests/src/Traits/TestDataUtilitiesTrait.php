<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Traits;

trait TestDataUtilitiesTrait {

  protected static function encodeXBData(array $data): string {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    self::assertIsString($json);
    return $json;
  }

}
