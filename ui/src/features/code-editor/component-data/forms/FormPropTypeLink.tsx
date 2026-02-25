/* eslint-disable */
// @ts-nocheck
import { useEffect, useMemo, useRef, useState } from 'react';
import clsx from 'clsx';
import { Box, Flex, Select, Text, TextField } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { jsonSchemaValidate } from '@/components/form/formUtil';
import { updateProp } from '@/features/code-editor/codeEditorSlice';
import {
  Divider,
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import { REQUIRED_EXAMPLE_ERROR_MESSAGE } from '@/features/code-editor/component-data/Props';
import { useRequiredProp } from '@/features/code-editor/hooks/useRequiredProp';

import type { CodeComponentProp } from '@/types/CodeComponent';

import styles from '@/features/code-editor/component-data/FormElement.module.css';

const BASE_URL = window.location.origin;

const linkFormatMap = {
  'uri-reference': 'relative',
  uri: 'full',
};

const DEFAULT_LINK_EXAMPLES = {
  relative: 'example',
  full: 'https://example.com',
};

export default function FormPropTypeLink({
  id,
  example,
  format,
  isDisabled = false,
  required,
}: Pick<CodeComponentProp, 'id'> & {
  example: string;
  format: string;
  isDisabled?: boolean;
  required: boolean;
}) {
  const dispatch = useAppDispatch();
  const [linkType, setLinkType] = useState<'relative' | 'full'>(
    format ? linkFormatMap[format] : 'relative',
  );
  const [isExampleValueValid, setIsExampleValueValid] = useState(true);
  const { showRequiredError, setShowRequiredError } = useRequiredProp(
    required,
    example,
    () => {
      dispatch(
        updateProp({
          id,
          updates: {
            example: DEFAULT_LINK_EXAMPLES[linkType],
            format: linkType === 'full' ? 'uri' : 'uri-reference',
          },
        }),
      );
    },
    [dispatch, id, linkType],
  );

  return (
    <Flex direction="column" gap="4" flexGrow="1">
      <FormElement>
        <Label htmlFor={`prop-link-type-${id}`}>Link type</Label>
        <Select.Root
          value={linkType}
          onValueChange={(value: 'relative' | 'full') => {
            setIsExampleValueValid(true);
            setLinkType(value);
          }}
          size="1"
          disabled={isDisabled}
        >
          <Select.Trigger id={`prop-link-type-${id}`} />
          <Select.Content>
            <Select.Item value="relative">Relative path</Select.Item>
            <Select.Item value="full">Full URL</Select.Item>
          </Select.Content>
        </Select.Root>
      </FormElement>
      <Divider />
      <FormElement>
        <Label htmlFor={`prop-example-${id}`}>Example value</Label>
        <Flex align="center" gap="1" width="100%">
          {linkType === 'relative' && (
            <Flex flexShrink="0" align="center">
              <Text size="1" color="gray">
                {BASE_URL}/
              </Text>
            </Flex>
          )}
          <Box flexGrow="1">
            <TextField.Root
              autoComplete="off"
              id={`prop-example-${id}`}
              type="text"
              placeholder={
                linkType === 'relative' ? 'Enter a path' : 'Enter a URL'
              }
              value={example}
              size="1"
              onChange={(e) => {
                const input = e.target;
                setIsExampleValueValid(true); // Reset validation state on change
                // Show/hide error based on whether field is empty while required
                setShowRequiredError(required && !input.value);
                dispatch(
                  updateProp({
                    id,
                    updates: {
                      example: input.value,
                      format: linkType === 'full' ? 'uri' : 'uri-reference',
                    },
                  }),
                );
              }}
              onBlur={(e) => {
                if (e.target.value === '') {
                  setIsExampleValueValid(true);
                  return;
                }
                const [isValidValue, validate] = jsonSchemaValidate(
                  e.target.value,
                  {
                    type: 'string',
                    format: linkType === 'full' ? 'uri' : 'uri-reference',
                  },
                );
                setIsExampleValueValid(isValidValue);
              }}
              className={clsx({
                [styles.error]: !isExampleValueValid || showRequiredError,
              })}
              {...(!isExampleValueValid || showRequiredError
                ? { 'data-invalid-prop-value': true }
                : {})}
            />
          </Box>
        </Flex>
        {showRequiredError && (
          <Text color="red" size="1">
            {REQUIRED_EXAMPLE_ERROR_MESSAGE}
          </Text>
        )}
      </FormElement>
    </Flex>
  );
}
