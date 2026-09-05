<?php

declare(strict_types=1);

namespace Drupal\Tests\multi_frontend\Kernel;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\Component\Exception\InvalidComponentException;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\multi_frontend\ComponentProducerManager;
use Drupal\multi_frontend\Element\ProducedComponent;
use Drupal\multi_frontend\Envelope\ComponentNode;
use Drupal\multi_frontend\Envelope\EnvelopeBuilder;
use Drupal\multi_frontend\Envelope\HtmlNode;
use Drupal\multi_frontend\EventSubscriber\EnvelopeExceptionSubscriber;
use Drupal\multi_frontend\ProducerInvoker;
use Drupal\multi_frontend\Schema\SchemaPublisher;
use Drupal\multi_frontend_test\AccessCallbacks;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests the component producer contract.
 */
#[Group('multi_frontend')]
#[RunTestsInSeparateProcesses]
final class ComponentProducerTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'multi_frontend',
    'multi_frontend_test',
  ];

  /**
   * The node the producers run against.
   */
  private Node $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'filter', 'node']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'body',
      'type' => 'text_with_summary',
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'bundle' => 'page',
      'field_name' => 'body',
      'label' => 'Body',
    ])->save();

    // An authenticated user, because permissions cannot be granted to the
    // anonymous account through a role in a kernel test.
    $this->setUpCurrentUser([], ['access content']);

    $author = $this->createUser([], 'ada');
    $this->node = Node::create([
      'type' => 'page',
      'title' => 'A node title',
      'status' => 1,
      'uid' => $author->id(),
      'created' => 1771065000,
      'body' => ['value' => '<p>A <em>summary</em>.</p>', 'format' => 'plain_text'],
    ]);
    $this->node->save();
  }

  /**
   * The same producer feeds the Twig render and the envelope node.
   */
  public function testProducerFeedsTwigAndEnvelope(): void {
    $build = ProducedComponent::build('multi_frontend_test.card', $this->node);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    $this->assertStringContainsString('A node title', $html);
    $this->assertStringContainsString('datetime="2026-02-14T10:30:00+00:00"', $html);
    $this->assertStringContainsString('class="card"', $html);

    $cacheability = new CacheableMetadata();
    $node = $this->container->get(ProducerInvoker::class)
      ->produceNode('multi_frontend_test.card', $this->node, $cacheability);

    $this->assertSame('multi_frontend_test:card', $node->componentId);
    $this->assertSame('A node title', $node->props['title']);
    // An ISO 8601 timestamp, not a formatted date. The template formats it;
    // the contract does not.
    $this->assertSame('2026-02-14T10:30:00+00:00', $node->props['createdAt']);
    $this->assertSame('/node/' . $this->node->id(), $node->props['url']);
  }

  /**
   * Props are serializable, which is the rule core never checks.
   */
  public function testPropsAreSerializable(): void {
    $cacheability = new CacheableMetadata();
    $node = $this->container->get(ProducerInvoker::class)
      ->produceNode('multi_frontend_test.card', $this->node, $cacheability);

    $json = json_encode($node->props, JSON_THROW_ON_ERROR);
    $round_tripped = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame($node->props, $round_tripped);
    // Nothing serialized to an empty object, which is what a Url or any other
    // object with no public state would have become.
    $this->assertStringNotContainsString('{}', $json);
  }

  /**
   * Cacheability is collected while producing, and reaches the render array.
   */
  public function testCacheabilityIsCollectedWhileProducing(): void {
    $cacheability = new CacheableMetadata();
    $node = $this->container->get(ProducerInvoker::class)
      ->produceNode('multi_frontend_test.card', $this->node, $cacheability);

    $this->assertContains('node:' . $this->node->id(), $node->getCacheTags());
    // The producer never mentioned caching for the body field. Reading it
    // through ProducerContext recorded the field access result anyway.
    $this->assertNotEmpty($node->getCacheContexts());
    $this->assertEqualsCanonicalizing($node->getCacheTags(), $cacheability->getCacheTags());

    $build = ProducedComponent::build('multi_frontend_test.card', $this->node);
    $this->container->get('renderer')->renderInIsolation($build);
    $this->assertContains('node:' . $this->node->id(), $build['#cache']['tags']);
  }

  /**
   * Render cache keys are computable before the producer runs.
   */
  public function testCacheKeysAreComputableUpFront(): void {
    $keys = ProducerInvoker::cacheKeys('multi_frontend_test.card', $this->node);
    $this->assertSame(
      [
        'produced_component',
        'multi_frontend_test.card',
        'node',
        (string) $this->node->id(),
        'en',
        // Nodes are revisionable, and two revisions share an entity ID, so
        // without this the first one rendered would be served for both.
        (string) $this->node->getRevisionId(),
      ],
      $keys,
    );
    $build = ProducedComponent::build('multi_frontend_test.card', $this->node);
    $this->assertSame($keys, $build['#cache']['keys']);
  }

  /**
   * A subject with no stable identity is not render cached.
   */
  public function testUnsavedSubjectIsNotCached(): void {
    $unsaved = Node::create(['type' => 'page', 'title' => 'Not saved yet']);
    $this->assertNull(ProducerInvoker::cacheKeys('multi_frontend_test.card', $unsaved));

    $build = ProducedComponent::build('multi_frontend_test.card', $unsaved);
    $this->assertArrayNotHasKey('keys', $build['#cache'] ?? []);
  }

  /**
   * Attributes change the output, so they change the cache key.
   */
  public function testAttributesAreInTheCacheKey(): void {
    $plain = ProducedComponent::build('multi_frontend_test.card', $this->node);
    $decorated = ProducedComponent::build(
      'multi_frontend_test.card',
      $this->node,
      ['#attributes' => ['class' => ['promoted']]],
    );
    $this->assertNotSame($plain['#cache']['keys'], $decorated['#cache']['keys']);
  }

  /**
   * A render cache hit never reaches the producer.
   *
   * This is the answer to the standing objection that generating a tree
   * before rendering it forfeits render caching. It does not: the cache key
   * is derived from the producer ID and the subject's identity, both known
   * before the producer runs, so a hit short-circuits the work exactly as a
   * render-cached views listing is not rebuilt from its query.
   */
  public function testRenderCacheHitSkipsTheProducer(): void {
    \Drupal::state()->set('multi_frontend_test.produce_count', 0);

    $first = ProducedComponent::build('multi_frontend_test.card', $this->node);
    $this->container->get('renderer')->renderInIsolation($first);
    $this->assertSame(1, \Drupal::state()->get('multi_frontend_test.produce_count'));

    $second = ProducedComponent::build('multi_frontend_test.card', $this->node);
    $this->container->get('renderer')->renderInIsolation($second);
    $this->assertSame(
      1,
      \Drupal::state()->get('multi_frontend_test.produce_count'),
      'The second render was served from the render cache without invoking the producer.',
    );
  }

  /**
   * A field the viewer may not see never reaches the props.
   */
  public function testFieldAccessIsApplied(): void {
    $cacheability = new CacheableMetadata();
    $allowed = $this->container->get(ProducerInvoker::class)
      ->produceNode('multi_frontend_test.card', $this->node, $cacheability);
    $this->assertNotEmpty($allowed->props['summary']);

    \Drupal::state()->set('multi_frontend_test.deny_body', TRUE);
    \Drupal::entityTypeManager()->getStorage('node')->resetCache();
    $node = \Drupal::entityTypeManager()->getStorage('node')->load((int) $this->node->id());

    $denied_cacheability = new CacheableMetadata();
    $denied = $this->container->get(ProducerInvoker::class)
      ->produceNode('multi_frontend_test.card', $node, $denied_cacheability);

    $this->assertArrayNotHasKey('summary', $denied->props);
    // The access result's cacheability came along, so the response varies on
    // whatever the check varied on.
    $this->assertContains('multi_frontend_test.deny_body', $denied->getCacheTags());
  }

  /**
   * Formatted text crosses the boundary filtered, not raw.
   */
  public function testFormattedTextIsFiltered(): void {
    $this->node->set('body', ['value' => '<p>Keep</p><script>alert(1)</script>', 'format' => 'plain_text'])->save();

    $cacheability = new CacheableMetadata();
    $node = $this->container->get(ProducerInvoker::class)
      ->produceNode('multi_frontend_test.card', $this->node, $cacheability);

    $this->assertStringNotContainsString('<script>', (string) $node->props['summary']);
    $this->assertContains('config:filter.format.plain_text', $node->getCacheTags());
  }

  /**
   * A value stored without a text format is still filtered.
   *
   * A text field whose format was never set, which any programmatic save or
   * migration can produce, used to be returned as the raw stored column on
   * the reasoning that an empty format meant "not a formatted-text field".
   * Core renders such a field as nothing; this returned live markup into a
   * prop declared as HTML.
   */
  public function testTextWithoutAFormatIsStillFiltered(): void {
    $this->node->set('body', ['value' => '<script>alert(1)</script>', 'format' => NULL])->save();

    $props = $this->container->get(ProducerInvoker::class)
      ->produceProps('multi_frontend_test.card', $this->node, new CacheableMetadata());

    $this->assertStringNotContainsString('<script>', (string) $props['summary']);
    $this->assertStringContainsString('&lt;script&gt;', (string) $props['summary']);
  }

  /**
   * Invalid props are rejected unconditionally, not behind assert().
   */
  public function testInvalidPropsAreRejectedWithoutAssertions(): void {
    $invoker = $this->container->get(ProducerInvoker::class);

    // Core's SDC validation runs behind assert(), which core's own
    // documentation tells sites to compile out in production. The producer
    // path validates unconditionally: the exception below is thrown by a
    // direct call, not by an assertion. The broken producer returns a
    // formatted date where the schema says format: date-time, which is
    // precisely the thing the contract exists to stop crossing the boundary.
    $this->expectException(InvalidComponentException::class);
    $this->expectExceptionMessageMatches('/createdAt/');
    $invoker->produceProps('multi_frontend_test.broken_card', $this->node, new CacheableMetadata());
  }

  /**
   * One component, two producers: the registry is keyed for it.
   */
  public function testTwoProducersForOneComponent(): void {
    $definitions = $this->container->get(ComponentProducerManager::class)->getDefinitions();

    $this->assertSame('multi_frontend_test:card', $definitions['multi_frontend_test.card']['component']);
    $this->assertSame('multi_frontend_test:card', $definitions['multi_frontend_test.broken_card']['component']);
  }

  /**
   * An optional prop with no value is absent, not null.
   *
   * A prop populated from an access-controlled field is NULL exactly when the
   * viewer may not see it. Passing NULL to a prop the schema types as a
   * string would refuse to render the component for that viewer, which turns
   * an access rule into a broken page.
   */
  public function testOptionalNullPropIsOmitted(): void {
    \Drupal::state()->set('multi_frontend_test.deny_body', TRUE);
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache();
    $node = $storage->load((int) $this->node->id());

    $props = $this->container->get(ProducerInvoker::class)
      ->produceProps('multi_frontend_test.card', $node, new CacheableMetadata());

    $this->assertArrayNotHasKey('summary', $props);
    $this->assertArrayHasKey('title', $props);
  }

  /**
   * The envelope is a closed union of component and html nodes.
   */
  public function testEnvelopeNodeUnion(): void {
    $build = [
      'card' => ProducedComponent::build('multi_frontend_test.card', $this->node),
      'legacy' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => 'Not converted yet.',
        '#cache' => ['tags' => ['legacy:1']],
      ],
    ];

    $cacheability = new CacheableMetadata();
    $nodes = $this->container->get(EnvelopeBuilder::class)->build($build, $cacheability);

    $this->assertCount(2, $nodes);
    $this->assertInstanceOf(ComponentNode::class, $nodes[0]);
    $this->assertInstanceOf(HtmlNode::class, $nodes[1]);
    $this->assertStringContainsString('Not converted yet.', $nodes[1]->markup);
    $this->assertContains('legacy:1', $cacheability->getCacheTags());
    $this->assertContains('node:' . $this->node->id(), $cacheability->getCacheTags());
  }

  /**
   * A wrapper carrying only #cache is still split into nodes.
   *
   * Found by pointing a real front end at this. A controller that does the
   * correct thing and declares a list cache tag on the array it returns was
   * turning its whole page into one markup blob, because #cache looked like
   * a render property. It is metadata: it moves up to the parent instead of
   * blocking the descent.
   */
  public function testContainerWithCacheMetadataIsStillSplit(): void {
    $build = [
      'a' => ProducedComponent::build('multi_frontend_test.card', $this->node),
      'b' => ProducedComponent::build('multi_frontend_test.card', $this->node),
      '#cache' => ['tags' => ['node_list']],
    ];

    $cacheability = new CacheableMetadata();
    $nodes = $this->container->get(EnvelopeBuilder::class)->build($build, $cacheability);

    $this->assertCount(2, $nodes);
    $this->assertContainsOnlyInstancesOf(ComponentNode::class, $nodes);
    // The wrapper's own cache tag survives the split.
    $this->assertContains('node_list', $cacheability->getCacheTags());
  }

  /**
   * A wrapper that renders something itself is not split.
   */
  public function testContainerThatRendersIsNotSplit(): void {
    $build = [
      '#type' => 'html_tag',
      '#tag' => 'section',
      'a' => ProducedComponent::build('multi_frontend_test.card', $this->node),
    ];

    $nodes = $this->container->get(EnvelopeBuilder::class)->build($build, new CacheableMetadata());

    // Descending would have silently dropped the <section> wrapper, so the
    // subtree is rendered whole instead. This is the containment limit the
    // design names, and it is deliberate rather than accidental.
    $this->assertCount(1, $nodes);
    $this->assertInstanceOf(HtmlNode::class, $nodes[0]);
    $this->assertStringContainsString('<section>', $nodes[0]->markup);
  }

  /**
   * An element whose access core has not resolved is never produced.
   *
   * #access_callback is resolved by core, through the trusted-callback
   * policy, and only then checked. An envelope that produced the element
   * before that happened would hand a consumer data the HTML path suppresses.
   */
  public function testUnresolvedAccessCallbackIsNotProduced(): void {
    $build = ProducedComponent::build('multi_frontend_test.card', $this->node);
    $build['#access_callback'] = [AccessCallbacks::class, 'deny'];

    $nodes = $this->container->get(EnvelopeBuilder::class)->build($build, new CacheableMetadata());

    // Denied, so it renders as nothing and contributes no node at all. What
    // matters is that it is not a ComponentNode carrying the props.
    $this->assertSame([], $nodes);
  }

  /**
   * A component node is the same whether fetched alone or read from a page.
   */
  public function testComponentNodeIsIdenticalInBothPlaces(): void {
    $alone = $this->container->get(ProducerInvoker::class)
      ->produceNode('multi_frontend_test.card', $this->node, new CacheableMetadata())
      ->toArray();

    $in_page = $this->container->get(EnvelopeBuilder::class)
      ->build(['card' => ProducedComponent::build('multi_frontend_test.card', $this->node)], new CacheableMetadata())[0]
      ->toArray();

    $this->assertEquals($alone, $in_page);
  }

  /**
   * Slots hold nodes, so a component can compose with another component.
   */
  public function testSlotsHoldNodes(): void {
    $node = $this->container->get(ProducerInvoker::class)
      ->produceNode('multi_frontend_test.card', $this->node, new CacheableMetadata());

    $this->assertArrayHasKey('footer', $node->slots);
    $child = $node->slots['footer'][0];
    $this->assertInstanceOf(ComponentNode::class, $child);
    $this->assertSame('multi_frontend_test:byline', $child->componentId);
    $this->assertSame('ada', $child->props['name']);

    // The child's cacheability reached the parent, and the child kept its own.
    $owner_tag = 'user:' . $this->node->getOwnerId();
    $this->assertContains($owner_tag, $node->getCacheTags());
    $this->assertContains($owner_tag, $child->getCacheTags());

    $serialized = $node->toArray();
    $this->assertSame('component', $serialized['slots']->footer[0]['type']);

    // And the Twig path renders the same tree.
    $build = ProducedComponent::build('multi_frontend_test.card', $this->node);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('class="byline"', $html);
  }

  /**
   * Published schemas are stamped draft-07 and carry an absolute identifier.
   */
  public function testPublishedSchema(): void {
    $schema = $this->container->get(SchemaPublisher::class)
      ->componentSchema('multi_frontend_test.card');

    $this->assertSame('http://json-schema.org/draft-07/schema#', $schema['$schema']);
    $this->assertStringContainsString('/component-api/schema/multi_frontend_test.card', $schema['$id']);
    $this->assertArrayHasKey('title', $schema['properties']);
    $this->assertSame(['title'], $schema['required']);

    $catalog = $this->container->get(SchemaPublisher::class)->catalog();
    $ids = array_column($catalog['producers'], 'producer');
    $this->assertContains('multi_frontend_test.card', $ids);
  }

  /**
   * The envelope schema is served at all, and types props per component.
   *
   * The first two assertions are the regression: a parameter shadowed by a
   * local made this method raise a TypeError on every request, and no test
   * called it, so eight green checks reported a route that never answered.
   */
  public function testEnvelopeSchemaDiscriminatesOnComponent(): void {
    $schema = $this->container->get(SchemaPublisher::class)->envelopeSchema();

    $this->assertSame('PageEnvelope', $schema['title']);
    $this->assertStringContainsString('/component-api/schema/_envelope', $schema['$id']);

    $definitions = $schema['definitions'];
    $refs = array_column($definitions['componentNode']['oneOf'], '$ref');
    $this->assertContains('#/definitions/componentNode.multi_frontend_test:card', $refs);
    $this->assertContains('#/definitions/componentNode.multi_frontend_test:byline', $refs);

    // Two producers serve multi_frontend_test:card. One variant, or a card
    // node matches two branches of a oneOf and validates against neither.
    $this->assertCount(\count($refs), \array_unique($refs));

    $variant = $definitions['componentNode.multi_frontend_test:card'];
    $this->assertSame(['const' => 'multi_frontend_test:card'], $variant['properties']['component']);

    // The point of the whole union: props is the component's own schema, not
    // an open object a generated client has to cast its way out of.
    $props = $variant['properties']['props'];
    $this->assertArrayHasKey('title', $props['properties']);
    $this->assertSame(['title'], $props['required']);
    // The standalone schema already generates a named type from these keys.
    // Repeating them would declare that type twice in one generated file.
    $this->assertArrayNotHasKey('title', $props);
    $this->assertArrayNotHasKey('$id', $props);
    $this->assertArrayNotHasKey('$schema', $props);
  }

  /**
   * An error on the envelope format is an envelope, with its status intact.
   */
  public function testEnvelopeErrorsAreEnvelopeShaped(): void {
    $event = self::exceptionEvent(
      new AccessDeniedHttpException('The "view test" permission is required.'),
      ['_wrapper_format' => 'envelope'],
    );
    $this->container->get(EnvelopeExceptionSubscriber::class)->onException($event);

    $response = $event->getResponse();
    $this->assertInstanceOf(CacheableJsonResponse::class, $response);
    // A 403 stays a 403, rather than becoming a 200 with an error key.
    $this->assertSame(403, $response->getStatusCode());

    $body = json_decode((string) $response->getContent(), TRUE);
    // Well-formed envelope plus one extra key, so a client parses one shape
    // whether the page existed or not.
    $this->assertSame(['page', 'regions', 'cacheability', 'error'], \array_keys($body));
    $this->assertSame('Forbidden', $body['page']['title']);
    $this->assertSame([], $body['regions']['content']);
    $this->assertSame(403, $body['error']['status']);
    $this->assertSame('The "view test" permission is required.', $body['error']['message']);
  }

  /**
   * Nothing else is reshaped: not other formats, not non-HTTP failures.
   */
  public function testOnlyHttpErrorsOnTheEnvelopeFormatAreConverted(): void {
    $subscriber = $this->container->get(EnvelopeExceptionSubscriber::class);

    // An ordinary HTML request keeps core's error page.
    $html = self::exceptionEvent(new AccessDeniedHttpException('Nope.'), []);
    $subscriber->onException($html);
    $this->assertFalse($html->hasResponse());

    // A programming or service failure keeps core's handling rather than
    // being reshaped into a tidy response that publishes its message.
    $fatal = self::exceptionEvent(new \RuntimeException('Database is on fire.'), ['_wrapper_format' => 'envelope']);
    $subscriber->onException($fatal);
    $this->assertFalse($fatal->hasResponse());
  }

  /**
   * Builds an exception event for a request with the given query.
   *
   * @param array<string, string> $query
   *   Query parameters.
   */
  private static function exceptionEvent(\Throwable $exception, array $query): ExceptionEvent {
    // The event carries a kernel it never calls, so a stub keeps this test
    // off the real one rather than booting it to be ignored.
    $kernel = new class implements HttpKernelInterface {

      public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
        throw new \LogicException('The subscriber must not invoke the kernel.');
      }

    };
    return new ExceptionEvent(
      $kernel,
      Request::create('/anything', 'GET', $query),
      HttpKernelInterface::MAIN_REQUEST,
      $exception,
    );
  }

}
