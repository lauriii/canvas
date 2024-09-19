<?php

declare(strict_types=1);

// We assume the "Standard" profile is installed at this point, along with the
// Experience Builder modules.

use Drupal\node\Entity\Node;

$node = Node::create([
  'type' => 'article',
  'title' => 'XB Needs This For The Time Being',
]);
$node->save();
