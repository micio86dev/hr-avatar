<?php

namespace App\Http\Requests;

class UpdateWebhookEndpointRequest extends WebhookEndpointRequest
{
    protected function presence(): string
    {
        return 'sometimes';
    }
}
