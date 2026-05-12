import { FormattedLabel } from '@/lib/FormattedLabel';

export default function Example({ title = 'Hello' }) {
  return <FormattedLabel>{title}</FormattedLabel>;
}
