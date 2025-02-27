import { Box, TextArea } from '@radix-ui/themes';
import {
  FormElement,
  Label,
  Divider,
} from '@/features/code-editor/component-data/FormElement';
import { useAppDispatch } from '@/app/hooks';
import { updateProp } from '@/features/code-editor/codeEditorSlice';
import type { CodeComponentProp } from '@/types/CodeComponent';

export default function FormPropTypeTextArea({
  id,
  example,
  isDisabled = false,
  _ref,
}: Pick<CodeComponentProp, 'id' | 'example' | '_ref'> & {
  isDisabled?: boolean;
}) {
  const dispatch = useAppDispatch();

  return (
    <Box flexGrow="1">
      <Divider />
      <FormElement>
        <Label htmlFor={`prop-example-${id}`}>Example value</Label>
        <TextArea
          id={`prop-example-${id}`}
          placeholder="Enter a text value"
          value={example as string}
          size="1"
          onChange={(e) =>
            dispatch(
              updateProp({
                id,
                updates: {
                  example: e.target.value,
                  _ref: _ref,
                },
              }),
            )
          }
          disabled={isDisabled}
        />
      </FormElement>
    </Box>
  );
}
