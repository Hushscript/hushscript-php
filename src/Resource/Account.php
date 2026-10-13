<?php

declare(strict_types=1);

namespace Hushscript\Resource;

use Hushscript\Account as AccountInfo;
use Hushscript\Balance;
use Hushscript\Http\Body;
use Hushscript\Http\Exchange;

/** The account's balance and limits (spec 6.3). */
final class Account
{
    public function __construct(private readonly Exchange $exchange)
    {
    }

    public function balance(): Balance
    {
        return $this->exchange->fetch('GET', '/v1/account', [], null, static fn (#[\SensitiveParameter] Body $body): Balance => Balance::fromApi($body));
    }

    public function account(): AccountInfo
    {
        return $this->exchange->fetch('GET', '/v1/account', [], null, static fn (#[\SensitiveParameter] Body $body): AccountInfo => AccountInfo::fromApi($body));
    }
}
