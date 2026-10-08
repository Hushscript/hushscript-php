<?php

declare(strict_types=1);

namespace Hushscript\Resource;

use Hushscript\AgentAccount;
use Hushscript\AgentSignup;
use Hushscript\Balance;
use Hushscript\Config;
use Hushscript\Credentials;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\OutcomeUnknownError;
use Hushscript\Exception\PreflightError;
use Hushscript\Exception\ValidationError;
use Hushscript\Http\BadBody;
use Hushscript\Http\Body;
use Hushscript\Http\Exchange;
use Hushscript\Http\Routes;
use Hushscript\RotatedToken;
use Hushscript\Transcribe\Shape;

/**
 * Agent accounts (spec 6.6): signup and claim need no token and are static on `Client`; rotation is the
 * client's own. Claim and rotation each mint a credential the server shows once, so an answer that arrived
 * and cannot be used is as lost as one that never came (spec 7.4): `OutcomeUnknownError`, which keeps the token
 * the answer carried.
 */
final class Agent
{
    private const SIGNUP_KEYS = ['email', 'pack', 'accept_terms', 'agent', 'data_region', 'dry_run'];

    private const AGENT_KEYS = ['name', 'platform', 'contact_url'];

    private const MIN_SECRET = 16;

    public function __construct(private readonly Exchange $exchange, private readonly Credentials $credentials)
    {
    }

    /**
     * Mints a successor token and revokes the one in use. The client sends the new token from then on, to retries
     * already queued as well; store the returned one right away. It is never retried. Apart from an API error
     * answer, a failure after the request was sent is an `OutcomeUnknownError` whose hint says what to check: a lost
     * answer, an answer without a usable token, or a failure of the SDK's own while reading it. The error's
     * `receivedToken()` holds the token the answer carried; the client sends it from then on when it passes the
     * token check and the expiry can be read, and keeps its old token otherwise.
     *
     * @throws HushscriptException
     */
    public function rotateToken(): RotatedToken
    {
        $credentials = $this->credentials;

        return self::minted($this->exchange, $credentials, '/v1/agent/credentials/rotate', null, Routes::ROTATE, static function (#[\SensitiveParameter] Body $answer, #[\SensitiveParameter] ?string $seen) use ($credentials): RotatedToken {
            $usable = $seen !== null && Config::acceptableToken($seen);
            $expires = null;
            $unreadable = null;
            try {
                $expires = $answer->nullableTimestamp('expires_at');
            } catch (BadBody $failure) {
                $unreadable = $failure;
            }
            if ($seen !== null && $usable && $unreadable === null) {
                // The server revoked the old token when it answered, so this is the one to send from now on. An
                // expiry that cannot be read is the exception: the answer is then not trusted at all, the old
                // token stays in use and the new one is only kept on the error.
                $credentials->replace($seen);
            }
            if ($seen === null || ! $usable) {
                throw new BadBody('credential response (pat: expected a token)');
            }
            if ($unreadable !== null) {
                throw $unreadable;
            }

            return new RotatedToken($seen, $answer->string('pat_id'), $answer->strings('scopes'), $expires);
        });
    }

    /**
     * Claims a paid signup (see `Client::agentClaim()`).
     *
     * @throws HushscriptException
     */
    public static function claim(Exchange $exchange, Credentials $credentials, #[\SensitiveParameter] string $path, #[\SensitiveParameter] string $claimSecret): AgentAccount
    {
        if (strlen($claimSecret) >= self::MIN_SECRET) {
            $credentials->remember($claimSecret);
        }

        return self::minted($exchange, $credentials, $path, ['claim_secret' => $claimSecret], Routes::CLAIM, static function (#[\SensitiveParameter] Body $answer, #[\SensitiveParameter] ?string $seen): AgentAccount {
            if ($seen === null || ! Config::acceptableToken($seen)) {
                throw new BadBody('credential response (pat: expected a token)');
            }

            return new AgentAccount($seen, $answer->string('user_id'), $answer->strings('scopes'), Balance::granted($answer->count('balance_seconds')));
        });
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws HushscriptException
     */
    public static function signup(Exchange $exchange, #[\SensitiveParameter] array $body): AgentSignup
    {
        return $exchange->fetch('POST', '/v1/agent/accounts', [], $body, static fn (#[\SensitiveParameter] Body $answer): AgentSignup => AgentSignup::fromApi($answer));
    }

    /**
     * The request body of a signup, checked before anything is sent. `accept_terms`, the policy version being
     * accepted, is never defaulted.
     *
     * @param  array<mixed>  $request
     * @return array<string, mixed>
     *
     * @throws HushscriptException
     */
    public static function signupBody(#[\SensitiveParameter] array $request): array
    {
        self::onlyKeys($request, self::SIGNUP_KEYS, 'signup');
        $terms = $request['accept_terms'] ?? null;
        if (! is_string($terms) || trim($terms) === '') {
            throw new ValidationError('accept_terms is required: pass the policy version you accept. It is never filled in for you.', 'accept_terms_required');
        }
        $agent = $request['agent'] ?? null;
        if (! is_array($agent)) {
            throw new PreflightError('agent is required: the name and platform of the agent the account is opened for.', 'invalid_option');
        }
        self::onlyKeys($agent, self::AGENT_KEYS, 'agent');
        $who = ['name' => self::text($agent, 'name', true), 'platform' => self::text($agent, 'platform', true)];
        $contact = self::text($agent, 'contact_url', false);
        if ($contact !== null) {
            $who['contact_url'] = $contact;
        }
        $body = [
            'contact_email' => self::text($request, 'email', true),
            'pack_id' => self::text($request, 'pack', true),
            'accept_terms_version' => $terms,
            'agent' => $who,
        ];
        $region = self::text($request, 'data_region', false);
        if ($region !== null) {
            $body['data_region'] = $region;
        }
        $dryRun = $request['dry_run'] ?? false;
        if (! is_bool($dryRun)) {
            throw new PreflightError('dry_run must be true or false.', 'invalid_option');
        }
        if ($dryRun) {
            $body['dry_run'] = true;
        }

        return $body;
    }

    /** The path of a claim; the signup id is one segment, percent-encoded. */
    public static function claimPath(string $signupId): string
    {
        return '/v1/agent/accounts/'.Shape::segment($signupId, 'signup id').'/claim';
    }

    /**
     * One request whose answer carries a credential. A non-2xx answer is its mapped error. A 2xx answer `$use`
     * cannot use, or that the SDK itself fails on, is `OutcomeUnknownError` with the route's hint and the token the
     * answer carried, which the credentials scrub from every text from then on.
     *
     * @template T
     *
     * @param  array<string, mixed>|null  $body
     * @param  \Closure(Body, ?string): T  $use
     * @return T
     *
     * @throws HushscriptException
     */
    private static function minted(Exchange $exchange, Credentials $credentials, #[\SensitiveParameter] string $path, #[\SensitiveParameter] ?array $body, string $route, \Closure $use): mixed
    {
        [$status, $content] = $exchange->send('POST', $path, [], $body);
        $seen = null;
        try {
            $fields = json_decode($content, true, 512);
            $fields = is_array($fields) ? $fields : [];
            $pat = $fields['pat'] ?? null;
            $seen = is_string($pat) ? $pat : null;
            if ($seen !== null && Config::acceptableToken(trim($seen))) {
                $credentials->remember(trim($seen));
            }

            return $use(Body::of($fields, 'response'), $seen);
        } catch (\Throwable $failure) {
            $summary = $failure instanceof BadBody ? null : Exchange::summary($failure, $credentials);
            $cause = $summary === null ? Exchange::badResponse($status) : new \RuntimeException($summary);
        }

        throw new OutcomeUnknownError(
            $credentials->scrub('The answer to '.($route === Routes::CLAIM ? 'the claim' : 'the rotation').' could not be used, so the token may be lost.'.($summary === null ? '' : ' '.$summary)),
            hint: Routes::UNKNOWN_HINTS[$route] ?? '',
            previous: $cause,
            receivedToken: $seen,
        );
    }

    /**
     * @param  array<mixed>  $fields
     * @param  list<string>  $allowed
     */
    private static function onlyKeys(#[\SensitiveParameter] array $fields, array $allowed, string $what): void
    {
        foreach (array_keys($fields) as $name) {
            if (! in_array($name, $allowed, true)) {
                throw new PreflightError('Unknown '.$what.' option "'.(is_string($name) ? $name : (string) $name).'".', 'invalid_option');
            }
        }
    }

    /**
     * @param  array<mixed>  $fields
     */
    private static function text(#[\SensitiveParameter] array $fields, string $key, bool $required): ?string
    {
        $value = $fields[$key] ?? null;
        if ($value === null && ! $required) {
            return null;
        }
        if (! is_string($value) || $value === '') {
            throw new PreflightError($key.' must be a non-empty string.', 'invalid_option');
        }

        return $value;
    }
}
