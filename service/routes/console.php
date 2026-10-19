<?php

use Illuminate\Support\Facades\Schedule;

// Every minute, and never two runs at once.
Schedule::command('hra:conversations:sweep')->everyMinute()->withoutOverlapping();
Schedule::command('hra:conversations:poll')->everyMinute()->withoutOverlapping();
