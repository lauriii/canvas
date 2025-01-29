const __vite__mapDeps=(i,m=__vite__mapDeps,d=(m.f||(m.f=["signals.module.js","preact.module.js","hooks.module.js"])))=>i.map(i=>d[i]);
import { _ as __vitePreload } from './preload-helper.js';
import { _, B, D } from './preact.module.js';

const StaticHtml = ({ value, name, hydrate = true }) => {
  if (!value) return null;
  const tagName = hydrate ? "astro-slot" : "astro-static-slot";
  return _(tagName, { name, dangerouslySetInnerHTML: { __html: value } });
};
StaticHtml.shouldComponentUpdate = () => false;
var static_html_default = StaticHtml;

const sharedSignalMap = /* @__PURE__ */ new Map();
var client_default = (element) => async (Component, props, { default: children, ...slotted }, { client }) => {
  if (!element.hasAttribute("ssr")) return;
  for (const [key, value] of Object.entries(slotted)) {
    props[key] = _(static_html_default, { value, name: key });
  }
  let signalsRaw = element.dataset.preactSignals;
  if (signalsRaw) {
    const { signal } = await __vitePreload(async () => { const { signal } = await import('./signals.module.js');return { signal }},true?__vite__mapDeps([0,1,2]):void 0);
    let signals = JSON.parse(
      element.dataset.preactSignals
    );
    for (const [propName, signalId] of Object.entries(signals)) {
      if (Array.isArray(signalId)) {
        signalId.forEach(([id, indexOrKeyInProps]) => {
          const mapValue = props[propName][indexOrKeyInProps];
          let valueOfSignal = mapValue;
          if (typeof indexOrKeyInProps !== "string") {
            valueOfSignal = mapValue[0];
            indexOrKeyInProps = mapValue[1];
          }
          if (!sharedSignalMap.has(id)) {
            const signalValue = signal(valueOfSignal);
            sharedSignalMap.set(id, signalValue);
          }
          props[propName][indexOrKeyInProps] = sharedSignalMap.get(id);
        });
      } else {
        if (!sharedSignalMap.has(signalId)) {
          const signalValue = signal(props[propName]);
          sharedSignalMap.set(signalId, signalValue);
        }
        props[propName] = sharedSignalMap.get(signalId);
      }
    }
  }
  const bootstrap = client !== "only" ? D : B;
  bootstrap(
    _(Component, props, children != null ? _(static_html_default, { value: children }) : children),
    element
  );
  element.addEventListener("astro:unmount", () => B(null, element), { once: true });
};

export { client_default as default };
