<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\StructuredExecutableFunctionCallInterface;

/**
 * Defines an interface for AI Function Call plugins that provide response data.
 *
 * Plugins that implement this interface should call ::setStructuredOutput()
 * upon success to set that data that will be returned by the Canvas AI Builder.
 *
 * @internal
 *
 * @see \Drupal\canvas_ai\Controller\CanvasBuilder::render
 * @see \Drupal\ai\Service\FunctionCalling\StructuredExecutableFunctionCallInterface::setStructuredOutput
 */
interface BuilderResponseFunctionCallInterface extends StructuredExecutableFunctionCallInterface {

}
