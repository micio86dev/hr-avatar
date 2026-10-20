<?php

/*
 * The coverage report is assembled in the process that runs `php artisan test --coverage`. As the suite grew
 * it no longer fit PHP's default memory limit (128 MB on a stock CLI) and the run died after every test had
 * passed, so the workflow states the limit it needs instead of relying on the runner's default.
 */
it('gives the job PHP enough memory for the coverage report', function () {
    $workflow = (string) file_get_contents(dirname(base_path()).'/.github/workflows/service.yml');

    expect($workflow)->toContain('ini-values: memory_limit=1G');
});
