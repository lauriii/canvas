import {a2p} from '@/local_packages/utils.js';

interface FormProps {
  attributes: {
    style?: {
      color?: string
      [x: string | number | symbol]: unknown;
    }
    [x: string | number | symbol]: unknown;
  }
  children: string|null
  renderChildren: string|any[]|null
}
const Form = ({attributes = {}, children = '', renderChildren = ''}: FormProps) => {
  if (!attributes.style) {
    attributes.style = {}
  }
  attributes.style.color = 'white'

  return (
    <form {...a2p(attributes)}>
      {renderChildren}
    </form>
  )
}

export default Form;
