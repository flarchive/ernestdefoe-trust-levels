import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import { t, enabled } from '../shared';
import type TrustLevelsPage from '../TrustLevelsPage';

const ITEMS: [string, string][] = [
  ['ernestdefoe-ladder', 'ladder'],
  ['ernestdefoe-docket', 'docket'],
  ['ernestdefoe-gatehouse', 'gatehouse'],
  ['ernestdefoe-sleuth', 'sleuth'],
  ['ernestdefoe-onramp', 'onramp'],
  ['ernestdefoe-buzz', 'buzz'],
];

/** Which optional extensions are on, and the Buzz toggle. */
export default function integrationsTab(page: TrustLevelsPage): Mithril.Children {
  return (
    <div>
      <ul className="TrustAdmin-integrations">
        {ITEMS.map(([id, key]) => (
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
                    checked={!!page.get(['buzz'])}
                    onchange={(e: Event) => page.set(['buzz'], (e.target as HTMLInputElement).checked)}
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
