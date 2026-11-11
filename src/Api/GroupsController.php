<?php

namespace ErnestDefoe\TrustLevels\Api;

use ErnestDefoe\TrustLevels\Config;
use ErnestDefoe\TrustLevels\Names;
use Flarum\Group\Group;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/trust-levels/groups: create a hidden group for each level that
 * has none ("Trust 2 · Member"), and tie it to the level. Admins only.
 */
class GroupsController implements RequestHandlerInterface
{
    public function __construct(private Config $config, private SettingsRepositoryInterface $settings, private Names $names)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $all = $this->config->all();
        $raw = json_decode((string) $this->settings->get(Config::KEY), true) ?: [];

        for ($level = 1; $level <= Config::MAX; $level++) {
            if (! empty($all['groups'][$level]) && Group::query()->whereKey($all['groups'][$level])->exists()) {
                continue;
            }

            $name = 'Trust '.$level.' · '.$this->names->name($level);
            $group = new Group();
            $group->name_singular = $name;
            $group->name_plural = $name;
            $group->icon = 'fas fa-shield-halved';
            $group->is_hidden = true;
            $group->save();
            $raw['groups'][$level] = $group->id;
        }

        $this->settings->set(Config::KEY, json_encode($raw));
        $this->config->refresh();

        return new JsonResponse(['data' => ['groups' => $this->config->all()['groups']]]);
    }
}
