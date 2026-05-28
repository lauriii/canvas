export type InterceptNativeSetterOptions<T = any> = {
  property: keyof T;
  afterSet?: (el: T, newValue: any) => void;
  skipSet?: (newValue: any) => boolean;
  fireJQueryChange?: boolean;
};

/**
 * Intercepts an element's native property setter so programmatic changes
 * (e.g., element.value = 'foo') result in React and jQuery handling the change
 * as if it were user-initiated.
 *
 * Used by base components (TextField, Select, Checkbox, Toggle, etc.) to ensure
 * external code that manipulates DOM values triggers the form system.
 *
 * Note: For triggering form state updates, use FieldContext.triggerChange() instead.
 * interceptNativeSetter is specifically for base components that need to intercept
 * vanilla JS/jQuery DOM manipulation.
 */
export function interceptNativeSetter<T = any>(
  element: T,
  options: InterceptNativeSetterOptions<T>,
) {
  if (!element) return;
  const { property, afterSet, skipSet, fireJQueryChange = true } = options;
  const elementProto = Object.getPrototypeOf(element);
  const descriptor = Object.getOwnPropertyDescriptor(elementProto, property);
  if (!descriptor || !descriptor.set) return;
  const originalSetter = descriptor.set;
  Object.defineProperty(element, property, {
    get: descriptor.get,
    set: function (newValue: any) {
      if (skipSet && skipSet(newValue)) return;
      originalSetter.call(this, newValue);
      // Always fire a native change event.
      const changeEvent = new Event('change', { bubbles: true });
      Object.defineProperty(changeEvent, 'target', {
        writable: false,
        value: this,
      });
      this.dispatchEvent(changeEvent);
      // Optionally fire jQuery event.
      if (fireJQueryChange && typeof window !== 'undefined' && window.jQuery) {
        const $target = window.jQuery(this);
        if ($target.length) {
          $target.trigger('change');
        }
      }
      if (typeof afterSet === 'function') {
        afterSet(this, newValue);
      }
    },
    configurable: true,
  });
}
