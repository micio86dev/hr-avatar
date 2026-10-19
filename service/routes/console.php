<?php

use Illuminate\Support\Facades\Schedule;

// Every minute, and never two runs at once. The polling of providers that have no webhooks is added by its own slice.
Schedule::command('hra:conversations:sweep')->everyMinute()->withoutOverlapping();
