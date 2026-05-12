import { ExampleLabel } from '@/lib/ExampleLabel';

export default function Example({ title = 'Hello' }) {
  return <ExampleLabel>{title}</ExampleLabel>;
}
