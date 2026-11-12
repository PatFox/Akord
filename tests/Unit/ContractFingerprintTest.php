<?php

use App\Support\ContractFingerprint;

$a = str_repeat('a', 64);
$b = str_repeat('b', 64);

test('the fingerprint algorithm is pinned: changing it invalidates every stored sign-off', function () use ($a, $b) {
    expect(ContractFingerprint::compute([1 => $a, 2 => $b], [3, 5]))
        ->toBe('10e034ce2ce90a058db1c4fd669c9ae343c486ba0c261c66acaa0bc2ece99416');
});

test('the fingerprint ignores order and whether ids arrive as strings', function () use ($a, $b) {
    expect(ContractFingerprint::compute(['2' => $b, '1' => $a], ['5', 3]))
        ->toBe(ContractFingerprint::compute([1 => $a, 2 => $b], [3, 5]));
});

test('the fingerprint changes with content and with the required parties', function () use ($a, $b) {
    $base = ContractFingerprint::compute([1 => $a, 2 => $b], [3, 5]);

    expect(ContractFingerprint::compute([1 => $a, 2 => $a], [3, 5]))->not->toBe($base)
        ->and(ContractFingerprint::compute([1 => $a, 2 => $b], [3]))->not->toBe($base)
        ->and(ContractFingerprint::compute([1 => $a], [3, 5]))->not->toBe($base);
});
