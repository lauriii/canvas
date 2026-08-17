import { useState } from 'react';
import { Flex, Text, TextField } from '@radix-ui/themes';

import Dialog, { DialogFieldLabel } from '@/components/Dialog';

interface AddFrontendDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onAdd: (url: string) => Promise<void>;
  existingUrls: string[];
}

// Keep this in sync with the canvas_headless.settings config constraint. The
// API applies the same restriction before saving.
const FRONTEND_URL_PATTERN =
  /^https?:\/\/(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(?:\.(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}|(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?)(?::\d+)?(?:\/[^\s\\@?#]*[^/\s\\@?#])?$/i;

const hasDotPathSegment = (value: string) => {
  const path = value.match(/^https?:\/\/[^/]+(\/.*)?$/i)?.[1] ?? '';
  return path.split('/').some((segment) => /^(?:\.|%2e){1,2}$/i.test(segment));
};

const canonicalFrontendUrl = (value: string): string | null => {
  try {
    const parsed = new URL(value);
    const path = value.match(/^https?:\/\/[^/]+(.*)$/i)?.[1] ?? '';
    return `${parsed.origin}${path}`;
  } catch {
    return null;
  }
};

const validateFrontendUrl = (value: string, existingUrls: string[]): string => {
  try {
    new URL(value);
  } catch {
    return Drupal.t(
      'Enter a valid URL, including the protocol (for example, https://example.com).',
    );
  }
  if (!FRONTEND_URL_PATTERN.test(value) || hasDotPathSegment(value)) {
    return Drupal.t(
      'Enter an http:// or https:// URL without credentials, a query, a fragment, dot path segments, or a trailing slash.',
    );
  }
  const canonical = canonicalFrontendUrl(value);
  if (
    canonical !== null &&
    existingUrls.some((url) => canonicalFrontendUrl(url) === canonical)
  ) {
    return Drupal.t('This frontend is already in the list.');
  }
  return '';
};

const getSaveErrorMessage = (error: unknown): string => {
  if (typeof error === 'object' && error !== null && 'data' in error) {
    const { data } = error;
    if (
      typeof data === 'object' &&
      data !== null &&
      'error' in data &&
      typeof data.error === 'string'
    ) {
      return data.error;
    }
  }
  return Drupal.t('The frontend could not be saved. Please try again.');
};

const AddFrontendDialog = ({
  open,
  onOpenChange,
  onAdd,
  existingUrls,
}: AddFrontendDialogProps) => {
  const [url, setUrl] = useState('');
  const [validationError, setValidationError] = useState('');
  const [submitError, setSubmitError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const isSubmitDisabled = !url.trim() || !!validationError || isSubmitting;

  const handleOnChange = (newUrl: string) => {
    setUrl(newUrl);
    setSubmitError('');
    setValidationError(
      newUrl.trim() ? validateFrontendUrl(newUrl.trim(), existingUrls) : '',
    );
  };

  const handleOpenChange = (isOpen: boolean) => {
    if (!isOpen) {
      setUrl('');
      setValidationError('');
      setSubmitError('');
    }
    onOpenChange(isOpen);
  };

  const handleAdd = async () => {
    if (isSubmitDisabled) {
      return;
    }
    setIsSubmitting(true);
    setSubmitError('');
    try {
      await onAdd(url.trim());
      handleOpenChange(false);
    } catch (error) {
      setSubmitError(getSaveErrorMessage(error));
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      onOpenChange={handleOpenChange}
      title={Drupal.t('Add frontend')}
      width="440px"
      description={Drupal.t(
        'Enter the URL where your frontend app runs. This can be a local development server or a deployed environment.',
      )}
      footer={{
        cancelText: Drupal.t('Cancel'),
        confirmText: Drupal.t('Add frontend'),
        onConfirm: () => void handleAdd(),
        isConfirmDisabled: isSubmitDisabled,
        isConfirmLoading: isSubmitting,
      }}
      error={
        submitError
          ? { title: Drupal.t('Failed to add frontend'), message: submitError }
          : undefined
      }
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          void handleAdd();
        }}
      >
        <Flex direction="column" gap="2">
          <DialogFieldLabel htmlFor="frontendUrl">
            {Drupal.t('Frontend URL')}
          </DialogFieldLabel>
          <TextField.Root
            autoComplete="off"
            id="frontendUrl"
            type="url"
            value={url}
            onChange={(e) => handleOnChange(e.target.value)}
            placeholder="https://example.com"
            size="1"
          />
          {validationError && (
            <Text size="1" color="red" weight="medium">
              {validationError}
            </Text>
          )}
        </Flex>
      </form>
    </Dialog>
  );
};

export default AddFrontendDialog;
