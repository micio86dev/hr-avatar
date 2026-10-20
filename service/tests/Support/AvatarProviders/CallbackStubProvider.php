<?php

namespace Tests\Support\AvatarProviders;

use App\AvatarProviders\Ingestion;

final class CallbackStubProvider extends StubProvider
{
    public function ingestion(): Ingestion
    {
        return Ingestion::Callback;
    }
}
