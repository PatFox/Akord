<?php

use App\Support\ContractFingerprint;

$a = str_repeat('a', 64);
$b = str_repeat('b', 64);

test('the fingerprint algorithm is pinned: changing it invalidates every stored sign-off', function () use ($a, $b) {
    expect(ContractFingerprint::compute(['step00000000000a' => $a, 'step00000000000b' => $b], ['party0000000000c', 'party0000000000e']))
        ->toBe('bd1d04317639e21d231c37c97f45350b353edb2fb6211a481702e48861e1d25d');
});

test('the fingerprint ignores the order of steps and of required parties', function () use ($a, $b) {
    expect(ContractFingerprint::compute(['step00000000000b' => $b, 'step00000000000a' => $a], ['party0000000000e', 'party0000000000c']))
        ->toBe(ContractFingerprint::compute(['step00000000000a' => $a, 'step00000000000b' => $b], ['party0000000000c', 'party0000000000e']));
});

test('the fingerprint changes with content and with the required parties', function () use ($a, $b) {
    $base = ContractFingerprint::compute(['step00000000000a' => $a, 'step00000000000b' => $b], ['party0000000000c', 'party0000000000e']);

    expect(ContractFingerprint::compute(['step00000000000a' => $a, 'step00000000000b' => $a], ['party0000000000c', 'party0000000000e']))->not->toBe($base)
        ->and(ContractFingerprint::compute(['step00000000000a' => $a, 'step00000000000b' => $b], ['party0000000000c']))->not->toBe($base)
        ->and(ContractFingerprint::compute(['step00000000000a' => $a], ['party0000000000c', 'party0000000000e']))->not->toBe($base);
});
