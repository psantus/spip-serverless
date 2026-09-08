<?php declare(strict_types=1);

use Bref\Context\Context;
use Bref\Event\Handler;
use Bref\Listener\BrefEventSubscriber;
use Psr\Http\Server\RequestHandlerInterface;

Bref\Bref::events()->subscribe(new class extends BrefEventSubscriber {
    public function beforeInvoke(
        Handler|RequestHandlerInterface|callable $handler,
        mixed $event,
        Context $context,
    ): void {
        file_put_contents('/tmp/xray_trace_id', $context->getTraceId());
    }
});
