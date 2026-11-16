import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import User from 'flarum/common/models/User';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Button from 'flarum/common/components/Button';
import Alert from 'flarum/common/components/Alert';
import Icon from 'flarum/common/components/Icon';
import UserPageResolver from 'flarum/forum/resolvers/UserPageResolver';
import UserControls from 'flarum/forum/utils/UserControls';
import type Mithril from 'mithril';
import { NS, levelName } from '../common/levels';
import TrustPage from './components/TrustPage';
import TrustModal from './components/TrustModal';
import { LevelReachedNotification, LevelDroppedNotification } from './components/LevelNotification';

declare const m: Mithril.Static;

/*
 * forum.js is on every page and stays small: the profile tab and its route,
 * the two alerts, the staff menu item, the "on hold" notice and the composer's
 * link/image counter. Levels are only in the payload for the member themselves
 * and staff, so nothing here shows anyone else's.
 */

/** Links and images in what's being typed, counted the way the server counts the parsed post (roughly). */
function countLinks(text: string): { links: number; images: number } {
  const images = (text.match(/!\[[^\]]*\]\([^)]*\)|\[img\][\s\S]*?\[\/img\]|\[upl-image(?:-preview)?\b[^\]]*\]/gi) || []).length;
  const stripped = text.replace(/!\[[^\]]*\]\([^)]*\)|\[img\][\s\S]*?\[\/img\]|\[upl-image(?:-preview)?\b[^\]]*\]/gi, ' ');
  const links = (stripped.match(/\b(?:https?:\/\/|www\.)[^\s<>()\]]+|\[url[=\]]|\]\([^)]+\)/gi) || []).length;

  return { links, images };
}

app.initializers.add('ernestdefoe/trust-levels', () => {
  app.routes['user.trust'] = { path: '/u/:username/trust', component: TrustPage, resolverClass: UserPageResolver } as any;

  app.notificationComponents.trustLevelReached = LevelReachedNotification;
  app.notificationComponents.trustLevelDropped = LevelDroppedNotification;

  // Members can turn the promotion email on (it's off by default).
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    items.add('trustLevelReached', {
      name: 'trustLevelReached',
      icon: 'fas fa-shield-halved',
      label: app.translator.trans(`${NS}.forum.settings.notify_reached_label`),
    });
  });

  // The profile tab, for the member themselves and staff (the only ones the level reaches).
  extend('flarum/forum/components/UserPage', 'navItems', function (this: any, items: ItemList<Mithril.Children>) {
    const user = this.user;
    if (!user || user.attribute('trustLevel') === undefined) return;

    items.add(
      'trustLevel',
      <LinkButton href={app.route('user.trust', { username: user.slug() })} icon="fas fa-shield-halved">
        {app.translator.trans(`${NS}.forum.page.nav`)}
      </LinkButton>,
      60
    );
  });

  extend(UserControls, 'moderationControls', function (items: ItemList<Mithril.Children>, user: any) {
    if (!app.forum.attribute('canManageTrustLevels')) return;

    items.add(
      'trustLevel',
      <Button icon="fas fa-shield-halved" onclick={() => app.modal.show(TrustModal, { user })}>
        {app.translator.trans(`${NS}.forum.modal.button`)}
      </Button>
    );
  });

  // "Your account is on hold": under the hero of every page, while it lasts.
  extend('flarum/forum/components/PageStructure', 'mainItems', function (items: ItemList<Mithril.Children>) {
    if (!app.session.user?.attribute('trustSilenced')) return;

    items.add(
      'trustSilenced',
      <div className="container">
        <Alert type="warning" dismissible={false} className="TrustSilenced">
          <strong>{app.translator.trans(`${NS}.forum.silenced.title`)}</strong> {app.translator.trans(`${NS}.forum.silenced.body`)}
        </Alert>
      </div>,
      50
    );
  });

  // The editor writes through a plain DOM listener, so typing doesn't redraw: redraw only when a count changes.
  extend('flarum/common/components/TextEditor', 'oninput', function (this: any) {
    if (!app.session.user?.attribute('trustLimits')) return;

    const counts = countLinks(String(this.value || ''));
    const key = counts.links + ':' + counts.images;

    if (key !== this.trustCounts) {
      this.trustCounts = key;
      m.redraw();
    }
  });

  // flarum/messages: a member who can't send yet sees its buttons disabled, in their own
  // browser only. Other members' copies of this member are never touched.
  type Limits = { links: number | null; images: number | null; messages?: boolean; messagesFrom?: number | null } | null;
  const ownLimits = (user: any): Limits => (user && user === app.session.user ? user.attribute('trustLimits') : null);

  if ('canSendAnyMessage' in User.prototype) {
    override(User.prototype as any, 'canSendAnyMessage', function (this: any, original: () => boolean) {
      return ownLimits(this)?.messages === false ? false : original();
    });
  }

  extend('ext:flarum/messages/forum/components/MessagesSidebar', 'items', function (items: ItemList<Mithril.Children>) {
    const limits = ownLimits(app.session.user);
    if (limits?.messages !== false) return;

    items.add(
      'trustLevels',
      <p className="TrustMessagesNote">
        {limits.messagesFrom === null || limits.messagesFrom === undefined
          ? app.translator.trans(`${NS}.forum.messages.on_hold`)
          : app.translator.trans(`${NS}.forum.messages.from_level`, { level: levelName(limits.messagesFrom) })}
      </p>,
      9
    );
  });

  // The composer: "3 of 2 links" as they type, once they're near a limit.
  extend('flarum/common/components/TextEditor', 'controlItems', function (this: any, items: ItemList<Mithril.Children>) {
    const limits = app.session.user?.attribute<{ links: number | null; images: number | null } | null>('trustLimits');
    if (!limits || !app.composer?.body) return;

    const counts = countLinks(String(this.value || ''));
    const chips: Mithril.Children[] = [];

    (['links', 'images'] as const).forEach((kind) => {
      const max = limits[kind];
      if (max === null || max === undefined || counts[kind] === 0) return;

      chips.push(
        <span className={'TrustLimit' + (counts[kind] > max ? ' TrustLimit--over' : '')}>
          <Icon name={kind === 'links' ? 'fas fa-link' : 'far fa-image'} />{' '}
          {app.translator.trans(`${NS}.forum.composer.${kind}`, { count: counts[kind], max })}
        </span>
      );
    });

    if (chips.length) items.add('trustLimits', <span className="TrustLimits">{chips}</span>, -100);
  });
});
