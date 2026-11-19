import app from 'flarum/admin/app';
import { NS } from '../../common/levels';

export const KEY = 'ernestdefoe-trust-levels.config';
export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`${NS}.admin.${key}`, params);

export type Tab = 'dashboard' | 'levels' | 'limits' | 'spam' | 'integrations';

/** Which requirements each automatic level has (the columns of the grid). */
export const REQS: Record<number, string[]> = {
  1: ['topics_entered', 'posts_read', 'read_minutes'],
  2: ['days_visited', 'topics_entered', 'posts_read', 'read_minutes', 'replied_topics', 'likes_given', 'likes_received'],
  3: [
    'days_visited',
    'replied_topics',
    'topics_percent',
    'topics_max',
    'posts_percent',
    'posts_max',
    'likes_given',
    'likes_received',
    'likes_received_users',
    'likes_received_days',
    'flagged_posts',
    'flagged_by',
    'suspended_months',
  ],
};

/** The rows of the requirement grid, in order. */
export const REQ_ROWS = [
  'days_visited',
  'topics_entered',
  'posts_read',
  'read_minutes',
  'replied_topics',
  'likes_given',
  'likes_received',
  'likes_received_users',
  'likes_received_days',
  'topics_percent',
  'topics_max',
  'posts_percent',
  'posts_max',
  'flagged_posts',
  'flagged_by',
  'suspended_months',
];

/** Requirements and limits that only count while another extension is on. */
export const NEEDS: Record<string, string> = {
  likes_given: 'flarum-likes',
  likes_received: 'flarum-likes',
  likes_received_users: 'flarum-likes',
  likes_received_days: 'flarum-likes',
  flagged_posts: 'flarum-flags',
  flagged_by: 'flarum-flags',
  mentions: 'flarum-mentions',
  messages_day: 'flarum-messages',
};

/** The server's defaults (Config::DEFAULTS), sent with the admin page. */
export interface Defaults {
  req: Record<number, Record<string, number>>;
  limits: Record<string, Record<number, number>>;
  uploads_from: number;
  approval_posts: number;
  spam: Record<string, number>;
  grace_days: number;
}

export const defaults = (): Defaults => app.data.trustLevelDefaults as Defaults;

export function enabled(id: string): boolean {
  try {
    return (JSON.parse(app.data.settings.extensions_enabled || '[]') as string[]).includes(id);
  } catch (e) {
    // Unreadable list: show the extension as off rather than break the page.
    return false;
  }
}

export interface Dashboard {
  counts: number[];
  moves: { up: number; down: number }[];
  close: { id: number; username: string; level: number; next: number; percent: number; missing: { key: string; have: number; need: number }[] }[];
  recent: {
    type: string;
    from: number | null;
    to: number | null;
    reason: string | null;
    at: string;
    userId: number;
    username: string;
    actor: string | null;
  }[];
  lastRun: string | null;
  lastRunCount: number;
  lastRunSeconds: number;
  stale: boolean;
  busy: boolean;
  queued: boolean;
}
