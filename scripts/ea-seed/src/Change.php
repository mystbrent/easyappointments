<?php

namespace EaSeed;

/** One converged resource, reported by both `plan` and `apply`. */
final class Change
{
    public function __construct(
        public readonly string $resource,
        public readonly string $label,
        public readonly string $action, // create | update | noop
        public readonly ?int $id = null,
    ) {
    }

    public function isNoop(): bool
    {
        return $this->action === 'noop';
    }
}
