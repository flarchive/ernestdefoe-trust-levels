<?php

namespace ErnestDefoe\TrustLevels\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
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
                ['id' => 3, 'username' => 'held', 'email' => 'held@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now(), 'trust_level_at' => Carbon::now(), 'trust_silenced_at' => Carbon::now()],
                ['id' => 4, 'username' => 'fresh', 'email' => 'fresh@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now(), 'trust_level' => 0, 'trust_level_at' => Carbon::now()],
            ],
            'access_tokens' => [
                ['token' => 'freshtoken', 'user_id' => 4, 'last_activity_at' => Carbon::now(), 'created_at' => Carbon::now(), 'type' => 'session'],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Watches', 'user_id' => 4, 'created_at' => Carbon::now(), 'comment_count' => 1, 'first_post_id' => 1, 'last_post_id' => 1, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 4, 'type' => 'comment', 'created_at' => Carbon::now(), 'content' => '<t><p>Buy watches</p></t>'],
            ],
        ]);
    }

    /**
     * The member's place in Gatehouse's queue, however that Gatehouse keeps it:
     * its Applicant model (1.5+) or the users column (1.4).
     */
    private function gatehouseStatus(int $userId): ?string
    {
        if (class_exists('Ernestdefoe\\Gatehouse\\Applicant')) {
            return \Ernestdefoe\Gatehouse\Applicant::query()->find($userId)?->status;
        }

        return $this->database()->table('users')->where('id', $userId)->value('gatehouse_status');
    }

    #[Test]
    public function approving_them_in_gatehouse_lifts_the_hold(): void
    {
        // Held through Gatehouse's own public hold(), so the queue is Gatehouse's, whichever version.
        $this->app();
        $this->app->getContainer()->make(\Ernestdefoe\Gatehouse\Listener\HoldNewMember::class)->hold(User::query()->findOrFail(3), false);
        $this->assertSame('pending', $this->gatehouseStatus(3));

        // Approved the way that Gatehouse approves: its applicant resource (1.5+) or its decide route (1.4).
        $response = class_exists('Ernestdefoe\\Gatehouse\\Applicant')
            ? $this->send($this->request('PATCH', '/api/gatehouse-applicants/3', ['authenticatedAs' => 1, 'json' => ['data' => ['type' => 'gatehouse-applicants', 'id' => '3', 'attributes' => ['status' => 'approved']]]]))
            : $this->send($this->request('POST', '/api/gatehouse/applicants/3/approve', ['authenticatedAs' => 1]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertNull($this->database()->table('users')->where('id', 3)->value('trust_silenced_at'));
        $this->assertSame(1, $this->database()->table('trust_events')->where('user_id', 3)->where('type', 'unsilenced')->count());
    }

    #[Test]
    public function a_new_member_put_on_hold_goes_back_to_gatehouses_queue_through_gatehouse(): void
    {
        $this->app();
        $container = $this->app->getContainer();

        $container->make(\ErnestDefoe\TrustLevels\Spam\Spam::class)->silenceFor(User::query()->findOrFail(4), Post::query()->findOrFail(1));

        // Gatehouse's own hold (its storage, its alerts), and signed out everywhere.
        $this->assertSame('pending', $this->gatehouseStatus(4));
        $this->assertSame(0, $this->database()->table('access_tokens')->where('user_id', 4)->count());
        $this->assertSame(1, $this->database()->table('trust_events')->where('user_id', 4)->where('type', 'gatehouse')->count());
        $this->assertNotNull($this->database()->table('users')->where('id', 4)->value('trust_silenced_at'));
    }
}
