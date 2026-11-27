<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

/**
 * Access control for style libraries.
 *
 * Content creators may view libraries (the editor and the CLI read them);
 * managing them requires "administer style libraries".
 */
final class StyleLibraryAccessControlHandler extends ContentCreatorVisibleCanvasConfigEntityAccessControlHandler {

}
