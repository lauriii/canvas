import { ReactNodeLabel } from '@/lib/ReactNodeLabel';

export default function Example({ title = 'Hello' }) {
  return <ReactNodeLabel>{title}</ReactNodeLabel>;
}
