import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';
import { Link } from 'react-router-dom';
import * as Collapsible from '@radix-ui/react-collapsible';
import {
  ExclamationTriangleIcon,
  OpenInNewWindowIcon,
} from '@radix-ui/react-icons';
import {
  Box,
  Button,
  ChevronDownIcon,
  Flex,
  Heading,
  Separator,
  Text,
} from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import ChangeIcon from '@/components/review/changes/ChangeIcon';
import { getGroupLabel } from '@/components/review/utils';
import {
  applyEntityFormViolations,
  focusFormField,
  isViolationForOpenEntity,
  propertyPathToFormElementName,
} from '@/features/validation/entityFormViolations';

import type { ErrorResponse } from '@/services/pendingChangesApi';

import detailsStyle from '@/components/form/components/AccordionAndDetails.module.css';
import style from '@/components/review/ReviewErrors.module.css';

interface ReviewErrorsProps {
  errorState: ErrorResponse | undefined;
}

interface EntityError {
  detail: string;
  meta?: {
    label?: string;
    entity_type?: string;
    entity_id?: string | number;
  };
  entityLabel: string;
  source: {
    pointer?: string;
  };
}

interface ErrorsByEntity {
  [key: string]: EntityError[];
}

interface ErrorGroupProps {
  errorGroup: EntityError[];
}

const getEntityLabel = (
  error: ErrorResponse['errors'][number],
): string | null => {
  if (!error.meta?.label) {
    return null;
  }

  if (error.meta.entity_type === 'brand_kit') {
    return getGroupLabel(error.meta.entity_type);
  }

  return error.meta.label;
};

const getErrorPath = (error: EntityError): string | null => {
  if (!error.meta?.entity_type || !error.meta?.entity_id) {
    return null;
  }

  if (error.meta.entity_type === 'brand_kit') {
    return null;
  }

  let errorPath = `/editor/${error.meta.entity_type}/${error.meta.entity_id}`;
  let componentId = '';

  if (typeof error.source.pointer === 'string') {
    const sourcePointerParts = error.source.pointer.split('.');
    // Find the UUID in the pointer.
    componentId = sourcePointerParts
      .reverse()
      .filter((part) =>
        part.match(
          /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/,
        ),
      )?.[0];
  }

  if (componentId) {
    errorPath = `${errorPath}/component/${componentId}`;
  }

  return errorPath;
};

const ErrorGroup: React.FC<ErrorGroupProps> = ({ errorGroup }) => {
  const [isOpen, setIsOpen] = useState(true);
  const { entityType, entityId } = useParams();
  return (
    <Collapsible.Root
      data-testid="error-details"
      open={isOpen}
      onOpenChange={setIsOpen}
    >
      <Collapsible.Trigger className={style.collapseButton}>
        <Flex px="1" py="2" gap="2" align="center">
          <ChangeIcon
            entityType={errorGroup[0]?.meta?.entity_type || ''}
            entityId={errorGroup[0]?.meta?.entity_id || ''}
          />
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
        className={clsx(detailsStyle.content, detailsStyle.detailsContent)}
      >
        {errorGroup.map((error: EntityError, ix: number) => {
          const errorPath = getErrorPath(error);
          // For the entity open in the editor, a pointer that maps to a form
          // field becomes a jump-to-field action instead of a navigation link:
          // the field error is already shown on the sidebar form.
          const jumpFieldName = isViolationForOpenEntity(error.meta, {
            entityType,
            entityId,
          })
            ? propertyPathToFormElementName(error.source?.pointer ?? '')
            : null;

          return (
            <Flex px="5" py="1" gap="2" align="start" key={ix}>
              <Flex pt="2.5px">
                <ExclamationTriangleIcon color="red" />
              </Flex>
              <Text size="1" data-testid="publish-error-detail">
                {error.detail}{' '}
                {jumpFieldName ? (
                  <Button
                    size="1"
                    variant="ghost"
                    data-testid="publish-error-jump-to-field"
                    onClick={() => focusFormField(jumpFieldName)}
                  >
                    Jump to field
                  </Button>
                ) : (
                  errorPath && (
                    <Link data-testid="publish-error-link" to={errorPath}>
                      {
                        <OpenInNewWindowIcon
                          color="blue"
                          width="16"
                          height="16"
                          style={{ position: 'relative', top: '4px' }}
                        />
                      }
                    </Link>
                  )
                )}
              </Text>
            </Flex>
          );
        })}
      </Collapsible.Content>
    </Collapsible.Root>
  );
};

const ReviewErrors: React.FC<ReviewErrorsProps> = ({ errorState }) => {
  const [isOpen, setIsOpen] = useState(true);
  const dispatch = useAppDispatch();
  const { entityType, entityId } = useParams();
  const errors = errorState?.errors;

  // Surface publish errors that belong to the entity open in the editor as
  // blocking errors on its sidebar form fields. Dispatched from an effect,
  // not during render; unmappable pointers are skipped by the helper.
  useEffect(() => {
    if (!errors?.length) {
      return;
    }
    const violations = errors
      .filter((error) =>
        isViolationForOpenEntity(error.meta, { entityType, entityId }),
      )
      .map((error) => ({
        propertyPath: error.source?.pointer ?? '',
        message: error.detail,
      }));
    if (violations.length) {
      applyEntityFormViolations(dispatch, violations);
    }
  }, [dispatch, errors, entityType, entityId]);

  if (errorState?.errors?.length) {
    // Organize errors by entity label.
    const errorsByEntity: ErrorsByEntity = errorState.errors.reduce(
      (carry, error) => {
        const label = getEntityLabel(error);
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
        data-testid="canvas-review-publish-errors"
        maxWidth="360px"
        className={style.reviewErrors}
      >
        <Box px="4" pb="2" pt="5">
          <Collapsible.Root open={isOpen} onOpenChange={setIsOpen}>
            <Collapsible.Trigger className={style.collapseButton}>
              <Flex gap="2" mb="1" align="center">
                <ExclamationTriangleIcon color="red" />
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
        <Separator my="3" size="4" />
      </Box>
    );
  }
  return null;
};

export default ReviewErrors;
