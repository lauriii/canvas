<?php

declare(strict_types=1);

namespace Drupal\canvas\Push;

enum PushStatus {
  case Started;
  case Completed;
  case Failed;
}
