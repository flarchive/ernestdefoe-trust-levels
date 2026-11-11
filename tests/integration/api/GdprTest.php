<?php

namespace ErnestDefoe\TrustLevels\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Gdpr\TrustData;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class GdprTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-gdpr', 'ernestdefoe-trust-levels');

        $this->prepareDatabase([
            User::class => [
                ['id' => 3, 'username' => 'nina', 'email' => 'nina@machine.local', 'is_email_confirmed' => 1, 'trust_level' => 1, 'trust_level_at' => Carbon::now(), 'trust_note' => 'Posted spam twice in May'],
            ],
            'trust_daily' => [['user_id' => 3, 'day' => Carbon::now()->toDateString(), 'posts_read' => 4]],
        ]);
    }

    #[Test]
    public function anonymizing_a_member_clears_staffs_note_and_their_counts(): void
    {
        $this->app();
        $data = $this->app->getContainer()->make(TrustData::class, ['user' => User::query()->find(3), 'erasureRequest' => null]);
        $data->anonymize();

        $this->assertNull($this->database()->table('users')->where('id', 3)->value('trust_note'));
        $this->assertSame(0, $this->database()->table('trust_daily')->where('user_id', 3)->count());
    }
}
