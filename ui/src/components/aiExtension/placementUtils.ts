/**
 * Helpers for applying the dev page builder's placement payloads.
 *
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\PlaceComponents
 */

import { isPropSourceComponent } from '@/types/Component';

import type { CanvasComponent, PropSourceComponent } from '@/types/Component';

/**
 * Whether a prop stores a reference to a media entity.
 */
export const isMediaBoundProp = (
  prop: PropSourceComponent['propSources'][string] | undefined,
): boolean =>
  (prop?.sourceTypeSettings?.storage as { target_type?: string } | undefined)
    ?.target_type === 'media';

/**
 * Whether a value can be stored on a media-bound prop.
 *
 * A media-bound prop holds the ID of a media entity; anything else the model
 * writes for it - the literal `{src, alt, width, height}` image shape, a URL
 * string, an empty value - cannot be stored and would leave the component
 * without an image.
 */
export const isMediaId = (value: unknown): boolean =>
  typeof value === 'number' ||
  (typeof value === 'string' && /^\d+$/.test(value));

/**
 * Drops values a media-bound prop cannot store from a component's fieldValues.
 *
 * The component then falls back to the example image from its definition. A
 * media ID is a valid value for a media-bound prop, so it is kept. Block
 * components have no propSources, so their fieldValues are returned unchanged.
 *
 * @todo Refactor this after https://www.drupal.org/i/3552000 is fixed.
 */
export function removeMediaFields<
  T extends { fieldValues?: Record<string, unknown> },
>(componentDef: CanvasComponent, componentInst: T): T {
  if (!isPropSourceComponent(componentDef)) {
    return componentInst;
  }
  const newFieldValues: Record<string, unknown> = {};
  const fieldValues = componentInst.fieldValues || {};
  for (const [key, value] of Object.entries(fieldValues)) {
    const prop = (componentDef as PropSourceComponent).propSources[key];
    if (!isMediaBoundProp(prop) || isMediaId(value)) {
      newFieldValues[key] = value;
    }
  }
  return {
    ...componentInst,
    fieldValues: newFieldValues,
  };
}

const NAMED_ENTITIES: Record<string, string> = {
  amp: '&',
  lt: '<',
  gt: '>',
  quot: '"',
  apos: "'",
  nbsp: ' ',
};

/**
 * Decodes the HTML entities the model sometimes writes in plain narration.
 *
 * The narration is rendered as escaped text, so an entity left in place would
 * be escaped a second time and show up literally (`&amp;`).
 */
export const decodeEntities = (text: string): string =>
  text.replace(
    /&(#x[0-9a-f]+|#[0-9]+|[a-z]+);/gi,
    (entity: string, body: string): string => {
      if (body.startsWith('#x') || body.startsWith('#X')) {
        return String.fromCodePoint(parseInt(body.slice(2), 16));
      }
      if (body.startsWith('#')) {
        return String.fromCodePoint(parseInt(body.slice(1), 10));
      }
      return NAMED_ENTITIES[body.toLowerCase()] ?? entity;
    },
  );

/**
 * How a turn ended, or that it is still running.
 *
 * A stopped turn is its own state rather than a finished one: the agent was
 * interrupted with work still to do, and the completed icon would claim it
 * finished that work.
 */
export type ProgressStatus = 'running' | 'finished' | 'stopped';

/**
 * The status row each state renders: its icon class and its label.
 */
const PROGRESS_STATUS_ROW: Record<
  ProgressStatus,
  { icon: string; label: string }
> = {
  running: { icon: 'aiLoader', label: 'Thinking' },
  finished: { icon: 'aiCompletedIcon', label: 'Thinking' },
  stopped: { icon: 'aiStoppedIcon', label: 'Stopped' },
};

/**
 * Builds the progress message: the agent's narration, above a status row that
 * spins until the turn is finished, and reports how it ended once it is.
 *
 * The narration is decoded, escaped and its newlines turned into line breaks.
 * The message is added as HTML rather than text so the backend leaves it out
 * of the chat history it sends to the model.
 *
 * @see \Drupal\canvas_ai\CanvasAiChatHelper::getFilteredChatHistory()
 */
export const progressToHtml = (
  progress: string,
  status: ProgressStatus,
): string => {
  const narration = decodeEntities(progress)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\n/g, '<br>');
  const { icon, label } = PROGRESS_STATUS_ROW[status];
  return `${narration}<div class="aiProgressStatus"><span class="${icon}"></span>${label}</div>`;
};
