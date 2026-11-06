import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { Notify, Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import { vi } from 'vitest';
import type { Component } from 'vue';
import { handle } from '../../dev/mockApi';
import { createAppI18n } from '../../src/i18n';
import type { Backup } from '../../src/types/api';
import { configure, installMockFetch, settle } from './app';

export { settle };

// jsdom has no ResizeObserver; Quasar's scroll components expect one.
class ResizeObserverStub {
  observe(): void {}
  unobserve(): void {}
  disconnect(): void {}
}

/** Prepare config, mock REST backend and stubs; returns the fetch spy. */
export function setup(caps?: Parameters<typeof configure>[0]) {
  vi.stubGlobal('ResizeObserver', ResizeObserverStub);
  configure(caps);
  setActivePinia(createPinia());
  return installMockFetch();
}

/** Mount one component with Quasar, i18n and Pinia, like the app shell provides them. */
export function mountComponent(component: Component, props: Record<string, unknown> = {}) {
  return mount(component, {
    props,
    attachTo: document.body,
    global: {
      plugins: [
        createPinia(),
        createAppI18n('en_US'),
        [Quasar, { plugins: { Notify }, iconSet }],
      ] as never[],
    },
  });
}

export function listBackups(): Backup[] {
  return (
    handle({ method: 'GET', path: 'backups', query: new URLSearchParams(), body: {} }).body as {
      items: Backup[];
    }
  ).items;
}

export function requests(fetchMock: ReturnType<typeof installMockFetch>): string[] {
  return fetchMock.mock.calls.map(
    (c) =>
      `${(c[1] as RequestInit | undefined)?.method ?? 'GET'} ${new URL(String(c[0])).pathname}`,
  );
}

export function buttonByText(re: RegExp): HTMLButtonElement | undefined {
  return Array.from(document.querySelectorAll<HTMLButtonElement>('button')).find((b) =>
    re.test(b.textContent ?? ''),
  );
}
