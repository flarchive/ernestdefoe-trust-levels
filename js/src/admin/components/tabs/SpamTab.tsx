import Alert from 'flarum/common/components/Alert';
import type Mithril from 'mithril';
import { levelName } from '../../../common/levels';
import { t, enabled, defaults } from '../shared';
import type TrustLevelsPage from '../TrustLevelsPage';

/** Reports that hide a post, who counts, who is put on hold, and upheld reports that drop a level. */
export default function spamTab(page: TrustLevelsPage): Mithril.Children {
  const docket = enabled('ernestdefoe-docket');
  const spam = defaults().spam;
  const row = (key: string, params: Record<string, any> = {}) => (
    <div className="Form-group">
      <label>{t(`spam.${key}`, params)}</label>
      {page.num(['spam', key], spam[key], String(t(`spam.${key}`, params)))}
      <p className="helpText">{t(`spam.${key}_help`, params)}</p>
    </div>
  );
  const levelOf = (key: string) => ({ level: levelName(page.get(['spam', key], spam[key])) });

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
      {docket ? null : row('flags')}
      {row('flagger_level', levelOf('flagger_level'))}
      {row('silence_up_to', levelOf('silence_up_to'))}
      {enabled('ernestdefoe-gatehouse') ? row('gatehouse_days') : null}
      {row('drop_flags')}
    </div>
  );
}
