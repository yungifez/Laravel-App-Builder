<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

/**
 * An OAuth client, as Passport keeps it. A tool that signs up by itself
 * (the Claude app, VS Code, Cursor) gets a new one on each fresh
 * connection, so the ones nobody uses any more are pruned.
 */
class OAuthClient extends Client
{
    use Prunable;

    /**
     * Get the self-registered clients nobody uses: older than a day, with no
     * token that still works, or that stopped working within the days a tool
     * stays connected to an app. Only public clients without an owner sign
     * up by themselves; any other client is left alone.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $since = now()->subDays((int) config('builder.agents.workers.project_days'));

        return static::query()
            ->whereNull('owner_id')
            ->whereNull('secret')
            ->where('created_at', '<', now()->subDay())
            ->whereNotExists(fn (QueryBuilder $tokens) => $tokens
                ->from((new Token)->getTable())
                ->whereColumn('client_id', $this->qualifyColumn('id'))
                ->where(fn (QueryBuilder $recent) => $recent
                    ->where(fn (QueryBuilder $live) => $live->where('revoked', false)->where('expires_at', '>', $since))
                    // A revoked token stopped working when it was revoked.
                    ->orWhere(fn (QueryBuilder $revoked) => $revoked->where('revoked', true)->where('updated_at', '>', $since))
                    // A tool renews its pass with the refresh token, which
                    // outlives the pass itself.
                    ->orWhereExists(fn (QueryBuilder $refresh) => $refresh
                        ->from((new RefreshToken)->getTable())
                        ->whereColumn('access_token_id', (new Token)->qualifyColumn('id'))
                        ->where('revoked', false)
                        ->where('expires_at', '>', $since))));
    }

    /**
     * Remove what the client was given along with it.
     */
    protected function pruning(): void
    {
        $tokens = Token::query()->where('client_id', $this->getKey());

        RefreshToken::query()->whereIn('access_token_id', $tokens->clone()->select('id'))->delete();
        $tokens->delete();
        AuthCode::query()->where('client_id', $this->getKey())->delete();
    }
}
