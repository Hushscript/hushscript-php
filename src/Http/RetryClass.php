<?php

declare(strict_types=1);

namespace Hushscript\Http;

/** The operation classes of the retry table in spec 7.2. */
enum RetryClass
{
    case Safe;
    case Part;
    case Complete;
    case Create;
    case Purchase;
    case Never;
    case Mutate;
}
