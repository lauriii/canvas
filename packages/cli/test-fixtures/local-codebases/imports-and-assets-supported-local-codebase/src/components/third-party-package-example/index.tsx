import { format } from 'date-fns';

export default function Example() {
  return <time>{format(new Date(), 'yyyy')}</time>;
}
