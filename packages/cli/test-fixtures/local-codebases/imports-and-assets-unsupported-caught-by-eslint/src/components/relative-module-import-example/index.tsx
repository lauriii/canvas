import { formatDate } from './formatDate';

export default function Example() {
  return <time>{formatDate(new Date())}</time>;
}
