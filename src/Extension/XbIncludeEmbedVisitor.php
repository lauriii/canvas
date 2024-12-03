<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Extension;

use Twig\Environment;
use Twig\Node\EmbedNode;
use Twig\Node\Expression\NameExpression;
use Twig\Node\IncludeNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\Node\PrintNode;
use Twig\Node\TextNode;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Defines a Twig node visitor for reacting to include/embed calls.
 */
final class XbIncludeEmbedVisitor implements NodeVisitorInterface {

  /**
   * {@inheritdoc}
   */
  public function enterNode(Node $node, Environment $env): Node {
    return $node;
  }

  /**
   * {@inheritdoc}
   */
  public function leaveNode(Node $node, Environment $env): ?Node {
    if (!$node instanceof IncludeNode || !$node instanceof EmbedNode) {
      return $node;
    }
    $line_number = $node->getTemplateLine();
    $nodes = [
      new TextNode('<!-- xb-start-', $line_number),
      new PrintNode(new NameExpression('xb_uuid', $line_number), $line_number),
      new TextNode(' -->', $line_number),
      $node,
      new TextNode('<!-- xb-end-', $line_number),
      new PrintNode(new NameExpression('xb_uuid', $line_number), $line_number),
      new TextNode(' -->', $line_number),
    ];
    if (\class_exists('\Twig\Node\Nodes')) {
      // Twig >= 3.15.
      // @todo Remove this wrapping if when Experience Builder requires
      //   Drupal >=11.1: then it is always the case.
      return new Nodes($nodes);
    }
    // Twig < 3.15.
    return new Node($nodes);
  }

  /**
   * {@inheritdoc}
   */
  public function getPriority(): int {
    // Runs after core's node visitor that adds the renderer wrapper.
    // @see \Drupal\Core\Template\TwigNodeVisitor
    return 300;
  }

}
