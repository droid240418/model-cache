<?php

declare(strict_types=1);

namespace Hyperf\ModelCache\Handler;

/** Opt-in guarantee: atomic whole-value replacement and scalar/null round trips. */
interface CompleteSnapshotHandler extends HandlerInterface
{
}
