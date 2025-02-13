import { Box, TextField } from '@radix-ui/themes';
import {
  FormElement,
  Label,
  Divider,
} from '@/features/code-editor/component-data/FormElement';
import { useAppDispatch } from '@/app/hooks';
import { updateProp } from '@/features/code-editor/codeEditorSlice';
import type { CodeComponentProp } from '@/types/CodeComponent';

export default function FormPropTypeTextField({
  id,
  example,
  type = 'string',
}: Pick<CodeComponentProp, 'id' | 'example'> & {
  type?: 'string' | 'integer' | 'number';
}) {
  const dispatch = useAppDispatch();

  return (
    <Box flexGrow="1">
      <Divider />
      <FormElement>
        <Label htmlFor={`prop-example-${id}`}>Example value</Label>
        <TextField.Root
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
          onChange={(e) =>
            dispatch(
              updateProp({
                id,
                updates: { example: e.target.value },
              }),
            )
          }
        />
      </FormElement>
    </Box>
  );
}
