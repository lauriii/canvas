import { formatPrice } from '@/components/pricing-card/helpers';

export default function Example() {
  return <article>{formatPrice(10)}</article>;
}
