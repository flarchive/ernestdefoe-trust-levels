import app from 'flarum/admin/app';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import { NS, levelName } from '../../../common/levels';
import { t, REQS, REQ_ROWS, NEEDS, enabled, defaults } from '../shared';
import type TrustLevelsPage from '../TrustLevelsPage';

/** Level names, the requirement grid, level groups and the Regular grace period. */
export default function levelsTab(page: TrustLevelsPage): Mithril.Children {
  const missingGroups = [1, 2, 3, 4].some((l) => !page.get(['groups', l], null));

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
            {namesRow(page)}
            {requirementRows(page)}
            {groupsRow(page)}
          </tbody>
        </table>
      </div>
      {missingGroups ? (
        <p className="TrustAdmin-groups">
          <Button className="Button" icon="fas fa-users" onclick={() => page.createGroups()}>
            {t('levels.create_groups')}
          </Button>{' '}
          <span className="helpText">{t('levels.create_groups_help')}</span>
        </p>
      ) : null}
      <div className="Form-group">
        <label>{t('levels.grace')}</label>
        {page.num(['grace_days'], defaults().grace_days)} <span className="helpText">{t('levels.grace_help', { level: levelName(3) })}</span>
      </div>
    </div>
  );
}

function namesRow(page: TrustLevelsPage): Mithril.Children {
  return (
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
            value={page.get(['names', l])}
            oninput={(e: InputEvent) => page.set(['names', l], (e.target as HTMLInputElement).value)}
          />
        </td>
      ))}
    </tr>
  );
}

function requirementRows(page: TrustLevelsPage): Mithril.Children {
  const req = defaults().req;

  return REQ_ROWS.map((key, i) => (
    <tr>
      <th scope="row" className="TrustAdmin-sticky">
        {t(`req.${key}`)}
        {NEEDS[key] && !enabled(NEEDS[key]) ? <em>{t('levels.not_counted')}</em> : null}
      </th>
      {[1, 2, 3].map((l) =>
        REQS[l].includes(key) ? <td>{page.num(['req', l, key], req[l][key], String(t(`req.${key}`)))}</td> : <td className="TrustAdmin-na">–</td>
      )}
      {i === 0 ? (
        <td rowSpan={REQ_ROWS.length} className="TrustAdmin-staffOnly">
          {t('levels.by_staff')}
        </td>
      ) : null}
    </tr>
  ));
}

function groupsRow(page: TrustLevelsPage): Mithril.Children {
  const groups = app.store.all<any>('groups').filter((g) => !['1', '2', '3', '4'].includes(g.id()));

  return (
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
            value={String(page.get(['groups', l], ''))}
            onchange={(e: Event) =>
              page.set(['groups', l], (e.target as HTMLSelectElement).value ? Number((e.target as HTMLSelectElement).value) : null)
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
  );
}
