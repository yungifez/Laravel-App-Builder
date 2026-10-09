<?php

namespace App\Actions\Accounts;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

class ListLetInTools
{
    /**
     * The tools a person let in through OAuth (the Claude app, VS Code,
     * Cursor) that can still reach their apps: each holds a pass that
     * works, or one it can renew. A tool renews its pass about every hour
     * while it works, so the newest pass says when it was last used.
     *
     * @return list<array{id: string, name: string, allowed: string, used: string}>
     */
    public function handle(User $user): array
    {
        $passes = Token::query()
            ->where('user_id', $user->getKey())
            ->where('revoked', false)
            ->get(['client_id', 'created_at'])
            ->groupBy('client_id');

        $tools = Client::query()
            ->whereIn('id', self::live($user)->select('client_id'))
            ->get(['id', 'name'])
            ->map(function (Client $client) use ($passes) {
                /** @var Collection<int, CarbonInterface> $times */
                $times = $passes[$client->id]->pluck('created_at')->sort()->values();

                return ['id' => (string) $client->id, 'name' => $client->name, 'first' => $times->first(), 'last' => $times->last()];
            })
            ->sortByDesc('last');

        return array_values($tools->map(fn (array $tool) => [
            'id' => $tool['id'],
            'name' => $tool['name'],
            'allowed' => $tool['first']->diffForHumans(),
            'used' => $tool['last']->diffForHumans(),
        ])->all());
    }

    /**
     * The person's passes that work now, or whose refresh token can still
     * get a new one.
     *
     * @return Builder<Token>
     */
    public static function live(User $user): Builder
    {
        return Token::query()
            ->where('user_id', $user->getKey())
            ->where('revoked', false)
            ->where(fn (Builder $query) => $query
                ->where('expires_at', '>', now())
                ->orWhereExists(fn (QueryBuilder $refresh) => $refresh
                    ->from((new RefreshToken)->getTable())
                    ->whereColumn('access_token_id', (new Token)->qualifyColumn('id'))
                    ->where('revoked', false)
                    ->where('expires_at', '>', now())));
    }
}
