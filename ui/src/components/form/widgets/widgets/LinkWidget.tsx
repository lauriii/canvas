import { Flex, Text } from '@radix-ui/themes';

import TextField from '@/components/form/components/TextField';
import { resolveEntityUri } from '@/utils/transforms';

import type {
  ClientWidgetContext,
  ClientWidgetDefinition,
  ClientWidgetProps,
} from '../types';

/**
 * Native counterpart of the Drupal `link_default` widget.
 *
 * The codec mirrors the `link` transform: with the title sub-field disabled
 * the resolved value is the uri string alone; with the title enabled it is a
 * `{uri, title}` object. Autocomplete-style input (`Label (123)`) resolves to
 * an `entity:node/123` uri either way.
 */

// ponytail: entity autocomplete suggestions for link URIs can be layered on
// later via the canvas autocomplete endpoint; a plain text input already
// satisfies the stored value contract.

interface LinkWidgetValue {
  uri: string;
  title: string;
}

/**
 * Reads the instance's title sub-field setting. `1` corresponds to
 * `DRUPAL_OPTIONAL` and `2` to `DRUPAL_REQUIRED` on the server side.
 *
 * @see DRUPAL_DISABLED
 * @see DRUPAL_OPTIONAL
 * @see DRUPAL_REQUIRED
 */
const getTitleMode = (context: ClientWidgetContext): 0 | 1 | 2 => {
  const instance = context.sourceTypeSettings.instance as
    | { title?: 0 | 1 | 2 }
    | undefined;
  return instance?.title ?? 0;
};

const isTitleEnabled = (context: ClientWidgetContext): boolean =>
  [1, 2].includes(getTitleMode(context));

const asLinkValue = (value: unknown): LinkWidgetValue => {
  const record = (value ?? {}) as Partial<LinkWidgetValue>;
  return { uri: record.uri ?? '', title: record.title ?? '' };
};

const LinkDefaultWidget = (props: ClientWidgetProps) => {
  const { value, onChange, disabled, required, errors, inputId, inputName } =
    props;
  const linkValue = asLinkValue(value);
  const titleMode = getTitleMode(props);
  return (
    <Flex direction="column" gap="2">
      {/* Internal paths and entity uris are valid link values, so the input
          deliberately uses `type="text"` rather than `type="url"`. */}
      <TextField
        attributes={{
          id: inputId,
          name: `${inputName}[0][uri]`,
          type: 'text',
          value: linkValue.uri,
          required,
          disabled,
          'aria-invalid': errors ? 'true' : undefined,
          onChange: (e: React.ChangeEvent<HTMLInputElement>) =>
            onChange({ ...linkValue, uri: e.target.value }),
        }}
      />
      {titleMode !== 0 && (
        <Flex direction="column" gap="1">
          <Text size="1" as="label" htmlFor={`${inputId}--title`}>
            Title
          </Text>
          <TextField
            attributes={{
              id: `${inputId}--title`,
              name: `${inputName}[0][title]`,
              type: 'text',
              value: linkValue.title,
              required: titleMode === 2,
              disabled,
              onChange: (e: React.ChangeEvent<HTMLInputElement>) =>
                onChange({ ...linkValue, title: e.target.value }),
            }}
          />
        </Flex>
      )}
    </Flex>
  );
};

export const linkDefaultWidget: ClientWidgetDefinition = {
  component: LinkDefaultWidget,
  codec: {
    toModel(widgetValue, context) {
      const linkValue = asLinkValue(widgetValue);
      // A link without a uri is empty; a title alone cannot be stored.
      if (!linkValue.uri) {
        return null;
      }
      const uri = resolveEntityUri(linkValue.uri);
      if (!isTitleEnabled(context)) {
        return { resolved: uri };
      }
      return { resolved: { uri, title: linkValue.title } };
    },
    fromModel(sourceValue, resolvedValue) {
      const modelValue = resolvedValue ?? sourceValue;
      if (typeof modelValue === 'string') {
        return { uri: modelValue, title: '' };
      }
      if (typeof modelValue === 'object' && modelValue !== null) {
        const record = modelValue as { uri?: unknown; title?: unknown };
        return {
          uri: typeof record.uri === 'string' ? record.uri : '',
          title: typeof record.title === 'string' ? record.title : '',
        };
      }
      return { uri: '', title: '' };
    },
  },
};
