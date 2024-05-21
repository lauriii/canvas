import { validate } from 'uuid';

export type UUID = string;

function isUUID(uuid: any): uuid is UUID {
  return typeof uuid === 'string' && validate(uuid);
}
