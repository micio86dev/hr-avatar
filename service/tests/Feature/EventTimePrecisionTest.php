<?php

use App\AvatarProviders\TranscriptLine;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\TranscriptReconciler;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use Illuminate\Support\Facades\Queue;

/*
 * A transcript is ordered by when each line was spoken, so the time of a line is kept to the microsecond
 * whoever wrote it: lines of one second are not left to the order they arrived in.
 */
it('keeps the sub-second time of the lines a provider reports, in UTC', function () {
    Queue::fake();
    $account = Account::factory()->create();
    $config = inAccount($account, fn () => AvatarConfig::factory()->create());
    $conversation = inAccount($account, fn () => Conversation::factory()->create([
        'avatar_config_id' => $config->id, 'status' => ConversationStatus::Ended, 'ended_reason' => EndedReason::Api, 'ended_at' => now(), 'provider_session_ref' => 'sess-1',
    ]));

    app(TranscriptReconciler::class)->reconcile($conversation, [
        new TranscriptLine('user', 'Later in the second', new DateTimeImmutable('2026-10-19T14:00:00.750123+02:00'), null),
        new TranscriptLine('avatar', 'Earlier in the second', new DateTimeImmutable('2026-10-19T12:00:00.250Z'), null),
    ]);

    $times = ConversationEvent::withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('sequence')->get()
        ->map(fn (ConversationEvent $event) => $event->occurred_at?->utc()->format('Y-m-d H:i:s.u'))->all();
    expect($times)->toBe(['2026-10-19 12:00:00.750123', '2026-10-19 12:00:00.250000']);
});
