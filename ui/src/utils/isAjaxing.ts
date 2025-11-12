import { getDrupal } from '@/utils/drupal-globals';

const Drupal = getDrupal();

export const isAjaxing = () =>
  Drupal.ajax.instances.some(
    (instance: { ajaxing: boolean }) => instance && instance.ajaxing === true,
  );
