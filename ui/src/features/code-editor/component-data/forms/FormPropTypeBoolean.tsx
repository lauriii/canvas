import { Box, Switch } from '@radix-ui/themes';
import {
  FormElement,
  Label,
  Divider,
} from '@/features/code-editor/component-data/FormElement';
import { useAppDispatch } from '@/app/hooks';
import { updateProp } from '@/features/code-editor/codeEditorSlice';
import type { CodeComponentProp } from '@/types/CodeComponent';

export default function FormPropTypeBoolean({
  id,
  example,
}: Pick<CodeComponentProp, 'id' | 'example'>) {
  const dispatch = useAppDispatch();

  return (
    <Box flexGrow="1">
      <Divider />
      <FormElement>
        <Label htmlFor={`prop-example-${id}`}>Example value</Label>
        <Switch
          id={`prop-example-${id}`}
          checked={example === 'true'}
          onCheckedChange={(checked) =>
            dispatch(
              updateProp({ id, updates: { example: checked.toString() } }),
            )
          }
          size="1"
        />
      </FormElement>
    </Box>
  );
}
