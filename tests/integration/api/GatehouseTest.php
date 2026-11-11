<?php

namespace ErnestDefoe\TrustLevels\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** Gatehouse: someone put on hold in their first days and sent back to its queue. */
class GatehouseTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-gatehouse', 'ernestdefoe-trust-levels');

        $this->prepareDatabase([
            User::class => [
                ['id' => 3, 'username' => 'held', 'email' => 'held@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now(), 'trust_level_at' => Carbon::now(), 'trust_silenced_at' => Carbon::now(), 'gatehouse_status' => 'pending'],
            ],
        ]);
    }

    #[Test]
    public function approving_them_in_gatehouse_lifts_the_hold(): void
    {
        $response = $this->send($this->request('POST', '/api/gatehouse/applicants/3/approve', ['authenticatedAs' => 1]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertNull($this->database()->table('users')->where('id', 3)->value('trust_silenced_at'));
        $this->assertSame(1, $this->database()->table('trust_events')->where('user_id', 3)->where('type', 'unsilenced')->count());
    }
}
