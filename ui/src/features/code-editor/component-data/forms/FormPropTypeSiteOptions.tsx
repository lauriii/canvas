import { isEqual } from 'lodash';
import { Select, Text } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { updateProp } from '@/features/code-editor/codeEditorSlice';
import {
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import { useListOptionsSourcesQuery } from '@/services/optionsSourcesApi';

import type { OptionsSourceSuggestion } from '@/services/optionsSourcesApi';
import type { CodeComponentOptionsSource } from '@/types/CodeComponent';

/**
 * Picks where a "List: from site" prop gets its options, such as a vocabulary.
 *
 * @see \Drupal\canvas\OptionsSource\OptionsSourceInterface
 */
export default function FormPropTypeSiteOptions({
  id,
  options,
  isDisabled = false,
}: {
  id: string;
  options: CodeComponentOptionsSource | undefined;
  isDisabled?: boolean;
}) {
  const dispatch = useAppDispatch();
  const {
    data: suggestions = [],
    isLoading,
    error,
  } = useListOptionsSourcesQuery();
  const selected = suggestions.findIndex((suggestion) =>
    isEqual(suggestion.options, options),
  );
  const groups = suggestions.reduce<
    Record<string, [number, OptionsSourceSuggestion][]>
  >((grouped, suggestion, index) => {
    (grouped[suggestion.group] ??= []).push([index, suggestion]);
    return grouped;
  }, {});

  return (
    <FormElement>
      <Label htmlFor={`prop-options-source-${id}`}>Options from</Label>
      <Select.Root
        size="1"
        value={selected === -1 ? undefined : String(selected)}
        disabled={isDisabled || isLoading}
        onValueChange={(index) =>
          dispatch(
            updateProp({
              id,
              updates: {
                'x-canvas-options': suggestions[Number(index)].options,
              },
            }),
          )
        }
      >
        <Select.Trigger
          id={`prop-options-source-${id}`}
          placeholder={
            // Set outside the code editor, e.g. by a module's own source.
            options && selected === -1 && !isLoading
              ? `Custom: ${options.source}`
              : 'Choose where the options come from'
          }
        />
        <Select.Content>
          {Object.entries(groups).map(([group, items]) => (
            <Select.Group key={group}>
              <Select.Label>{group}</Select.Label>
              {items.map(([index, suggestion]) => (
                <Select.Item key={index} value={String(index)}>
                  {suggestion.label}
                </Select.Item>
              ))}
            </Select.Group>
          ))}
        </Select.Content>
      </Select.Root>
      {error ? (
        <Text size="1" color="red">
          The options sources could not be loaded.
        </Text>
      ) : !options && !isLoading ? (
        <Text size="1" color="red">
          Choose where the options come from. Until then, this prop is plain
          text.
        </Text>
      ) : (
        <Text size="1" color="gray">
          The component receives the ID of the chosen option as text, such as
          "12" or "main". These props are optional, because the site may have no
          options to choose from.
        </Text>
      )}
    </FormElement>
  );
}
