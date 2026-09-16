import { useEffect, useRef, useState } from 'react';
import { Flex, Select } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { updateProp } from '@/features/code-editor/codeEditorSlice';
import {
  Divider,
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';

import type {
  CodeComponentProp,
  CodeComponentPropDocumentExample,
  ValueMode,
} from '@/types/CodeComponent';

const NONE_VALUE = '_none_';

export const CONFIG_EXAMPLE_URLS = {
  pdf: '/ui/assets/documents/sample.pdf',
};

export const EXAMPLE_DOCUMENT_VALUES: Array<{
  value: keyof typeof CONFIG_EXAMPLE_URLS;
  label: string;
  example: CodeComponentPropDocumentExample;
}> = [
  {
    value: 'pdf',
    label: 'Sample document (PDF)',
    example: {
      src: CONFIG_EXAMPLE_URLS.pdf,
      filename: 'sample.pdf',
      mimetype: 'application/pdf',
    },
  },
];

const DEFAULT_DOCUMENT = EXAMPLE_DOCUMENT_VALUES[0].value;

export const parseExampleSrc = (src: string): string => {
  return (
    EXAMPLE_DOCUMENT_VALUES.find((doc) => src?.endsWith(doc.example.src))
      ?.value ?? DEFAULT_DOCUMENT
  );
};

export default function FormPropTypeDocument({
  id,
  example,
  required,
  allowMultiple = false,
  valueMode = 'unlimited',
  limitedCount = 1,
}: Pick<CodeComponentProp, 'id'> & {
  example:
    | CodeComponentPropDocumentExample
    | CodeComponentPropDocumentExample[];
  required: boolean;
  allowMultiple?: boolean;
  valueMode?: ValueMode;
  limitedCount?: number;
}) {
  // Handle both a single document and an array (when allowMultiple is
  // enabled). If it is an array, use the first element for display. The
  // example can also be an empty string right after the prop type is first
  // created or changed.
  const documentExample = Array.isArray(example)
    ? example[0] || { src: '' }
    : !example
      ? { src: '' }
      : example;

  const dispatch = useAppDispatch();
  const [selectedDocument, setSelectedDocument] = useState(
    documentExample.src ? parseExampleSrc(documentExample.src) : NONE_VALUE,
  );
  const [localRequired, setLocalRequired] = useState(required);

  // Compare UI-controlled values against their previous state before
  // dispatching, so the example written to the store does not re-trigger this
  // component's own effect in an infinite loop.
  // @see FormPropTypeVideo for the full explanation of this guard.
  const prevValuesRef = useRef({
    selectedDocument: documentExample.src
      ? parseExampleSrc(documentExample.src)
      : NONE_VALUE,
    allowMultiple,
    valueMode,
    limitedCount,
  });

  const isInitialMount = useRef(true);

  useEffect(() => {
    if (isInitialMount.current) {
      isInitialMount.current = false;
      // A required prop must always carry an example: initialize one when the
      // prop starts out empty.
      if (required && !documentExample.src) {
        setSelectedDocument(DEFAULT_DOCUMENT);
      }
    }
  }, [required, documentExample.src]);

  useEffect(() => {
    // Track changes to the required prop; drop the "None" selection when the
    // prop becomes required.
    setLocalRequired(required);
    if (
      required !== localRequired &&
      required &&
      selectedDocument === NONE_VALUE
    ) {
      setSelectedDocument(DEFAULT_DOCUMENT);
    }
  }, [required, localRequired, selectedDocument]);

  useEffect(() => {
    const hasChanged =
      prevValuesRef.current.selectedDocument !== selectedDocument ||
      prevValuesRef.current.allowMultiple !== allowMultiple ||
      prevValuesRef.current.valueMode !== valueMode ||
      prevValuesRef.current.limitedCount !== limitedCount;

    if (!hasChanged) {
      return;
    }

    prevValuesRef.current = {
      selectedDocument,
      allowMultiple,
      valueMode,
      limitedCount,
    };

    if (selectedDocument === NONE_VALUE) {
      dispatch(
        updateProp({
          id,
          updates: {
            example: allowMultiple ? [] : '',
          },
        }),
      );
      return;
    }

    const newDocumentExample =
      EXAMPLE_DOCUMENT_VALUES.find((doc) => doc.value === selectedDocument)
        ?.example ?? EXAMPLE_DOCUMENT_VALUES[0].example;

    if (allowMultiple) {
      const currentArray = Array.isArray(example) ? example : [];
      const newArray =
        valueMode === 'limited'
          ? Array.from({ length: limitedCount }, () => newDocumentExample)
          : currentArray.length > 0
            ? currentArray.map(() => newDocumentExample)
            : [newDocumentExample];
      dispatch(
        updateProp({
          id,
          updates: {
            example: newArray,
          },
        }),
      );
      return;
    }

    dispatch(
      updateProp({
        id,
        updates: {
          example: newDocumentExample,
        },
      }),
    );
  }, [
    selectedDocument,
    dispatch,
    id,
    example,
    allowMultiple,
    valueMode,
    limitedCount,
  ]);

  return (
    <Flex direction="column" gap="4" flexGrow="1">
      <Divider />
      <FormElement>
        <Label htmlFor={`prop-example-${id}`}>Example document</Label>
        <Select.Root
          value={selectedDocument}
          onValueChange={setSelectedDocument}
          size="1"
        >
          <Select.Trigger id={`prop-example-${id}`} />
          <Select.Content>
            {!required && (
              <Select.Item value={NONE_VALUE}>- None -</Select.Item>
            )}
            {EXAMPLE_DOCUMENT_VALUES.map((doc) => (
              <Select.Item key={doc.value} value={doc.value}>
                {doc.label}
              </Select.Item>
            ))}
          </Select.Content>
        </Select.Root>
      </FormElement>
    </Flex>
  );
}
