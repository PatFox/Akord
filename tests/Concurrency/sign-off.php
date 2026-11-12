<?php

/**
 * Test helper: one sign-off in its own process and database connection.
 * Usage: php sign-off.php <contract> <party> <user> <fingerprint> <start-at microtime>
 * Prints the HTTP status the action maps to.
 */

use App\Actions\Contracts\SignOffContract;
use App\Models\Contract;
use App\Models\Party;
use App\Models\User;
use App\Support\Actor;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $contractId, $partyId, $userId, $fingerprint, $startAt] = $argv;

$actor = Actor::user(User::query()->findOrFail((int) $userId), Party::query()->findOrFail((int) $partyId));
$contract = Contract::query()->findOrFail((int) $contractId);

while (microtime(true) < (float) $startAt) {
    usleep(100);
}

try {
    app(SignOffContract::class)->handle($actor, $contract, $fingerprint);
    echo 200;
} catch (HttpExceptionInterface $e) {
    echo $e->getStatusCode();
}
