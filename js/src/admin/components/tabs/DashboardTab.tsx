import app from 'flarum/admin/app';
import Button from 'flarum/common/components/Button';
import Alert from 'flarum/common/components/Alert';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { NS, MAX, levelName } from '../../../common/levels';
import { t, type Dashboard } from '../shared';
import type TrustLevelsPage from '../TrustLevelsPage';

/** Members per level, who's closest to their next one, this week's changes and the last check. */
export default function dashboardTab(page: TrustLevelsPage): Mithril.Children {
  const d = page.dashboard;
  if (!d) return <LoadingIndicator />;

  return (
    <div className="TrustAdmin-dashboard">
      {d.stale ? (
        <Alert type="warning" dismissible={false}>
          {t('dashboard.stale')}
        </Alert>
      ) : null}
      {levelCounts(d)}
      <div className="TrustAdmin-two">
        {closest(d)}
        {recent(d)}
      </div>
      <p className="TrustAdmin-run">
        <Icon name={d.stale ? 'fas fa-triangle-exclamation' : 'fas fa-circle-check'} />{' '}
        {d.lastRun
          ? t('dashboard.last_run', { time: humanTime(new Date(d.lastRun)), count: d.lastRunCount, seconds: d.lastRunSeconds })
          : t('dashboard.never_run')}{' '}
        <Button className="Button" loading={page.running} onclick={() => page.loadDashboard(true)}>
          {t('dashboard.run_now')}
        </Button>
      </p>
    </div>
  );
}

function moves(d: Dashboard, level: number): Mithril.Children {
  if (level === MAX) return t('dashboard.by_staff');

  const { up, down } = d.moves[level];
  if (!up && !down) return t('dashboard.no_moves');

  return [up ? `+${up}` : null, down ? `−${down}` : null].filter(Boolean).join(' · ') + ' ' + t('dashboard.this_week');
}

function levelCounts(d: Dashboard): Mithril.Children {
  const total = d.counts.reduce((a, b) => a + b, 0) || 1;

  return [
    <div className="TrustAdmin-stats">
      {d.counts.map((n, level) => (
        <div className={'TrustAdmin-stat TrustAdmin-stat--' + level}>
          <div className="TrustAdmin-statNum">{n.toLocaleString()}</div>
          <div className="TrustAdmin-statLabel">
            {level} · {levelName(level)}
          </div>
          <div className="TrustAdmin-statMoves">{moves(d, level)}</div>
        </div>
      ))}
    </div>,
    <div className="TrustAdmin-dist" aria-hidden="true">
      {d.counts.map((n, level) => (
        <span className={'TrustAdmin-dist--' + level} style={{ width: (n / total) * 100 + '%' }} />
      ))}
    </div>,
  ];
}

function closest(d: Dashboard): Mithril.Children {
  return (
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
  );
}

function recent(d: Dashboard): Mithril.Children {
  return (
    <section className="TrustAdmin-box">
      <h4>{t('dashboard.recent_title')}</h4>
      {d.recent.length ? (
        d.recent.map((r) => (
          <div className="TrustAdmin-row">
            <div className="TrustAdmin-rowMain">
              {r.username}
              <em>
                {humanTime(new Date(r.at))}
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
  );
}
