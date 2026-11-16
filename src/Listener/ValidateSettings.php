<?php

namespace ErnestDefoe\TrustLevels\Listener;

use ErnestDefoe\TrustLevels\Config;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ValidationException;
use Flarum\Group\Group;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\Event\Saving;
use Illuminate\Database\ConnectionInterface;

/**
 * A level's group must be a group of its own: not Admin, Guest, Member or
 * Mod, and not one of Ladder's rank groups (Ladder owns those: it shows them
 * as badges and moves members in and out of them).
 */
class ValidateSettings
{
    public function __construct(
        private ConnectionInterface $db,
        private ExtensionManager $extensions,
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(Saving $event): void
    {
        if (! array_key_exists(Config::KEY, $event->settings)) {
            return;
        }

        $stored = json_decode((string) $event->settings[Config::KEY], true);
        $groups = array_filter(array_map('intval', (array) ($stored['groups'] ?? [])));

        if (! $groups) {
            return;
        }

        $reserved = [Group::ADMINISTRATOR_ID, Group::GUEST_ID, Group::MEMBER_ID, Group::MODERATOR_ID];

        if (array_intersect($groups, $reserved)) {
            throw new ValidationException(['groups' => $this->translator->trans('ernestdefoe-trust-levels.api.group_reserved')]);
        }

        if (count($groups) !== count(array_unique($groups))) {
            throw new ValidationException(['groups' => $this->translator->trans('ernestdefoe-trust-levels.api.group_twice')]);
        }

        if ($this->extensions->isEnabled('ernestdefoe-ladder') && $this->db->table('ladder_rungs')->whereIn('group_id', $groups)->exists()) {
            throw new ValidationException(['groups' => $this->translator->trans('ernestdefoe-trust-levels.api.group_ladder')]);
        }
    }
}
