<?php

namespace EaSeed;

/**
 * Numeric ids assigned by EasyAppointments, keyed by the manifest's stable keys.
 * Threaded through convergence so later resources can resolve references and the
 * env emitter can write the ids the storefronts read.
 */
final class Ids
{
    /** @var array<string,int> category name => id */
    public array $categories = [];

    /** @var array<string,int> service key => id */
    public array $services = [];

    /** @var array<string,int> provider key => id */
    public array $providers = [];

    /** @var array<string,int> webhook name => id */
    public array $webhooks = [];
}
