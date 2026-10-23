import { mount } from '@vue/test-utils';
import { Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import ConfirmDialog from '../src/components/ui/ConfirmDialog.vue';
import ProgressRing from '../src/components/ui/ProgressRing.vue';
import StatusChip from '../src/components/ui/StatusChip.vue';
import TagInput from '../src/components/ui/TagInput.vue';
import { createAppI18n } from '../src/i18n';

function plugins() {
  return [[Quasar, { iconSet }], createAppI18n('en_US')] as never[];
}

afterEach(() => {
  document.body.innerHTML = '';
});

describe('StatusChip', () => {
  it('shows icon and translated text, never colour alone', () => {
    const wrapper = mount(StatusChip, { props: { domain: 'job', status: 'failed' }, global: { plugins: plugins() } });
    expect(wrapper.text()).toContain('Failed');
    expect(wrapper.classes()).toContain('swbi-chip--danger');
    expect(wrapper.find('.q-icon').exists()).toBe(true);
  });
  it('falls back to the raw status for unknown values', () => {
    const wrapper = mount(StatusChip, { props: { domain: 'job', status: 'mystery' }, global: { plugins: plugins() } });
    expect(wrapper.text()).toContain('mystery');
  });
});

describe('ProgressRing', () => {
  it('exposes progressbar semantics and clamps the value', () => {
    const wrapper = mount(ProgressRing, { props: { value: 140, label: 'Backup' }, global: { plugins: plugins() } });
    const root = wrapper.find('[role="progressbar"]');
    expect(root.attributes('aria-valuenow')).toBe('100');
    expect(root.attributes('aria-label')).toBe('Backup');
  });
});

describe('TagInput', () => {
  it('adds unique trimmed values and removes them', async () => {
    const wrapper = mount(TagInput, {
      props: { modelValue: ['a'], label: 'Paths', 'onUpdate:modelValue': (v: string[]) => wrapper.setProps({ modelValue: v }) },
      global: { plugins: plugins() },
    });
    const input = wrapper.find('input');
    await input.setValue('  b  ');
    await input.trigger('keydown.enter');
    expect(wrapper.props('modelValue')).toEqual(['a', 'b']);
    await input.setValue('b');
    await input.trigger('keydown.enter');
    expect(wrapper.props('modelValue')).toEqual(['a', 'b']);
    await wrapper.findAll('.swbi-tags__remove')[0]!.trigger('click');
    expect(wrapper.props('modelValue')).toEqual(['b']);
  });
});

describe('ConfirmDialog', () => {
  it('requires the typed text before confirming', async () => {
    const wrapper = mount(ConfirmDialog, {
      props: { modelValue: true, title: 'Delete?', confirmLabel: 'Delete now', requireText: 'abcd1234', target: 'Backup X' },
      global: { plugins: plugins() },
      attachTo: document.body,
    });
    await nextTick();
    await new Promise((r) => setTimeout(r, 50));
    const confirm = (): HTMLButtonElement => [...document.body.querySelectorAll('button')].find((b) => b.textContent?.includes('Delete now')) as HTMLButtonElement;
    expect(document.body.textContent).toContain('Backup X');
    expect(confirm().disabled).toBe(true);
    const input = document.body.querySelector('input') as HTMLInputElement;
    input.value = 'abcd1234';
    input.dispatchEvent(new Event('input'));
    await nextTick();
    expect(confirm().disabled).toBe(false);
    wrapper.unmount();
  });
});
