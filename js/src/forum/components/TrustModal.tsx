import app from 'flarum/forum/app';
import FormModal, { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import humanTime from 'flarum/common/helpers/humanTime';
import Stream from 'flarum/common/utils/Stream';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';
import { NS, MAX, levelName } from '../../common/levels';
import type { Progress } from './TrustPage';

declare const m: Mithril.Static;

interface Attrs extends IFormModalAttrs {
  user: User;
  progress?: Progress;
  onsave?: (p: Progress) => void;
}

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`${NS}.forum.modal.${key}`, params);

/** Staff: set a member's level, lock it, leave a note. */
export default class TrustModal extends FormModal<Attrs> {
  level!: number;
  locked!: boolean;
  note!: Stream<string>;
  progress: Progress | null = null;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);

    this.progress = this.attrs.progress || null;
    this.level = this.progress?.level ?? (this.attrs.user.attribute<number>('trustLevel') || 0);
    this.locked = this.progress?.locked ?? !!this.attrs.user.attribute('trustLocked');
    this.note = Stream(this.progress?.note || '');

    if (!this.progress) {
      app
        .request<{ data: Progress }>({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/trust-levels/users/${this.attrs.user.id()}` })
        .then((r) => {
          this.progress = r.data;
          this.level = r.data.level;
          this.locked = r.data.locked;
          this.note(r.data.note || '');
          m.redraw();
        });
    }
  }

  className() {
    return 'TrustModal Modal--small';
  }

  title() {
    return t('title', { username: this.attrs.user.displayName() });
  }

  content() {
    const p = this.progress;

    return (
      <div className="Modal-body">
        {p ? (
          <p className="TrustModal-now">
            {t('now', { level: levelName(p.level) })}
            {p.levelAt ? <> · {humanTime(p.levelAt)}</> : null}
            {p.next ? (
              <> · {t('next', { level: levelName(p.next.level), done: p.next.rows.filter((r) => r.met).length, total: p.next.rows.length })}</>
            ) : null}
          </p>
        ) : null}
        <div className="Form">
          <div className="Form-group">
            <label id="TrustModal-level">{t('level')}</label>
            <div className="TrustModal-levels" role="radiogroup" aria-labelledby="TrustModal-level">
              {Array.from({ length: MAX + 1 }, (_, level) => (
                <button
                  type="button"
                  role="radio"
                  aria-checked={this.level === level}
                  className={'Button' + (this.level === level ? ' Button--primary' : '')}
                  onclick={() => (this.level = level)}
                >
                  {levelName(level)}
                </button>
              ))}
            </div>
          </div>
          <div className="Form-group">
            <label className="checkbox">
              <input type="checkbox" checked={this.locked} onchange={(e: Event) => (this.locked = (e.target as HTMLInputElement).checked)} />
              {t('lock')}
            </label>
            <p className="helpText">{t('lock_help', { username: this.attrs.user.displayName() })}</p>
          </div>
          <div className="Form-group">
            <label for="TrustModal-note">{t('note')}</label>
            <input id="TrustModal-note" className="FormControl" maxlength={200} bidi={this.note} />
          </div>
          <div className="Form-group">
            <Button type="submit" className="Button Button--primary" loading={this.loading}>
              {t('save')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;

    app
      .request<{ data: Progress }>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/trust-levels/users/${this.attrs.user.id()}`,
        body: { level: this.level, locked: this.locked, note: this.note() },
      })
      .then((r) => {
        this.attrs.user.pushAttributes({ trustLevel: r.data.level, trustLocked: r.data.locked });
        this.attrs.onsave?.(r.data);
        this.hide();
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
