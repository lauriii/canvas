import { Box } from '@radix-ui/themes';
import {
  FormElement,
  Label,
  Divider,
} from '@/features/code-editor/component-data/FormElement';
import { useAppDispatch } from '@/app/hooks';
import { updateSlot } from '@/features/code-editor/codeEditorSlice';
import type { CodeComponentSlot } from '@/types/CodeComponent';
import CodeMirror from '@uiw/react-codemirror';
import { javascript } from '@codemirror/lang-javascript';
import styles from './FormPropTypeSlot.module.css';

export default function FormPropTypeSlot({
  id,
  example,
}: Pick<CodeComponentSlot, 'id' | 'example'>) {
  const dispatch = useAppDispatch();

  return (
    <Box flexGrow="1">
      <Divider />
      <FormElement>
        <Label>Example HTML value</Label>
        <div className={styles.editorWrapper}>
          <CodeMirror
            data-testid={`slot-example-${id}`}
            value={example}
            height="100px"
            className={styles.codeMirror}
            extensions={[javascript({ jsx: true })]}
            onChange={(value) =>
              dispatch(
                updateSlot({
                  id,
                  updates: { example: value },
                }),
              )
            }
          />
        </div>
      </FormElement>
    </Box>
  );
}
