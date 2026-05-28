import type React from 'react';
import type { FieldError } from 'react-hook-form';

interface FieldErrorDisplayProps {
  blockingError?: {
    type: string;
    message: string;
  } | null;
  fieldState: {
    error?: FieldError;
  };
}

/**
 * Displays field errors in the Page Data and Component Instance forms.
 */
export const FieldErrorDisplay: React.FC<FieldErrorDisplayProps> = ({
  blockingError,
  fieldState,
}) => {
  const displayError = blockingError || fieldState.error;

  if (!displayError) return null;

  return (
    <span
      data-prop-message
      className={blockingError ? 'error-blocking' : 'error-display'}
    >
      {blockingError
        ? `${blockingError.type === 'error' ? '❌ ' : ''}${blockingError.message}`
        : fieldState.error?.message}
    </span>
  );
};
