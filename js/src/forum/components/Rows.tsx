import app from 'flarum/forum/app';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import { NS, Row } from '../../common/levels';

declare const m: Mithril.Static;

/** A requirement list: met or not, a bar, "have / need". */
export default function Rows(rows: Row[]): Mithril.Children {
  return rows.map((row) => {
    const pct = row.upTo ? (row.met ? 100 : 0) : row.need > 0 ? Math.min(100, Math.round((row.have / row.need) * 100)) : 100;

    return (
      <div className={'TrustRow' + (row.met ? ' TrustRow--met' : '')}>
        <Icon name={row.met ? 'fas fa-circle-check' : 'far fa-circle'} className="TrustRow-icon" />
        <span className="TrustRow-label">{app.translator.trans(`${NS}.forum.req.${row.key}`, { need: row.need })}</span>
        <span className="TrustRow-meter" aria-hidden="true">
          <b style={{ width: pct + '%' }} />
        </span>
        <span className="TrustRow-num">
          {row.key === 'not_suspended' ? '' : row.upTo ? `${row.have} / ≤ ${row.need}` : `${row.have} / ${row.need}`}
        </span>
      </div>
    );
  });
}
