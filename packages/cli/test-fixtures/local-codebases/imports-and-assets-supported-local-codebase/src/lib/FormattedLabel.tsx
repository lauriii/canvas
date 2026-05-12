import { formatTitle } from '@/lib/formatTitle';

interface FormattedLabelProps {
  children: string;
}

export function FormattedLabel({ children }: FormattedLabelProps) {
  return <span>{formatTitle(children)}</span>;
}
