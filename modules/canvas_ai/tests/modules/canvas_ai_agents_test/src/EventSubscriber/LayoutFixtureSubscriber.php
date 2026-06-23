<?php

declare(strict_types=1);

namespace Drupal\canvas_ai_agents_test\EventSubscriber;

use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Yaml\Yaml;

/**
 * Supplies the current page layout to page builder and template builder tests.
 *
 * Both agents need the page's current layout to work. The Canvas UI normally
 * sends it from the client, which is not possible under test, so each test
 * targeting either agent must set a canvas_layout_fixture token naming a JSON
 * file in fixtures/page_layout/. This subscriber loads that file into
 * CanvasAiTempStore for the get_current_layout tool and clears it afterwards.
 */
class LayoutFixtureSubscriber implements EventSubscriberInterface {

  /**
   * Token key that identifies which layout fixture file to load.
   */
  private const FIXTURE_TOKEN = 'canvas_layout_fixture';

  /**
   * Route names on which the layout fixture lifecycle runs.
   *
   * @var string[]
   */
  private const TEST_ROUTES = [
    'ai_agents_test.group_ajax',
    'ai_agents_test.run_test',
  ];

  /**
   * Agent IDs whose tests must declare a layout fixture.
   *
   * @var string[]
   */
  private const LAYOUT_DEPENDENT_AGENT_IDS = [
    'canvas_page_builder_agent',
    'canvas_template_builder_agent',
  ];

  /**
   * Constructs a LayoutFixtureSubscriber.
   *
   * @param \Drupal\canvas_ai\CanvasAiTempStore $tempStore
   *   The Canvas AI tempstore service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    private readonly CanvasAiTempStore $tempStore,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST  => ['onRequest'],
      KernelEvents::RESPONSE => ['onResponse'],
    ];
  }

  /**
   * Loads the layout fixture before a page/template builder test runs.
   *
   * Acts only on the test-execution routes for those two agents. For them the
   * fixture is required, so a missing, absent, or non-JSON fixture throws.
   *
   * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\GetCurrentLayout::execute()
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    if (!\in_array($request->attributes->get('_route'), self::TEST_ROUTES, TRUE)) {
      return;
    }

    $testId = $request->attributes->get('test_id');
    if (!$testId) {
      return;
    }

    /** @var \Drupal\ai_agents_test\Entity\AgentTest|null $test */
    $test = $this->entityTypeManager
      ->getStorage('ai_agents_test')
      ->load($testId);
    if (!$test) {
      return;
    }

    // Only page builder and template builder tests need a layout fixture.
    if (!\in_array($test->ai_agent->target_id, self::LAYOUT_DEPENDENT_AGENT_IDS, TRUE)) {
      return;
    }

    $tokens = Yaml::parse($test->tokens->value ?? '');
    $fixtureName = \is_array($tokens) ? (string) ($tokens[self::FIXTURE_TOKEN] ?? '') : '';

    $this->tempStore->setData(
      CanvasAiTempStore::CURRENT_LAYOUT_KEY,
      $this->loadFixture($fixtureName),
    );
  }

  /**
   * Clears the layout fixture from tempstore after the test response is built.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    if (!\in_array($request->attributes->get('_route'), self::TEST_ROUTES, TRUE)) {
      return;
    }

    $this->tempStore->deleteData(CanvasAiTempStore::CURRENT_LAYOUT_KEY);
  }

  /**
   * Reads and validates the JSON fixture named by the test's token.
   *
   * @param string $fixtureName
   *   The token value: a fixture file name in fixtures/page_layout/, without
   *   the .json extension.
   *
   * @return string
   *   The fixture JSON, stored verbatim for the get_current_layout tool.
   *
   * @throws \InvalidArgumentException
   *   When the token is empty, names a file that does not exist, or names a
   *   file whose contents are not valid JSON.
   */
  private function loadFixture(string $fixtureName): string {
    if ($fixtureName === '') {
      throw new \InvalidArgumentException(\sprintf("Tests targeting this agent must set a '%s' token naming a fixture.", self::FIXTURE_TOKEN));
    }

    $contents = $this->readFixtureFile($fixtureName);
    if ($contents === NULL) {
      throw new \InvalidArgumentException(\sprintf("Layout fixture '%s' does not exist.", $fixtureName));
    }

    try {
      \json_decode($contents, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new \InvalidArgumentException(\sprintf("Layout fixture '%s' is not valid JSON: %s", $fixtureName, $e->getMessage()), 0, $e);
    }

    return $contents;
  }

  /**
   * Reads a layout fixture file.
   *
   * @param string $fixtureName
   *   The fixture file name, without the .json extension.
   *
   * @return string|null
   *   The file contents, or NULL when the file does not exist.
   */
  protected function readFixtureFile(string $fixtureName): ?string {
    $path = $this->moduleHandler->getModuleDirectories()['canvas_ai_agents_test'] . '/fixtures/page_layout/' . $fixtureName . '.json';
    return \file_exists($path) ? (string) \file_get_contents($path) : NULL;
  }

}
