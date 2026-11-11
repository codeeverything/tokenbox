<?php

namespace Tokenbox;

final class Health implements \JsonSerializable
{
    public const OK = 'ok';
    public const EXPIRING = 'expiring';
    public const EXPIRED = 'expired';
    public const INVALID = 'invalid';
    public const UNREACHABLE = 'unreachable';
    public const UNVERIFIED = 'unverified';
    public const UNCHECKED = 'unchecked';

    public function __construct(
        public readonly string $state,
        public readonly ?string $account = null,
        public readonly array $scopes = [],
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly ?string $detail = null,
        /** True when the provider told us the expiry, so a null $expiresAt really means "never". */
        public readonly bool $expiryKnown = false,
    ) {
    }

    public function neverExpires(): bool
    {
        return $this->expiryKnown && !$this->expiresAt && in_array($this->state, [self::OK, self::EXPIRING], true);
    }

    /** Downgrade an OK result to expiring/expired based on the expiry date. */
    public function withExpiry(?\DateTimeImmutable $expiresAt, int $warnDays): self
    {
        $state = $this->state;
        if ($expiresAt && $state === self::OK) {
            $days = $this->daysLeft($expiresAt);
            $state = $days < 0 ? self::EXPIRED : ($days <= $warnDays ? self::EXPIRING : self::OK);
        }

        return new self($state, $this->account, $this->scopes, $expiresAt, $this->detail, $this->expiryKnown);
    }

    public function daysLeft(?\DateTimeImmutable $at = null): ?int
    {
        $at ??= $this->expiresAt;
        if (!$at) {
            return null;
        }
        $seconds = $at->getTimestamp() - time();

        return (int) floor($seconds / 86400);
    }

    public function isProblem(): bool
    {
        return in_array($this->state, [self::EXPIRED, self::INVALID, self::EXPIRING], true);
    }

    public function jsonSerialize(): array
    {
        return [
            'state' => $this->state,
            'account' => $this->account,
            'scopes' => $this->scopes,
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'days_left' => $this->daysLeft(),
            'never_expires' => $this->neverExpires(),
            'detail' => $this->detail,
        ];
    }
}
