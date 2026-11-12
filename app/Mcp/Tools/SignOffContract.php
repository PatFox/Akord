<?php

namespace App\Mcp\Tools;

use App\Support\Actor;
use App\Support\Agents\AgentApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('sign_off_contract')]
#[Description('Sign off the whole contract for your party. Pass the fingerprint from get_contract_status that you reviewed. 409 if the contract changed since, 422 if not ready, 423 if already agreed, 403 if your party may not sign off.')]
class SignOffContract extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate(['contract_id' => 'required|integer', 'fingerprint' => 'required|string|size:64']);

            return $api->signOff($actor, (int) $v['contract_id'], $v['fingerprint']);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'contract_id' => $schema->integer()->required(),
            'fingerprint' => $schema->string()->description('The fingerprint you reviewed (from get_contract_status).')->required(),
        ];
    }
}
