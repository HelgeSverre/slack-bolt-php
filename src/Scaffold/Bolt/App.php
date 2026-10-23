<?php

declare(strict_types=1);

/**
 * Declaration-only quick scaffold for Bolt JS `App`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L233
 * Unsupported TypeScript signatures are deliberately represented as `mixed`.
 */
namespace Thunder\Bolt\Scaffold\Bolt;

use Thunder\Bolt\Scaffold\Bolt\NotPortedYet;
use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]

class App
{
    public function __construct(mixed ...$arguments)
    {
        NotPortedYet::forSymbol('App::__construct');
    }

    /** Bolt JS `init`; source type: `Promise<void>`. */
    public function init(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('App::init');
    }

    /** Bolt JS `use`; source type: `this`. */
    public function use(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('App::use');
    }

    /** Bolt JS `assistant`; source type: `this`. */
    public function assistant(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('App::assistant');
    }

    /** Bolt JS `function`; source type: `this`. */
    public function function(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('App::function');
    }

    /** Bolt JS `start`; source type: `ReturnType<HTTPReceiver['start']>`. */
    public function start(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('App::start');
    }

    /** Bolt JS `stop`; source type: `Promise<unknown>`. */
    public function stop(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('App::stop');
    }

    /** Bolt JS `event`; source type: `void`. */
    public function event(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('App::event');
    }

    /** Bolt JS `message`; source type: `void`. */
    public function message(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('App::message');
    }

    /** Bolt JS `shortcut`; source type: `void`. */
    public function shortcut(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('App::shortcut');
    }

    /** Bolt JS `action`; source type: `void`. */
    public function action(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('App::action');
    }

    /** Bolt JS `command`; source type: `void`. */
    public function command(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('App::command');
    }

    /** Bolt JS `options`; source type: `void`. */
    public function options(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('App::options');
    }

    /** Bolt JS `view`; source type: `void`. */
    public function view(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('App::view');
    }

    /** Bolt JS `error`; source type: `void`. */
    public function error(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('App::error');
    }

    /** Bolt JS `processEvent`; source type: `Promise<void>`. */
    public function processEvent(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('App::processEvent');
    }
}
