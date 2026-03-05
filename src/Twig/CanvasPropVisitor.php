<?php

declare(strict_types=1);

namespace Drupal\canvas\Twig;

use Drupal\Core\Template\TwigNodeTrans;
use Masterminds\HTML5;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\Node\PrintNode;
use Twig\Node\TextNode;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Defines a Twig node visitor for reacting to print node calls.
 */
final class CanvasPropVisitor implements NodeVisitorInterface {

  /**
   * Keeps track of text buffer for a given node.
   *
   * @var string[]
   */
  protected array $buffer;

  /**
   * Recursion prevention.
   *
   * @var bool
   */
  protected bool $replacingFilter = FALSE;

  /**
   * TwigNodeTrans can't handle compound nodes.
   *
   * It calls $node->getAttribute('data') without checking that the node is an
   * instance of \Twig\Node\TextNode. We can't add property boundaries inside
   * {% trans %} tags.
   *
   * @var bool
   *
   * @see https://www.drupal.org/project/drupal/issues/3486273
   */
  protected bool $inTranslationTag = FALSE;

  /**
   * {@inheritdoc}
   */
  public function enterNode(Node $node, Environment $env): Node {
    // We've entered a new Twig template (ModuleNode). We start a buffer
    // entry for the given template so we can keep track of the printed HTML.
    // We want to wrap twig print statements (i.e. {{ variable }}) in an HTML
    // comment, but we can only do this in contexts where HTML comments are
    // allowed. For example, we can wrap 'variable' here because HTML comments
    // are allowed in an element's inner HTML.
    // @code
    // <div>{{ variable }}</div>
    // @endcode
    // But we cannot wrap 'variable' here because HTML comments are not allowed
    // in attribute values.
    // @code
    // <div class="{{ variable }}"></div>
    // @endcode
    if ($node instanceof ModuleNode && $node->getSourceContext() !== NULL) {
      // Initialize the buffer for this template.
      $this->buffer[$node->getSourceContext()->getName()] = '';
      return $node;
    }
    // We're visiting a text node, Twig uses this for any text or markup inside
    // a template. For example consider this template.
    // @code
    // <div class="{{ className }}">Hi {{ name }}</div>
    // {% if new %}<span class="marker">New</span>{% endif %}
    // @code
    // When parsed, the Twig token stream would contain the following TextNodes
    // - <div class="
    // - ">Hi
    // - </div>
    // - <span class="marker">New</span>
    // The other control structures would be represented by other Twig node
    // types.
    if ($node instanceof TextNode && $node->getSourceContext() !== NULL && $node->hasAttribute('data')) {
      // Append the text node's contents to the buffer for this template.
      $this->buffer[$node->getSourceContext()->getName()] .= $node->getAttribute('data');
      return $node;
    }
    if ($node instanceof TwigNodeTrans) {
      // Keep track that we've visited a translation tag and toggle off our
      // replacements for all child traversals.
      $this->inTranslationTag = TRUE;
      return $node;
    }
    // We've reached a PrintNode, Twig uses this for outputting a variable, e.g.
    // @code
    // {{ variable }}
    // @endcode
    if ($node instanceof PrintNode &&
      // We're not inside a {% trans %} wrapper.
      !$this->inTranslationTag &&
      // We're not revisiting a node we just replaced - Twig calls node visitors
      // recursively, including on any elements we return here as replacements.
      !$this->replacingFilter &&
      // We have access to the HTML buffer for the parent template.
      $node->getSourceContext()) {
      $expr = $node->getNode('expr');
      // We are printing the outcome of another expression rather than a
      // variable.
      if (!$expr instanceof NameExpression || !$expr->hasAttribute('name')) {
        return $node;
      }

      $buffer = $this->buffer[$node->getSourceContext()->getName()];

      // Check if we're in an attribute value of a Canvas custom element.
      // This will determine if the element is to ultimately be rendered by JSX.
      if (self::isInCustomElementAttribute($buffer)) {
        // Check if there's a 'children' attribute within the custom element.
        if (self::hasChildrenAttribute($buffer)) {
          throw new SyntaxError(
            'The attribute name "children" is reserved for React components and cannot be used in Canvas custom elements. Please use a different attribute name.',
            $node->getTemplateLine(),
            $node->getSourceContext()
          );
        }

        $line_number = $node->getTemplateLine();

        // Wrap the expression with the jsx_attributes filter, which will
        // convert the Attribute object and arrays / object to a JSON-encoded
        // string. This allows the value to be received as a prop by the
        // corresponding React component.
        $filtered = new FilterExpression(
          $expr,
          new ConstantExpression('jsx_attributes', $line_number),
          new Node(),
          $line_number
        );

        $filtered->setAttribute('twig_callable', $env->getFilter('jsx_attributes'));
        // Prevent recursive replacement when Twig traverses into the replaced
        // node.
        $this->replacingFilter = TRUE;
        return new PrintNode($filtered, $line_number);
      }

      // Try to parse the current buffer to ascertain if we're in a context
      // where HTML comments are allowed.
      $html5 = new HTML5(['disable_html_ns' => TRUE, 'encoding' => 'UTF-8']);
      $html5->loadHTMLFragment($buffer);
      if (!$html5->hasErrors()) {
        // We have valid HTML5 in the buffer.
        $name = $expr->getAttribute('name');
        $line_number = $node->getTemplateLine();
        // Prevent recursive replacement when Twig traverses into the replaced
        // node.
        $this->replacingFilter = TRUE;
        // Build our replacement.
        $nodes = [
          new CanvasWrapperNode($name, TRUE, $line_number),
          $node,
          new CanvasWrapperNode($name, FALSE, $line_number),
        ];
        return new Nodes($nodes);
      }
    }
    return $node;
  }

  /**
   * {@inheritdoc}
   */
  public function leaveNode(Node $node, Environment $env): Node {
    if ($node instanceof ModuleNode && $node->getSourceContext() !== NULL) {
      // We have left a template, we no longer need the buffer for it, so flush
      // it.
      unset($this->buffer[$node->getSourceContext()->getName()]);
      return $node;
    }
    if ($node instanceof PrintNode) {
      // We have finished our replacement, so can safely turn off the flag that
      // prevents recursion.
      $this->replacingFilter = FALSE;
      return $node;
    }
    if ($node instanceof TwigNodeTrans) {
      // We are leaving the {% trans %} tag.
      $this->inTranslationTag = FALSE;
      return $node;
    }

    return $node;
  }

  /**
   * {@inheritdoc}
   */
  public function getPriority(): int {
    // Runs before the EscapeNodeVisitor, which has priority 0.
    return -1;
  }

  /**
   * Determines if we're in an attribute value of a Canvas custom element.
   *
   * @param string $buffer
   *   The current HTML buffer.
   *
   * @return bool
   *   TRUE if in an attribute value of a custom element tag, FALSE otherwise.
   */
  protected static function isInCustomElementAttribute(string $buffer): bool {
    // Find last unclosed tag.
    $last_gt = strrpos($buffer, '>');
    $last_lt = strrpos($buffer, '<');

    // Not in a tag if < doesn't come after >.
    if ($last_lt === FALSE || ($last_gt !== FALSE && $last_lt < $last_gt)) {
      return FALSE;
    }

    // Extract tag content after the last <.
    $tag_content = substr($buffer, $last_lt + 1);

    // Check if it's a custom element opening tag (drupal-canvas* or canvas-*).
    if (!preg_match('/^(drupal-canvas-|canvas-)[a-z0-9-]+/i', $tag_content)) {
      return FALSE;
    }

    // Check if we're in an attribute value by counting quotes.
    // An odd number of quotes means we're inside a quoted value.
    // If there are any backslashes, account for escaped quotes.
    if (str_contains($tag_content, "\\")) {
      $double_quotes = $single_quotes = 0;
      $i = 0;
      $len = strlen($tag_content);

      while ($i < $len) {
        $char = $tag_content[$i];

        if ($char === "\\") {
          // Backslash escapes the next character, so skip both.
          $i += 2;
        }
        elseif ($char === '"') {
          $double_quotes++;
          $i++;
        }
        elseif ($char === "'") {
          $single_quotes++;
          $i++;
        }
        else {
          $i++;
        }
      }
    }
    else {
      // If no backslashes, we can simply count quotes.
      $double_quotes = substr_count($tag_content, '"');
      $single_quotes = substr_count($tag_content, "'");
    }

    return ($double_quotes % 2 === 1) || ($single_quotes % 2 === 1);
  }

  /**
   * Extracts the current attribute name from the buffer.
   *
   * @param string $buffer
   *   The current HTML buffer.
   *
   * @return string|null
   *   The attribute name, or NULL if not found.
   */
  protected static function extractAttributeName(string $buffer): ?string {
    // Find last unclosed tag.
    $last_lt = strrpos($buffer, '<');
    if ($last_lt === FALSE) {
      return NULL;
    }

    // Extract tag content after the last <.
    $tag_content = substr($buffer, $last_lt + 1);

    // Find the last attribute name before the current position.
    // Look for pattern: attribute-name="
    if (preg_match('/([a-z0-9_-]+)\s*=\s*["\'](?:[^"\']*)?$/i', $tag_content, $matches)) {
      return $matches[1];
    }

    return NULL;
  }

  /**
   * Checks if there's a 'children' attribute within a custom element tag.
   *
   * This is important because 'children' is a reserved prop name in React.
   *
   * @param string $buffer
   *   The current HTML buffer.
   *
   * @return bool
   *   TRUE if a 'children' attribute is found in the current tag.
   */
  protected static function hasChildrenAttribute(string $buffer): bool {
    // Find last unclosed tag.
    $last_lt = strrpos($buffer, '<');

    // If there's no unclosed tag, or if the buffer doesn't contain 'children'
    // at all, return early.
    if ($last_lt === FALSE || !str_contains($buffer, 'children')) {
      return FALSE;
    }

    // Extract tag content after the last <.
    $tag_content = substr($buffer, $last_lt + 1);

    // Check for 'children' attribute only as a complete attribute name.
    return preg_match('/(?:^|\s)children\s*=\s*["\']/', $tag_content) === 1;
  }

}
