<?php

namespace ErnestDefoe\TrustLevels\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Docket\Crowd\Crowd;
use ErnestDefoe\Docket\Crowd\Reporters;
use ErnestDefoe\TrustLevels\Integration\DocketReporters;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Docket 1.5+: its crowd moderation weighs reporters in Crowd\Reporters, and
 * Trust Levels weighs them by level there, built by the container with Docket's
 * real dependencies (1.0.2 extended Crowd, whose constructor 1.5 changed: the
 * admin 500'd).
 */
class DocketTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-flags', 'ernestdefoe-docket', 'ernestdefoe-trust-levels');
        $placed = Carbon::now()->subDays(5);

        $this->prepareDatabase([
            User::class => [
                ['id' => 3, 'username' => 'nina', 'email' => 'nina@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now(), 'trust_level' => 0, 'trust_level_at' => $placed],
                ['id' => 4, 'username' => 'mara', 'email' => 'mara@machine.local', 'is_email_confirmed' => 1, 'trust_level' => 2, 'trust_level_at' => $placed],
                ['id' => 5, 'username' => 'lena', 'email' => 'lena@machine.local', 'is_email_confirmed' => 1, 'trust_level' => 4, 'trust_level_at' => $placed],
            ],
        ]);
    }

    #[Test]
    public function docket_builds_its_crowd_and_weighs_reporters_by_level(): void
    {
        // Docket's own track-record weighting off (it's on by default): the level alone.
        $this->setting('ernestdefoe-docket.crowd_trust', '0');
        $this->app();
        $container = $this->app->getContainer();

        $this->assertInstanceOf(Crowd::class, $container->make(Crowd::class));

        $reporters = $container->make(Reporters::class);
        $this->assertInstanceOf(DocketReporters::class, $reporters);
        $this->assertSame([3 => 0.5, 4 => 1.0, 5 => 1.5], $reporters->weights([3, 4, 5]));
    }

    #[Test]
    public function with_docket_track_record_on_the_two_weights_are_averaged(): void
    {
        $this->setting('ernestdefoe-docket.crowd_trust', '1');
        $this->app();

        // No closed reports yet: Docket counts everyone 1.0, averaged with the level.
        $this->assertSame([3 => 0.75, 4 => 1.0, 5 => 1.25], $this->app->getContainer()->make(Reporters::class)->weights([3, 4, 5]));
    }

    #[Test]
    public function the_admin_loads_with_docket_enabled(): void
    {
        $response = $this->send($this->request('GET', '/admin', ['authenticatedAs' => 1]));
        $this->assertSame(200, $response->getStatusCode());

        $response = $this->send($this->request('GET', '/api', ['authenticatedAs' => 1]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }
}
