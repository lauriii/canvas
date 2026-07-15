import { useRef, useState } from 'react';
import { Flex } from '@radix-ui/themes';

import CKEditorHost from '@/components/form/components/CKEditorHost';
import TextArea from '@/components/form/components/TextArea';
import TextField from '@/components/form/components/TextField';
import { useGetTextEditorSettingsQuery } from '@/services/textEditorSettings';
import { getTextFormats } from '@/utils/drupal-globals';

import type { TextFormatSummary } from '@/utils/drupal-globals';
import type {
  ClientWidgetContext,
  ClientWidgetDefinition,
  ClientWidgetProps,
  WidgetCodec,
} from '../types';

/**
 * Native counterparts of the formatted text Drupal widgets: `text_textarea`,
 * `text_textarea_with_summary`, and `text_textfield`.
 *
 * The editing UI derives entirely from the text format configuration: when
 * the active format has a CKEditor 5 editor configured, the shared CKEditor
 * host mounts with that format's configured toolbar, plugins, and settings
 * (fetched with the editor's asset libraries once per session); a format
 * without an editor renders the plain input. Editors attach to textareas
 * only, matching `\Drupal\editor\Element::preRenderTextFormat()`, so
 * `text_textfield` never mounts an editor. Any format configured with a
 * non-CKEditor-5 editor plugin sends the prop to the escape hatch, where
 * that editor's attach pipeline works unchanged.
 *
 * The raw editor markup is the optimistic resolved value; the server's
 * evaluation of the format's filters remains authoritative on the patch
 * echo, so the model write carries a `source` value and skips client-side
 * schema validation, like the media widgets.
 */

interface FormattedTextValue {
  value: string;
  format: string;
}

/**
 * The formats permitted for a prop: the user's permitted formats intersected
 * with the prop's stored `allowed_formats` instance settings. An absent or
 * empty `allowed_formats` list means every permitted format, matching the
 * `text_format` element.
 */
const allowedFormats = (context: ClientWidgetContext): TextFormatSummary[] => {
  const permitted = getTextFormats();
  const allowed = (
    context.sourceTypeSettings.instance as
      | { allowed_formats?: string[] }
      | undefined
  )?.allowed_formats;
  if (!Array.isArray(allowed) || allowed.length === 0) {
    return permitted;
  }
  return permitted.filter((format) => allowed.includes(format.id));
};

const defaultFormatId = (context: ClientWidgetContext): string =>
  allowedFormats(context)[0]?.id ?? '';

const asFormattedTextValue = (
  value: unknown,
  context: ClientWidgetContext,
): FormattedTextValue => {
  const candidate = value as Partial<FormattedTextValue> | undefined;
  return {
    value: typeof candidate?.value === 'string' ? candidate.value : '',
    format:
      typeof candidate?.format === 'string' && candidate.format !== ''
        ? candidate.format
        : defaultFormatId(context),
  };
};

const formattedTextCodec: WidgetCodec = {
  toModel(widgetValue, context) {
    const { value, format } = asFormattedTextValue(widgetValue, context);
    if (value === '') {
      return null;
    }
    // Source shape parity with the Drupal-widget path: `{value, format}`.
    // The raw markup is only the optimistic resolved value; the server
    // echoes the filter-processed markup.
    return { resolved: value, source: { value, format } };
  },
  fromModel(sourceValue, resolvedValue, context) {
    if (sourceValue && typeof sourceValue === 'object') {
      const source = sourceValue as { value?: string; format?: string };
      return asFormattedTextValue(
        { value: source.value, format: source.format },
        context,
      );
    }
    // Defensive: tolerate a bare-string source, and fall back to the
    // resolved (processed) markup when no source value exists.
    const raw =
      typeof sourceValue === 'string'
        ? sourceValue
        : typeof resolvedValue === 'string'
          ? resolvedValue
          : '';
    return asFormattedTextValue({ value: raw }, context);
  },
};

/**
 * Native only when the prop has at least one usable format and every one of
 * them is editorless or CKEditor 5. Contrib editor plugins go to the escape
 * hatch.
 */
const isEligibleForNativeText = (context: ClientWidgetContext): boolean => {
  const formats = allowedFormats(context);
  return (
    formats.length > 0 &&
    formats.every(
      (format) => format.editor === null || format.editor === 'ckeditor5',
    )
  );
};

interface FormatSelectProps {
  formats: TextFormatSummary[];
  value: string;
  onChange: (formatId: string) => void;
  inputId: string;
  disabled: boolean;
}

// The select element used to choose the text format, at parity with the
// `text_format` element (only shown when there is a choice to make).
const FormatSelect = ({
  formats,
  value,
  onChange,
  inputId,
  disabled,
}: FormatSelectProps) => {
  if (formats.length < 2) {
    return null;
  }
  const selectId = `${inputId}--format`;
  return (
    <Flex gap="1" align="center" my="2">
      <label htmlFor={selectId}>Text format</label>
      {/* Using a native select instead of Radix requires less plumbing. */}
      <select
        id={selectId}
        data-testid="text-format-select"
        value={value}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value)}
      >
        {formats.map((format) => (
          <option key={format.id} value={format.id}>
            {format.label}
          </option>
        ))}
      </select>
    </Flex>
  );
};

const FormattedTextAreaWidget = (props: ClientWidgetProps) => {
  const { value, onChange, disabled, required, inputId, inputName, errors } =
    props;
  const current = asFormattedTextValue(value, props);
  const formats = allowedFormats(props);
  const activeFormat =
    formats.find((format) => format.id === current.format) ?? formats[0];
  const needsEditor = formats.some((format) => format.editor === 'ckeditor5');
  // One session-cached fetch delivers the editor settings and asset
  // libraries for every permitted format; when it reports success the
  // CKEditor globals are on the page.
  const { data, isSuccess } = useGetTextEditorSettingsQuery(undefined, {
    skip: !needsEditor,
  });
  const editorSettings = isSuccess
    ? data?.editor?.formats?.[activeFormat?.id ?? '']?.editorSettings
    : undefined;

  // CKEditor is uncontrolled after mount: remount it (via key) when the
  // model value changes externally (undo/redo, selection change), not on the
  // widget's own edits round-tripping through the slot.
  const lastEmitted = useRef<string | null>(null);
  const [editorEpoch, setEditorEpoch] = useState(0);
  const [prevExternalValue, setPrevExternalValue] = useState(current.value);
  if (current.value !== prevExternalValue) {
    setPrevExternalValue(current.value);
    if (current.value !== lastEmitted.current) {
      setEditorEpoch((epoch) => epoch + 1);
    }
  }

  const handleMarkupChange = (markup: string) => {
    lastEmitted.current = markup;
    onChange({ value: markup, format: current.format });
  };

  const useEditor = activeFormat?.editor === 'ckeditor5';
  return (
    <>
      {useEditor && editorSettings && (
        <CKEditorHost
          key={`${activeFormat?.id}--${editorEpoch}`}
          editorSettings={editorSettings}
          initialValue={current.value}
          onChange={handleMarkupChange}
          disabled={disabled}
          minRows={5}
        />
      )}
      {useEditor && !editorSettings && (
        // Placeholder while the (session-cached) editor settings and assets
        // load: falling back to the escape hatch here would issue the form
        // request this widget exists to avoid.
        <TextArea
          value={current.value}
          attributes={{
            id: inputId,
            name: inputName,
            rows: 5,
            disabled: true,
            'aria-busy': 'true',
          }}
        />
      )}
      {!useEditor && (
        <TextArea
          value={current.value}
          attributes={{
            id: inputId,
            name: inputName,
            rows: 5,
            required,
            disabled,
            'aria-invalid': errors ? 'true' : undefined,
            onChange: (e: React.ChangeEvent<HTMLTextAreaElement>) =>
              handleMarkupChange(e.target.value),
          }}
        />
      )}
      <FormatSelect
        formats={formats}
        value={activeFormat?.id ?? current.format}
        onChange={(formatId) =>
          onChange({ value: current.value, format: formatId })
        }
        inputId={inputId}
        disabled={disabled}
      />
    </>
  );
};

// Editors attach to textareas only, so the single-line formatted text widget
// is a plain input plus the format select, matching the server-built form.
const FormattedTextFieldWidget = (props: ClientWidgetProps) => {
  const { value, onChange, disabled, required, inputId, inputName, errors } =
    props;
  const current = asFormattedTextValue(value, props);
  const formats = allowedFormats(props);
  return (
    <>
      <TextField
        attributes={{
          id: inputId,
          name: inputName,
          type: 'text',
          value: current.value,
          required,
          disabled,
          'aria-invalid': errors ? 'true' : undefined,
          onChange: (e: React.ChangeEvent<HTMLInputElement>) =>
            onChange({ value: e.target.value, format: current.format }),
        }}
      />
      <FormatSelect
        formats={formats}
        value={current.format}
        onChange={(formatId) =>
          onChange({ value: current.value, format: formatId })
        }
        inputId={inputId}
        disabled={disabled}
      />
    </>
  );
};

export const formattedTextAreaWidget: ClientWidgetDefinition = {
  component: FormattedTextAreaWidget,
  codec: formattedTextCodec,
  isEligible: isEligibleForNativeText,
};

export const formattedTextFieldWidget: ClientWidgetDefinition = {
  component: FormattedTextFieldWidget,
  codec: formattedTextCodec,
  isEligible: isEligibleForNativeText,
};
