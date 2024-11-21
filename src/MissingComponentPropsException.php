<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

class MissingComponentPropsException extends \OutOfRangeException {

  public function __construct(
    public readonly string $componentInstanceUuid,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct(
      message: sprintf('No props sources stored for %s. Caused by either incorrect logic or `props` being out of sync with `tree`.', $this->componentInstanceUuid),
      previous: $previous
    );
  }

}
