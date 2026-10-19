<?php

namespace Tests\Support\AvatarProviders;

use App\AvatarProviders\Ingestion;

final class PollStubProvider extends StubProvider
{
    public function ingestion(): Ingestion
    {
        return Ingestion::Poll;
    }
}
