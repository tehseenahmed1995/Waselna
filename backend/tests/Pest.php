<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest configuration
|--------------------------------------------------------------------------
|
| Suite bindings for the four backend suites of the Testing Strategy:
| Unit (framework-free Domain code), Feature (HTTP layer), Property
| (Pest + giorgiosironi/eris, >=100 generated inputs per property), and
| Integration (Payment_Gateway, Realtime_Gateway, AWS-hosted behaviour).
|
| Shared expectations, datasets, and helpers are added by the tasks that
| introduce them; this file intentionally declares only the bindings.
|
*/

pest()->extend(Tests\TestCase::class)->in('Feature', 'Property', 'Integration');
