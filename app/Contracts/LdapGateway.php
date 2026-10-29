<?php

namespace App\Contracts;

interface LdapGateway
{
    public function authenticate(string $uid, string $password): array;

    public function findUser(string $uid): ?array;
}
