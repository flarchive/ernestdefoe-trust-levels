import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type { ExtensionPageAttrs } from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import Alert from 'flarum/common/components/Alert';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { NS, MAX, levelName } from '../../common/levels';

declare const m: Mithril.Static;

const KEY = 'ernestdefoe-trust-levels.config';
const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`${NS}.admin.${key}`, params);

type Tab = 'dashboard' | 'levels' | 'limits' | 'spam' | 'integrations';

const REQS: Record<number, string[]> = {
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
const REQ_ROWS = [
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
const LIMITS = ['links', 'images', 'mentions', 'post_seconds', 'discussions_day', 'messages_day'];
const NEEDS: Record<string, string> = {
  likes_given: 'flarum-likes',
  likes_received: 'flarum-likes',
  likes_received_users: 'flarum-likes',
  likes_received_days: 'flarum-likes',
  flagged_posts: 'flarum-flags',
  flagged_by: 'flarum-flags',
  mentions: 'flarum-mentions',
  messages_day: 'flarum-messages',
};

function enabled(id: string): boolean {
  try {
    return (JSON.parse(app.data.settings.extensions_enabled || '[]') as string[]).includes(id);
  } catch (e) {
    return false;
  }
}

interface Dashboard {
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
}

/**
 * Admin → Trust Levels: the dashboard, the requirement grid, the limits grid,
 * spam defense and other extensions. Everything is one JSON setting, saved
 * through core's settings API (the server cleans every value on the way out).
 */
export default class TrustLevelsPage extends ExtensionPage<ExtensionPageAttrs> {
  tab: Tab = 'dashboard';
  config: any = {};
  dashboard: Dashboard | null = null;
  running = false;
  saving = false;
  dirty = false;

  oninit(vnode: Mithril.Vnode<ExtensionPageAttrs, this>) {
    super.oninit(vnode);

    try {
      this.config = JSON.parse(app.data.settings[KEY] || '{}') || {};
    } catch (e) {
      this.config = {};
    }

    this.loadDashboard();
  }

  loadDashboard(run = false) {
    this.running = run;

    return app
      .request<{ data: Dashboard }>({
        method: run ? 'POST' : 'GET',
        url: app.forum.attribute('apiUrl') + (run ? '/trust-levels/run' : '/trust-levels/dashboard'),
      })
      .then((r) => {
        this.dashboard = r.data;
        if (run && r.data.busy) app.alerts.show({ type: 'warning' }, t('dashboard.busy'));
      })
      .finally(() => {
        this.running = false;
        m.redraw();
      });
  }

  // ---- config helpers ---------------------------------------------------------

  get(path: (string | number)[], fallback: any = ''): any {
    let v = this.config;
    for (const k of path) v = v?.[k];
    return v === undefined || v === null ? fallback : v;
  }

  set(path: (string | number)[], value: any) {
    let o = this.config;
    path.slice(0, -1).forEach((k) => {
      if (typeof o[k] !== 'object' || o[k] === null || Array.isArray(o[k])) o[k] = Array.isArray(o[k]) ? { ...o[k] } : o[k] || {};
      o = o[k];
    });
    o[path[path.length - 1]] = value;
    this.dirty = true;
  }

  num(path: (string | number)[], placeholder: string | number = '', label = '') {
    const value = this.get(path, '');

    return (
      <input
        className="FormControl TrustAdmin-num"
        type="number"
        min="0"
        inputmode="numeric"
        aria-label={label}
        placeholder={String(placeholder)}
        value={value}
        oninput={(e: InputEvent) => {
          const v = (e.target as HTMLInputElement).value;
          this.set(path, v === '' ? null : Number(v));
        }}
      />
    );
  }

  save() {
    this.saving = true;

    return app
      .request({ method: 'POST', url: app.forum.attribute('apiUrl') + '/settings', body: { [KEY]: JSON.stringify(this.config) } })
      .then(() => {
        app.data.settings[KEY] = JSON.stringify(this.config);
        this.dirty = false;
        app.alerts.show({ type: 'success' }, app.translator.trans('core.admin.settings.saved_message'));
      })
      .finally(() => {
        this.saving = false;
        m.redraw();
      });
  }

  // ---- page ---------------------------------------------------------------------

  content() {
    const tabs: Tab[] = ['dashboard', 'levels', 'limits', 'spam', 'integrations'];

    return (
      <div className="ExtensionPage-settings TrustAdmin">
        <div className="container">
          <nav className="TrustAdmin-tabs" role="tablist">
            {tabs.map((tab) => (
              <button
                type="button"
                role="tab"
                aria-selected={this.tab === tab}
                className={'TrustAdmin-tab' + (this.tab === tab ? ' is-active' : '')}
                onclick={() => (this.tab = tab)}
              >
                {t(`tabs.${tab}`)}
              </button>
            ))}
          </nav>
          {this[`${this.tab}Tab` as 'dashboardTab']()}
          {this.tab !== 'dashboard' ? (
            <div className="Form-group TrustAdmin-save">
              <Button className="Button Button--primary" loading={this.saving} disabled={!this.dirty} onclick={() => this.save()}>
                {app.translator.trans('core.admin.settings.submit_button')}
              </Button>
            </div>
          ) : null}
        </div>
      </div>
    );
  }

  dashboardTab(): Mithril.Children {
    const d = this.dashboard;
    if (!d) return <LoadingIndicator />;

    const total = d.counts.reduce((a, b) => a + b, 0) || 1;

    return (
      <div className="TrustAdmin-dashboard">
        {d.stale ? (
          <Alert type="warning" dismissible={false}>
            {t('dashboard.stale')}
          </Alert>
        ) : null}
        <div className="TrustAdmin-stats">
          {d.counts.map((n, level) => (
            <div className={'TrustAdmin-stat TrustAdmin-stat--' + level}>
              <div className="TrustAdmin-statNum">{n.toLocaleString()}</div>
              <div className="TrustAdmin-statLabel">
                {level} · {levelName(level)}
              </div>
              <div className="TrustAdmin-statMoves">
                {level === MAX
                  ? t('dashboard.by_staff')
                  : d.moves[level].up || d.moves[level].down
                    ? [d.moves[level].up ? `+${d.moves[level].up}` : null, d.moves[level].down ? `−${d.moves[level].down}` : null]
                        .filter(Boolean)
                        .join(' · ') +
                      ' ' +
                      t('dashboard.this_week')
                    : t('dashboard.no_moves')}
              </div>
            </div>
          ))}
        </div>
        <div className="TrustAdmin-dist" aria-hidden="true">
          {d.counts.map((n, level) => (
            <span className={'TrustAdmin-dist--' + level} style={{ width: (n / total) * 100 + '%' }} />
          ))}
        </div>
        <div className="TrustAdmin-two">
          <section className="TrustAdmin-box">
            <h4>{t('dashboard.close_title')}</h4>
            {d.close.length ? (
              d.close.map((c) => (
                <div className="TrustAdmin-row">
                  <div className="TrustAdmin-rowMain">
                    <a href={app.forum.attribute('baseUrl') + '/u/' + encodeURIComponent(c.username) + '/trust'} target="_blank" rel="noopener">
                      {c.username}
                    </a>{' '}
                    <span className="TrustAdmin-pill">
                      {levelName(c.level)} → {levelName(c.next)}
                    </span>
                    <em>
                      {c.missing
                        .map((r) => extractText(app.translator.trans(`${NS}.admin.missing.${r.key}`, { count: Math.max(0, r.need - r.have) })))
                        .join(', ')}
                    </em>
                  </div>
                  <span className="TrustAdmin-meter" title={c.percent + '%'}>
                    <b style={{ width: c.percent + '%' }} />
                  </span>
                </div>
              ))
            ) : (
              <p className="TrustAdmin-empty">{t('dashboard.close_empty')}</p>
            )}
          </section>
          <section className="TrustAdmin-box">
            <h4>{t('dashboard.recent_title')}</h4>
            {d.recent.length ? (
              d.recent.map((r) => (
                <div className="TrustAdmin-row">
                  <div className="TrustAdmin-rowMain">
                    {r.username}
                    <em>
                      {humanTime(r.at)}
                      {r.reason && r.reason !== 'promoted' ? ' · ' + t(`dashboard.reason.${r.reason}`) : ''}
                      {r.actor ? ' · ' + r.actor : ''}
                    </em>
                  </div>
                  <span className={'TrustAdmin-pill TrustAdmin-pill--' + r.type}>
                    {r.type === 'promoted' ? '↑ ' : r.type === 'demoted' ? '↓ ' : ''}
                    {r.to !== null && (r.type === 'promoted' || r.type === 'demoted') ? levelName(r.to) : t(`dashboard.event.${r.type}`)}
                  </span>
                </div>
              ))
            ) : (
              <p className="TrustAdmin-empty">{t('dashboard.recent_empty')}</p>
            )}
          </section>
        </div>
        <p className="TrustAdmin-run">
          <Icon name={d.stale ? 'fas fa-triangle-exclamation' : 'fas fa-circle-check'} />{' '}
          {d.lastRun
            ? t('dashboard.last_run', { time: humanTime(d.lastRun), count: d.lastRunCount, seconds: d.lastRunSeconds })
            : t('dashboard.never_run')}{' '}
          <Button className="Button" loading={this.running} onclick={() => this.loadDashboard(true)}>
            {t('dashboard.run_now')}
          </Button>
        </p>
      </div>
    );
  }

  levelsTab(): Mithril.Children {
    const groups = app.store.all<any>('groups').filter((g) => !['1', '2', '3', '4'].includes(g.id()));
    const missingGroups = [1, 2, 3, 4].some((l) => !this.get(['groups', l], null));

    return (
      <div>
        <p className="helpText">{t('levels.intro')}</p>
        <div className="TrustAdmin-gridWrap">
          <table className="TrustAdmin-grid">
            <thead>
              <tr>
                <th className="TrustAdmin-sticky">{t('levels.to_reach')}</th>
                {[1, 2, 3, 4].map((l) => (
                  <th>
                    {l} · {levelName(l)}
                    {l === 3 ? <small> {t('levels.window')}</small> : null}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              <tr>
                <th scope="row" className="TrustAdmin-sticky">
                  {t('levels.name')}
                </th>
                {[1, 2, 3, 4].map((l) => (
                  <td>
                    <input
                      className="FormControl TrustAdmin-name"
                      maxlength={30}
                      aria-label={String(t('levels.name')) + ' ' + l}
                      placeholder={String(app.translator.trans(`${NS}.lib.levels.${l}`))}
                      value={this.get(['names', l])}
                      oninput={(e: InputEvent) => this.set(['names', l], (e.target as HTMLInputElement).value)}
                    />
                  </td>
                ))}
              </tr>
              {REQ_ROWS.map((key, i) => (
                <tr>
                  <th scope="row" className="TrustAdmin-sticky">
                    {t(`req.${key}`)}
                    {NEEDS[key] && !enabled(NEEDS[key]) ? <em>{t('levels.not_counted')}</em> : null}
                  </th>
                  {[1, 2, 3].map((l) =>
                    REQS[l].includes(key) ? (
                      <td>{this.num(['req', l, key], DEFAULTS[l][key], String(t(`req.${key}`)))}</td>
                    ) : (
                      <td className="TrustAdmin-na">–</td>
                    )
                  )}
                  {i === 0 ? (
                    <td rowSpan={REQ_ROWS.length} className="TrustAdmin-staffOnly">
                      {t('levels.by_staff')}
                    </td>
                  ) : null}
                </tr>
              ))}
              <tr>
                <th scope="row" className="TrustAdmin-sticky">
                  {t('levels.group')}
                  <em>{t('levels.group_help')}</em>
                </th>
                {[1, 2, 3, 4].map((l) => (
                  <td>
                    <select
                      className="FormControl"
                      aria-label={String(t('levels.group')) + ' ' + l}
                      value={String(this.get(['groups', l], ''))}
                      onchange={(e: Event) =>
                        this.set(['groups', l], (e.target as HTMLSelectElement).value ? Number((e.target as HTMLSelectElement).value) : null)
                      }
                    >
                      <option value="">{t('levels.no_group')}</option>
                      {groups.map((g) => (
                        <option value={g.id()}>{g.namePlural()}</option>
                      ))}
                    </select>
                  </td>
                ))}
              </tr>
            </tbody>
          </table>
        </div>
        {missingGroups ? (
          <p className="TrustAdmin-groups">
            <Button className="Button" icon="fas fa-users" onclick={() => this.createGroups()}>
              {t('levels.create_groups')}
            </Button>{' '}
            <span className="helpText">{t('levels.create_groups_help')}</span>
          </p>
        ) : null}
        <div className="Form-group">
          <label>{t('levels.grace')}</label>
          {this.num(['grace_days'], 14)} <span className="helpText">{t('levels.grace_help', { level: levelName(3) })}</span>
        </div>
      </div>
    );
  }

  createGroups() {
    app
      .request<{ data: { groups: Record<number, number | null> } }>({ method: 'POST', url: app.forum.attribute('apiUrl') + '/trust-levels/groups' })
      .then((r) => {
        this.config.groups = r.data.groups;
        app.data.settings[KEY] = JSON.stringify(this.config);
        // The new groups, for the selects.
        app.store.find('groups').then(() => m.redraw());
      });
  }

  limitsTab(): Mithril.Children {
    return (
      <div>
        <p className="helpText">{t('limits.intro')}</p>
        <div className="TrustAdmin-gridWrap">
          <table className="TrustAdmin-grid">
            <thead>
              <tr>
                <th className="TrustAdmin-sticky">{t('limits.limit')}</th>
                {[0, 1, 2, 3, 4].map((l) => (
                  <th>
                    {l} · {levelName(l)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {LIMITS.map((key) => (
                <tr>
                  <th scope="row" className="TrustAdmin-sticky">
                    {t(`limits.${key}`)}
                    {NEEDS[key] && !enabled(NEEDS[key]) ? <em>{t('levels.not_counted')}</em> : null}
                  </th>
                  {[0, 1, 2, 3, 4].map((l) => {
                    const stored = this.config.limits?.[key];
                    const value = stored === undefined ? LIMIT_DEFAULTS[key][l] : stored?.[l];

                    return (
                      <td>
                        <input
                          className="FormControl TrustAdmin-num"
                          type="number"
                          min="0"
                          inputmode="numeric"
                          aria-label={String(t(`limits.${key}`)) + ' ' + l}
                          placeholder={String(t('limits.none'))}
                          value={value ?? ''}
                          oninput={(e: InputEvent) => {
                            const v = (e.target as HTMLInputElement).value;
                            if (this.config.limits?.[key] === undefined) this.set(['limits', key], { ...LIMIT_DEFAULTS[key] });
                            this.set(['limits', key, l], v === '' ? null : Number(v));
                          }}
                        />
                      </td>
                    );
                  })}
                </tr>
              ))}
              <tr>
                <th scope="row" className="TrustAdmin-sticky">
                  {t('limits.uploads')}
                  {!enabled('fof-upload') ? <em>{t('levels.not_counted')}</em> : null}
                </th>
                <td colSpan={5}>
                  <select
                    className="FormControl TrustAdmin-from"
                    value={String(this.get(['uploads_from'], 2))}
                    onchange={(e: Event) => this.set(['uploads_from'], Number((e.target as HTMLSelectElement).value))}
                  >
                    {[0, 1, 2, 3, 4].map((l) => (
                      <option value={l}>{t('limits.from_level', { level: levelName(l) })}</option>
                    ))}
                  </select>
                </td>
              </tr>
              <tr>
                <th scope="row" className="TrustAdmin-sticky">
                  {t('limits.approval')}
                  {!enabled('flarum-approval') ? <em>{t('levels.not_counted')}</em> : null}
                </th>
                <td colSpan={5}>
                  {this.num(['approval_posts'], 2, String(t('limits.approval')))}{' '}
                  <span className="helpText">{t('limits.approval_help', { level: levelName(0) })}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div className="Form-group">
          <label>{t('limits.exempt')}</label>
          <p className="helpText">{t('limits.exempt_help')}</p>
          <div className="TrustAdmin-checks">
            {app.store
              .all<any>('groups')
              .filter((g) => !['1', '2', '3'].includes(g.id()))
              .map((g) => {
                const list: number[] = this.get(['exempt_groups'], []);
                const id = Number(g.id());

                return (
                  <label className="checkbox">
                    <input
                      type="checkbox"
                      checked={list.includes(id)}
                      onchange={(e: Event) =>
                        this.set(['exempt_groups'], (e.target as HTMLInputElement).checked ? [...list, id] : list.filter((x) => x !== id))
                      }
                    />
                    {g.namePlural()}
                  </label>
                );
              })}
          </div>
        </div>
      </div>
    );
  }

  spamTab(): Mithril.Children {
    const docket = enabled('ernestdefoe-docket');
    const row = (key: string, fallback: number, params: Record<string, any> = {}) => (
      <div className="Form-group">
        <label>{t(`spam.${key}`, params)}</label>
        {this.num(['spam', key], fallback, String(t(`spam.${key}`, params)))}
        <p className="helpText">{t(`spam.${key}_help`, params)}</p>
      </div>
    );

    return (
      <div className="TrustAdmin-spam">
        {!enabled('flarum-flags') ? (
          <Alert type="warning" dismissible={false}>
            {t('spam.needs_flags')}
          </Alert>
        ) : null}
        {docket ? (
          <Alert type="info" dismissible={false}>
            {t('spam.docket')}
          </Alert>
        ) : null}
        {docket ? null : row('flags', 3)}
        {row('flagger_level', 2, { level: levelName(this.get(['spam', 'flagger_level'], 2)) })}
        {row('silence_up_to', 1, { level: levelName(this.get(['spam', 'silence_up_to'], 1)) })}
        {enabled('ernestdefoe-gatehouse') ? row('gatehouse_days', 7) : null}
        {row('drop_flags', 3)}
      </div>
    );
  }

  integrationsTab(): Mithril.Children {
    const items: [string, string][] = [
      ['ernestdefoe-ladder', 'ladder'],
      ['ernestdefoe-docket', 'docket'],
      ['ernestdefoe-gatehouse', 'gatehouse'],
      ['ernestdefoe-sleuth', 'sleuth'],
      ['ernestdefoe-onramp', 'onramp'],
      ['ernestdefoe-buzz', 'buzz'],
    ];

    return (
      <div>
        <ul className="TrustAdmin-integrations">
          {items.map(([id, key]) => (
            <li className={enabled(id) ? 'is-on' : ''}>
              <Icon name={enabled(id) ? 'fas fa-circle-check' : 'far fa-circle'} />
              <div>
                <strong>{t(`integrations.${key}`)}</strong>{' '}
                <span className="TrustAdmin-pill">{enabled(id) ? t('integrations.on') : t('integrations.off')}</span>
                <p className="helpText">{t(`integrations.${key}_help`)}</p>
                {key === 'buzz' && enabled(id) ? (
                  <label className="checkbox">
                    <input
                      type="checkbox"
                      checked={!!this.get(['buzz'], false)}
                      onchange={(e: Event) => this.set(['buzz'], (e.target as HTMLInputElement).checked)}
                    />
                    {t('integrations.buzz_toggle')}
                  </label>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      </div>
    );
  }
}

const DEFAULTS: Record<number, Record<string, number>> = {
  1: { topics_entered: 5, posts_read: 30, read_minutes: 10 },
  2: { days_visited: 15, topics_entered: 20, posts_read: 100, read_minutes: 60, replied_topics: 3, likes_given: 3, likes_received: 1 },
  3: {
    days_visited: 50,
    replied_topics: 10,
    topics_percent: 25,
    topics_max: 500,
    posts_percent: 25,
    posts_max: 20000,
    likes_received: 20,
    likes_received_users: 5,
    likes_received_days: 7,
    likes_given: 30,
    flagged_posts: 5,
    flagged_by: 5,
    suspended_months: 6,
  },
};

const LIMIT_DEFAULTS: Record<string, Record<number, number>> = {
  links: { 0: 2, 1: 5, 2: 10 },
  images: { 0: 1, 1: 5 },
  mentions: { 0: 2, 1: 5, 2: 10 },
  post_seconds: { 0: 60, 1: 15 },
  discussions_day: { 0: 3, 1: 10 },
  messages_day: { 0: 0, 1: 5, 2: 20 },
};
