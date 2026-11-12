<?php

namespace App\Actions\Organisations;

use App\Enums\OrganisationRole;
use App\Enums\PartyType;
use App\Models\Organisation;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates an organisation owned by the user, with a personal party for them (content edits are always made as a party).
 */
final class CreateOrganisation
{
    public function handle(User $user, string $name): Organisation
    {
        return DB::transaction(function () use ($user, $name): Organisation {
            $base = Str::slug($name) ?: 'organisation';
            $slug = $base;
            for ($n = 2; Organisation::query()->where('slug', $slug)->exists(); $n++) {
                $slug = "{$base}-{$n}";
            }

            $organisation = Organisation::query()->create(['name' => $name, 'slug' => $slug]);
            $organisation->users()->attach($user, ['role' => OrganisationRole::Owner->value]);

            $party = Party::query()->create([
                'organisation_id' => $organisation->id,
                'name' => $user->name,
                'type' => PartyType::Individual,
            ]);
            $party->users()->attach($user);

            return $organisation;
        });
    }
}
