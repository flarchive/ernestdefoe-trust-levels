<?php

namespace ErnestDefoe\TrustLevels\Notification;

use ErnestDefoe\TrustLevels\Names;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * "You've reached Member".
 *
 * 🚨 The subject is the member themselves: User has a core API resource, and
 * a subject model without one 500s `/api/notifications` for everyone. Flarum
 * keeps the newest alert per type and subject, so a member has one of these
 * at a time. Email is the member's choice (off by default).
 */
class LevelReachedBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(public User $user, public int $level)
    {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->user;
    }

    public function getData(): mixed
    {
        return ['level' => $this->level];
    }

    public static function getType(): string
    {
        return 'trustLevelReached';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'ernestdefoe-trust-levels::emails.plain.reached',
            'html' => 'ernestdefoe-trust-levels::emails.html.reached',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-trust-levels.email.reached.subject', ['{level}' => resolve(Names::class)->name($this->level)]);
    }
}
