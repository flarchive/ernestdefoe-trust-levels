import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import TrustLevelsPage from './components/TrustLevelsPage';

const t = (key: string) => app.translator.trans(`ernestdefoe-trust-levels.admin.permissions.${key}`);

export const extend = [
  new Extend.Admin()
    .page(TrustLevelsPage)
    .permission(() => ({ icon: 'fas fa-shield-halved', label: t('manage'), permission: 'ernestdefoe-trust-levels.manage' }), 'moderate', 70)
    .permission(() => ({ icon: 'fas fa-person-running', label: t('bypass'), permission: 'ernestdefoe-trust-levels.bypassLimits' }), 'moderate', 69),
];

app.initializers.add('ernestdefoe/trust-levels', () => {});
