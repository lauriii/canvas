import {
  Box,
  ChevronDownIcon,
  Flex,
  Heading,
  Separator,
  Text,
} from '@radix-ui/themes';
import * as Collapsible from '@radix-ui/react-collapsible';
import { ExclamationTriangleIcon, FileIcon } from '@radix-ui/react-icons';
import style from '@/components/review/ReviewErrors.module.css';
import detailsStyle from '@/components/form/components/AccordionAndDetails.module.css';
import type { ErrorResponse } from '@/services/pendingChangesApi';
import { useState } from 'react';
import clsx from 'clsx';

interface ReviewErrorsProps {
  errorState: ErrorResponse | undefined;
}

interface EntityError {
  detail: string;
  meta?: {
    label?: string;
  };
  entityLabel: string;
}

interface ErrorsByEntity {
  [key: string]: EntityError[];
}

interface ErrorGroupProps {
  errorGroup: EntityError[];
}

const ErrorGroup: React.FC<ErrorGroupProps> = ({ errorGroup }) => {
  const [isOpen, setIsOpen] = useState(true);

  return (
    <Collapsible.Root open={isOpen} onOpenChange={setIsOpen}>
      <Collapsible.Trigger className={style.collapseButton}>
        <Flex px="1" py="2" gap="2" align="center">
          <FileIcon width="12" height="12" className={style.labelIcon} />
          <Heading as="h4" size="1" weight="regular">
            {errorGroup[0].entityLabel}
          </Heading>
          <ChevronDownIcon
            className={clsx(style.chevron, !isOpen && style.closed)}
            aria-hidden
          />
        </Flex>
      </Collapsible.Trigger>

      <Collapsible.Content
        forceMount
        className={clsx(detailsStyle.content, detailsStyle.detailsContent)}
      >
        {errorGroup.map((error: EntityError, ix: number) => (
          <Flex px="5" py="1" gap="2" align="start" key={ix}>
            <Flex pt="2.5px">
              <ExclamationTriangleIcon color="red" width="12" height="12" />
            </Flex>
            <Text size="1" data-testid="publish-error-detail">
              {error.detail}
            </Text>
          </Flex>
        ))}
      </Collapsible.Content>
    </Collapsible.Root>
  );
};

const ReviewErrors: React.FC<ReviewErrorsProps> = ({ errorState }) => {
  const [isOpen, setIsOpen] = useState(true);

  if (errorState?.errors?.length) {
    // Organize errors by entity label.
    const errorsByEntity: ErrorsByEntity = errorState.errors.reduce(
      (carry, error) => {
        const label = error.meta?.label;
        if (label) {
          if (!carry[label]) {
            carry[label] = [];
          }
          carry[label].push({
            ...error,
            entityLabel: label,
          });
        }
        return carry;
      },
      {} as ErrorsByEntity,
    );
    return (
      <Box
        data-testid="xb-review-publish-errors"
        maxWidth="360px"
        className={style.reviewErrors}
      >
        <Separator my="3" size="4" />
        <Box px="4" pb="2">
          <Collapsible.Root open={isOpen} onOpenChange={setIsOpen}>
            <Collapsible.Trigger className={style.collapseButton}>
              <Flex gap="2" mb="1" align="center">
                <ExclamationTriangleIcon color="red" width="12" height="12" />
                <Heading as="h3" size="1" mb="0">
                  {errorState.errors.length} Error
                  {errorState.errors.length > 1 ? 's' : ''}
                </Heading>
                <ChevronDownIcon
                  className={clsx(style.chevron, !isOpen && style.closed)}
                />
              </Flex>
            </Collapsible.Trigger>

            <Collapsible.Content
              forceMount
              className={clsx(
                detailsStyle.content,
                detailsStyle.detailsContent,
              )}
            >
              {Object.values(errorsByEntity).map(
                (errorGroup: EntityError[], ix: number) => (
                  <ErrorGroup key={ix} errorGroup={errorGroup} />
                ),
              )}
            </Collapsible.Content>
          </Collapsible.Root>
        </Box>
      </Box>
    );
  }
  return null;
};

export default ReviewErrors;
