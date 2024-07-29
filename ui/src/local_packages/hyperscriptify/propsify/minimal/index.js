import factory from '../factory';
import camelCase from 'just-camel-case';
import mapKeys from 'just-map-keys';
import mapValues from 'just-map-values';
import set from 'just-safe-set';

const propsify = factory({ camelCase, mapKeys, mapValues, set });
export default propsify;
