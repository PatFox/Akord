<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\ClientRepository;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('akord:oauth-client {name=omp} {--redirect=* : Allowed redirect URIs (default: localhost and 127.0.0.1 on port 3000, path /callback)}', function (ClientRepository $clients) {
    $redirects = array_values(array_map(strval(...), (array) $this->option('redirect'))) ?: ['http://localhost:3000/callback', 'http://127.0.0.1:3000/callback'];

    $client = $clients->createAuthorizationCodeGrantClient(
        name: is_string($name = $this->argument('name')) ? $name : 'omp',
        redirectUris: $redirects,
        confidential: false,
        enableDeviceFlow: false,
    );

    $this->components->info("Public OAuth client \"{$client->name}\" created.");
    $this->line('Client ID: '.$client->id);
    $this->line('Use it only if your MCP client cannot register itself, e.g. in omp: "oauth": {"clientId": "'.$client->id.'"}');
})->purpose('Pre-register a public OAuth client for an MCP client that does not use dynamic client registration');
