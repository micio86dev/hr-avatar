<?php

use App\AvatarProviders\TranscriptShapeError;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\TranscriptStatus;
use App\Jobs\ReconcileTranscript;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\AvatarProviders\ScriptedProvider;

/*
 * The job is dispatched, not called: the queue builds the job, hands it its queue job and runs it, so
 * `fail()` and the failure hook are the ones a worker uses. (QUEUE_CONNECTION is sync in the test
 * environment; a sync job is marked failed and its failure hook is run exactly as a worker does it.)
 */

beforeEach(function () {
    ScriptedProvider::register();
    $this->account = Account::factory()->create();
    $config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    $this->conversation = inAccount($this->account, fn () => Conversation::factory()->create([
        'avatar_config_id' => $config->id, 'provider' => 'scripted', 'status' => ConversationStatus::Ended, 'ended_reason' => EndedReason::Api,
        'ended_at' => now()->subMinutes(2), 'provider_session_ref' => 'sess-1',
    ]));
    inAccount($this->account, fn () => ConversationEvent::factory()->create(['conversation_id' => $this->conversation->id, 'text' => 'Kept']));
});

function transcriptStatusOf(Conversation $conversation): TranscriptStatus
{
    return Conversation::withoutGlobalScopes()->findOrFail($conversation->id)->transcript_status;
}

describe('a dispatched job that cannot read the transcript', function () {
    it('finishes the transcript as partial at once, after one provider call, and is recorded as failed', function () {
        ScriptedProvider::$transcripts = [fn () => throw new TranscriptShapeError('A transcript row has an unknown role.')];
        Event::fake([JobFailed::class]);

        ReconcileTranscript::dispatch($this->account->id, $this->conversation->id);

        expect(ScriptedProvider::$calls)->toHaveCount(1)
            ->and(transcriptStatusOf($this->conversation))->toBe(TranscriptStatus::Partial);
        Event::assertDispatched(JobFailed::class, fn (JobFailed $event) => $event->exception instanceof TranscriptShapeError);
    });

    it('finishes the transcript as partial at once when the provider needs a key and there is none', function () {
        config()->set('hr-avatar.providers.scripted.credential', true);
        config()->set('hr-avatar.platform_keys.scripted', null);

        ReconcileTranscript::dispatch($this->account->id, $this->conversation->id);

        expect(ScriptedProvider::$calls)->toBe([])
            ->and(transcriptStatusOf($this->conversation))->toBe(TranscriptStatus::Partial);
    });
});

describe('the failure hook', function () {
    it('does not throw when finishing the transcript fails, and logs the kind of failure', function () {
        $logs = captureLogs();
        DB::beforeExecuting(function (string $query) {
            if (str_starts_with($query, 'update "conversations"')) {
                throw new RuntimeException('database-is-down-SECRET');
            }
        });

        (new ReconcileTranscript($this->account->id, $this->conversation->id))->failed(new RuntimeException('gave up'));

        $everything = implode("\n", $logs->getArrayCopy());
        expect(transcriptStatusOf($this->conversation))->toBe(TranscriptStatus::Pending)
            ->and($everything)->toContain('Transcript could not be finished as partial')->and($everything)->toContain($this->conversation->id)
            ->and($everything)->not->toContain('database-is-down-SECRET');
    });
});
