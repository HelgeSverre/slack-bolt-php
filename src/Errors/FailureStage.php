<?php

declare(strict_types=1);

namespace Thunder\Bolt\Errors;

enum FailureStage: string
{
    case Payload = 'payload';
    case Authorization = 'authorization';
    case Context = 'context';
    case Middleware = 'middleware';
    case Listener = 'listener';
    case Acknowledgement = 'acknowledgement';
    case Receiver = 'receiver';
}
