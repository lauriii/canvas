<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_ai\Controller;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai_agents\Enum\AiAgentStatusItemTypes;
use Drupal\ai_agents\PluginBase\AiAgentEntityWrapper;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\Service\AgentStatus\Interfaces\AiAgentStatusPollerServiceInterface;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\TextGenerated;
use Drupal\canvas_ai\CanvasAiChatHelper;
use Drupal\canvas_ai\CanvasAiPageBuilderHelper;
use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\canvas_ai\Plugin\AiFunctionCall\BuilderResponseFunctionCallInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Environment;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileExists;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Drupal\file\Upload\FormUploadedFile;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Renders the Drupal Canvas Dev AI calls.
 *
 * @todo Replace the single request/response call with the real hop loop in https://git.drupalcode.org/project/canvas/-/work_items/3591777
 *
 * @internal
 */
final class CanvasDevAiBuilder extends ControllerBase {

  /**
   * The prompt keys the client must send on every request.
   */
  private const REQUIRED_PROMPT_KEYS = [
    'request_id',
    'messages',
    'derived_proptypes',
    'selected_component_required_props',
  ];

  /**
   * Constructs a new CanvasBuilder object.
   */
  public function __construct(
    protected AiProviderPluginManager $providerService,
    protected PluginManagerInterface $agentManager,
    protected CsrfTokenGenerator $csrfTokenGenerator,
    protected CanvasAiPageBuilderHelper $canvasAiPageBuilderHelper,
    protected CanvasAiTempStore $canvasAiTempStore,
    protected FileUploadHandlerInterface $fileUploadHandler,
    protected AiAgentStatusPollerServiceInterface $poller,
    protected CanvasAiChatHelper $canvasAiChatHelper,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai.provider'),
      $container->get('plugin.manager.ai_agents'),
      $container->get(CsrfTokenGenerator::class),
      $container->get('canvas_ai.page_builder_helper'),
      $container->get(CanvasAiTempStore::class),
      $container->get(FileUploadHandlerInterface::class),
      $container->get('ai_agents.agent_status_poller'),
      $container->get('canvas_ai.chat_helper'),
    );
  }

  /**
   * Renders the Drupal Canvas AI calls.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   */
  public function render(Request $request): JsonResponse {
    $token = $request->headers->get('X-CSRF-Token') ?? '';
    if (!$this->csrfTokenGenerator->validate($token, 'canvas_ai.canvas_builder')) {
      throw new AccessDeniedHttpException('Invalid CSRF token');
    }

    try {
      $prompt = self::normalizePrompt($request);
      $image_files = $this->extractImageFiles($request->files->all());
    }
    catch (BadRequestHttpException $e) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => $e->getMessage(),
        'should_continue' => FALSE,
        'progress' => '',
      ], Response::HTTP_BAD_REQUEST);
    }
    $job_id = $prompt['request_id'];

    $agent = $this->agentManager->createInstance('canvas_component_agent');
    \assert($agent instanceof AiAgentEntityWrapper);
    $this->prepareAgent($agent, $prompt, $image_files);

    // Store the current layout in the temp store. This will be later used by
    // the ai agents.
    // @see \Drupal\canvas_ai\Plugin\AiFunctionCall\GetCurrentLayout.
    // Both request branches carry the layout; multipart requests send it as a
    // JSON string, which normalizePrompt() decodes before this point.
    $current_layout = Json::encode($prompt['current_layout'] ?? '');
    if (!empty($prompt['current_layout'])) {
      $this->canvasAiTempStore->setData(CanvasAiTempStore::CURRENT_LAYOUT_KEY, $current_layout);
    }

    $default = $this->providerService->getDefaultProviderForOperationType('chat');
    if (!\is_array($default) || empty($default['provider_id']) || empty($default['model_id'])) {
      return $this->buildErrorResponse('No default provider found.', $job_id);
    }
    $provider = $this->providerService->createInstance($default['provider_id'], [
      'http_client_options' => [
        'timeout' => $this->config('canvas_ai.settings')->get('http_client_options.timeout'),
      ],
    ]);

    // The provider, token contexts and progress tracking are not part of the
    // serialized agent state, so they are re-applied on every hop.
    $agent->setProgressThreadId($job_id);
    $agent->setDetailedProgressTracking([
      AiAgentStatusItemTypes::Started,
      AiAgentStatusItemTypes::TextGenerated,
    ]);
    $agent->setAiProvider($provider);
    $agent->setModelName($default['model_id']);
    $agent->setAiConfiguration([]);
    $agent->setCreateDirectly(TRUE);
    $agent->setTokenContexts($this->buildTokenContexts($prompt, $current_layout));
    // Stop the agent after a single tool decision, so each request returns
    // quickly and the frontend drives the next hop.
    $agent->setLooped(FALSE);

    try {
      $solvability = $agent->determineSolvability();
    }
    catch (\Exception $e) {
      // Drop any half-serialized state so the next turn starts clean.
      $this->canvasAiTempStore->deleteStoredAgentState($job_id);
      return $this->buildErrorResponse($e->getMessage(), $job_id);
    }

    // Persist the agent while it still has work to do, so the next hop resumes
    // it. should_continue tells the frontend whether to send that next hop.
    $should_continue = !$agent->isFinished();
    if ($should_continue) {
      $this->canvasAiTempStore->setStoredAgentState($job_id, $agent->toArray());
    }
    else {
      $this->canvasAiTempStore->deleteStoredAgentState($job_id);
    }

    if ($solvability === AiAgentInterface::JOB_SOLVABLE) {
      return $this->buildSolvableResponse($agent, $job_id, $should_continue);
    }
    [$status, $message] = match ($solvability) {
      AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION => [FALSE, $agent->answerQuestion()],
      AiAgentInterface::JOB_INFORMS => [TRUE, $agent->inform()],
      default => [FALSE, 'Something went wrong'],
    };
    return new JsonResponse([
      'status' => $status,
      'message' => $message,
      'should_continue' => $should_continue,
      'progress' => $this->getAiProgress($job_id),
    ]);
  }

  /**
   * Decodes the request into the prompt array the agent is configured from.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   The prompt, with every required key present.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When the body cannot be decoded or a required key is missing.
   */
  private static function normalizePrompt(Request $request): array {
    if ($request->getContentTypeFormat() === 'json') {
      $prompt = Json::decode($request->getContent());
      if (!\is_array($prompt)) {
        throw new BadRequestHttpException('The request body is not valid JSON.');
      }
      self::assertRequiredPromptKeys($prompt);
      return $prompt;
    }

    $prompt = self::collectNumberedMessages($request->request->all());
    self::assertRequiredPromptKeys($prompt);
    // Multipart form values arrive as JSON strings, where the JSON request
    // branch sends the decoded structures.
    $prompt['derived_proptypes'] = Json::decode($prompt['derived_proptypes']);
    $prompt['selected_component_required_props'] = Json::decode($prompt['selected_component_required_props']);
    // Absent while the user is not editing a page, and NULL once decoded when
    // the page carries no layout yet.
    if (\array_key_exists('current_layout', $prompt)) {
      $prompt['current_layout'] = Json::decode($prompt['current_layout']);
    }
    return $prompt;
  }

  /**
   * Assembles the chat messages a multipart request sends as separate values.
   *
   * @param array $prompt
   *   The raw form values, carrying one 'message<number>' key per message.
   *
   * @return array
   *   The prompt, with the numbered keys replaced by an ordered 'messages'.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When a message is not valid JSON.
   */
  private static function collectNumberedMessages(array $prompt): array {
    $messages = [];
    foreach ($prompt as $key => $value) {
      if (preg_match('/^message(\d+)$/', (string) $key, $matches) !== 1) {
        continue;
      }
      $decoded = Json::decode($value);
      if (!\is_array($decoded)) {
        throw new BadRequestHttpException(\sprintf('The "%s" value is not valid JSON.', $key));
      }
      $messages[(int) $matches[1]] = $decoded;
      unset($prompt[$key]);
    }
    ksort($messages);
    $prompt['messages'] = array_values($messages);
    return $prompt;
  }

  /**
   * Asserts that the client sent every key the agent configuration reads.
   *
   * @param array $prompt
   *   The decoded prompt.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When a required key is missing or carries no usable value.
   */
  private static function assertRequiredPromptKeys(array $prompt): void {
    foreach (self::REQUIRED_PROMPT_KEYS as $key) {
      if (!isset($prompt[$key])) {
        throw new BadRequestHttpException(\sprintf('The request is missing the required "%s" value.', $key));
      }
    }
    if ($prompt['request_id'] === '') {
      throw new BadRequestHttpException('The "request_id" value is empty.');
    }
    if ($prompt['messages'] === []) {
      throw new BadRequestHttpException('The "messages" value is empty.');
    }
  }

  /**
   * Reads the uploaded images into the objects the chat input carries.
   *
   * @param array $files
   *   The uploaded files.
   *
   * @return \Drupal\ai\OperationType\GenericType\ImageFile[]
   *   The images.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When an upload is not an accepted image.
   */
  private function extractImageFiles(array $files): array {
    $image_files = [];
    foreach ($files as $file) {
      if (!$file instanceof UploadedFile) {
        continue;
      }
      // Validate and store the upload through the core file upload pipeline.
      // Once validated, we discard it, but we want to centralize validation.
      $upload_result = $this->fileUploadHandler->handleFileUpload(
        new FormUploadedFile($file),
        validators: [
          'FileNameLength' => [],
          'FileExtension' => ['extensions' => 'png jpg jpeg'],
          'FileSizeLimit' => ['fileLimit' => Environment::getUploadMaxSize()],
          'FileIsImage' => [],
        ],
        destination: 'temporary://',
        fileExists: FileExists::Rename,
      );
      if ($upload_result->hasViolations()) {
        throw new BadRequestHttpException('Only image files are allowed (jpeg, png, jpg).');
      }
      $uploaded_file = $upload_result->getFile();
      $mime_type = (string) $uploaded_file->getMimeType();
      $filename = (string) $uploaded_file->getFilename();
      $binary = file_get_contents((string) $uploaded_file->getFileUri());

      // Delete the managed file immediately, we only care about the contents.
      $uploaded_file->delete();

      if ($binary === FALSE) {
        throw new \RuntimeException(\sprintf('Unable to read the uploaded file "%s".', $filename));
      }

      $image_files[] = new ImageFile($binary, $mime_type, $filename);
    }
    return $image_files;
  }

  /**
   * Resumes a paused chat turn, or seeds the agent for a new one.
   *
   * @param \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $agent
   *   The agent to prepare.
   * @param array $prompt
   *   The decoded prompt.
   * @param \Drupal\ai\OperationType\GenericType\ImageFile[] $image_files
   *   The images the user attached to the message.
   */
  private function prepareAgent(AiAgentEntityWrapper $agent, array $prompt, array $image_files): void {
    $state = $this->canvasAiTempStore->getStoredAgentState($prompt['request_id']);
    if ($state !== NULL) {
      // ::fromArray() restores the chat history, which already holds the user
      // message, so seeding the chat input again would duplicate it.
      $agent->fromArray($state);
      return;
    }

    $messages = $prompt['messages'];
    $task_message = array_pop($messages);
    $context = $this->canvasAiPageBuilderHelper->generateVerboseContextForOrchestrator($prompt);
    $message_xml = $this->canvasAiPageBuilderHelper->formatMessageWithContext($context, $task_message['text']);
    $agent->setChatInput(new ChatInput([
      new ChatMessage($task_message['role'], $message_xml, $image_files),
    ]));
    $agent->setChatHistory($this->canvasAiChatHelper->getFilteredChatHistory($messages));
  }

  /**
   * Builds the values the agent's system prompt tokens are replaced with.
   *
   * @param array $prompt
   *   The decoded prompt.
   * @param string $current_layout
   *   The JSON-encoded current layout.
   *
   * @return array
   *   The token contexts.
   *
   * @see \Drupal\canvas_ai\Hook\CanvasAiHooks::canvas_ai_tokens()
   */
  private function buildTokenContexts(array $prompt, string $current_layout): array {
    $selected_component = $prompt['selected_component'] ?? NULL;
    $component_agent_dynamic_state = $this->canvasAiPageBuilderHelper->generateComponentAgentDynamicPromptSection([
      'selected_component' => $selected_component,
      'selected_component_required_props' => Json::encode($prompt['selected_component_required_props']),
      'json_api_module_status' => $this->moduleHandler()->moduleExists('jsonapi') ? 'enabled' : 'disabled',
      'menu_fetch_source' => $this->getMenuFetchSource(),
    ]);
    return [
      'entity_type' => $prompt['entity_type'] ?? NULL,
      'entity_id' => $prompt['entity_id'] ?? NULL,
      'selected_component' => $selected_component,
      'derived_proptypes' => Json::encode($prompt['derived_proptypes']),
      'page_title' => $prompt['page_title'] ?? NULL,
      'page_description' => $prompt['page_description'] ?? NULL,
      'active_component_uuid' => $prompt['active_component_uuid'] ?? 'None',
      'component_agent_dynamic_state' => $component_agent_dynamic_state,
      'available_regions' => Json::encode($this->canvasAiPageBuilderHelper->getAvailableRegions($current_layout)),
      // JSON-encode so the libraries render as readable data in the system
      // prompt token rather than the string "Array".
      'custom_libraries' => Json::encode(self::getSupportedLibraries()),
    ];
  }

  /**
   * Builds the response for a turn the agent is able to solve.
   *
   * @param \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $agent
   *   The agent that ran this hop.
   * @param string $job_id
   *   The job ID identifying the chat turn.
   * @param bool $should_continue
   *   Whether the turn continues after this hop.
   */
  private function buildSolvableResponse(AiAgentEntityWrapper $agent, string $job_id, bool $should_continue): JsonResponse {
    $response = [
      'status' => TRUE,
      'should_continue' => $should_continue,
    ];
    foreach ($agent->getToolResults(TRUE) as $tool) {
      if ($tool instanceof BuilderResponseFunctionCallInterface) {
        $response = array_merge($response, $tool->getStructuredOutput());
      }
      // @todo Remove this branch without replacing it: neither agent runs here, and a file-upload turn carries no layout of its own, so deleting the key at turn end would leave the layout-reading tools with nothing. See https://git.drupalcode.org/project/canvas/-/work_items/3591777
      if (\in_array($tool->getPluginId(), [
        'ai_agents::ai_agent::canvas_page_builder_agent',
        'ai_agents::ai_agent::canvas_template_builder_agent',
      ], TRUE)) {
        $this->canvasAiTempStore->deleteData(CanvasAiTempStore::CURRENT_LAYOUT_KEY);
      }
    }
    // Only the final hop carries a message: the agent's answer to the user.
    if ($should_continue) {
      $response['progress'] = $this->getAiProgress($job_id);
    }
    else {
      $response['message'] = $agent->solve();
      $response['progress'] = $this->getAiProgressWithoutAnswer($job_id, $response['message']);
    }
    return new JsonResponse($this->canvasAiPageBuilderHelper->processCanvasPageFields($response));
  }

  /**
   * Builds the response for a turn that could not be run.
   *
   * @param string $message
   *   The message shown to the user.
   * @param string $job_id
   *   The job ID identifying the chat turn.
   */
  private function buildErrorResponse(string $message, string $job_id): JsonResponse {
    return new JsonResponse([
      'status' => FALSE,
      'message' => $message,
      'should_continue' => FALSE,
      'progress' => $this->getAiProgress($job_id),
    ]);
  }

  /**
   * Function to get the source for menu fetching.
   *
   * @return string
   *   The menu fetch source.
   */
  private function getMenuFetchSource(): string {
    if ($this->moduleHandler()->moduleExists('jsonapi_menu_items')) {
      $menuFetchSource = 'jsonapi_menu_items';
    }
    elseif ($this->config('system.feature_flags')->get('linkset_endpoint') === TRUE) {
      $menuFetchSource = 'linkset';
    }
    elseif ($this->currentUser()->hasPermission('administer site configuration')) {
      $menuFetchSource = 'linkset_not_configured';
    }
    else {
      $menuFetchSource = 'menu_fetching_functionality_not_available';
    }
    return $menuFetchSource;
  }

  /**
   * Returns the narration for the given request, without its final answer.
   *
   * @param string $request_id
   *   The request ID.
   * @param string $final_message
   *   The answer returned separately as `message`. The poller tracks every
   *   piece of text the agent generates, the answer included, so it is
   *   stripped here to keep the chat from showing it twice.
   *
   * @return string
   *   The generated status text.
   */
  private function getAiProgressWithoutAnswer(string $request_id, string $final_message): string {
    $progress = $this->getAiProgress($request_id);
    $position = strrpos($progress, $final_message);
    if ($final_message === '' || $position === FALSE) {
      return $progress;
    }
    return rtrim(substr($progress, 0, $position));
  }

  /**
   * Returns the text generated for the given request.
   *
   * @param string $request_id
   *   The request ID.
   *
   * @return string
   *   The generated status text.
   */
  private function getAiProgress(string $request_id): string {
    $progress = $this->poller->getLatestStatusUpdates($request_id);
    $text = '';
    foreach ($progress->getItems() as $event) {
      if ($event instanceof TextGenerated) {
        $generated_text = $event->getGeneratedText();
        if (!empty($generated_text)) {
          $text = !empty($text) ? $text . "\n\n" . $generated_text : $generated_text;
        }
      }
    }
    return $text;
  }

  /**
   * Gets the libraries supported by Canvas.
   *
   * @return array
   *   The array of supported libraries.
   */
  protected static function getSupportedLibraries(): array {
    return [
      [
        "name" => "formatted_text",
        "type" => "Built-in custom package",
        "description" => "A built-in component to render text with trusted HTML using [`dangerouslySetInnerHTML`](https://react.dev/reference/react-dom/components/common#dangerously-setting-the-inner-html). The content is safe when processed through Drupal's filter system that is [correctly configured](https://www.drupal.org/docs/administering-a-drupal-site/security-in-drupal/configuring-text-formats-aka-input-formats-for-security).",
        "code" => "```jsx\nimport { FormattedText } from 'drupal-canvas';\n\nexport default function Example() {\n  return (\n    <FormattedText>\n      <em>Hello, world!</em>\n    </FormattedText>\n  );\n}\n```",
      ],
      [
        "name" => "cn",
        "type" => "Built-in custom package",
        "description" => "Utility for combining Tailwind CSS classes.",
        "code" => "```jsx\nimport { cn } from 'drupal-canvas';\n\nexport default function Example() {\n  return <ControlDots className=\"top-4 left-4 stroke-white absolute\" />;\n}\n\nconst ControlDots = ({ className }) => (\n  <svg\n    xmlns=\"http://www.w3.org/2000/svg\"\n    viewBox=\"0 0 31 9\"\n    fill=\"none\"\n    strokeWidth=\"2\"\n    className={cn('w-12', className)}\n  >\n    <ellipse cx=\"4.13\" cy=\"4.97\" rx=\"3.13\" ry=\"2.97\" />\n    <ellipse cx=\"15.16\" cy=\"4.97\" rx=\"3.13\" ry=\"2.97\" />\n    <ellipse cx=\"26.19\" cy=\"4.97\" rx=\"3.13\" ry=\"2.97\" />\n  </svg>\n);\n```",
      ],
      [
        "name" => "tailwind",
        "type" => "Bundled npm package",
        "description" => "Tailwind 4 is available to all components by default. The global CSS is added to all pages with the `@import \"tailwindcss\"` directive included. You can use the [`@theme` directive to customize theme variables](https://tailwindcss.com/docs/theme). For example, you can add a new color to your project by defining a theme variable like `--color-drupal-blue`: Now you can use utility classes like `bg-drupal-blue`, `text-drupal-blue`, or `fill-drupal-blue` in your component markup:",
        "code" => "```css\n@theme {\n  --color-drupal-blue: #009cde;\n}\n``` \n```jsx\nexport default function Example() {\nreturn <div className=\"bg-drupal-blue\">Drupal Blue</div>;\n}\n```",
      ],
      [
        "name" => "clsx",
        "type" => "Bundled npm package",
        "description" => "A tiny utility for constructing `className` strings conditionally. Also serves as a faster & smaller drop-in replacement for the `classnames` module.",
        "code" => "```jsx\nimport { clsx } from 'clsx'\n\nexport default function Example() {\n  return (\n    <div className={clsx('foo', true && 'bar', 'baz');} />\n    // => 'foo bar baz'\n  );\n};\n```",
      ],
      [
        "name" => "class_variance_authority",
        "type" => "Bundled npm package",
        "description" => "CVA helps you define components with multiple visual variants (like size, color, state) in a clean, type-safe way. Instead of manually concatenating CSS classes or writing complex conditional logic, you define variants upfront and let CVA handle the class composition.",
        "code" => "```js\nimport { cva } from 'class-variance-authority';\n\nconst button = cva(\n  'font-semibold border rounded', // base classes\n  {\n    variants: {\n      intent: {\n        primary: 'bg-blue-500 text-white border-blue-500',\n        secondary: 'bg-gray-200 text-gray-900 border-gray-200',\n      },\n      size: {\n        small: 'text-sm py-1 px-2',\n        medium: 'text-base py-2 px-4',\n      },\n    },\n    defaultVariants: {\n      intent: 'primary',\n      size: 'medium',\n    },\n  },\n);\n\n// Usage\nbutton({ intent: 'secondary', size: 'small' });\n// Returns: \"font-semibold border rounded bg-gray-200 text-gray-900 border-gray-200 text-sm py-1 px-2\"\n```",
      ],
      [
        "name" => "json_api_client",
        "type" => "Bundled npm package",
        "description" => "A JSON:API client for fetching Drupal content from code components. Use it with drupal-jsonapi-params to build query strings and swr to load and cache remote data.",
        "code" => "```js\nimport { JsonApiClient } from '@drupal-api-client/json-api-client';\nimport { DrupalJsonApiParams } from 'drupal-jsonapi-params';\nimport useSWR from 'swr';\n```",
      ],
      [
        "name" => "drupal_jsonapi_params",
        "type" => "Bundled npm package",
        "description" => "A helper package for generating JSON:API query strings, including includes, filters, fields, sorts, and pagination.",
        "code" => "```js\nimport { DrupalJsonApiParams } from 'drupal-jsonapi-params';\n\nconst params = new DrupalJsonApiParams()\n  .addInclude(['field_media_image'])\n  .addFields('node--article', ['title', 'path', 'field_media_image']);\n```",
      ],
      [
        "name" => "swr",
        "type" => "Bundled npm package",
        "description" => "A React data fetching hook for loading, caching, and revalidating content in code components.",
        "code" => "```js\nimport useSWR from 'swr';\n\nconst { data, error, isLoading } = useSWR('/jsonapi/node/article', fetcher);\n```",
      ],
      [
        "name" => "tailwind_merge",
        "type" => "Bundled npm package",
        "description" => "A utility function to efficiently merge Tailwind CSS classes in JS without style conflicts.",
        "code" => "```js\nimport { twMerge } from 'tailwind-merge';\n\ntwMerge('px-2 py-1 bg-red hover:bg-dark-red', 'p-3 bg-[#B91C1C]');\n// → 'hover:bg-dark-red p-3 bg-[#B91C1C]'\n```",
      ],
      [
        "name" => 'tailwindcss_typography',
        "type" => "Bundled npm package",
        "description" => "A Tailwind CSS plugin that provides a set of pre-configured typography classes for consistent and readable text styles.",
        "code" => "```js\n<FormattedText className=\"prose md:prose-lg lg:prose-xl\">\n  {body}\n</FormattedText>\n```",
      ],
    ];
  }

}
