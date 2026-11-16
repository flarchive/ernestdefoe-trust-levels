<?php

namespace ErnestDefoe\TrustLevels\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/** A quiet alert when a level drops. Never an email. */
class LevelDroppedBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public User $user, public int $level, public string $reason)
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
        return ['level' => $this->level, 'reason' => $this->reason];
    }

    public static function getType(): string
    {
        return 'trustLevelDropped';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
