import { formatTitle } from '@/lib/formatTitle';

export default function Example({ title = 'Hello' }) {
  return <h2>{formatTitle(title)}</h2>;
}
