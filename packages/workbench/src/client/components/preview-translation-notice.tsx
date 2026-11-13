import { CircleAlertIcon } from 'lucide-react';
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from '@wb/client/components/ui/alert';
import { formatTranslationIssue } from '@wb/lib/preview-translation';

import type { PreviewTranslation } from '@wb/lib/preview-translation';

/**
 * Tells why the preview shows source strings rather than translations.
 */
export function PreviewTranslationNotice({
  error,
  unknownComponents,
}: {
  /** Why the languages or the translations are not available. */
  error: string | null;
  unknownComponents: PreviewTranslation['unknownComponents'];
}) {
  if (!error && unknownComponents.length === 0) {
    return null;
  }
  return (
    <Alert className="max-w-3xl">
      <CircleAlertIcon />
      <AlertTitle>Some strings are shown untranslated.</AlertTitle>
      <AlertDescription className="whitespace-pre-wrap font-mono text-[11px]">
        {[
          ...(error ? [error] : []),
          ...unknownComponents.map(
            ({ name, issues }) =>
              `The strings of '${name}' are unknown${issues.length > 0 ? `: ${issues.map(formatTranslationIssue).join(' ')}` : '.'}`,
          ),
        ].join('\n')}
      </AlertDescription>
    </Alert>
  );
}
