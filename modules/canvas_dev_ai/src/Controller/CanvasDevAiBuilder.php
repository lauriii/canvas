<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_ai\Controller;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
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
use Drupal\canvas_ai\Plugin\AiFunctionCall\EditComponents;
use Drupal\canvas_ai\Plugin\AiFunctionCall\PlaceComponents;
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
 * A turn is one user message, run as several requests under one request_id:
 * the agent pauses after each tool decision and the client re-POSTs until
 * it reports finished. A conversation is several turns under one
 * conversation_id: when the site opts in on the Agents & Tools form, the
 * agent's own history, tool calls and results included, is kept when a turn
 * ends and resumed by the next, with each placement result cut to its success
 * sentence. Otherwise every turn is seeded from the client transcript, which
 * carries text only.
 *
 * @internal
 */
final class CanvasDevAiBuilder extends ControllerBase {

  /**
   * Asserted alongside every non-canvas_page context.
   */
  private const NOT_PLACEABLE = ' Components cannot be placed or edited here, because this is not a Canvas page.';

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
    // The state a previous hop of this turn parked, if any.
    $stored = $this->canvasAiTempStore->getStoredAgentState($job_id);

    try {
      $agent_to_call = $this->resolveAgentId($prompt);
    }
    catch (\RuntimeException $e) {
      return $this->buildErrorResponse($e->getMessage(), $job_id);
    }
    // The Tool is fixed for the turn: resuming the state in another agent
    // would hand it a chat history it did not write. Clearing the Tool
    // mid-turn resolves the main agent, so it is caught here too.
    if ($stored !== NULL && $stored['agent_id'] !== $agent_to_call) {
      $this->canvasAiTempStore->deleteStoredAgentState($job_id);
      return $this->buildErrorResponse('The selected tool cannot change during a turn.', $job_id);
    }
    $agent = $this->agentManager->createInstance($agent_to_call);
    \assert($agent instanceof AiAgentEntityWrapper);
    $this->prepareAgent($agent, $prompt, $image_files, $stored === NULL ? NULL : $stored['state'], $agent_to_call);

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
    $agent->setTokenContexts($this->buildTokenContexts($prompt));
    // Stop the agent after a single tool decision, so each request returns
    // quickly and the frontend drives the next hop.
    $agent->setLooped(FALSE);

    try {
      $solvability = $agent->determineSolvability();
    }
    catch (\Exception $e) {
      // Drop any half-serialized state so the next turn starts clean.
      $this->forgetTurn($prompt);
      return $this->buildErrorResponse($e->getMessage(), $job_id);
    }

    // Persist the agent while it still has work to do, so the next hop resumes
    // it. should_continue tells the frontend whether to send that next hop.
    $should_continue = !$agent->isFinished();
    if ($should_continue) {
      $this->canvasAiTempStore->setStoredAgentState($job_id, $agent_to_call, $agent->toArray());
    }
    elseif ($solvability === AiAgentInterface::JOB_NOT_SOLVABLE) {
      // The agent gave up: it ran out of loops or the provider failed. What
      // it did in this turn is not a state the conversation should resume
      // from, so the next turn seeds from the client transcript again.
      $this->forgetTurn($prompt);
    }
    else {
      $this->canvasAiTempStore->deleteStoredAgentState($job_id);
      $this->storeConversationState($prompt, $agent_to_call, $agent);
    }

    if ($solvability === AiAgentInterface::JOB_SOLVABLE) {
      return $this->buildSolvableResponse($agent, $job_id, $should_continue);
    }
    [$status, $message] = match ($solvability) {
      AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION => [FALSE, $agent->answerQuestion()],
      AiAgentInterface::JOB_INFORMS => [TRUE, $agent->inform()],
      AiAgentInterface::JOB_NOT_SOLVABLE => [FALSE, self::getNotSolvableMessage($agent)],
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
   * Resolves the ID of the agent to run, from the request's Tool or settings.
   *
   * @param array $prompt
   *   The decoded prompt.
   *
   * @return string
   *   The agent ID, which the plugin manager has a definition for.
   *
   * @throws \RuntimeException
   *   Carrying the message shown to the user.
   *
   * @see \Drupal\canvas_dev_ai\Form\CanvasDevAiAgentSelectionForm
   */
  private function resolveAgentId(array $prompt): string {
    $settings = $this->config('canvas_dev_ai.settings');
    // Only one Tool is active at a time, and the chat sends its agent ID as
    // `selected_tool` on every request of a turn; the key is absent while no
    // Tool is active.
    $selected_tool = $prompt['selected_tool'] ?? '';
    if ($selected_tool !== '') {
      if (!\in_array($selected_tool, $settings->get('tools') ?? [], TRUE)) {
        throw new \RuntimeException('This tool is not allowed.');
      }
      $agent_to_call = $selected_tool;
    }
    else {
      $agent_to_call = $settings->get('main_agent') ?? '';
      if ($agent_to_call === '') {
        throw new \RuntimeException('Unable to resolve the agent to run.');
      }
    }

    // Check that the configured AI agent exists.
    if (!$this->agentManager->hasDefinition($agent_to_call)) {
      throw new \RuntimeException('The agent to run does not exist.');
    }
    return $agent_to_call;
  }

  /**
   * Prepares the agent for a hop.
   *
   * Resumes the paused turn, resumes the conversation for a new turn, or seeds
   * a new conversation.
   *
   * @param \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $agent
   *   The agent to prepare.
   * @param array $prompt
   *   The decoded prompt.
   * @param \Drupal\ai\OperationType\GenericType\ImageFile[] $image_files
   *   The images the user attached to the message.
   * @param array|null $state
   *   The state a previous hop of this turn parked, as written by the agent's
   *   ::toArray(), or NULL for a new turn.
   * @param string $agent_id
   *   The ID of the agent running this hop.
   */
  private function prepareAgent(AiAgentEntityWrapper $agent, array $prompt, array $image_files, ?array $state, string $agent_id): void {
    if ($state !== NULL) {
      // ::fromArray() restores the chat history, which already holds the user
      // message, so seeding the chat input again would duplicate it.
      $agent->fromArray($state);
      return;
    }

    $messages = $prompt['messages'];
    $task_message = array_pop($messages);
    $context = self::getAgentExtraContext($prompt);
    $message_xml = $this->canvasAiPageBuilderHelper->formatMessageWithContext($context, $task_message['text']);
    $agent->setChatInput(new ChatInput([
      new ChatMessage($task_message['role'], $message_xml, $image_files),
    ]));

    // The conversation's earlier turns left the agent's own history, every
    // tool call and tool result included. Resume from it rather than from
    // the client transcript, which carries text only. Only the agent that
    // wrote that history resumes it: to any other agent it describes work it
    // never did. Selecting a different Tool between turns is allowed, so that
    // case seeds from the transcript instead of failing the turn.
    // @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::render()
    $conversation_id = self::getConversationId($prompt);
    $conversation = $conversation_id !== '' && $this->keepsToolCallsInHistory()
      ? $this->canvasAiTempStore->getStoredConversationState($conversation_id)
      : NULL;
    if ($conversation !== NULL && $conversation['agent_id'] === $agent_id) {
      // The loop counter is serialized with the state and drives three things
      // in the agent: the chat input is appended to the history on loop 1
      // only, the progress thread is started at loop 0 only, and max_loops
      // is compared against it. Start this turn from 0 so the new message is
      // read, its narration is recorded, and the ceiling bounds this turn.
      // @see \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper::determineSolvability()
      // @see \Drupal\ai_agents\EventSubscriber\AgentStatusSubscriber
      $resumed = $conversation['state'];
      $resumed['looped'] = 0;
      $agent->fromArray($resumed);
      return;
    }
    $agent->setChatHistory($this->canvasAiChatHelper->getFilteredChatHistory($messages));
  }

  /**
   * Keeps the agent's history for the conversation's next turn.
   *
   * Called when a turn ended with the agent finished. Nothing is kept unless
   * the site opted in. A state still carrying a tool call the agent parked but
   * never ran cannot reach here: parking one leaves the agent unfinished, and
   * an unfinished turn stores its own state for the next hop instead. The
   * placement results in the kept history are cut to their success sentence,
   * as the rest of their text only serves the turn that already ended.
   *
   * @param array $prompt
   *   The decoded prompt.
   * @param string $agent_id
   *   The ID of the agent that ran the turn. Stored with the state, so a later
   *   turn running another agent does not resume this agent's history.
   * @param \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $agent
   *   The agent that ran the turn.
   *
   * @see \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper::determineSolvability()
   */
  private function storeConversationState(array $prompt, string $agent_id, AiAgentEntityWrapper $agent): void {
    $conversation_id = self::getConversationId($prompt);
    if ($conversation_id === '') {
      return;
    }
    if (!$this->keepsToolCallsInHistory()) {
      // Drop what an earlier turn may have kept while the setting was on, so
      // turning it off means nothing is resumed from then on.
      $this->canvasAiTempStore->deleteStoredConversationState($conversation_id);
      return;
    }
    $this->canvasAiTempStore->setStoredConversationState($conversation_id, $agent_id, self::trimKeptToolResults($agent->toArray()));
  }

  /**
   * Cuts each kept placement result down to its success sentence.
   *
   * The place_components and edit_components tools succeed with a verbose
   * message whose details — assigned UUIDs, predicted layout, applied
   * updates — only serve the turn the tool ran in, so the state a later
   * turn resumes keeps the success sentence alone. Failure results are
   * kept whole.
   *
   * @param array $state
   *   The agent state, as written by its ::toArray().
   *
   * @return array
   *   The state, with each trimmed tool message carrying the sentence only.
   */
  private static function trimKeptToolResults(array $state): array {
    foreach ($state['chat_history'] ?? [] as $index => $message) {
      if ($message['role'] !== 'tool') {
        continue;
      }
      foreach ([PlaceComponents::SUCCESS_MESSAGE, EditComponents::SUCCESS_MESSAGE] as $success_message) {
        if (\str_starts_with($message['text'], $success_message)) {
          $state['chat_history'][$index]['text'] = $success_message;
          break;
        }
      }
    }
    return $state;
  }

  /**
   * Drops the state of a turn that did not end cleanly.
   *
   * Both the turn's own state and the conversation's are removed: the next
   * turn seeds the agent from the client transcript, as the first turn of a
   * conversation does, rather than from a history that stops before the
   * failed turn.
   *
   * @param array $prompt
   *   The decoded prompt.
   */
  private function forgetTurn(array $prompt): void {
    $this->canvasAiTempStore->deleteStoredAgentState($prompt['request_id']);
    $conversation_id = self::getConversationId($prompt);
    if ($conversation_id !== '') {
      $this->canvasAiTempStore->deleteStoredConversationState($conversation_id);
    }
  }

  /**
   * Reads the conversation ID a request carries.
   *
   * The dev wizard sends the same value with every turn of one chat session
   * and a new one when the chat is cleared. A request without one runs its
   * turn on its own: nothing is resumed and nothing is kept.
   *
   * @param array $prompt
   *   The decoded prompt.
   *
   * @return string
   *   The conversation ID, or an empty string when the request sent none.
   */
  private static function getConversationId(array $prompt): string {
    $conversation_id = $prompt['conversation_id'] ?? '';
    return \is_string($conversation_id) ? $conversation_id : '';
  }

  /**
   * Whether finished turns keep their agent state for the conversation.
   *
   * Off by default: the kept tool calls and results are sent to the model on
   * every later turn, so the site opts in on the Agents & Tools form.
   *
   * @see \Drupal\canvas_dev_ai\Form\CanvasDevAiAgentSelectionForm
   */
  private function keepsToolCallsInHistory(): bool {
    return (bool) $this->config('canvas_dev_ai.settings')->get('keep_tool_calls_in_history');
  }

  /**
   * Explains why the agent gave up on the turn.
   *
   * The agent returns JOB_NOT_SOLVABLE when it ran out of loops or when the
   * provider call failed. Only the first case has a message worth showing;
   * anything else in the chat history is the narration of an earlier hop.
   *
   * @param \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $agent
   *   The agent that gave up.
   *
   * @return string
   *   The configured max-loops message, or a generic failure message.
   *
   * @see \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper::getMaxLoopsMessage()
   */
  private static function getNotSolvableMessage(AiAgentEntityWrapper $agent): string {
    $entity = $agent->getAiAgentEntity();
    if ($agent->toArray()['looped'] > (int) $entity->get('max_loops')) {
      $message = (string) $entity->get('max_loops_message');
      return $message !== '' ? $message : 'I was unable to fully answer your question within the allowed number of processing steps. Please try rephrasing or narrowing your question.';
    }
    return 'The request could not be completed. Please try again.';
  }

  /**
   * Provides extra context about the canvas UI to the active agent.
   *
   * Identical to generateVerboseContextForOrchestrator(), except it omits that
   * method's instructions forcing the model to generate a title and
   * description. Kept as a separate method so the orchestrator's existing use
   * of that method stays unaffected.
   *
   * @todo Replace generateVerboseContextForOrchestrator() with this method once the orchestrator no longer forces title and description generation, see https://git.drupalcode.org/project/canvas/-/work_items/3591777
   *
   * @param array $prompt
   *   The decoded prompt.
   *
   * @return string
   *   The context string; the caller wraps it into the user message.
   *
   * @see \Drupal\canvas_ai\CanvasAiPageBuilderHelper::generateVerboseContextForOrchestrator()
   * @see \Drupal\canvas_ai\CanvasAiPageBuilderHelper::formatMessageWithContext()
   */
  private static function getAgentExtraContext(array $prompt): string {
    if (!empty($prompt['selected_component'])) {
      return 'User is now in the code component editor, viewing a code component with id ' . $prompt['selected_component'] . '.' . self::NOT_PLACEABLE;
    }

    $entity_type = $prompt['entity_type'] ?? '';
    if ($entity_type === 'node') {
      return 'The user is currently working on a \'node\' entity.' . self::NOT_PLACEABLE;
    }

    if ($entity_type !== 'canvas_page') {
      return 'User has not created any entities.' . self::NOT_PLACEABLE;
    }

    $has_active_component = !empty($prompt['active_component_uuid'])
      && $prompt['active_component_uuid'] !== 'None';

    $base_message = 'The user is currently working on a canvas_page entity. ';
    $base_message .= $has_active_component
      ? 'User has selected a component in the page with uuid ' . $prompt['active_component_uuid'] . '. '
      : 'User has not selected any particular component from the page. ';

    $has_title = !empty($prompt['page_title']) && $prompt['page_title'] !== 'Untitled page';
    $base_message .= $has_title
      ? 'Page title: ' . $prompt['page_title'] . '. '
      : 'Page title is empty. ';
    $base_message .= !empty($prompt['page_description'])
      ? 'Page description: ' . $prompt['page_description']
      : 'Page description is empty.';

    return $base_message;
  }

  /**
   * Builds the values the agent's system prompt tokens are replaced with.
   *
   * @param array $prompt
   *   The decoded prompt.
   *
   * @return array
   *   The token contexts.
   *
   * @see \Drupal\canvas_ai\Hook\CanvasAiHooks::canvas_ai_tokens()
   */
  private function buildTokenContexts(array $prompt): array {
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
      // JSON-encode so the libraries render as readable data in the system
      // prompt token rather than the string "Array".
      'custom_libraries' => Json::encode($this->canvasAiPageBuilderHelper->getSupportedLibraries()),
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
        $structured_output = $tool->getStructuredOutput();
        // Combine canvas_page_data across every tool call in this hop.
        if (isset($response['canvas_page_data'], $structured_output['canvas_page_data'])) {
          $structured_output['canvas_page_data'] += $response['canvas_page_data'];
        }
        $response = array_merge($response, $structured_output);
      }
    }
    // Only the final hop carries a message: the agent's answer to the user.
    if ($should_continue) {
      $response['progress'] = $this->getAiProgress($job_id);
    }
    else {
      // ai_agents 1.3.5 widened solve() to also return a streaming iterator,
      // see https://www.drupal.org/project/ai_agents/issues/3538174. This
      // controller never calls ::setStreaming(), and its response is JSON, so
      // a stream here would mean the agent was configured elsewhere: fail
      // loudly rather than serialize an iterator into the response.
      $message = $agent->solve();
      if ($message instanceof StreamedChatMessageIteratorInterface) {
        throw new \LogicException('Canvas AI agents does not support streaming.');
      }
      $response['message'] = $message;
      $response['progress'] = $this->getAiProgressWithoutAnswer($job_id, $message);
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

}
