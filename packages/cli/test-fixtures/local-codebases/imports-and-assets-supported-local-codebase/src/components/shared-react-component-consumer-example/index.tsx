import { ExampleLabel } from '@/lib/ExampleLabel';

export default function Example({ title = 'Hello again' }) {
  return <ExampleLabel>{title}</ExampleLabel>;
}
