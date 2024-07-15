import propsify
  from '@/local_packages/hyperscriptify/propsify/standard/index.js';
export function a2p( attributesFromDrupal, attributesFromComponent = {} ) {
  const combinedAttributes = {};
  const attributeNames = new Set(Object.keys(attributesFromDrupal).concat(Object.keys(attributesFromComponent)));
  attributeNames.forEach((name) => {
    let value;
    if (Array.isArray(attributesFromDrupal[name]) && Array.isArray(attributesFromComponent[name])) {
      value = attributesFromDrupal[name].concat(attributesFromComponent[name]);
    }
    else {
      value = attributesFromComponent[name] ?? attributesFromDrupal[name];
    }

    if (Array.isArray(value)) {
      value = value.join(" ");
    }
    combinedAttributes[name] = value;
  });

  return propsify( combinedAttributes, {}, {} );
}

// Replacement for Drupal's clean_class function.
export function cleanClass(input) {
  input = input.replace(/[\s_]+/g, '-').toLowerCase();
  input = input.replace(/[^\w-]/g, '');
  return input;
}

