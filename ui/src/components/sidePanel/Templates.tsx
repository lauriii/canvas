import { useEffect, useState } from 'react';
import { Form } from 'radix-ui';
import { PlusIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Select, Text } from '@radix-ui/themes';

import Dialog from '@/components/Dialog';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import {
  AccordionDetails,
  AccordionRoot,
} from '@/components/form/components/Accordion';
import TemplateList from '@/components/list/TemplateList';
import PermissionCheck from '@/components/PermissionCheck';
import {
  useCreateContentTemplateMutation,
  useGetViewModesQuery,
} from '@/services/componentAndLayout';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type { ModeData } from '@/services/componentAndLayout';

const canvasSettings = getCanvasSettings();

const Templates = () => {
  const [openEntityTypes, setOpenEntityTypes] = useState<string[]>([
    'content-types',
  ]);

  const onClickHandler = (categoryName: string) => {
    setOpenEntityTypes((state) => {
      if (!state.includes(categoryName)) {
        return [...state, categoryName];
      }
      return state.filter((stateName) => stateName !== categoryName);
    });
  };

  return (
    <>
      <PermissionCheck hasPermission="contentTemplates">
        <Flex direction="column" mb="4">
          <AddTemplateButton />
        </Flex>
      </PermissionCheck>
      <AccordionRoot value={openEntityTypes}>
        <AccordionDetails
          value="content-types"
          title="Content types"
          onTriggerClick={() => onClickHandler('content-types')}
        >
          <ErrorBoundary title="An unexpected error has occurred while fetching templates.">
            <TemplateList />
          </ErrorBoundary>
        </AccordionDetails>
      </AccordionRoot>
    </>
  );
};

const AddTemplateButton = () => {
  const [isOpen, setIsOpen] = useState(false);

  return (
    <>
      <Button
        data-testid="big-add-template-button"
        variant="soft"
        size="1"
        onClick={() => setIsOpen(true)}
      >
        <PlusIcon />
        Add new template
      </Button>
      {isOpen && <AddTemplateDialog isOpen={isOpen} setIsOpen={setIsOpen} />}
    </>
  );
};

interface TemplateDialogProps {
  isOpen: boolean;
  setIsOpen: (isOpen: boolean) => void;
  contentType?: string | null;
  entityType?: string | null;
}

const AddTemplateDialog = ({
  isOpen,
  setIsOpen,
  contentType = null,
  entityType = 'node',
}: TemplateDialogProps) => {
  const [selectedContentType, setSelectedContentType] = useState<string | null>(
    contentType,
  );
  const [selectedEntityType, setSelectedEntityType] = useState<string | null>(
    entityType,
  );
  const [selectedViewMode, setSelectedViewMode] = useState<string | null>();

  const [createTemplate, { reset, isSuccess, isError, error }] =
    useCreateContentTemplateMutation();
  const {
    data,
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    isLoading,
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    isFetching,
    error: getViewModeError,
  } = useGetViewModesQuery();

  useEffect(() => {
    if (isError || getViewModeError) {
      console.error('Failed to add template:', error || getViewModeError);
    }
  }, [isError, error, getViewModeError]);

  useEffect(() => {
    if (isSuccess) {
      setIsOpen(false);
      setSelectedContentType('');
      setSelectedViewMode(null);
      setSelectedEntityType('null');
      reset();
    }
  }, [isSuccess, reset, setIsOpen]);

  const entityBundleLabels =
    typeof entityType === 'string' &&
    canvasSettings?.entityTypeLabels?.[entityType];
  const bundleLabelType = typeof entityBundleLabels;
  return (
    <Dialog
      open={isOpen}
      title="Add new template"
      headerClose={true}
      footer={{ hidden: true }}
      onOpenChange={(open) => setIsOpen(open)}
    >
      <Box
        py="3"
        px="2"
        m="0"
        data-testid="xb-manage-library-add-template-content"
      >
        {isOpen && (
          <Form.Root
            onSubmit={async (e) => {
              e.preventDefault();
              createTemplate({
                entityType: selectedEntityType,
                bundle: selectedContentType,
                viewMode: selectedViewMode,
              });
            }}
            id="add-new-template-form"
          >
            {!contentType && (
              <Box>
                <Box width="100%">
                  <Text as="label" htmlFor="content-type">
                    Content Type
                  </Text>
                </Box>
                <Select.Root
                  name="content-type"
                  required
                  value={selectedContentType || undefined}
                  onValueChange={(value) =>
                    setSelectedContentType(value as string)
                  }
                >
                  <Select.Trigger
                    id="content-type"
                    placeholder="Select content type…"
                    style={{ width: '100%' }}
                  />
                  <Select.Content>
                    {bundleLabelType === 'object' &&
                      Object.entries(entityBundleLabels).map(
                        ([type, label]) => (
                          <Select.Item key={type} value={type}>
                            {String(label)}
                          </Select.Item>
                        ),
                      )}
                  </Select.Content>
                </Select.Root>
              </Box>
            )}

            <Box>
              <Box width="100%">
                <Text as="label" htmlFor="template name">
                  Template name
                </Text>
              </Box>
              <Select.Root
                name="template name"
                required
                disabled={!selectedContentType}
                onValueChange={(value) => setSelectedViewMode(value)}
              >
                <Select.Trigger
                  id="content-type"
                  placeholder="Existing templates…"
                  style={{ width: '100%' }}
                  disabled={!selectedContentType}
                />
                <Select.Content>
                  <Select.Group>
                    {!!selectedEntityType &&
                      selectedContentType &&
                      Object.entries(
                        data?.[selectedEntityType]?.[selectedContentType] || {},
                      ).map(([mode, modeData]) => {
                        const typedModeData = modeData as unknown as ModeData;
                        if (mode === 'full') {
                          return (
                            <Select.Item
                              key={mode}
                              value={mode}
                              disabled={
                                mode !== 'full' || typedModeData.hasTemplate
                              }
                            >
                              {typedModeData.label}{' '}
                              {typedModeData.hasTemplate &&
                                '(template already exists)'}{' '}
                              {mode !== 'full' && '(support coming soon)'}
                            </Select.Item>
                          );
                        }
                        return null;
                      })}
                  </Select.Group>
                </Select.Content>
              </Select.Root>
            </Box>

            {error && 'data' in error ? (
              <Text
                size="1"
                color="red"
                weight="medium"
                dangerouslySetInnerHTML={{
                  __html:
                    'errors' in (error.data as any)
                      ? (error.data as any).errors
                          .map((err: { detail: string }) => err.detail)
                          .join(`\n`)
                      : 'An error occurred',
                }}
              ></Text>
            ) : error ? (
              <Text size="1" color="red" weight="medium">
                {'error' in error
                  ? error.error
                  : 'Failed to create template. Your JS console may have more information.'}
              </Text>
            ) : null}
            <Form.Submit asChild>
              <Button
                data-testid="canvas-create-template-submit"
                variant="solid"
                size="1"
                mt="2"
              >
                Add new template
              </Button>
            </Form.Submit>
          </Form.Root>
        )}
      </Box>
    </Dialog>
  );
};

export default Templates;
