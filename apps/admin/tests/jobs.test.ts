import { describe, expect, it } from 'vitest';
import { appendBounded } from '../src/composables/useJobMonitor';
import type { JobLogEntry } from '../src/types/api';
import { isActive, isTerminal, jobErrorText, nextPollDelay, phaseIndex } from '../src/utils/jobs';

const entry = (id: number): JobLogEntry => ({
  id,
  time: '2026-10-24T12:00:00Z',
  severity: 'info',
  phase: 'archiving',
  message: `m${id}`,
  context: {},
});

describe('job utils', () => {
  it('classifies statuses', () => {
    expect(isTerminal('completed')).toBe(true);
    expect(isTerminal('archiving')).toBe(false);
    expect(isActive({ status: 'paused' })).toBe(false);
    expect(isActive({ status: 'uploading' })).toBe(true);
    expect(isActive({ status: 'retry_waiting' })).toBe(true);
  });
  it('locates step ids in the pipeline', () => {
    expect(phaseIndex('preflight')).toBe(0);
    expect(phaseIndex('upload')).toBe(7);
    expect(phaseIndex('notify')).toBe(11);
    expect(phaseIndex('weird')).toBe(-1);
    expect(phaseIndex(null)).toBe(-1);
  });
  it('reads job errors in both shapes', () => {
    expect(jobErrorText('boom')).toBe('boom');
    expect(jobErrorText({ code: 'swbi_integrity', message: 'bad hash' })).toBe('bad hash');
    expect(jobErrorText({ code: 'swbi_vetoed' })).toBe('swbi_vetoed');
    expect(jobErrorText(null)).toBeNull();
  });
  it('backs off while nothing changes and after errors', () => {
    expect(nextPollDelay({ unchangedCount: 0, errorCount: 0, paused: false })).toBe(1000);
    expect(nextPollDelay({ unchangedCount: 50, errorCount: 0, paused: false })).toBe(5000);
    expect(nextPollDelay({ unchangedCount: 0, errorCount: 3, paused: false })).toBe(8000);
    expect(nextPollDelay({ unchangedCount: 0, errorCount: 20, paused: false })).toBe(30000);
    expect(nextPollDelay({ unchangedCount: 0, errorCount: 0, paused: true })).toBe(5000);
  });
});

describe('appendBounded', () => {
  it('keeps the newest entries within the limit and reports drops', () => {
    const base = Array.from({ length: 5 }, (_, i) => entry(i + 1));
    const res = appendBounded(base, [entry(6), entry(7)], 5);
    expect(res.entries.map((e) => e.id)).toEqual([3, 4, 5, 6, 7]);
    expect(res.dropped).toBe(2);
  });
  it('is a no-op for empty input', () => {
    const base = [entry(1)];
    expect(appendBounded(base, [], 5).entries).toBe(base);
  });
});
