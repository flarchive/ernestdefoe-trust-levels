<?php

namespace ErnestDefoe\TrustLevels\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Gdpr\TrustData;
use Flarum\Gdpr\DataProcessor;
use Flarum\Gdpr\Models\ErasureRequest;
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
                ['id' => 3, 'username' => 'nina', 'email' => 'nina@machine.local', 'is_email_confirmed' => 1, 'trust_level' => 2, 'trust_level_at' => Carbon::now(), 'trust_locked' => true, 'trust_silenced_at' => Carbon::now(), 'trust_note' => 'Posted spam twice in May'],
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

    /** Runs every registered gdpr data type in order, the way gdpr's ErasureJob does. */
    private function erase(string $mode): void
    {
        $this->app();
        // Flarum connects to MySQL with strict mode off, which turns a NULL into 0; a strict server refuses it, as PostgreSQL does.
        if ($this->database()->getDriverName() === 'mysql') {
            $this->database()->statement("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
        }
        $request = ErasureRequest::query()->forceCreate(['user_id' => 3, 'status' => 'confirmed', 'processed_mode' => $mode, 'created_at' => Carbon::now()]);
        $container = $this->app->getContainer();

        foreach ($container->make(DataProcessor::class)->types() as $type => $extension) {
            $container->make($type, ['user' => User::query()->find(3), 'erasureRequest' => $request])->{$mode === 'anonymization' ? 'anonymize' : 'delete'}();
        }
    }

    #[Test]
    public function gdpr_anonymization_succeeds_and_resets_the_level(): void
    {
        $this->erase('anonymization');

        $row = $this->database()->table('users')->where('id', 3)->first();
        $this->assertSame(1, (int) $row->anonymized);
        $this->assertSame(0, (int) $row->trust_level);
        $this->assertSame(0, (int) $row->trust_locked);
        $this->assertNull($row->trust_level_at);
        $this->assertNull($row->trust_silenced_at);
        $this->assertNull($row->trust_note);
        $this->assertSame(0, $this->database()->table('trust_daily')->where('user_id', 3)->count());
    }

    #[Test]
    public function gdpr_deletion_succeeds(): void
    {
        $this->erase('deletion');

        $this->assertFalse($this->database()->table('users')->where('id', 3)->exists());
        $this->assertSame(0, $this->database()->table('trust_daily')->where('user_id', 3)->count());
    }
}
