import app from 'flarum/admin/app';
import type Mithril from 'mithril';
import { levelName } from '../../../common/levels';
import { t, NEEDS, enabled, defaults } from '../shared';
import type TrustLevelsPage from '../TrustLevelsPage';

const LEVELS = [0, 1, 2, 3, 4];

/** The limits grid, uploads, approval and the groups that are never limited. */
export default function limitsTab(page: TrustLevelsPage): Mithril.Children {
  return (
    <div>
      <p className="helpText">{t('limits.intro')}</p>
      <div className="TrustAdmin-gridWrap">
        <table className="TrustAdmin-grid">
          <thead>
            <tr>
              <th className="TrustAdmin-sticky">{t('limits.limit')}</th>
              {LEVELS.map((l) => (
                <th>
                  {l} · {levelName(l)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {Object.keys(defaults().limits).map((key) => limitRow(page, key))}
            {abilityRows(page)}
          </tbody>
        </table>
      </div>
      {exemptGroups(page)}
    </div>
  );
}

function limitRow(page: TrustLevelsPage, key: string): Mithril.Children {
  const start = { ...defaults().limits[key] };

  return (
    <tr>
      <th scope="row" className="TrustAdmin-sticky">
        {t(`limits.${key}`)}
        {NEEDS[key] && !enabled(NEEDS[key]) ? <em>{t('levels.not_counted')}</em> : null}
      </th>
      {LEVELS.map((l) => {
        const stored = page.config.limits?.[key];
        const value = stored === undefined ? start[l] : stored?.[l];

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
                if (page.config.limits?.[key] === undefined) page.set(['limits', key], start);
                page.set(['limits', key, l], v === '' ? null : Number(v));
              }}
            />
          </td>
        );
      })}
    </tr>
  );
}

function abilityRows(page: TrustLevelsPage): Mithril.Children {
  return [
    <tr>
      <th scope="row" className="TrustAdmin-sticky">
        {t('limits.uploads')}
        {!enabled('fof-upload') ? <em>{t('levels.not_counted')}</em> : null}
      </th>
      <td colSpan={5}>
        <select
          className="FormControl TrustAdmin-from"
          value={String(page.get(['uploads_from'], defaults().uploads_from))}
          onchange={(e: Event) => page.set(['uploads_from'], Number((e.target as HTMLSelectElement).value))}
        >
          {LEVELS.map((l) => (
            <option value={l}>{t('limits.from_level', { level: levelName(l) })}</option>
          ))}
        </select>
      </td>
    </tr>,
    <tr>
      <th scope="row" className="TrustAdmin-sticky">
        {t('limits.approval')}
        {!enabled('flarum-approval') ? <em>{t('levels.not_counted')}</em> : null}
      </th>
      <td colSpan={5}>
        {page.num(['approval_posts'], defaults().approval_posts, String(t('limits.approval')))}{' '}
        <span className="helpText">{t('limits.approval_help', { level: levelName(0) })}</span>
      </td>
    </tr>,
  ];
}

function exemptGroups(page: TrustLevelsPage): Mithril.Children {
  const list: number[] = page.get(['exempt_groups'], []);

  return (
    <div className="Form-group">
      <label>{t('limits.exempt')}</label>
      <p className="helpText">{t('limits.exempt_help')}</p>
      <div className="TrustAdmin-checks">
        {app.store
          .all<any>('groups')
          .filter((g) => !['1', '2', '3'].includes(g.id()))
          .map((g) => {
            const id = Number(g.id());

            return (
              <label className="checkbox">
                <input
                  type="checkbox"
                  checked={list.includes(id)}
                  onchange={(e: Event) =>
                    page.set(['exempt_groups'], (e.target as HTMLInputElement).checked ? [...list, id] : list.filter((x) => x !== id))
                  }
                />
                {g.namePlural()}
              </label>
            );
          })}
      </div>
    </div>
  );
}
