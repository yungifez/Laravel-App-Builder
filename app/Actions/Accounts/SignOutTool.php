<?php

namespace App\Actions\Accounts;

use App\Models\User;
use Laravel\Passport\AuthCode;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

class SignOutTool
{
    /**
     * Sign a tool out of one person's apps: its passes, the refresh tokens
     * that renew them and any code not yet traded are revoked. Other
     * people who let the same tool in stay signed in.
     */
    public function handle(User $user, string $client): void
    {
        $passes = Token::query()->where('user_id', $user->getKey())->where('client_id', $client);

        RefreshToken::query()->whereIn('access_token_id', $passes->clone()->select('id'))->update(['revoked' => true]);
        $passes->update(['revoked' => true]);
        AuthCode::query()->where('user_id', $user->getKey())->where('client_id', $client)->update(['revoked' => true]);
    }
}
