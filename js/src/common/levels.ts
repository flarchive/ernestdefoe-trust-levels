import app from 'flarum/common/app';

export const NS = 'ernestdefoe-trust-levels';
export const MAX = 4;

/** A level's name: the admin's own, else the translated default. */
export function levelName(level: number): string {
  const names = (app.forum?.attribute<Record<string, string>>('trustLevelNames') || {}) as Record<string, string>;

  return names[level] || String(app.translator.trans(`${NS}.lib.levels.${level}`));
}

export interface Row {
  key: string;
  have: number;
  need: number;
  met: boolean;
  upTo?: boolean;
}
