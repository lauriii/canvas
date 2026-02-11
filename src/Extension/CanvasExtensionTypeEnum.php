<?php

declare(strict_types=1);

namespace Drupal\canvas\Extension;

enum CanvasExtensionTypeEnum: string {
  case Canvas = 'canvas';
  case CodeEditor = 'code-editor';
}
