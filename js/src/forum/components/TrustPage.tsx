import app from 'flarum/forum/app';
import UserPage from 'flarum/forum/components/UserPage';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Alert from 'flarum/common/components/Alert';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { NS, MAX, Row, levelName } from '../../common/levels';
import Rows from './Rows';
import TrustModal from './TrustModal';

declare const m: Mithril.Static;

export interface Progress {
  level: number;
  levelAt: string | null;
  joinedAt: string | null;
  locked: boolean;
  silenced: boolean;
  note: string | null;
  computedAt: string | null;
  next: { level: number; rows: Row[] } | null;
  keep: Row[] | null;
  unlocks: { key: string; value?: number | null }[];
  stats: Record<string, number> | null;
  history: { type: string; from: number | null; to: number | null; reason: string | null; actor: string | null; postId: number | null; at: string }[];
}

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`${NS}.forum.page.${key}`, params);

const UNLOCK_ICONS: Record<string, string> = {
  links: 'fas fa-link',
  images: 'far fa-image',
  mentions: 'fas fa-at',
  post_seconds: 'fas fa-bolt',
  discussions_day: 'fas fa-comments',
  messages_day: 'fas fa-envelope',
  uploads: 'fas fa-paperclip',
  approval: 'fas fa-check',
};

const HISTORY_ICONS: Record<string, string> = {
  promoted: 'fas fa-arrow-up',
  demoted: 'fas fa-arrow-down',
  silenced: 'fas fa-hand',
  unsilenced: 'fas fa-hand-peace',
  locked: 'fas fa-lock',
  unlocked: 'fas fa-lock-open',
  flag_upheld: 'fas fa-flag',
  suspended: 'fas fa-ban',
  gatehouse: 'fas fa-door-closed',
  spam: 'fas fa-trash',
};

/**
 * Profile → Trust level. The member's own progress (and anyone's, for staff):
 * where they are, what the next level needs, what it unlocks, the history.
 * Loads one small endpoint when opened.
 */
export default class TrustPage extends UserPage {
  progress: Progress | null = null;
  failed = false;
  busy = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.loadUser(m.route.param('username'));
  }

  show(user: any) {
    // Only the member and staff get a level: anyone else following a link lands on the profile.
    if (user.attribute('trustLevel') === undefined) {
      m.route.set(app.route('user', { username: user.slug() }), {}, { replace: true });
      return;
    }

    super.show(user);
    app.setTitle(extractText(t('title')));

    if (!this.progress) this.load();
  }

  load() {
    app
      .request<{ data: Progress }>({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/trust-levels/users/${this.user!.id()}`,
        errorHandler: () => {},
      })
      .then((r) => {
        this.progress = r.data;
        m.redraw();
      })
      .catch(() => {
        this.failed = true;
        m.redraw();
      });
  }

  update(body: Record<string, unknown>) {
    this.busy = true;

    return app
      .request<{ data: Progress }>({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/trust-levels/users/${this.user!.id()}`, body })
      .then((r) => {
        this.progress = r.data;
        this.user!.pushAttributes({ trustLevel: r.data.level, trustLocked: r.data.locked, trustSilenced: r.data.silenced });
      })
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }

  content() {
    const p = this.progress;

    if (this.failed) return <p className="TrustPage-empty">{t('unavailable')}</p>;
    if (!p) return <LoadingIndicator display="block" />;

    const staff = !!app.forum.attribute('canManageTrustLevels');
    const own = app.session.user === this.user;

    return (
      <div className="TrustPage">
        <div className="TrustPage-head">
          <h2 className="TrustPage-level">
            {levelName(p.level)}
            <small>
              {t('level_number', { level: p.level })}
              {p.levelAt ? <> · {t('since', { date: humanTime(p.levelAt) })}</> : null}
              {p.locked ? (
                <span className="TrustPage-locked">
                  <Icon name="fas fa-lock" /> {t('locked')}
                </span>
              ) : null}
            </small>
          </h2>
          <ol className="TrustLadder" aria-label={extractText(t('ladder_label'))}>
            {Array.from({ length: MAX + 1 }, (_, level) => (
              <li className={'TrustLadder-step' + (level < p.level ? ' is-done' : '') + (level === p.level ? ' is-now' : '')}>
                <span className="TrustLadder-bar" />
                <span className="TrustLadder-name">{levelName(level)}</span>
              </li>
            ))}
          </ol>
        </div>

        {p.silenced ? (
          <Alert type="warning" dismissible={false} className="TrustPage-alert">
            {own && !staff ? t('silenced_own') : t('silenced_staff')}
          </Alert>
        ) : null}

        {staff ? this.staffBar(p) : null}

        {p.next ? this.nextCard(p) : null}

        {p.keep ? (
          <section className="TrustCard">
            <h3>{t('keep_title', { level: levelName(3) })}</h3>
            <p className="TrustCard-sub">{t('keep_sub')}</p>
            {Rows(p.keep)}
          </section>
        ) : null}

        {p.level >= 3 ? <p className="TrustPage-note">{t('leader_by_staff', { level: levelName(4) })}</p> : null}

        {p.unlocks.length && p.level < MAX ? (
          <section className="TrustCard">
            <h3>{t('unlocks_title', { level: levelName(p.next ? p.next.level : p.level + 1) })}</h3>
            <ul className="TrustUnlocks">
              {p.unlocks.map((u) => (
                <li>
                  <Icon name={UNLOCK_ICONS[u.key] || 'fas fa-check'} />
                  <span>
                    {u.value === null
                      ? app.translator.trans(`${NS}.forum.unlocks.${u.key}_none`)
                      : app.translator.trans(`${NS}.forum.unlocks.${u.key}`, { value: u.value })}
                  </span>
                </li>
              ))}
            </ul>
          </section>
        ) : null}

        {p.history.length ? (
          <section className="TrustCard">
            <h3>{t('history_title')}</h3>
            <ul className="TrustHistory">
              {p.history.map((h) => (
                <li>
                  <span className="TrustHistory-time">{humanTime(h.at)}</span>
                  <Icon name={HISTORY_ICONS[h.type] || 'fas fa-circle'} className={'TrustHistory-icon TrustHistory-icon--' + h.type} />
                  <span>
                    {app.translator.trans(`${NS}.forum.history.${h.type}`, {
                      level: h.to !== null ? levelName(h.to) : '',
                      reason: h.reason && h.reason !== 'promoted' ? app.translator.trans(`${NS}.forum.history.reason_${h.reason}`) : '',
                    })}
                    {h.actor ? <em> · {h.actor}</em> : null}
                  </span>
                </li>
              ))}
            </ul>
          </section>
        ) : null}
      </div>
    );
  }

  nextCard(p: Progress): Mithril.Children {
    const rows = p.next!.rows;
    const done = rows.filter((r) => r.met).length;

    return (
      <section className="TrustCard">
        <h3>{t('next_title', { level: levelName(p.next!.level) })}</h3>
        <p className="TrustCard-sub">
          {t('next_sub', { done, total: rows.length })}
          {p.next!.level === 3 ? <> · {t('window')}</> : null}
          {p.computedAt ? <> · {t('updated', { time: humanTime(p.computedAt) })}</> : null}
        </p>
        {Rows(rows)}
      </section>
    );
  }

  staffBar(p: Progress): Mithril.Children {
    const user = this.user!;
    const self = app.session.user === user;

    return (
      <div className="TrustPage-staff">
        {p.note ? (
          <p className="TrustPage-staffNote">
            <Icon name="far fa-note-sticky" /> {p.note}
          </p>
        ) : null}
        <div className="TrustPage-staffButtons">
          <Button
            className="Button"
            icon="fas fa-shield-halved"
            onclick={() => app.modal.show(TrustModal, { user, progress: p, onsave: (r: Progress) => (this.progress = r) })}
          >
            {t('change')}
          </Button>
          {p.silenced ? (
            <Button className="Button" icon="fas fa-hand-peace" loading={this.busy} onclick={() => this.update({ silenced: false })}>
              {t('unsilence')}
            </Button>
          ) : null}
          {p.silenced && !self ? (
            <Button className="Button Button--danger" icon="fas fa-trash" loading={this.busy} onclick={() => this.confirmSpam()}>
              {t('confirm_spam')}
            </Button>
          ) : null}
        </div>
      </div>
    );
  }

  confirmSpam() {
    if (!confirm(extractText(t('confirm_spam_prompt', { username: this.user!.displayName() })))) return;

    this.busy = true;
    app
      .request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/trust-levels/users/${this.user!.id()}/spam` })
      .then(() => this.load())
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }
}
