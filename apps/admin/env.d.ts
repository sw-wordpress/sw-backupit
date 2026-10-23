/// <reference types="vite/client" />

import type { SwbiConfig } from './src/types/config';

declare global {
  interface Window {
    swbackupitConfig?: SwbiConfig;
  }
}

declare module '*.vue' {
  import type { DefineComponent } from 'vue';
  const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>;
  export default component;
}
