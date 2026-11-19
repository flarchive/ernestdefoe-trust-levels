import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type { ExtensionPageAttrs } from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import { KEY, t, type Tab, type Dashboard } from './shared';
import dashboardTab from './tabs/DashboardTab';
import levelsTab from './tabs/LevelsTab';
import limitsTab from './tabs/LimitsTab';
import spamTab from './tabs/SpamTab';
import integrationsTab from './tabs/IntegrationsTab';

const TABS: Record<Tab, (page: TrustLevelsPage) => Mithril.Children> = {
  dashboard: dashboardTab,
  levels: levelsTab,
  limits: limitsTab,
  spam: spamTab,
  integrations: integrationsTab,
};

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
  changed = false;
  waiting = false;

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
        const before = this.dashboard?.lastRun;
        this.dashboard = r.data;
        if (run && r.data.busy) app.alerts.show({ type: 'warning' }, t('dashboard.busy'));
        // Run now is queued: with a queue worker it finishes later, so check back until it has.
        if (run && r.data.queued && r.data.lastRun === before) this.waitForRun(before);
      })
      .finally(() => {
        this.running = this.waiting;
        m.redraw();
      });
  }

  waitForRun(before: string | null | undefined, tries = 60) {
    this.waiting = true;

    setTimeout(() => {
      app
        .request<{ data: Dashboard }>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/trust-levels/dashboard' })
        .then((r) => {
          this.dashboard = r.data;
          if (r.data.lastRun === before && tries > 1) return this.waitForRun(before, tries - 1);
          this.waiting = this.running = false;
        })
        .catch(() => {
          // app.request already showed the alert.
          this.waiting = this.running = false;
        })
        .finally(() => m.redraw());
    }, 5000);
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
    this.changed = true;
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
        this.changed = false;
        app.alerts.show({ type: 'success' }, app.translator.trans('core.admin.settings.saved_message'));
      })
      .finally(() => {
        this.saving = false;
        m.redraw();
      });
  }

  // ---- page ---------------------------------------------------------------------

  content() {
    const tabs = Object.keys(TABS) as Tab[];

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
          {TABS[this.tab](this)}
          {this.tab !== 'dashboard' ? (
            <div className="Form-group TrustAdmin-save">
              <Button className="Button Button--primary" loading={this.saving} disabled={!this.changed} onclick={() => this.save()}>
                {app.translator.trans('core.admin.settings.submit_button')}
              </Button>
            </div>
          ) : null}
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
}
