import clsx from 'clsx';
import { Flex, Text, TextField } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { updateProp } from '@/features/code-editor/codeEditorSlice';
import {
  Divider,
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import {
  DEFAULT_EXAMPLES,
  REQUIRED_EXAMPLE_ERROR_MESSAGE,
} from '@/features/code-editor/component-data/Props';
import { useRequiredProp } from '@/features/code-editor/hooks/useRequiredProp';

import type { CodeComponentProp } from '@/types/CodeComponent';

import styles from '@/features/code-editor/component-data/FormElement.module.css';

export default function FormPropTypeTextField({
  id,
  example,
  type = 'string',
  isDisabled = false,
  required,
}: Pick<CodeComponentProp, 'id'> & {
  example: string;
  type?: 'string' | 'integer' | 'number';
  isDisabled?: boolean;
  required: boolean;
}) {
  const dispatch = useAppDispatch();
  const { showRequiredError, setShowRequiredError } = useRequiredProp(
    required,
    example,
    () => {
      // Map 'string' type to 'text' key in DEFAULT_EXAMPLES
      const exampleKey = type === 'string' ? 'text' : type;
      dispatch(
        updateProp({
          id,
          updates: { example: DEFAULT_EXAMPLES[exampleKey] },
        }),
      );
    },
    [dispatch, id, type],
  );

  return (
    <Flex direction="column" gap="4" flexGrow="1">
      <Divider />
      <FormElement>
        <Label htmlFor={`prop-example-${id}`}>Example value</Label>
        <TextField.Root
          autoComplete="off"
          id={`prop-example-${id}`}
          type={['integer', 'number'].includes(type) ? 'number' : 'text'}
          step={type === 'integer' ? 1 : undefined}
          placeholder={
            {
              string: 'Enter a text value',
              integer: 'Enter an integer',
              number: 'Enter a number',
            }[type]
          }
          value={example}
          size="1"
          onChange={(e) => {
            dispatch(
              updateProp({
                id,
                updates: { example: e.target.value },
              }),
            );
            // Show/hide error based on whether field is empty while required
            setShowRequiredError(required && !e.target.value);
          }}
          disabled={isDisabled}
          className={clsx({
            [styles.error]: showRequiredError,
          })}
          {...(showRequiredError ? { 'data-invalid-prop-value': true } : {})}
        />
        {showRequiredError && (
          <Text color="red" size="1">
            {REQUIRED_EXAMPLE_ERROR_MESSAGE}
          </Text>
        )}
      </FormElement>
    </Flex>
  );
}
