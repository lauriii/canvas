import { HookLabel } from '@/lib/HookLabel';

export default function Example({ title = 'Hello' }) {
  return <HookLabel>{title}</HookLabel>;
}
