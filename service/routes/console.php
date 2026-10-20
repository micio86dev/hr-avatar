<?php

use Illuminate\Support\Facades\Schedule;

// Every minute, and never two runs at once.
Schedule::command('hra:conversations:sweep')->everyMinute()->withoutOverlapping();
Schedule::command('hra:conversations:poll')->everyMinute()->withoutOverlapping();
// Queues again the work whose job was lost (a delivery or a provider event stored and never queued).
Schedule::command('hra:relay')->everyMinute()->withoutOverlapping();
