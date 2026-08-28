import { CircleAlertIcon } from 'lucide-react';
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from '@wb/client/components/ui/alert';

export function PreviewErrorAlert({
  message,
  title = 'Preview failed to render.',
}: {
  message: string;
  title?: string;
}) {
  return (
    <Alert className="max-w-3xl" variant="destructive">
      <CircleAlertIcon />
      <AlertTitle>{title}</AlertTitle>
      <AlertDescription className="whitespace-pre-wrap font-mono text-[11px]">
        {message}
      </AlertDescription>
    </Alert>
  );
}
