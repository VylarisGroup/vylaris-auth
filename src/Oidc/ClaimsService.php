<?php
declare(strict_types=1);

namespace Vylaris\Oidc;

use Vylaris\Core\Clock;

/** Erzeugt ID-Token-/UserInfo-Claims gemäß gewährten Scopes. */
final class ClaimsService
{
    public function __construct(private ClientService $clients, private string $secret)
    {
    }

    public function sub(int $userId): string
    {
        return Subject::forUser($userId, $this->secret);
    }

    /** @param string[] $scopes */
    public function forUser(array $user, array $scopes, array $client): array
    {
        $claims = ['sub' => $this->sub((int) $user['id'])];
        if (in_array('profile', $scopes, true)) {
            $claims['name'] = $user['display_name'];
            $claims['updated_at'] = Clock::fromDb($user['updated_at']);
        }
        if (in_array('email', $scopes, true)) {
            $claims['email'] = $user['email'];
            $claims['email_verified'] = $user['email_verified_at'] !== null;
        }
        if (in_array('roles', $scopes, true)) {
            $claims['roles'] = $this->clients->userRoles((int) $user['id'], (int) $client['id']);
        }
        return $claims;
    }
}
