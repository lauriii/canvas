<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

class MissingHostEntityException extends \Exception {

  public function __construct(string $message = "Missing host entity.", int $code = 0, ?\Throwable $previous = NULL) {
    parent::__construct($message, $code, $previous);
  }

}
