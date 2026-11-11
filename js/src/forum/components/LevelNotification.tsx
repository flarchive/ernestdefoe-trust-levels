import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import type User from 'flarum/common/models/User';
import { NS, levelName } from '../../common/levels';

/** "You've reached Member" / "You're now Member": both open the progress tab. */
export class LevelReachedNotification extends Notification {
  icon() {
    return 'fas fa-shield-halved';
  }

  href() {
    const user = this.attrs.notification.subject() as User | undefined;

    return user ? app.route('user.trust', { username: user.slug() }) : '#';
  }

  content() {
    return app.translator.trans(`${NS}.forum.notifications.reached`, {
      level: levelName(this.attrs.notification.content<{ level: number }>().level),
    });
  }

  excerpt() {
    return app.translator.trans(`${NS}.forum.notifications.reached_excerpt`);
  }
}

export class LevelDroppedNotification extends LevelReachedNotification {
  icon() {
    return 'fas fa-shield';
  }

  content() {
    return app.translator.trans(`${NS}.forum.notifications.dropped`, {
      level: levelName(this.attrs.notification.content<{ level: number }>().level),
    });
  }

  excerpt() {
    const reason = this.attrs.notification.content<{ reason: string }>().reason;

    return app.translator.trans(`${NS}.forum.notifications.dropped_${reason === 'quiet' || reason === 'flags' ? reason : 'other'}`);
  }
}
