import type * as React from 'react';
import type { FieldDataItem } from '@/types/Component';

/**
 * Read-only context describing the prop a client widget edits.
 *
 * Derived entirely from cached component metadata
 * (`GET /canvas/api/v0/config/component`), so widgets render without any
 * server round trip.
 */
export interface ClientWidgetContext {
  /** The prop name within the component's schema. */
  propName: string;
  /** The component config entity id (e.g. `sdc.canvas_test_sdc.heading`). */
  componentId: string;
  /** The component config entity version the instance is pinned to. */
  componentVersion: string;
  /** The prop's resolved JSON schema (already simplified for ajv). */
  jsonSchema: NonNullable<FieldDataItem['jsonSchema']> & {
    [key: string]: unknown;
  };
  /** Field storage/instance settings and cardinality for the prop's source. */
  sourceTypeSettings: NonNullable<FieldDataItem['sourceTypeSettings']>;
  /** Field cardinality: 1, a fixed N, or -1 for unlimited. */
  cardinality: number;
  required: boolean;
  /** The full metadata entry for the prop. */
  fieldData: FieldDataItem;
}

/**
 * The result of mapping a widget value to model values.
 *
 * `resolved` feeds the component render (and the client-side preview);
 * `source` is the stored source value. When `source` is omitted it is
 * derived from `resolved` (the common case for scalar widgets). Returning
 * `null` means "empty": the prop is removed from the model.
 */
export type WidgetCodecResult = {
  resolved: unknown;
  source?: unknown;
} | null;

/**
 * Maps between widget values and model values.
 *
 * Codecs replace the transforms registry one for one on the native path:
 * `toModel` must produce the same persisted model values that the
 * corresponding Drupal widget produced through its `canvas.transforms`
 * metadata. The server remains the validation authority.
 */
export interface WidgetCodec {
  toModel(
    widgetValue: unknown,
    context: ClientWidgetContext,
  ): WidgetCodecResult;
  fromModel(
    sourceValue: unknown,
    resolvedValue: unknown,
    context: ClientWidgetContext,
  ): unknown;
}

/**
 * Props every client widget component receives from the prop slot.
 *
 * Shared chrome (label, description, required indicator, error presentation)
 * is rendered by the slot, not by the widget.
 */
export interface ClientWidgetProps extends ClientWidgetContext {
  /** The current widget value (`codec.fromModel` output). */
  value: unknown;
  /** Report a new widget value; the slot validates and writes the model. */
  onChange: (widgetValue: unknown) => void;
  disabled: boolean;
  /** Validation error text to associate with the input, if any. */
  errors: string | null;
  /** `id` attribute for the primary input, for label association. */
  inputId: string;
  /**
   * Drupal-style input name (`canvas_component_props[<uuid>][<prop>]`), kept
   * for functional parity with the server-rendered form's inputs.
   */
  inputName: string;
  /** Read-only resolved values of the component's other props. */
  siblingValues: Record<string, unknown>;
}

/**
 * A client-side counterpart to a Drupal field widget plugin.
 */
export interface ClientWidgetDefinition {
  component: React.ComponentType<ClientWidgetProps>;
  codec: WidgetCodec;
  /**
   * Extra validation beyond the shared ajv pass. Returns an error message or
   * null. The shared ajv validation always runs regardless.
   */
  validate?: (
    widgetValue: unknown,
    context: ClientWidgetContext,
  ) => string | null;
  /**
   * Optional per-prop eligibility check: a widget can decline a prop it
   * cannot render natively (e.g. `options_select` without an enum), sending
   * that prop to the escape hatch.
   */
  isEligible?: (context: ClientWidgetContext) => boolean;
  /**
   * When true the widget renders its own multi-value UI (e.g. the media
   * widget's selection list, or a multi-select). Multi-value props whose
   * widget does not handle multiple values render via the escape hatch,
   * keeping the server-built widget's add/remove/reorder UX.
   */
  handlesMultipleValues?: boolean;
}
